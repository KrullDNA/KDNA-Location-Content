<?php
/**
 * KDNA Multilingual Text custom JetEngine field type.
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
 * KDNA_RC_Multilingual_Text_Field
 *
 * One <input type="text"> per tab. Stored as a serialised array on a
 * single post meta row keyed by the field's name.
 */
class KDNA_RC_Multilingual_Text_Field extends KDNA_RC_Multilingual_Base {

	/**
	 * Field-type slug used in JetEngine's config and on save.
	 *
	 * @return string
	 */
	public function field_type_slug() {
		return 'kdna_rc_ml_text';
	}

	/**
	 * Display label in the JetEngine field-type dropdown.
	 *
	 * @return string
	 */
	public function field_type_label() {
		return __( 'KDNA Multilingual Text', 'kdna-regional-content' );
	}

	/**
	 * Render a single tab's text input.
	 *
	 * @param string $name    Form field name.
	 * @param string $value   Stored value for this tab.
	 * @param string $tab_key Tab key (default or language slug).
	 * @param array  $args    Field args.
	 * @return void
	 */
	protected function render_input( $name, $value, $tab_key, array $args ) {
		unset( $tab_key );

		$placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';

		printf(
			'<input type="text" class="regular-text kdna-rc-mlf-input" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( is_string( $value ) || is_numeric( $value ) ? (string) $value : '' ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Sanitise a single tab's posted value.
	 *
	 * @param mixed $value Raw posted value.
	 * @return string
	 */
	protected function sanitise_value( $value ) {
		return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
	}
}
