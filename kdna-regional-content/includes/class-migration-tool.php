<?php
/**
 * Migration tool: convert a JetEngine Text/Textarea/WYSIWYG field to its
 * KDNA Multilingual equivalent.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Migration_Tool
 *
 * Two AJAX endpoints back the Tools-tab migration UI:
 *
 *   kdna_rc_migration_start  - inspects JetEngine config + post count and
 *                              returns batch metadata.
 *   kdna_rc_migration_batch  - processes one batch of posts: reads the
 *                              field's existing scalar value and rewrites
 *                              it as ['default' => $value] under the same
 *                              meta key.
 *
 * After the last batch completes, the handler also rewrites the
 * JetEngine meta-box config to change the field's type from
 * Text/Textarea/WYSIWYG to its Multilingual equivalent. That step is
 * isolated in update_jetengine_field_type() so the post-meta migration
 * can succeed even when the JetEngine config write path varies between
 * versions.
 */
class KDNA_RC_Migration_Tool {

	/**
	 * AJAX action name for the migration start handshake.
	 *
	 * @var string
	 */
	const AJAX_START = 'kdna_rc_migration_start';

	/**
	 * AJAX action name for processing a single batch.
	 *
	 * @var string
	 */
	const AJAX_BATCH = 'kdna_rc_migration_batch';

	/**
	 * Posts processed per batch. Conservative to keep the request quick
	 * even on large CPTs; adjust here if the host has plenty of headroom.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Wire admin AJAX handlers.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::AJAX_START, array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_' . self::AJAX_BATCH, array( $this, 'ajax_batch' ) );
	}

	/**
	 * Inspect JetEngine config + post count and return batch metadata.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->guard();

		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$field       = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$target_type = isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : '';

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Pick a valid post type.', 'kdna-regional-content' ) ), 400 );
		}
		if ( '' === $field ) {
			wp_send_json_error( array( 'message' => __( 'Pick a source field.', 'kdna-regional-content' ) ), 400 );
		}
		if ( ! in_array( $target_type, array( 'kdna_rc_ml_text', 'kdna_rc_ml_image', 'kdna_rc_ml_wysiwyg' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Pick a target multilingual type.', 'kdna-regional-content' ) ), 400 );
		}

		$count_query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$total = (int) $count_query->found_posts;

		wp_send_json_success(
			array(
				'total'      => $total,
				'batch_size' => self::BATCH_SIZE,
				'batches'    => $total > 0 ? (int) ceil( $total / self::BATCH_SIZE ) : 0,
			)
		);
	}

	/**
	 * Process one batch of posts, then (on the final batch) flip the
	 * JetEngine field type definition.
	 *
	 * @return void
	 */
	public function ajax_batch() {
		$this->guard();

		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$field       = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$target_type = isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : '';
		$batch       = isset( $_POST['batch'] ) ? max( 0, (int) $_POST['batch'] ) : 0;

		if ( '' === $post_type || '' === $field || '' === $target_type ) {
			wp_send_json_error( array( 'message' => __( 'Missing batch parameters.', 'kdna-regional-content' ) ), 400 );
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $batch + 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$processed = 0;
		foreach ( $query->posts as $post_id ) {
			$existing = get_post_meta( $post_id, $field, true );

			// Already migrated: skip silently.
			if ( is_array( $existing ) && array_key_exists( 'default', $existing ) ) {
				continue;
			}

			$default_value = $existing;
			if ( is_array( $existing ) ) {
				// Unexpected non-multilingual array; coerce to JSON string
				// rather than lose the data.
				$default_value = wp_json_encode( $existing );
			}

			$wrapped = array( 'default' => $default_value );
			update_post_meta( (int) $post_id, $field, $wrapped );
			++$processed;
		}

		// Determine whether this was the last batch.
		$total_query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$total       = (int) $total_query->found_posts;
		$total_batches = $total > 0 ? (int) ceil( $total / self::BATCH_SIZE ) : 0;
		$is_last       = ( $batch + 1 ) >= $total_batches;

		$type_changed = false;
		if ( $is_last ) {
			$type_changed = $this->update_jetengine_field_type( $field, $target_type );
		}

		wp_send_json_success(
			array(
				'processed'    => $processed,
				'batch'        => $batch,
				'is_last'      => $is_last,
				'type_changed' => $type_changed,
				'total'        => $total,
			)
		);
	}

	/**
	 * Rewrite the JetEngine meta-box configuration to swap a field's type.
	 *
	 * Reads the option, walks every box's meta_fields, flips the matching
	 * entry's type to the multilingual equivalent. Returns true when at
	 * least one entry was changed.
	 *
	 * @param string $field_name  Field name to flip.
	 * @param string $target_type New JetEngine field-type slug.
	 * @return bool
	 */
	private function update_jetengine_field_type( $field_name, $target_type ) {
		$option_name = 'jet_engine_meta_boxes';
		$option      = get_option( $option_name );
		if ( ! is_array( $option ) ) {
			$option_name = 'jet-engine-meta-boxes';
			$option      = get_option( $option_name );
		}
		if ( ! is_array( $option ) ) {
			return false;
		}

		$dirty = false;
		foreach ( $option as $box_idx => $box ) {
			if ( ! is_array( $box ) || empty( $box['meta_fields'] ) || ! is_array( $box['meta_fields'] ) ) {
				continue;
			}
			foreach ( $box['meta_fields'] as $field_idx => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( isset( $field['name'] ) && $field['name'] === $field_name ) {
					$option[ $box_idx ]['meta_fields'][ $field_idx ]['type'] = $target_type;
					$dirty = true;
				}
			}
		}

		if ( $dirty ) {
			update_option( $option_name, $option, false );
		}
		return $dirty;
	}

	/**
	 * Shared nonce + capability check.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run migrations.', 'kdna-regional-content' ) ),
				403
			);
		}
	}

	/**
	 * Discover JetEngine fields available for migration on a given post type.
	 *
	 * Used by the Tools-tab UI populator. Returns an associative array of
	 * meta-key => label for every Text / Textarea / WYSIWYG field. Used by
	 * the JS to populate the Source field dropdown when the admin picks a
	 * post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string,string>
	 */
	public static function discover_simple_fields( $post_type ) {
		$out    = array();
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return $out;
		}

		$accept = array( 'text', 'textarea', 'wysiwyg' );

		foreach ( $option as $box ) {
			if ( ! is_array( $box ) ) { continue; }
			$applies = isset( $box['args']['allowed_post_type'] ) ? (array) $box['args']['allowed_post_type'] : array();
			if ( empty( $applies ) ) {
				$applies = isset( $box['args']['post_type'] ) ? (array) $box['args']['post_type'] : array();
			}
			if ( ! in_array( $post_type, $applies, true ) ) { continue; }
			foreach ( $box['meta_fields'] as $field ) {
				if ( ! is_array( $field ) ) { continue; }
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' === $name || ! in_array( $type, $accept, true ) ) { continue; }
				$label = isset( $field['title'] ) ? (string) $field['title'] : $name;
				$out[ $name ] = sprintf( '%1$s (%2$s, %3$s)', $label, $name, $type );
			}
		}
		asort( $out );
		return $out;
	}
}
