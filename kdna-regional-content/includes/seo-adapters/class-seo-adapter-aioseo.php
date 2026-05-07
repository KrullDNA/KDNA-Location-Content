<?php
/**
 * All in One SEO (AIOSEO) adapter.
 *
 * Targets AIOSEO 4.x.
 *
 * Storage caveat: AIOSEO stores per-post SEO data in the `aioseoposts`
 * custom database table rather than post meta. The override pattern in
 * this plugin uses post meta with a suffix per region / language
 * (`{base_key}_{slug}`), which still works — the resolver hooks AIOSEO's
 * filters and substitutes the override at render time. The Default-tab
 * "currently shown" preview on the SEO meta box reads our own meta keys
 * (`_aioseo_title`, etc.) which AIOSEO does not write to, so the preview
 * shows blank when no override exists. To see AIOSEO's defaults, edit
 * the post under AIOSEO's own meta box — the integration substitutes
 * the override on the front end regardless.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_AIOSEO
 */
class KDNA_RC_SEO_Adapter_AIOSEO extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'aioseo'; }
	public function label() { return 'All in One SEO'; }

	public function is_active() {
		return defined( 'AIOSEO_VERSION' )
			|| defined( 'AIOSEO_DIR_PATH' )
			|| class_exists( 'AIOSEO\\Plugin' )
			|| class_exists( 'AIOSEO\\Plugin\\AIOSEO' );
	}

	public function meta_keys() {
		// Our own override key prefix (AIOSEO uses custom DB tables, so
		// these keys are KDNA-scoped placeholders rather than mirrors
		// of AIOSEO's storage).
		return array(
			'title'         => '_aioseo_title',
			'description'   => '_aioseo_description',
			'canonical'     => '_aioseo_canonical',
			'focus'         => '_aioseo_focus_keyphrase',
			'og_title'      => '_aioseo_og_title',
			'og_description' => '_aioseo_og_description',
			'og_image'      => '_aioseo_og_image_url',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'aioseo_title',
			'description'   => 'aioseo_description',
			'canonical'     => 'aioseo_canonical_url',
			'og_title'      => 'aioseo_facebook_title',
			'og_description' => 'aioseo_facebook_description',
			'og_image'      => 'aioseo_facebook_image',
		);
	}

	public function og_image_storage() {
		// AIOSEO emits image URLs directly. Editors paste a URL or pick
		// from the media library (we then store the URL).
		return 'url';
	}
}
