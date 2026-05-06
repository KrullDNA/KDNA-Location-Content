<?php
/**
 * KDNA Multilingual Image custom JetEngine field type.
 *
 * Targets JetEngine 3.x. See class-multilingual-base.php for notes on
 * version variance.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Multilingual_Image_Field
 *
 * Each tab contains a WordPress media library picker that stores the
 * selected attachment ID. Tabs that have no image fall back to the
 * default tab when the visitor's resolved language has no value.
 */
class KDNA_RC_Multilingual_Image_Field extends KDNA_RC_Multilingual_Base {

	/**
	 * Field-type slug used in JetEngine's config and on save.
	 *
	 * @return string
	 */
	public function field_type_slug() {
		return 'kdna_rc_ml_image';
	}

	/**
	 * Display label in the JetEngine field-type dropdown.
	 *
	 * @return string
	 */
	public function field_type_label() {
		return __( 'KDNA Multilingual Image', 'kdna-regional-content' );
	}

	/**
	 * Render a single tab's media picker. The hidden input holds the
	 * attachment ID which is what survives the form submit. The visible
	 * thumbnail and Choose / Remove buttons are wired up by the
	 * multilingual-fields.js admin script.
	 *
	 * @param string $name    Form field name.
	 * @param mixed  $value   Stored value (attachment ID).
	 * @param string $tab_key Tab key.
	 * @param array  $args    Field args.
	 * @return void
	 */
	protected function render_input( $name, $value, $tab_key, array $args ) {
		unset( $tab_key, $args );

		$attachment_id = absint( is_scalar( $value ) ? $value : 0 );
		$thumb_url     = '';
		if ( $attachment_id > 0 ) {
			$thumb_url = (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
		}
		$has_image = $attachment_id > 0 && '' !== $thumb_url;

		echo '<div class="kdna-rc-mlf-image-picker' . ( $has_image ? ' has-image' : '' ) . '">';
		echo '<div class="kdna-rc-mlf-image-preview">';
		if ( $has_image ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="" />';
		} else {
			echo '<span class="kdna-rc-mlf-image-empty">' . esc_html__( 'No image selected', 'kdna-regional-content' ) . '</span>';
		}
		echo '</div>';
		echo '<p class="kdna-rc-mlf-image-actions">';
		echo '<button type="button" class="button kdna-rc-mlf-image-choose">' . esc_html__( 'Select Image', 'kdna-regional-content' ) . '</button> ';
		echo '<button type="button" class="button-link kdna-rc-mlf-image-remove"' . ( $has_image ? '' : ' style="display:none"' ) . '>' . esc_html__( 'Remove', 'kdna-regional-content' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- conditional inline style is literal.
		echo '</p>';
		printf(
			'<input type="hidden" class="kdna-rc-mlf-image-id" name="%1$s" value="%2$s" />',
			esc_attr( $name ),
			esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' )
		);
		echo '</div>';
	}

	/**
	 * Sanitise a single tab's posted attachment ID.
	 *
	 * @param mixed $value Raw posted value.
	 * @return int
	 */
	protected function sanitise_value( $value ) {
		return absint( is_scalar( $value ) ? $value : 0 );
	}

	/**
	 * Override the completion indicator so an attachment ID of zero or an
	 * empty string registers as empty.
	 *
	 * @param mixed $value Stored tab value.
	 * @return bool
	 */
	protected function is_value_present( $value ) {
		return is_numeric( $value ) && (int) $value > 0;
	}
}
