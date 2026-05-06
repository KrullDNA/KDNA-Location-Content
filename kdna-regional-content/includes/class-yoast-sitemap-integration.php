<?php
/**
 * Yoast sitemap integration: include regional / language URL variants
 * with hreflang annotations in the XML sitemap.
 *
 * Targets Yoast SEO 21.x. Provides three operating modes selectable
 * from the General-tab "Sitemap integration mode" radio:
 *   - extend       Augment Yoast's sitemap by adding xhtml:link rows
 *                  per <url> entry (the recommended Google pattern).
 *   - supplementary  Generate a parallel /kdna-rc-sitemap.xml that
 *                  lists every regional URL with its alternates.
 *   - disabled     Do nothing.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Yoast_Sitemap_Integration
 */
class KDNA_RC_Yoast_Sitemap_Integration {

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init() {
		$mode = $this->mode();
		if ( 'disabled' === $mode ) {
			return;
		}

		if ( 'extend' === $mode && defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_sitemap_url', array( $this, 'extend_yoast_url_entry' ), 10, 2 );
		}

		if ( 'supplementary' === $mode ) {
			// Register the supplementary sitemap as a virtual URL.
			add_action( 'init', array( $this, 'register_supplementary_route' ) );
			add_filter( 'wpseo_sitemap_index_links', array( $this, 'append_to_yoast_sitemap_index' ) );
			add_filter( 'robots_txt', array( $this, 'append_to_robots_txt' ), 10, 2 );
		}
	}

	/**
	 * Extend a single <url> entry in Yoast's per-post-type sitemap with
	 * one xhtml:link per regional / language variant + an x-default.
	 *
	 * Yoast passes the half-built XML output for one URL plus an args
	 * array including the post object. We append xhtml:link siblings
	 * inside the <url> element.
	 *
	 * @param string $output Yoast's <url>...</url> XML string.
	 * @param array  $url    Yoast's URL args (loc, mod, etc., plus images/post_id depending on version).
	 * @return string
	 */
	public function extend_yoast_url_entry( $output, $url ) {
		if ( ! is_string( $output ) || '' === $output ) {
			return $output;
		}
		// Yoast may pass a `post_id` or the full WP_Post; read defensively.
		$post_id = 0;
		if ( is_array( $url ) ) {
			if ( ! empty( $url['post_id'] ) ) {
				$post_id = (int) $url['post_id'];
			} elseif ( isset( $url['post'] ) && is_object( $url['post'] ) && isset( $url['post']->ID ) ) {
				$post_id = (int) $url['post']->ID;
			} elseif ( ! empty( $url['loc'] ) ) {
				$post_id = (int) url_to_postid( (string) $url['loc'] );
			}
		}
		if ( $post_id <= 0 ) {
			return $output;
		}

		$variants = $this->variants_for( $post_id );
		if ( empty( $variants ) ) {
			return $output;
		}

		$links = '';
		foreach ( $variants as $variant ) {
			$lang = $this->hreflang_for( $variant );
			if ( '' === $lang ) { continue; }
			$links .= sprintf(
				"\n\t<xhtml:link rel=\"alternate\" hreflang=\"%1\$s\" href=\"%2\$s\" />",
				esc_attr( $lang ),
				esc_url( $variant['url'] )
			);
		}

		// Inject just before </url>.
		if ( '' !== $links ) {
			$output = str_replace( '</url>', $links . "\n</url>", $output );
		}

		return $output;
	}

	/**
	 * Register the /kdna-rc-sitemap.xml route for the supplementary mode.
	 *
	 * @return void
	 */
	public function register_supplementary_route() {
		add_rewrite_rule( '^kdna-rc-sitemap\\.xml$', 'index.php?kdna_rc_sitemap=1', 'top' );
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'kdna_rc_sitemap';
				return $vars;
			}
		);
		add_action( 'template_redirect', array( $this, 'maybe_render_supplementary' ) );
	}

	/**
	 * Render the supplementary sitemap when the query var is set.
	 *
	 * @return void
	 */
	public function maybe_render_supplementary() {
		if ( ! get_query_var( 'kdna_rc_sitemap' ) ) {
			return;
		}
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
		nocache_headers();

		echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		// List every public post on every eligible post type. Capped at
		// 5000 entries so the file stays a reasonable size; large sites
		// should switch back to extend mode.
		$post_types = class_exists( 'KDNA_RC_URL_Routing' ) ? ( new KDNA_RC_URL_Routing() )->eligible_post_types() : array_keys( get_post_types( array( 'public' => true ), 'names' ) );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 5000,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post ) {
			$variants = $this->variants_for( $post->ID );
			if ( empty( $variants ) ) { continue; }

			foreach ( $variants as $self ) {
				echo "\t<url>\n";
				echo "\t\t<loc>" . esc_url( $self['url'] ) . "</loc>\n";
				echo "\t\t<lastmod>" . esc_html( get_post_modified_time( 'c', true, $post ) ) . "</lastmod>\n";
				foreach ( $variants as $alt ) {
					$lang = $this->hreflang_for( $alt );
					if ( '' === $lang ) { continue; }
					echo "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $lang ) . "\" href=\"" . esc_url( $alt['url'] ) . "\" />\n";
				}
				echo "\t</url>\n";
			}
		}

		echo '</urlset>' . "\n";
		exit;
	}

	/**
	 * Append the supplementary sitemap to Yoast's <sitemapindex>.
	 *
	 * @param array $links Existing sitemap index links.
	 * @return array
	 */
	public function append_to_yoast_sitemap_index( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$links[] = array(
			'loc'     => home_url( '/kdna-rc-sitemap.xml' ),
			'lastmod' => gmdate( 'c' ),
		);
		return $links;
	}

	/**
	 * Append the supplementary sitemap to robots.txt as a Sitemap line.
	 *
	 * @param string $output    Existing robots.txt body.
	 * @param bool   $is_public Whether public access is allowed.
	 * @return string
	 */
	public function append_to_robots_txt( $output, $is_public ) {
		if ( ! $is_public ) { return $output; }
		$line = "Sitemap: " . esc_url_raw( home_url( '/kdna-rc-sitemap.xml' ) ) . "\n";
		// Avoid duplicates on repeat saves.
		if ( false === strpos( (string) $output, 'kdna-rc-sitemap.xml' ) ) {
			$output .= "\n" . $line;
		}
		return $output;
	}

	/**
	 * Resolve the configured mode, defaulting to "extend".
	 *
	 * @return string One of 'extend', 'supplementary', 'disabled'.
	 */
	public function mode() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$mode     = isset( $settings['sitemap_mode'] ) ? sanitize_key( (string) $settings['sitemap_mode'] ) : 'extend';
		if ( ! in_array( $mode, array( 'extend', 'supplementary', 'disabled' ), true ) ) {
			$mode = 'extend';
		}
		return $mode;
	}

	/**
	 * Variants list for a post via the Stage 14 helper.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array>
	 */
	private function variants_for( $post_id ) {
		if ( ! class_exists( 'KDNA_RC_URL_Routing' ) ) {
			return array();
		}
		return ( new KDNA_RC_URL_Routing() )->build_post_url_variants( $post_id );
	}

	/**
	 * Compute the hreflang value for a variant (mirrors the Stage 15
	 * hreflang class so the two outputs stay in lockstep).
	 *
	 * @param array $variant Variant.
	 * @return string
	 */
	private function hreflang_for( array $variant ) {
		$region   = isset( $variant['region'] ) ? (string) $variant['region'] : '';
		$language = isset( $variant['language'] ) ? (string) $variant['language'] : '';

		if ( '' === $region && '' === $language ) {
			return 'x-default';
		}
		if ( '' !== $region && '' !== $language ) {
			return strtolower( $language ) . '-' . strtoupper( $region );
		}
		if ( '' === $region ) {
			return strtolower( $language );
		}
		$region_obj = ( new KDNA_RC_Regions() )->get( $region );
		if ( is_array( $region_obj ) && ! empty( $region_obj['default_language'] ) ) {
			return strtolower( (string) $region_obj['default_language'] ) . '-' . strtoupper( $region );
		}
		return strtoupper( $region );
	}
}
