<?php
/**
 * Yoast SEO filter integration: substitute regional / language overrides
 * for Yoast's title, meta description, Open Graph, Twitter, and canonical
 * outputs based on the URL prefix from Stage 14.
 *
 * Targets Yoast SEO 21.x. Earlier versions emit the same filter names
 * with the same signatures, so the integration generally works there
 * too; only the schema-property filter names have shifted across major
 * Yoast releases (handled in class-yoast-schema-integration.php).
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Yoast_Integration
 *
 * Reads the visitor's URL-derived region / language slug from the
 * cookies set by KDNA_RC_URL_Routing during parse_request, then for
 * each Yoast filter it intercepts:
 *   - returns the matching regional / language override from
 *     KDNA_RC_SEO_Meta_Box::read_override() when one exists,
 *   - otherwise lets Yoast's default value pass through unchanged.
 *
 * Resolution priority documented in the README:
 *   - Visitor-facing fields where language matters most (titles,
 *     descriptions, OG / Twitter copy) prefer the language slug, then
 *     the region slug.
 *   - Region-bound fields where region matters most (LocalBusiness
 *     address / phone, regional canonical) prefer the region slug,
 *     then the language slug.
 *   - Bare URL (no prefix) returns Yoast's default unchanged.
 */
class KDNA_RC_Yoast_Integration {

	/**
	 * Wire filters when Yoast is loaded.
	 *
	 * @return void
	 */
	public function init() {
		// As of v0.1.9 filter registration moved into the SEO adapter
		// registry so the same UX works against every supported SEO
		// plugin (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework,
		// Slim SEO, SmartCrawl, Squirrly). This class is kept as a thin
		// shim because KDNA_RC_Yoast_Schema_Integration calls
		// resolve_active_slug() on it; its filter registration is now
		// the adapter's job.
		$adapter = KDNA_RC_SEO_Adapter_Registry::active_adapter();
		if ( null !== $adapter ) {
			$adapter->init();
		}
	}

	/**
	 * Resolve which slug to use for the current request.
	 *
	 * @param string $priority Either 'language_first' or 'region_first'.
	 * @return string Empty string when no prefix is in play.
	 */
	public function resolve_active_slug( $priority = 'language_first' ) {
		$region   = isset( $_COOKIE['kdna_region'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_region'] ) ) : '';
		$language = isset( $_COOKIE['kdna_language'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) ) : '';

		// Only treat the cookie as authoritative for SEO when the URL
		// itself carried the prefix (Stage 14). Otherwise Yoast's defaults
		// stay in play, which matches the "bare URL is canonical" pattern.
		if ( ! $this->request_was_prefixed() ) {
			return '';
		}

		if ( 'region_first' === $priority ) {
			return '' !== $region ? $region : $language;
		}
		return '' !== $language ? $language : $region;
	}

	/**
	 * Return the language slug + region slug pair for the current request.
	 *
	 * @return array{language:string,region:string}
	 */
	public function active_slugs() {
		if ( ! $this->request_was_prefixed() ) {
			return array( 'language' => '', 'region' => '' );
		}
		return array(
			'region'   => isset( $_COOKIE['kdna_region'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_region'] ) ) : '',
			'language' => isset( $_COOKIE['kdna_language'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) ) : '',
		);
	}

	/**
	 * Whether the current request URL carried a KDNA prefix.
	 *
	 * Inspects WP::query_vars set by KDNA_RC_URL_Routing::inject_query_vars().
	 *
	 * @return bool
	 */
	private function request_was_prefixed() {
		$wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;
		if ( ! is_object( $wp ) || empty( $wp->query_vars ) ) {
			return false;
		}
		return ! empty( $wp->query_vars['kdna_region'] ) || ! empty( $wp->query_vars['kdna_language'] );
	}

	/**
	 * Generic resolver: try the language slug first (or region first),
	 * then the other, then fall back to Yoast's default value.
	 *
	 * @param string $default_value Yoast's default.
	 * @param string $base_key      Base meta key (without slug suffix).
	 * @param string $priority      'language_first' or 'region_first'.
	 * @return string
	 */
	private function resolve_string( $default_value, $base_key, $priority = 'language_first' ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) {
			return $default_value;
		}

		$slugs = $this->active_slugs();
		if ( '' === $slugs['language'] && '' === $slugs['region'] ) {
			return $default_value;
		}

		$order = ( 'region_first' === $priority )
			? array( $slugs['region'], $slugs['language'] )
			: array( $slugs['language'], $slugs['region'] );

		foreach ( $order as $slug ) {
			if ( '' === $slug ) { continue; }
			$override = KDNA_RC_SEO_Meta_Box::read_override( $post_id, $base_key, $slug );
			if ( '' !== $override ) {
				return $override;
			}
		}
		return $default_value;
	}

	/**
	 * OG image: stored as attachment ID, returned as URL.
	 *
	 * @param string $default_url Yoast's default OG image URL.
	 * @return string
	 */
	public function filter_opengraph_image( $default_url ) {
		return $this->resolve_image( $default_url, '_yoast_wpseo_opengraph-image-id' );
	}

	/**
	 * Resolve an image override to a full URL.
	 *
	 * @param string $default_url Default URL.
	 * @param string $base_key    Base meta key.
	 * @return string
	 */
	private function resolve_image( $default_url, $base_key ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) { return $default_url; }
		$slugs = $this->active_slugs();
		foreach ( array( $slugs['language'], $slugs['region'] ) as $slug ) {
			if ( '' === $slug ) { continue; }
			$attachment_id = (int) KDNA_RC_SEO_Meta_Box::read_override( $post_id, $base_key, $slug );
			if ( $attachment_id > 0 ) {
				$url = wp_get_attachment_image_url( $attachment_id, 'full' );
				if ( $url ) { return $url; }
			}
		}
		return $default_url;
	}

	/**
	 * Canonical URL filter, honouring the Stage 14 canonical strategy.
	 *
	 * Strategy 'bare' (default): every regional / language URL declares
	 * the bare URL as canonical, consolidating ranking signals.
	 * Strategy 'each':           regional / language URLs are
	 * self-canonical (Google treats each as its own page).
	 *
	 * Per-post canonical overrides (set on the SEO meta box's region
	 * tab) win over the strategy when present.
	 *
	 * @param string $default Yoast's default canonical.
	 * @return string
	 */
	public function filter_canonical( $default ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) {
			return $default;
		}

		$slugs = $this->active_slugs();
		// Per-post override beats the strategy.
		foreach ( array( $slugs['region'], $slugs['language'] ) as $slug ) {
			if ( '' === $slug ) { continue; }
			$override = KDNA_RC_SEO_Meta_Box::read_override( $post_id, '_yoast_wpseo_canonical', $slug );
			if ( '' !== $override ) {
				return $override;
			}
		}

		if ( '' === $slugs['region'] && '' === $slugs['language'] ) {
			return $default; // Bare URL: keep Yoast's value.
		}

		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$strategy = isset( $settings['canonical_strategy'] ) && 'each' === $settings['canonical_strategy'] ? 'each' : 'bare';

		if ( 'each' === $strategy ) {
			return is_ssl() ? set_url_scheme( home_url( add_query_arg( null, null ) ), 'https' ) : home_url( add_query_arg( null, null ) );
		}

		// Bare-canonical: rebuild the URL without the KDNA prefix.
		return $this->build_bare_url( $post_id );
	}

	/**
	 * Build the bare (prefix-less) URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function build_bare_url( $post_id ) {
		$bare = (string) get_permalink( $post_id );
		return '' !== $bare ? $bare : home_url( '/' );
	}

	/**
	 * Resolve the current post ID being rendered.
	 *
	 * @return int
	 */
	private function resolve_post_id() {
		$queried = get_queried_object_id();
		if ( $queried > 0 ) { return (int) $queried; }
		$post_id = (int) get_the_ID();
		return $post_id > 0 ? $post_id : 0;
	}
}
