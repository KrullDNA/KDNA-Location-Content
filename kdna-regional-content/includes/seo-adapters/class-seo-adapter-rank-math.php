<?php
/**
 * Rank Math SEO adapter.
 *
 * Targets Rank Math 1.0.x. Filter names and meta keys verified against
 * the public Rank Math hook reference; the namespaces in the filter
 * paths use slashes (`rank_math/frontend/title`) which differs from
 * Yoast's underscore convention.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_Rank_Math
 */
class KDNA_RC_SEO_Adapter_Rank_Math extends KDNA_RC_SEO_Adapter {

	public function slug()  { return 'rank-math'; }
	public function label() { return 'Rank Math'; }

	public function is_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	public function meta_keys() {
		return array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'canonical'     => 'rank_math_canonical_url',
			'focus'         => 'rank_math_focus_keyword',
			'og_title'      => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'      => 'rank_math_facebook_image_id',
		);
	}

	public function filter_names() {
		return array(
			'title'         => 'rank_math/frontend/title',
			'description'   => 'rank_math/frontend/description',
			'canonical'     => 'rank_math/frontend/canonical',
			'og_title'      => 'rank_math/opengraph/facebook/og_title',
			'og_description' => 'rank_math/opengraph/facebook/og_description',
			'og_image'      => 'rank_math/opengraph/facebook/og_image',
			// Rank Math does not expose a focus-keyword filter for
			// front-end output (it is only used in their analyser), so
			// the override is stored but not surfaced. The meta box
			// still saves it so editors with workflows that read the
			// raw meta key directly continue to work.
		);
	}

	public function og_image_storage() {
		return 'id';
	}
}
