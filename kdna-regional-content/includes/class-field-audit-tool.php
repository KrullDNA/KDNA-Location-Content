<?php
/**
 * Field Translation Audit tool.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Field_Audit_Tool
 *
 * Scans Multilingual fields across one or all CPTs and reports per-language
 * completeness. Powers the Tools-tab Audit UI via three AJAX endpoints:
 *
 *   kdna_rc_audit_scan       - returns the field list, language list, and
 *                              the per-post completeness table.
 *   kdna_rc_audit_bulk_add   - adds an empty per-language slot to every
 *                              post that is missing it, so editors can
 *                              find them quickly when next editing.
 */
class KDNA_RC_Field_Audit_Tool {

	const AJAX_SCAN     = 'kdna_rc_audit_scan';
	const AJAX_BULK_ADD = 'kdna_rc_audit_bulk_add';

	/**
	 * Wire admin AJAX handlers.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::AJAX_SCAN, array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_' . self::AJAX_BULK_ADD, array( $this, 'ajax_bulk_add' ) );
	}

	/**
	 * AJAX: scan posts and return the completeness table.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		$this->guard();

		$cpt = isset( $_POST['cpt'] ) ? sanitize_key( wp_unslash( $_POST['cpt'] ) ) : '';
		if ( '' !== $cpt && '__all__' !== $cpt && ! post_type_exists( $cpt ) ) {
			wp_send_json_error( array( 'message' => __( 'Pick a valid post type.', 'kdna-regional-content' ) ), 400 );
		}

		$languages = ( new KDNA_RC_Languages() )->get_all();
		$lang_keys = array();
		$lang_map  = array();
		foreach ( $languages as $language ) {
			$lang_keys[]               = $language['slug'];
			$lang_map[ $language['slug'] ] = $language;
		}

		$fields = $this->discover_fields( '__all__' === $cpt || '' === $cpt ? null : $cpt );
		if ( empty( $fields ) ) {
			wp_send_json_success(
				array(
					'fields'   => array(),
					'rows'     => array(),
					'lang_map' => $lang_map,
				)
			);
		}

		$rows = $this->build_rows( $fields, '__all__' === $cpt ? '' : $cpt, $lang_keys );

		wp_send_json_success(
			array(
				'fields'   => $fields,
				'rows'     => $rows,
				'lang_map' => $lang_map,
			)
		);
	}

	/**
	 * AJAX: add an empty per-language slot to every post missing it.
	 *
	 * Useful for surfacing "needs translation" rows on edit screens.
	 * The empty string keeps the row in the serialised array so the
	 * tab editor finds the slot and admins can paste content directly.
	 *
	 * @return void
	 */
	public function ajax_bulk_add() {
		$this->guard();

		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		$cpt      = isset( $_POST['cpt'] ) ? sanitize_key( wp_unslash( $_POST['cpt'] ) ) : '';

		if ( '' === $field || '' === $language ) {
			wp_send_json_error( array( 'message' => __( 'Missing field or language.', 'kdna-regional-content' ) ), 400 );
		}
		if ( ! KDNA_RC_Multilingual_Query_Helper::is_multilingual_field( $field ) ) {
			wp_send_json_error( array( 'message' => __( 'Field is not a multilingual type.', 'kdna-regional-content' ) ), 400 );
		}

		$post_types = array();
		if ( '' !== $cpt && '__all__' !== $cpt && post_type_exists( $cpt ) ) {
			$post_types[] = $cpt;
		} else {
			$post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$updated = 0;
		foreach ( $query->posts as $post_id ) {
			$value = get_post_meta( $post_id, $field, true );
			$value = KDNA_RC_Multilingual_Base::normalise_stored_value( $value );
			if ( ! isset( $value[ $language ] ) ) {
				$value[ $language ] = '';
				update_post_meta( (int) $post_id, $field, $value );
				++$updated;
				continue;
			}
			// Ensure the key is present on disk; it might already be empty
			// but in some cases the array was missing the slot.
			update_post_meta( (int) $post_id, $field, $value );
		}

		wp_send_json_success(
			array(
				'updated' => $updated,
				/* translators: 1: count, 2: language name. */
				'message' => sprintf( __( '%1$d posts now carry an empty %2$s slot.', 'kdna-regional-content' ), $updated, $language ),
			)
		);
	}

	/**
	 * Build the per-post completeness rows for the audit table.
	 *
	 * @param array<int,string> $fields    Multilingual field names.
	 * @param string            $cpt       CPT slug filter; empty for all CPTs.
	 * @param array<int,string> $lang_keys Configured language slugs.
	 * @return array<int,array>
	 */
	private function build_rows( array $fields, $cpt, array $lang_keys ) {
		$post_types = '' !== $cpt ? array( $cpt ) : array_keys( get_post_types( array( 'public' => true ), 'names' ) );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'posts_per_page'         => 500,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			$row = array(
				'id'        => (int) $post->ID,
				'title'     => (string) get_the_title( $post ),
				'edit_link' => (string) get_edit_post_link( $post->ID, 'raw' ),
				'fields'    => array(),
			);
			foreach ( $fields as $field ) {
				$stored = get_post_meta( $post->ID, $field, true );
				$norm   = KDNA_RC_Multilingual_Base::normalise_stored_value( $stored );
				$entry  = array(
					'default' => $this->is_filled( isset( $norm['default'] ) ? $norm['default'] : '' ),
				);
				foreach ( $lang_keys as $lang ) {
					$entry[ $lang ] = $this->is_filled( isset( $norm[ $lang ] ) ? $norm[ $lang ] : '' );
				}
				$row['fields'][ $field ] = $entry;
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Discover Multilingual fields. Returns an associative array of name => label.
	 *
	 * @param string|null $cpt CPT slug or null for every CPT.
	 * @return array<string,string>
	 */
	private function discover_fields( $cpt ) {
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return array();
		}

		$ml_types = KDNA_RC_Multilingual_Query_Helper::multilingual_field_types();
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
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				if ( '' === $name || ! in_array( $type, $ml_types, true ) ) {
					continue;
				}
				$label = isset( $field['title'] ) ? (string) $field['title'] : $name;
				$out[ $name ] = $label;
			}
		}
		return $out;
	}

	/**
	 * Whether a tab value should count as filled.
	 *
	 * @param mixed $value Tab value.
	 * @return bool
	 */
	private function is_filled( $value ) {
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		return ! empty( $value );
	}

	/**
	 * Shared nonce + capability guard.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run the audit.', 'kdna-regional-content' ) ),
				403
			);
		}
	}
}
