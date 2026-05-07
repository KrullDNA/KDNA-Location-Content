<?php
/**
 * Abstract base for SEO-plugin adapters.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter
 *
 * One concrete subclass per supported SEO plugin (Yoast, Rank Math,
 * AIOSEO, SEOPress, The SEO Framework, Slim SEO, SmartCrawl, Squirrly).
 *
 * Each adapter declares:
 *   - slug() / label()        : identity for the registry + admin UI.
 *   - is_active()             : detection check.
 *   - meta_keys()             : 'concept' => meta key the SEO plugin
 *                               itself uses for that concept on a post.
 *   - filter_names()          : 'concept' => filter hook the SEO plugin
 *                               fires when rendering that concept.
 *
 * Concepts: title, description, canonical, focus, og_title, og_description, og_image.
 *
 * The adapter's init() walks the filter map and registers a single
 * resolver per concept; the resolver looks up a per-region / per-language
 * override in post meta (suffix pattern: `{meta_key}_{slug}`) and falls
 * back to the SEO plugin's own value when no override is set.
 *
 * Schema and Sitemap integration live outside the adapter for now and
 * remain Yoast-only — those plugins each have radically different schema
 * and sitemap architectures, so porting them is a separate stage.
 */
abstract class KDNA_RC_SEO_Adapter {

	/**
	 * Short slug for the registry, the Tools-tab UI, and SEO-meta-box
	 * field-key prefixes. ASCII, lowercase, no spaces.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Display label for the Tools-tab "Detected SEO plugin" line.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the SEO plugin this adapter supports is currently active.
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Map of concept => meta key that the SEO plugin reads on post.
	 *
	 * Example for Yoast:
	 *   array(
	 *       'title'       => '_yoast_wpseo_title',
	 *       'description' => '_yoast_wpseo_metadesc',
	 *       ...
	 *   );
	 *
	 * Adapters whose SEO plugin stores values somewhere other than post
	 * meta (AIOSEO uses custom tables; Slim SEO uses a serialised meta
	 * array) should still return a meta-key prefix here for OUR override
	 * storage. The 'currently shown' preview on the Default tab can be
	 * blank in that case; the override pattern still works because we
	 * intercept the SEO plugin's filter chain.
	 *
	 * @return array<string,string>
	 */
	abstract public function meta_keys();

	/**
	 * Map of concept => filter name to hook with our resolver.
	 *
	 * Concepts that the SEO plugin does not expose via a filter can be
	 * omitted; the meta box still surfaces the field but the override
	 * will only take effect if the SEO plugin happens to read OUR meta
	 * key directly.
	 *
	 * @return array<string,string>
	 */
	abstract public function filter_names();

	/**
	 * The "concept" of an OG image: 'id' (attachment ID) or 'url' (URL).
	 *
	 * Yoast and Rank Math store the attachment ID in post meta and emit
	 * the URL in the filter. SEOPress and AIOSEO store the URL directly.
	 *
	 * @return string Either 'id' or 'url'.
	 */
	public function og_image_storage() {
		return 'id';
	}

	/**
	 * Wire the filter resolvers.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_active() ) {
			return;
		}

		$keys    = $this->meta_keys();
		$filters = $this->filter_names();

		foreach ( $filters as $concept => $hook ) {
			$meta_key = isset( $keys[ $concept ] ) ? $keys[ $concept ] : '';
			if ( '' === $meta_key ) {
				continue;
			}

			if ( 'og_image' === $concept ) {
				add_filter(
					$hook,
					function ( $value ) use ( $meta_key ) {
						return $this->resolve_image( $value, $meta_key );
					},
					100
				);
				continue;
			}

			if ( 'canonical' === $concept ) {
				add_filter(
					$hook,
					function ( $value ) use ( $meta_key ) {
						return $this->resolve_canonical( $value, $meta_key );
					},
					100
				);
				continue;
			}

			$priority = 'language_first';
			if ( 'focus' === $concept ) {
				$priority = 'language_first'; // Same priority; admins-only field.
			}

			add_filter(
				$hook,
				function ( $value ) use ( $meta_key, $priority ) {
					return $this->resolve_string( $value, $meta_key, $priority );
				},
				100
			);
		}
	}

	/**
	 * Whether the current request URL carried a KDNA prefix.
	 *
	 * @return bool
	 */
	protected function request_was_prefixed() {
		$wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;
		if ( ! is_object( $wp ) || empty( $wp->query_vars ) ) {
			return false;
		}
		return ! empty( $wp->query_vars['kdna_region'] ) || ! empty( $wp->query_vars['kdna_language'] );
	}

	/**
	 * Active region + language slugs for the current request.
	 *
	 * @return array{region:string,language:string}
	 */
	protected function active_slugs() {
		if ( ! $this->request_was_prefixed() ) {
			return array( 'region' => '', 'language' => '' );
		}
		return array(
			'region'   => isset( $_COOKIE['kdna_region'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_region'] ) ) : '',
			'language' => isset( $_COOKIE['kdna_language'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) ) : '',
		);
	}

	/**
	 * Resolve a string-typed override in priority order. Falls back to the
	 * SEO plugin's default value when no per-region / per-language
	 * override exists.
	 *
	 * @param string $default_value SEO plugin's default.
	 * @param string $base_key      Base meta key.
	 * @param string $priority      'language_first' or 'region_first'.
	 * @return string
	 */
	protected function resolve_string( $default_value, $base_key, $priority = 'language_first' ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) { return $default_value; }

		$slugs = $this->active_slugs();
		if ( '' === $slugs['language'] && '' === $slugs['region'] ) {
			return $default_value;
		}

		$order = ( 'region_first' === $priority )
			? array( $slugs['region'], $slugs['language'] )
			: array( $slugs['language'], $slugs['region'] );

		foreach ( $order as $slug ) {
			if ( '' === $slug ) { continue; }
			$override = (string) get_post_meta( $post_id, $base_key . '_' . $slug, true );
			if ( '' !== trim( $override ) ) {
				return $override;
			}
		}
		return $default_value;
	}

	/**
	 * Resolve an image-typed override.
	 *
	 * Adapters that store attachment IDs translate the override ID to a
	 * URL. Adapters that store URLs directly return the override URL
	 * verbatim.
	 *
	 * @param string $default_value Default URL the SEO plugin produced.
	 * @param string $base_key      Base meta key.
	 * @return string
	 */
	protected function resolve_image( $default_value, $base_key ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) { return $default_value; }

		$slugs = $this->active_slugs();
		foreach ( array( $slugs['language'], $slugs['region'] ) as $slug ) {
			if ( '' === $slug ) { continue; }
			$override = get_post_meta( $post_id, $base_key . '_' . $slug, true );
			if ( '' === $override || null === $override ) { continue; }

			if ( 'id' === $this->og_image_storage() && is_numeric( $override ) ) {
				$url = wp_get_attachment_image_url( (int) $override, 'full' );
				if ( $url ) { return (string) $url; }
				continue;
			}
			if ( is_string( $override ) && '' !== trim( $override ) ) {
				return $override;
			}
		}
		return $default_value;
	}

	/**
	 * Resolve a canonical-URL override, honouring the Stage 14 strategy.
	 *
	 * Per-post overrides win. When no override is set and the request has
	 * a KDNA prefix, the strategy chooses bare-URL or self-canonical.
	 *
	 * @param string $default Default canonical from the SEO plugin.
	 * @param string $base_key Base meta key.
	 * @return string
	 */
	protected function resolve_canonical( $default, $base_key ) {
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) { return $default; }

		$slugs = $this->active_slugs();
		foreach ( array( $slugs['region'], $slugs['language'] ) as $slug ) {
			if ( '' === $slug ) { continue; }
			$override = (string) get_post_meta( $post_id, $base_key . '_' . $slug, true );
			if ( '' !== trim( $override ) ) {
				return $override;
			}
		}

		if ( '' === $slugs['region'] && '' === $slugs['language'] ) {
			return $default;
		}

		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$strategy = isset( $settings['canonical_strategy'] ) && 'each' === $settings['canonical_strategy'] ? 'each' : 'bare';

		if ( 'each' === $strategy ) {
			return is_ssl() ? set_url_scheme( home_url( add_query_arg( null, null ) ), 'https' ) : home_url( add_query_arg( null, null ) );
		}
		$bare = (string) get_permalink( $post_id );
		return '' !== $bare ? $bare : home_url( '/' );
	}

	/**
	 * Current post ID being rendered.
	 *
	 * @return int
	 */
	protected function resolve_post_id() {
		$queried = get_queried_object_id();
		if ( $queried > 0 ) { return (int) $queried; }
		$post_id = (int) get_the_ID();
		return $post_id > 0 ? $post_id : 0;
	}
}
