<?php
/**
 * JetSmartFilters integration: rewrite meta_query clauses targeting KDNA
 * Multilingual fields, and translate filter option labels (checkbox lists)
 * to the visitor's language.
 *
 * Targets JetSmartFilters 3.x. The filter / action names below are the
 * canonical ones used in current versions; a couple of older variants are
 * registered alongside as a safety net.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_JetSmartFilters_Adapter
 *
 * Two responsibilities:
 *   1. Final-query rewrite: when JetSmartFilters constructs a WP_Query
 *      args array from active filters, walk the meta_query and replace
 *      every multilingual clause via the helper.
 *   2. Option labels: when JetSmartFilters renders a checkbox or radio
 *      filter sourced from a multilingual field, swap option labels for
 *      the visitor's language.
 */
class KDNA_RC_JetSmartFilters_Adapter {

	/**
	 * Whether JetSmartFilters is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'Jet_Smart_Filters' );
	}

	/**
	 * Wire hooks. Bails silently when JetSmartFilters is not present.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::is_active() ) {
			return;
		}

		// Final-query filter. Different versions emit slightly different
		// names; register both so the rewrite lands regardless.
		add_filter( 'jet-smart-filters/query/final-query', array( $this, 'filter_final_query' ), 10, 2 );
		add_filter( 'jet-smart-filters/query/final-args', array( $this, 'filter_final_query' ), 10, 2 );

		// Option label translation for filter widgets.
		add_filter( 'jet-smart-filters/filters/render_filter_options', array( $this, 'translate_option_labels' ), 10, 3 );
		add_filter( 'jet-smart-filters/filters/filter-options', array( $this, 'translate_option_labels' ), 10, 3 );
	}

	/**
	 * Walk the WP_Query args JetSmartFilters built and rewrite multilingual
	 * meta_query clauses.
	 *
	 * @param array $args            Final WP_Query args.
	 * @param mixed $jet_query_args  Whatever JetSmartFilters passes as the second arg.
	 * @return array
	 */
	public function filter_final_query( $args, $jet_query_args = null ) {
		unset( $jet_query_args );
		if ( ! is_array( $args ) || empty( $args['meta_query'] ) ) {
			return $args;
		}
		$args['meta_query'] = KDNA_RC_Multilingual_Query_Helper::rewrite_meta_query( $args['meta_query'] );
		return $args;
	}

	/**
	 * Translate option labels for filter widgets sourced from multilingual fields.
	 *
	 * Each option in the array is shaped roughly like:
	 *   array( 'value' => '...', 'label' => '...' )
	 *
	 * When the source meta key is a multilingual field, the labels JetSmartFilters
	 * extracts come from the raw serialised value of the field. We replace the
	 * label with the visitor-language tab's value (or default tab when missing).
	 *
	 * @param array $options Filter options.
	 * @param mixed $filter  Filter context (object or args).
	 * @param mixed $args    Render args.
	 * @return array
	 */
	public function translate_option_labels( $options, $filter = null, $args = null ) {
		unset( $args );
		if ( ! is_array( $options ) || empty( $options ) ) {
			return $options;
		}

		// Attempt to discover the source meta key from the filter context.
		$meta_key = $this->extract_meta_key( $filter );
		if ( '' === $meta_key || ! KDNA_RC_Multilingual_Query_Helper::is_multilingual_field( $meta_key ) ) {
			return $options;
		}

		$language = KDNA_RC_Multilingual_Query_Helper::resolve_language();
		if ( '' === $language ) {
			return $options;
		}

		// Each option's "value" is what gets matched in the WHERE clause; we
		// keep that as-is. Only the "label" surfaces to the visitor and that
		// is what we replace. Labels in JetSmartFilters can already carry
		// the raw serialised array string; detect that case and unpack.
		foreach ( $options as $idx => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$label = isset( $option['label'] ) ? (string) $option['label'] : '';
			if ( '' === $label ) {
				continue;
			}

			$translated = $this->translate_label( $label, $language );
			if ( $translated !== $label ) {
				$options[ $idx ]['label'] = $translated;
			}
		}

		return $options;
	}

	/**
	 * Best-effort extraction of the source meta key from a filter context.
	 *
	 * JetSmartFilters passes either an array of args or an object depending
	 * on the filter type and version, so try several shapes.
	 *
	 * @param mixed $filter Filter context.
	 * @return string Meta key, or empty string when none could be determined.
	 */
	private function extract_meta_key( $filter ) {
		if ( is_array( $filter ) ) {
			if ( ! empty( $filter['filter_meta_key'] ) ) {
				return (string) $filter['filter_meta_key'];
			}
			if ( ! empty( $filter['query_var'] ) ) {
				return (string) $filter['query_var'];
			}
		}
		if ( is_object( $filter ) ) {
			foreach ( array( 'filter_meta_key', 'meta_key', 'query_var' ) as $prop ) {
				if ( isset( $filter->{$prop} ) && '' !== (string) $filter->{$prop} ) {
					return (string) $filter->{$prop};
				}
			}
		}
		return '';
	}

	/**
	 * Translate a single label.
	 *
	 * If the label looks like a serialised PHP array (which can happen
	 * when JetSmartFilters sources options directly from the meta value),
	 * unserialise + resolve the visitor language. Otherwise leave it as-is.
	 *
	 * @param string $label    Raw label.
	 * @param string $language Visitor language slug.
	 * @return string
	 */
	private function translate_label( $label, $language ) {
		if ( 0 === strpos( $label, 'a:' ) ) {
			$maybe = @unserialize( $label, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.Security.PHP.DiscouragedFunctions
			if ( is_array( $maybe ) ) {
				if ( '' !== $language && isset( $maybe[ $language ] ) && '' !== trim( (string) $maybe[ $language ] ) ) {
					return (string) $maybe[ $language ];
				}
				if ( isset( $maybe['default'] ) ) {
					return (string) $maybe['default'];
				}
			}
		}
		return $label;
	}
}
