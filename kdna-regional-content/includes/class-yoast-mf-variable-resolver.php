<?php
/**
 * Yoast variable replacement resolver for KDNA Multilingual Fields.
 *
 * Targets Yoast SEO 21.x.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Yoast_MF_Variable_Resolver
 *
 * Yoast lets editors compose title / description / OG / schema templates
 * with placeholder variables like %%cf_product_name%%. Yoast resolves
 * each one by reading the matching post meta key. For Stage 12
 * Multilingual Fields the raw value is a serialised PHP array; without
 * intervention Yoast renders that array's serialised representation
 * verbatim, which is unusable.
 *
 * This class registers a higher-priority filter on Yoast's
 * `wpseo_replacements` chain (run after the value has been read) and,
 * when the resolved value looks like a multilingual array, replaces it
 * with the visitor language's value (with default-tab fallback).
 *
 * Implementation detail: Yoast's variable system fires
 * `wpseo_replacements` once per template render with a snapshot of the
 * replacements array keyed by their final placeholder names (e.g.
 * `%%cf_product_name%%`). We walk the snapshot and unpack any entry
 * whose value is a serialised KDNA Multilingual array.
 */
class KDNA_RC_Yoast_MF_Variable_Resolver {

	/**
	 * Wire filters when Yoast is loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		add_filter( 'wpseo_replacements', array( $this, 'filter_replacements' ), 100, 2 );
	}

	/**
	 * Walk Yoast's replacements snapshot and unpack multilingual arrays.
	 *
	 * @param array $replacements Map of `%%placeholder%%` => resolved value.
	 * @param array $args         Yoast args; structure varies by version.
	 * @return array
	 */
	public function filter_replacements( $replacements, $args = array() ) {
		unset( $args );
		if ( ! is_array( $replacements ) || empty( $replacements ) ) {
			return $replacements;
		}

		$language = $this->resolve_language();

		foreach ( $replacements as $placeholder => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			// Cheap shape check: serialised PHP array starts with `a:`.
			if ( 0 !== strpos( $value, 'a:' ) ) {
				continue;
			}
			$decoded = $this->maybe_unserialize_multilingual( $value );
			if ( null === $decoded ) {
				continue;
			}
			$replacements[ $placeholder ] = $this->pick_value( $decoded, $language );
		}

		return $replacements;
	}

	/**
	 * Decide which language slug to use for the resolver. Reads from the
	 * KDNA query helper so it stays in lockstep with the cookie / URL
	 * detection used everywhere else.
	 *
	 * @return string
	 */
	private function resolve_language() {
		if ( class_exists( 'KDNA_RC_Multilingual_Query_Helper' ) ) {
			return KDNA_RC_Multilingual_Query_Helper::resolve_language();
		}
		if ( ! empty( $_COOKIE['kdna_language'] ) ) {
			return sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) );
		}
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		return is_array( $settings ) && ! empty( $settings['default_language'] ) ? sanitize_key( (string) $settings['default_language'] ) : '';
	}

	/**
	 * Decode a serialised value and confirm it looks like a multilingual array.
	 *
	 * Returns the array on success, null when the input was something else
	 * (e.g. an unrelated serialised array Yoast surfaced).
	 *
	 * @param string $serialised Serialised PHP value.
	 * @return array|null
	 */
	private function maybe_unserialize_multilingual( $serialised ) {
		// Allow only scalar arrays; reject objects to avoid accidental
		// instantiation.
		$decoded = @unserialize( $serialised, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.Security.PHP.DiscouragedFunctions
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		if ( ! array_key_exists( 'default', $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Pick the value for the visitor's language with default fallback.
	 *
	 * Image fields store an attachment ID; we resolve that to a URL so
	 * Yoast templates referencing image custom-fields render usefully.
	 *
	 * @param array  $values   Multilingual map (default + slugs).
	 * @param string $language Visitor language slug.
	 * @return string
	 */
	private function pick_value( array $values, $language ) {
		$candidate = '';
		if ( '' !== $language && isset( $values[ $language ] ) ) {
			$candidate = $values[ $language ];
		}
		if ( '' === trim( (string) $candidate ) ) {
			$candidate = isset( $values['default'] ) ? $values['default'] : '';
		}

		if ( is_numeric( $candidate ) && (int) $candidate > 0 ) {
			$url = wp_get_attachment_image_url( (int) $candidate, 'full' );
			if ( $url ) { return (string) $url; }
		}
		return is_scalar( $candidate ) ? (string) $candidate : '';
	}
}
