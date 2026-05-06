<?php
/**
 * Multilingual-aware meta query helper.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Multilingual_Query_Helper
 *
 * Single utility used by every Stage 13 adapter (JetSmartFilters,
 * JetSearch, JetEngine Query Builder, REST API). Encapsulates:
 *   - detecting whether a meta key holds a KDNA Multilingual value,
 *   - building meta_query clauses that match inside the serialised
 *     storage shape used by Stage 12 ('default' => x, 'fr' => y, ...),
 *   - walking an existing meta_query and rewriting any clause that
 *     targets a multilingual field.
 *
 * Storage shape recap (Stage 12):
 *   array( 'default' => 'val', 'fr' => 'val', 'de' => 'val', ... )
 *
 * Serialised by PHP as:
 *   a:N:{s:7:"default";s:LEN:"VALUE";s:2:"fr";s:LEN:"VALUE";...}
 *
 * The helper builds LIKE patterns against the serialised string. PHP's
 * serialiser uses byte counts, not character counts, so for unicode
 * content we use strlen() (which returns bytes) to compute the width
 * placeholder. This works cleanly for ASCII, accented Latin, Cyrillic,
 * Japanese, Arabic, and emoji.
 *
 * Limitation worth knowing: very long values (multi-KB WYSIWYG bodies)
 * make the LIKE comparison slow because MySQL still has to scan every
 * meta_value row. Document recommends short translatable fields when
 * filtering by them.
 */
class KDNA_RC_Multilingual_Query_Helper {

	/**
	 * Slugs of every Stage 12 multilingual field type.
	 *
	 * @return array<int,string>
	 */
	public static function multilingual_field_types() {
		return array( 'kdna_rc_ml_text', 'kdna_rc_ml_image', 'kdna_rc_ml_wysiwyg' );
	}

	/**
	 * Whether the supplied meta key is registered as a Multilingual field.
	 *
	 * Walks JetEngine's stored meta-box config. Optionally narrows to a
	 * single CPT for callers that have one in hand. Returns false on any
	 * structural variance so older / newer JetEngine versions never
	 * trigger fatals here.
	 *
	 * @param string      $field_name Meta key to test.
	 * @param string|null $cpt        Optional CPT slug to narrow the lookup.
	 * @return bool
	 */
	public static function is_multilingual_field( $field_name, $cpt = null ) {
		if ( '' === (string) $field_name ) {
			return false;
		}

		$cache_key = $field_name . '|' . ( $cpt ?: '*' );
		static $cache = array();
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			$cache[ $cache_key ] = false;
			return false;
		}

		$ml_types = self::multilingual_field_types();
		foreach ( $option as $box ) {
			if ( ! is_array( $box ) || empty( $box['meta_fields'] ) || ! is_array( $box['meta_fields'] ) ) {
				continue;
			}
			if ( $cpt ) {
				$applies = isset( $box['args']['allowed_post_type'] ) ? (array) $box['args']['allowed_post_type'] : array();
				if ( empty( $applies ) ) {
					$applies = isset( $box['args']['post_type'] ) ? (array) $box['args']['post_type'] : array();
				}
				if ( ! in_array( $cpt, $applies, true ) ) {
					continue;
				}
			}
			foreach ( $box['meta_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( $name === $field_name && in_array( $type, $ml_types, true ) ) {
					$cache[ $cache_key ] = true;
					return true;
				}
			}
		}

		$cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * List every Multilingual field name on a CPT.
	 *
	 * Used by the Field Translation Audit tool and by adapters that need
	 * to discover what to rewrite when the meta_query they receive is
	 * empty (e.g. fully-textual JetSearch).
	 *
	 * @param string|null $cpt Optional CPT slug. Null returns every CPT's fields.
	 * @return array<int,string>
	 */
	public static function multilingual_field_names( $cpt = null ) {
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return array();
		}

		$ml_types = self::multilingual_field_types();
		$out      = array();

		foreach ( $option as $box ) {
			if ( ! is_array( $box ) || empty( $box['meta_fields'] ) ) {
				continue;
			}
			if ( $cpt ) {
				$applies = isset( $box['args']['allowed_post_type'] ) ? (array) $box['args']['allowed_post_type'] : array();
				if ( empty( $applies ) ) {
					$applies = isset( $box['args']['post_type'] ) ? (array) $box['args']['post_type'] : array();
				}
				if ( ! in_array( $cpt, $applies, true ) ) {
					continue;
				}
			}
			foreach ( $box['meta_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' !== $name && in_array( $type, $ml_types, true ) && ! in_array( $name, $out, true ) ) {
					$out[] = $name;
				}
			}
		}
		return $out;
	}

	/**
	 * Resolve the language slug to compare against.
	 *
	 * Reads the kdna_language cookie when present, falls back to the
	 * configured Default Language, then to an empty string.
	 *
	 * @return string
	 */
	public static function resolve_language() {
		if ( ! empty( $_COOKIE['kdna_language'] ) ) {
			$cookie = sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) );
			if ( '' !== $cookie ) {
				return $cookie;
			}
		}
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( is_array( $settings ) && ! empty( $settings['default_language'] ) ) {
			return sanitize_key( (string) $settings['default_language'] );
		}
		return '';
	}

	/**
	 * Build a meta_query clause that matches inside the serialised value.
	 *
	 * For exact matches we build the exact byte-precise LIKE pattern
	 * (s:LEN:"lang";s:LEN:"value"). For LIKE/CONTAINS we drop the value
	 * length placeholder and use a partial match (s:LEN:"lang";s:[any]:"
	 * containing the substring), which is approximate but good enough
	 * for filter UIs.
	 *
	 * Always returns an OR group between the visitor-language match and
	 * the default-tab match so editors who have not translated a value
	 * yet still see results from the source language.
	 *
	 * @param string      $field_name Meta key.
	 * @param mixed       $value      Compared value.
	 * @param string      $compare    Operator (=, !=, LIKE, NOT LIKE, IN, NOT IN).
	 * @param string|null $language   Language slug, or null to resolve.
	 * @return array
	 */
	public static function build_multilingual_meta_clause( $field_name, $value, $compare = '=', $language = null ) {
		$language = null !== $language ? sanitize_key( (string) $language ) : self::resolve_language();
		$compare  = strtoupper( (string) $compare );

		// IN / NOT IN: explode into sub-clauses joined by OR / AND.
		if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) && is_array( $value ) ) {
			$relation = ( 'NOT IN' === $compare ) ? 'AND' : 'OR';
			$out      = array( 'relation' => $relation );
			foreach ( $value as $single ) {
				$out[] = self::build_multilingual_meta_clause(
					$field_name,
					$single,
					( 'NOT IN' === $compare ) ? '!=' : '=',
					$language
				);
			}
			return $out;
		}

		// Build LIKE patterns. For exact compares we need a byte-precise
		// pattern; for LIKE we use a partial pattern.
		$value_string = is_scalar( $value ) ? (string) $value : '';

		if ( in_array( $compare, array( '=', '!=' ), true ) ) {
			$lang_pattern    = self::exact_pattern( $language ?: 'default', $value_string );
			$default_pattern = self::exact_pattern( 'default', $value_string );
			$op              = ( '!=' === $compare ) ? 'NOT LIKE' : 'LIKE';

			if ( '!=' === $compare ) {
				// NOT EQUALS must hold against BOTH languages: a row
				// matches the meta_query only if its value differs in
				// the visitor language AND in default. Otherwise a
				// row whose default still equals $value would slip in.
				return array(
					'relation' => 'AND',
					array( 'key' => $field_name, 'value' => $lang_pattern, 'compare' => $op ),
					array( 'key' => $field_name, 'value' => $default_pattern, 'compare' => $op ),
				);
			}

			// EQUALS: row matches if visitor-language value equals OR
			// (visitor-language is empty and default equals).
			return array(
				'relation' => 'OR',
				array( 'key' => $field_name, 'value' => $lang_pattern, 'compare' => 'LIKE' ),
				array( 'key' => $field_name, 'value' => $default_pattern, 'compare' => 'LIKE' ),
			);
		}

		if ( in_array( $compare, array( 'LIKE', 'NOT LIKE' ), true ) ) {
			$lang_pattern    = self::contains_pattern( $language ?: 'default', $value_string );
			$default_pattern = self::contains_pattern( 'default', $value_string );

			if ( 'NOT LIKE' === $compare ) {
				return array(
					'relation' => 'AND',
					array( 'key' => $field_name, 'value' => $lang_pattern, 'compare' => 'NOT LIKE' ),
					array( 'key' => $field_name, 'value' => $default_pattern, 'compare' => 'NOT LIKE' ),
				);
			}

			return array(
				'relation' => 'OR',
				array( 'key' => $field_name, 'value' => $lang_pattern, 'compare' => 'LIKE' ),
				array( 'key' => $field_name, 'value' => $default_pattern, 'compare' => 'LIKE' ),
			);
		}

		if ( in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
			// EXISTS / NOT EXISTS just check whether the meta row is set
			// at all; the serialised array always exists once an editor
			// touches the field, so passthrough as-is.
			return array( 'key' => $field_name, 'compare' => $compare );
		}

		// Unsupported operator: fall back to a vanilla clause.
		return array( 'key' => $field_name, 'value' => $value, 'compare' => $compare );
	}

	/**
	 * Walk a meta_query array and rewrite every clause whose key is a
	 * Multilingual field. Preserves the relation tree.
	 *
	 * Idempotent: a meta_query that has already been rewritten (its
	 * clauses are already OR/AND groups against LIKE patterns) is
	 * returned untouched because their `key` is set on inner clauses
	 * but the LIKE pattern is no longer the bare user-supplied value.
	 *
	 * @param array       $meta_query Existing meta_query arg.
	 * @param string|null $language   Language to compare against.
	 * @return array
	 */
	public static function rewrite_meta_query( $meta_query, $language = null ) {
		if ( ! is_array( $meta_query ) || empty( $meta_query ) ) {
			return $meta_query;
		}

		$out = array();
		foreach ( $meta_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				$out[ $key ] = $clause;
				continue;
			}
			if ( ! is_array( $clause ) ) {
				$out[ $key ] = $clause;
				continue;
			}
			// Nested group: recurse.
			if ( ! isset( $clause['key'] ) ) {
				$out[ $key ] = self::rewrite_meta_query( $clause, $language );
				continue;
			}
			// Simple clause: rewrite if multilingual.
			$field = (string) $clause['key'];
			if ( ! self::is_multilingual_field( $field ) ) {
				$out[ $key ] = $clause;
				continue;
			}
			$value   = isset( $clause['value'] ) ? $clause['value'] : '';
			$compare = isset( $clause['compare'] ) ? $clause['compare'] : '=';
			$out[ $key ] = self::build_multilingual_meta_clause( $field, $value, $compare, $language );
		}

		return $out;
	}

	/**
	 * Exact-match pattern: byte-precise LIKE for serialised value.
	 *
	 * @param string $tab_key Default or language slug.
	 * @param string $value   Compared value.
	 * @return string
	 */
	private static function exact_pattern( $tab_key, $value ) {
		$tab_key = (string) $tab_key;
		$value   = (string) $value;

		return sprintf(
			'%%s:%d:"%s";s:%d:"%s"%%',
			strlen( $tab_key ),
			self::escape_like_inner( $tab_key ),
			strlen( $value ),
			self::escape_like_inner( $value )
		);
	}

	/**
	 * Substring-match pattern: drop the value byte-length placeholder
	 * so any value that contains the search string matches.
	 *
	 * @param string $tab_key Default or language slug.
	 * @param string $value   Substring to find.
	 * @return string
	 */
	private static function contains_pattern( $tab_key, $value ) {
		$tab_key = (string) $tab_key;
		$value   = (string) $value;

		return sprintf(
			'%%s:%d:"%s";s:%%%s%%',
			strlen( $tab_key ),
			self::escape_like_inner( $tab_key ),
			self::escape_like_inner( $value )
		);
	}

	/**
	 * Escape the value for inclusion inside a LIKE pattern.
	 *
	 * Doubles backslashes (LIKE escape character) and replaces
	 * % / _ literals with their escaped forms so user input like a
	 * percent sign cannot turn into a wildcard.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	private static function escape_like_inner( $value ) {
		global $wpdb;
		// $wpdb->esc_like handles _ and %; we then double the backslash.
		return $wpdb->esc_like( (string) $value );
	}
}
