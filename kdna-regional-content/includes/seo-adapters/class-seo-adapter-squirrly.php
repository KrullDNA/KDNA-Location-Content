<?php
/**
 * Squirrly SEO adapter.
 *
 * Targets Squirrly 12.x. Squirrly's filter surface is limited; this
 * adapter covers the four primary fields (title, description, OG
 * title, OG description) and provides KDNA-scoped meta key overrides
 * for the fields Squirrly does not expose.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_Squirrly
 */
class KDNA_RC_SEO_Adapter_Squirrly extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'squirrly'; }
	public function label() { return 'Squirrly SEO'; }

	public function is_active() {
		return defined( 'SQ_VERSION' )
			|| class_exists( 'SQ_Classes_Helpers_Sanitize' )
			|| class_exists( 'SQ_Classes_Helpers_Tools' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_sq_title',
			'description'   => '_sq_description',
			'canonical'     => '_sq_canonical',
			'focus'         => '_sq_keyword',
			'og_title'      => '_sq_og_title',
			'og_description' => '_sq_og_description',
			'og_image'      => '_sq_og_media',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'sq_title',
			'description'   => 'sq_description',
			'og_title'      => 'sq_og_title',
			'og_description' => 'sq_og_description',
			// Squirrly does not consistently expose canonical / OG image
			// filters across versions; the meta box still saves overrides
			// for sites that have customised Squirrly to read them.
		);
	}

	public function og_image_storage() {
		return 'url';
	}
}
