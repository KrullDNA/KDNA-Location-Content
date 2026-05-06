<?php
/**
 * JetEngine Query Builder integration: rewrite multilingual meta_query
 * clauses on the WP_Query args before the query runs.
 *
 * Targets JetEngine 3.x.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_JetEngine_Query_Adapter
 *
 * JetEngine's Query Builder lets editors build named queries that other
 * widgets reference. When a query targets a Multilingual field, the
 * meta_query JetEngine emits compares against the raw serialised array,
 * which never matches. We hook the get-items-args filter and rewrite
 * every multilingual clause via the helper.
 */
class KDNA_RC_JetEngine_Query_Adapter {

	/**
	 * Whether JetEngine itself is loaded. The query-builder filter only
	 * fires when JetEngine runs, so we hook unconditionally and rely on
	 * the filter not firing on JetEngine-less sites.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'Jet_Engine' );
	}

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Posts query type. Different JetEngine builds emit slightly different
		// names; register the canonical pair to be resilient.
		add_filter( 'jet-engine/query-builder/types/posts/get-items-args', array( $this, 'rewrite_args' ), 10, 2 );
		add_filter( 'jet-engine/query-builder/query/posts/args', array( $this, 'rewrite_args' ), 10, 2 );

		// Generic listing query. JetEngine listing grids that bypass the
		// Query Builder still go through this filter.
		add_filter( 'jet-engine/listing/grid/posts-query-args', array( $this, 'rewrite_args' ), 10, 1 );
	}

	/**
	 * Rewrite WP_Query args.
	 *
	 * @param array $args  Query args.
	 * @param mixed $query Query context (object varies).
	 * @return array
	 */
	public function rewrite_args( $args, $query = null ) {
		unset( $query );
		if ( ! is_array( $args ) || empty( $args['meta_query'] ) ) {
			return $args;
		}
		$args['meta_query'] = KDNA_RC_Multilingual_Query_Helper::rewrite_meta_query( $args['meta_query'] );
		return $args;
	}
}
