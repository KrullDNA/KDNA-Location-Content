<?php
/**
 * JetSearch integration: extend the AJAX search WHERE clause so it
 * matches inside Multilingual fields too.
 *
 * Targets JetSearch 3.x.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_JetSearch_Adapter
 *
 * Extends the search payload that JetSearch builds before running its
 * AJAX query so the search term also matches inside the visitor's
 * resolved language tab on every Multilingual field configured for
 * search. When the "Search across all language variants" admin toggle
 * is on, every language tab is searched alongside default.
 */
class KDNA_RC_JetSearch_Adapter {

	/**
	 * Whether JetSearch is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'Jet_Search' );
	}

	/**
	 * Wire hooks. No-op when JetSearch is missing.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::is_active() ) {
			return;
		}

		// Final ajax data. Names vary across JetSearch versions; register
		// the canonical pair to cover both currently shipping ones.
		add_filter( 'jet-search/ajax-search/data', array( $this, 'extend_query_args' ), 10, 2 );
		add_filter( 'jet-search/ajax/data', array( $this, 'extend_query_args' ), 10, 2 );

		// Final WP_Query args. Some JetSearch installs run a separate
		// filter on the query args before WP_Query is constructed; hook
		// that too for resilience.
		add_filter( 'jet-search/ajax/query-args', array( $this, 'rewrite_query_args' ), 10, 1 );
	}

	/**
	 * Whether the admin has enabled cross-language search.
	 *
	 * @return bool
	 */
	public static function search_across_all_languages() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		return is_array( $settings ) && ! empty( $settings['search_across_all_languages'] );
	}

	/**
	 * Augment JetSearch's data payload by injecting an additional
	 * meta_query that matches the search term inside every multilingual
	 * field. JetSearch already searches post title / content / excerpt;
	 * we only add to that.
	 *
	 * Because JetSearch's data shape varies by version we leave the
	 * existing keys alone and only add a side meta_query when the
	 * payload is in a recognisable shape.
	 *
	 * @param array $data         Data payload built by JetSearch.
	 * @param mixed $instance_args Instance-level args (signature varies).
	 * @return array
	 */
	public function extend_query_args( $data, $instance_args = null ) {
		unset( $instance_args );
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$term = $this->extract_search_term( $data );
		if ( '' === $term ) {
			return $data;
		}

		$languages = $this->languages_to_search();
		$ml_fields = KDNA_RC_Multilingual_Query_Helper::multilingual_field_names();
		if ( empty( $ml_fields ) ) {
			return $data;
		}

		$clauses = array( 'relation' => 'OR' );
		foreach ( $ml_fields as $field ) {
			foreach ( $languages as $lang ) {
				$clauses[] = KDNA_RC_Multilingual_Query_Helper::build_multilingual_meta_clause( $field, $term, 'LIKE', $lang );
			}
		}

		// Merge into existing meta_query (if any) under an OR.
		if ( ! empty( $data['meta_query'] ) && is_array( $data['meta_query'] ) ) {
			$existing       = $data['meta_query'];
			$relation       = isset( $existing['relation'] ) ? $existing['relation'] : 'AND';
			unset( $existing['relation'] );
			// Wrap each sub-array under their own relation, then OR with our group.
			$data['meta_query'] = array(
				'relation' => 'OR',
				array_merge( array( 'relation' => $relation ), $existing ),
				$clauses,
			);
		} else {
			$data['meta_query'] = $clauses;
		}

		return $data;
	}

	/**
	 * Rewrite an explicit query args array passed by JetSearch.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function rewrite_query_args( $args ) {
		if ( ! is_array( $args ) || empty( $args['meta_query'] ) ) {
			return $args;
		}
		$args['meta_query'] = KDNA_RC_Multilingual_Query_Helper::rewrite_meta_query( $args['meta_query'] );
		return $args;
	}

	/**
	 * Languages to include in the search clause.
	 *
	 * Visitor language plus default by default; every configured
	 * language when the cross-language toggle is on.
	 *
	 * @return array<int,string>
	 */
	private function languages_to_search() {
		if ( self::search_across_all_languages() ) {
			$out = array( 'default' );
			foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
				$out[] = $language['slug'];
			}
			return array_values( array_unique( $out ) );
		}
		$visitor = KDNA_RC_Multilingual_Query_Helper::resolve_language();
		return array_values( array_unique( array_filter( array( $visitor, 'default' ) ) ) );
	}

	/**
	 * Pull the search term from JetSearch's data payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_search_term( array $data ) {
		foreach ( array( 's', 'search_string', 'value', 'query' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}
		// Fallback: read the URL query param.
		if ( isset( $_REQUEST['value'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return trim( sanitize_text_field( wp_unslash( $_REQUEST['value'] ) ) );
		}
		if ( isset( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
		}
		return '';
	}
}
