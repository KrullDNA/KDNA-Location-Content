<?php
/**
 * SmartCrawl (WPMU DEV) adapter.
 *
 * Targets SmartCrawl 3.x. Storage uses the `_wds_*` post meta key
 * convention; OG values are stored under `_wds_opengraph` as a
 * serialised array which we sidestep via our own override keys.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_SmartCrawl
 */
class KDNA_RC_SEO_Adapter_SmartCrawl extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'smartcrawl'; }
	public function label() { return 'SmartCrawl SEO'; }

	public function is_active() {
		return defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'Smartcrawl_Settings' )
			|| class_exists( '\\SmartCrawl\\Plugin' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_wds_title',
			'description'   => '_wds_metadesc',
			'canonical'     => '_wds_canonical',
			'focus'         => '_wds_focus-keywords',
			'og_title'      => '_kdna_rc_wds_og_title',
			'og_description' => '_kdna_rc_wds_og_description',
			'og_image'      => '_kdna_rc_wds_og_image_id',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'wds-title-tag',
			'description'   => 'wds-metadesc',
			'og_title'      => 'wds-opengraph-title',
			'og_description' => 'wds-opengraph-description',
			'og_image'      => 'wds-opengraph-image',
			// Canonical: SmartCrawl handles canonicals via its own logic
			// without a public filter; the URL routing layer in this
			// plugin emits canonicals for prefixed URLs regardless.
		);
	}

	public function og_image_storage() {
		return 'id';
	}
}
