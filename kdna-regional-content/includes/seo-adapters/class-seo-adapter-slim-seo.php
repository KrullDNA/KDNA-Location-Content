<?php
/**
 * Slim SEO adapter.
 *
 * Targets Slim SEO 4.x. Slim SEO stores its per-post values in a
 * single serialised array under the `slim_seo` post meta key:
 *   array(
 *       'title'       => '...',
 *       'description' => '...',
 *       'noindex'     => false,
 *       ...
 *   );
 *
 * Storage caveat: the override pattern used by this plugin needs flat
 * meta keys, so we use our own `_slim_seo_*` prefix for KDNA overrides
 * (separate from Slim SEO's serialised array). The filter resolvers
 * substitute at render time; the Default-tab "currently shown" preview
 * tries to read from Slim SEO's serialised array via the helper below.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_Slim_SEO
 */
class KDNA_RC_SEO_Adapter_Slim_SEO extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'slim-seo'; }
	public function label() { return 'Slim SEO'; }

	public function is_active() {
		return defined( 'SLIM_SEO_VER' ) || function_exists( 'slim_seo' );
	}

	public function meta_keys() {
		return array(
			'title'         => '_slim_seo_title',
			'description'   => '_slim_seo_description',
			'canonical'     => '_slim_seo_canonical',
			'focus'         => '_slim_seo_focus_keyphrase',
			'og_title'      => '_slim_seo_og_title',
			'og_description' => '_slim_seo_og_description',
			'og_image'      => '_slim_seo_og_image_id',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'slim_seo_meta_title',
			'description'   => 'slim_seo_meta_description',
			'og_title'      => 'slim_seo_open_graph_title',
			'og_description' => 'slim_seo_open_graph_description',
			'og_image'      => 'slim_seo_open_graph_image',
			// Slim SEO does not expose a public canonical filter; the
			// override is stored and surfaced via wp_head canonical
			// only when the URL routing layer constructs it.
		);
	}

	public function og_image_storage() {
		return 'id';
	}
}
