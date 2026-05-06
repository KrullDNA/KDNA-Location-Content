<?php
/**
 * hreflang annotations for regional / language URL variants.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Hreflang
 *
 * Emits <link rel="alternate" hreflang="..."> tags in <head> for the
 * current page's regional / language URL variants. Output is ordered:
 *   1. x-default → bare URL (Google's recommended fallback signal),
 *   2. one tag per region (using the region's mapped Default Language
 *      from Stage 10 to build the language-region code where possible),
 *   3. one tag per language,
 *   4. one tag per region/language combination.
 *
 * Defers to Yoast Premium's hreflang feature when it is detected so we
 * never produce duplicate tags.
 *
 * Setting: General-tab "Generate hreflang tags" toggle (default on).
 */
class KDNA_RC_Hreflang {

	/**
	 * Wire wp_head emission.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'emit' ), 1 );
	}

	/**
	 * Emit the hreflang tags.
	 *
	 * Skips on admin pages, REST requests, feeds, and any non-singular
	 * content type (search, 404, paginated archives) because hreflang
	 * applies to indexable pages with stable equivalents in other
	 * regions / languages.
	 *
	 * @return void
	 */
	public function emit() {
		if ( is_admin() || ! is_singular() && ! is_home() && ! is_front_page() ) {
			return;
		}
		if ( ! $this->enabled() ) {
			return;
		}
		if ( $this->yoast_premium_handles_hreflang() ) {
			return;
		}
		if ( ! class_exists( 'KDNA_RC_URL_Routing' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return;
		}

		$routing  = new KDNA_RC_URL_Routing();
		$variants = $routing->build_post_url_variants( $post_id );
		if ( empty( $variants ) ) {
			return;
		}

		$lines = array();
		foreach ( $variants as $variant ) {
			$href     = (string) $variant['url'];
			$hreflang = $this->compute_hreflang_value( $variant );
			if ( '' === $hreflang ) { continue; }
			$lines[] = sprintf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />',
				esc_attr( $hreflang ),
				esc_url( $href )
			);
		}

		if ( empty( $lines ) ) {
			return;
		}

		echo "<!-- KDNA Regional Content hreflang -->\n";
		foreach ( $lines as $line ) {
			echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled with esc_attr/esc_url above.
		}
	}

	/**
	 * Compute the hreflang attribute value for a URL variant.
	 *
	 * Combinations: language-region (e.g. `en-AU`).
	 * Language-only: language code (e.g. `fr`).
	 * Region-only: try the region's mapped Default Language for a
	 * language-region pair; fall back to `x-default-region` style isn't
	 * a real Google value, so when no language is mappable we emit the
	 * raw region as a hreflang country code (e.g. `AU`). Google accepts
	 * this with a warning; documented in the README.
	 * Bare URL: `x-default`.
	 *
	 * @param array $variant Variant entry from KDNA_RC_URL_Routing::build_post_url_variants().
	 * @return string
	 */
	private function compute_hreflang_value( array $variant ) {
		$region   = isset( $variant['region'] ) ? (string) $variant['region'] : '';
		$language = isset( $variant['language'] ) ? (string) $variant['language'] : '';

		if ( '' === $region && '' === $language ) {
			return 'x-default';
		}

		// Combination: language-region.
		if ( '' !== $region && '' !== $language ) {
			return strtolower( $language ) . '-' . strtoupper( $region );
		}

		// Language only.
		if ( '' === $region ) {
			return strtolower( $language );
		}

		// Region only: try to upgrade with the region's default language.
		$region_obj = ( new KDNA_RC_Regions() )->get( $region );
		if ( is_array( $region_obj ) && ! empty( $region_obj['default_language'] ) ) {
			return strtolower( (string) $region_obj['default_language'] ) . '-' . strtoupper( $region );
		}

		return strtoupper( $region );
	}

	/**
	 * Whether the General-tab toggle is on.
	 *
	 * @return bool
	 */
	private function enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) || ! array_key_exists( 'hreflang_enabled', $settings ) ) {
			return true; // Default on.
		}
		return ! empty( $settings['hreflang_enabled'] );
	}

	/**
	 * Whether Yoast Premium is active and handling its own hreflang.
	 *
	 * @return bool
	 */
	private function yoast_premium_handles_hreflang() {
		return defined( 'WPSEO_PREMIUM_FILE' );
	}
}
