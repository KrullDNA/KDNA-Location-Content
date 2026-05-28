<?php
/**
 * Yoast SEO adapter.
 *
 * Targets Yoast SEO 21.x.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_Yoast
 *
 * Mirrors what KDNA_RC_Yoast_Integration used to do, expressed as a
 * registry-discoverable adapter so the rest of the plugin can drive
 * the same UX through a uniform contract regardless of which SEO
 * plugin is active.
 */
class KDNA_RC_SEO_Adapter_Yoast extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'yoast'; }
	public function label() { return 'Yoast SEO'; }

	public function is_active() {
		return defined( 'WPSEO_VERSION' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'canonical'     => '_yoast_wpseo_canonical',
			'focus'         => '_yoast_wpseo_focuskw',
			'og_title'      => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'og_image'      => '_yoast_wpseo_opengraph-image-id',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'wpseo_title',
			'description'   => 'wpseo_metadesc',
			'canonical'     => 'wpseo_canonical',
			'focus'         => 'wpseo_focuskw',
			'og_title'      => 'wpseo_opengraph_title',
			'og_description' => 'wpseo_opengraph_desc',
			'og_image'      => 'wpseo_opengraph_image',
		);
	}

	public function og_image_storage() {
		return 'id';
	}
}
