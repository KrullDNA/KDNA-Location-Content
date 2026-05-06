<?php
/**
 * KDNA Multilingual WYSIWYG custom JetEngine field type.
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
 * KDNA_RC_Multilingual_WYSIWYG_Field
 *
 * Renders one TinyMCE editor instance per tab via wp_editor(). Tab
 * switching toggles which panel is visible without destroying the
 * editor state, so quill state is preserved when the editor jumps
 * between tabs while writing.
 */
class KDNA_RC_Multilingual_WYSIWYG_Field extends KDNA_RC_Multilingual_Base {

	/**
	 * Field-type slug used in JetEngine's config and on save.
	 *
	 * @return string
	 */
	public function field_type_slug() {
		return 'kdna_rc_ml_wysiwyg';
	}

	/**
	 * Display label in the JetEngine field-type dropdown.
	 *
	 * @return string
	 */
	public function field_type_label() {
		return __( 'KDNA Multilingual WYSIWYG', 'kdna-regional-content' );
	}

	/**
	 * Render a single tab's TinyMCE editor.
	 *
	 * Each editor needs a unique editor_id in the global editor registry,
	 * so we hash the input name + tab key together to keep ids stable
	 * within the post but unique across multiple multilingual fields on
	 * the same screen.
	 *
	 * @param string $name    Form field name.
	 * @param mixed  $value   Stored HTML for this tab.
	 * @param string $tab_key Tab key.
	 * @param array  $args    Field args.
	 * @return void
	 */
	protected function render_input( $name, $value, $tab_key, array $args ) {
		unset( $args );

		$editor_id = 'kdna_rc_mlf_ed_' . substr( md5( $name . '|' . $tab_key ), 0, 12 );
		$content   = is_string( $value ) ? $value : '';

		wp_editor(
			$content,
			$editor_id,
			array(
				'textarea_name' => $name,
				'media_buttons' => true,
				'teeny'         => false,
				'editor_height' => 240,
				'tinymce'       => array(
					'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,fullscreen,wp_adv',
					'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
				),
			)
		);
	}

	/**
	 * Sanitise a single tab's HTML value with wp_kses_post() so safe
	 * markup survives but scripts and unsafe attributes are stripped.
	 *
	 * @param mixed $value Raw posted value.
	 * @return string
	 */
	protected function sanitise_value( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return wp_kses_post( $value );
	}
}
