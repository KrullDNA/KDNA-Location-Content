<?php
/**
 * The SEO Framework adapter.
 *
 * Targets The SEO Framework 5.x. TSF inherits its post-meta key shape
 * from its Genesis-era roots, hence the `_genesis_*` keys.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_The_SEO_Framework
 */
class KDNA_RC_SEO_Adapter_The_SEO_Framework extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'the-seo-framework'; }
	public function label() { return 'The SEO Framework'; }

	public function is_active() {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_genesis_title',
			'description'   => '_genesis_description',
			'canonical'     => '_genesis_canonical_uri',
			// TSF derives focus keywords automatically; we still store
			// our override for downstream code that may want it.
			'focus'         => '_kdna_rc_tsf_focus_keyphrase',
			'og_title'      => '_open_graph_title',
			'og_description' => '_open_graph_description',
			'og_image'      => '_social_image_id',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'the_seo_framework_title_from_generation',
			'description'   => 'the_seo_framework_description_from_generation',
			'canonical'     => 'the_seo_framework_meta_canonical_url',
			'og_title'      => 'the_seo_framework_ogtitle_output',
			'og_description' => 'the_seo_framework_ogdescription_output',
			'og_image'      => 'the_seo_framework_og_image_after_featured',
		);
	}

	public function og_image_storage() {
		return 'id';
	}
}
