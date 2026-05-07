<?php
/**
 * SEOPress adapter.
 *
 * Targets SEOPress 7.x. SEOPress stores per-post values in post meta
 * with the `_seopress_*` prefix and exposes a wide set of filters at
 * render time.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_SEOPress
 */
class KDNA_RC_SEO_Adapter_SEOPress extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'seopress'; }
	public function label() { return 'SEOPress'; }

	public function is_active() {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'canonical'     => '_seopress_robots_canonical',
			'focus'         => '_seopress_analysis_target_kw',
			'og_title'      => '_seopress_social_fb_title',
			'og_description' => '_seopress_social_fb_desc',
			'og_image'      => '_seopress_social_fb_img',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'seopress_titles_single_titles',
			'description'   => 'seopress_titles_single_metadesc',
			'canonical'     => 'seopress_canonical',
			'og_title'      => 'seopress_social_og_title',
			'og_description' => 'seopress_social_og_desc',
			'og_image'      => 'seopress_social_og_img',
		);
	}

	public function og_image_storage() {
		// SEOPress stores the OG image URL as a string.
		return 'url';
	}
}
