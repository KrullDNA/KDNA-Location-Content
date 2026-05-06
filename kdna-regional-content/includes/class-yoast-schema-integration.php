<?php
/**
 * Yoast Schema.org overrides per region / language.
 *
 * Targets Yoast SEO 21.x. Yoast emits structured data via the
 * `wpseo_schema_*` filter family; the exact filter names have varied
 * slightly across major Yoast releases. This class hooks the names
 * used in the current major and falls back gracefully when a hook
 * does not fire (the schema graph still emits, just without
 * regional overrides).
 *
 * Initial coverage: LocalBusiness and Organization. Product schema
 * deferred to a future stage per the brief.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Yoast_Schema_Integration
 */
class KDNA_RC_Yoast_Schema_Integration {

	/**
	 * Wire filters when Yoast is loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		// Two filter shapes for the same graph piece across Yoast versions.
		add_filter( 'wpseo_schema_organization', array( $this, 'filter_organization' ), 100, 2 );
		add_filter( 'wpseo_schema_local_business', array( $this, 'filter_local_business' ), 100, 2 );
	}

	/**
	 * Override LocalBusiness schema properties with regional values.
	 *
	 * @param array $data Yoast's local-business schema data.
	 * @param mixed $context Yoast Meta_Tags_Context (signature varies).
	 * @return array
	 */
	public function filter_local_business( $data, $context = null ) {
		unset( $context );
		if ( ! is_array( $data ) ) { return $data; }

		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) { return $data; }

		$slug = $this->resolve_active_region_slug();
		if ( '' === $slug ) { return $data; }

		// Address override: stored as plain text; we surface it as
		// streetAddress for sites with a single-line value, and let
		// Yoast's existing structure cover the rest.
		$address = KDNA_RC_SEO_Meta_Box::read_override( $post_id, '_yoast_wpseo_localbusiness_address', $slug );
		if ( '' !== $address ) {
			if ( ! isset( $data['address'] ) || ! is_array( $data['address'] ) ) {
				$data['address'] = array( '@type' => 'PostalAddress' );
			}
			$data['address']['streetAddress'] = $address;
		}

		$phone = KDNA_RC_SEO_Meta_Box::read_override( $post_id, '_yoast_wpseo_localbusiness_phone', $slug );
		if ( '' !== $phone ) {
			$data['telephone'] = $phone;
		}

		return $data;
	}

	/**
	 * Override Organization schema properties with regional values.
	 *
	 * Mirrors filter_local_business() so site-wide Organization
	 * schema also picks up regional contact details.
	 *
	 * @param array $data    Organization schema data.
	 * @param mixed $context Yoast context (unused).
	 * @return array
	 */
	public function filter_organization( $data, $context = null ) {
		return $this->filter_local_business( $data, $context );
	}

	/**
	 * Resolve the active region slug for this request. Region wins for
	 * schema because schema concerns physical-address style data.
	 *
	 * @return string
	 */
	private function resolve_active_region_slug() {
		// Reuse Yoast integration's resolver to stay consistent with the
		// rest of the SEO chain.
		if ( class_exists( 'KDNA_RC_Yoast_Integration' ) ) {
			return ( new KDNA_RC_Yoast_Integration() )->resolve_active_slug( 'region_first' );
		}
		return '';
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
