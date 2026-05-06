<?php
/**
 * Public developer-facing helper namespace.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC
 *
 * Stable surface for downstream code that wants to opt in to multilingual
 * query rewriting. The class is intentionally small: each method is a
 * thin shim over the internal helper so we are free to refactor the
 * internals without breaking sites that call into us.
 *
 * Documented in the README under "Developer guide".
 */
final class KDNA_RC {

	/**
	 * Rewrite a WP_Query args array so multilingual fields match the
	 * visitor's resolved language with default-tab fallback.
	 *
	 * Walks the meta_query (if present) and replaces every clause that
	 * targets a Stage 12 multilingual field with the language-aware
	 * clause built by KDNA_RC_Multilingual_Query_Helper. Non-multilingual
	 * clauses pass through untouched.
	 *
	 * Use case:
	 *   $args = KDNA_RC::translate_query_args( array(
	 *       'post_type'  => 'product',
	 *       'meta_query' => array(
	 *           array( 'key' => 'product_category', 'value' => 'coffee' ),
	 *       ),
	 *   ) );
	 *   $query = new WP_Query( $args );
	 *
	 * @param array       $query_args Standard WP_Query args.
	 * @param string|null $language   Language slug, or null to auto-resolve.
	 * @return array Rewritten args.
	 */
	public static function translate_query_args( $query_args, $language = null ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}
		if ( ! empty( $query_args['meta_query'] ) ) {
			$query_args['meta_query'] = KDNA_RC_Multilingual_Query_Helper::rewrite_meta_query(
				$query_args['meta_query'],
				$language
			);
		}
		return $query_args;
	}

	/**
	 * Resolve a Stage 12 multilingual field's stored array to a single
	 * value for the given language with default-tab fallback.
	 *
	 * Convenience over KDNA_RC_Multilingual_Base::resolve_value() so
	 * downstream code does not need to know which class file the helper
	 * lives in.
	 *
	 * @param int         $post_id  Post ID.
	 * @param string      $meta_key Field meta key.
	 * @param string|null $language Language slug, or null to auto-resolve.
	 * @return mixed
	 */
	public static function resolve_field( $post_id, $meta_key, $language = null ) {
		$language = null !== $language ? sanitize_key( (string) $language ) : KDNA_RC_Multilingual_Query_Helper::resolve_language();
		if ( ! class_exists( 'KDNA_RC_Multilingual_Base' ) ) {
			return get_post_meta( (int) $post_id, (string) $meta_key, true );
		}
		return KDNA_RC_Multilingual_Base::resolve_value( $post_id, $meta_key, $language );
	}

	/**
	 * Whether the supplied meta key is registered as a Multilingual field.
	 *
	 * @param string      $field_name Meta key.
	 * @param string|null $cpt        Optional CPT scope.
	 * @return bool
	 */
	public static function is_multilingual_field( $field_name, $cpt = null ) {
		return KDNA_RC_Multilingual_Query_Helper::is_multilingual_field( $field_name, $cpt );
	}
}
