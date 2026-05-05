<?php
/**
 * Text Editor widget regional content variant extension.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Text_Editor_Extension
 *
 * Adds a Regional Content controls section to the Elementor Text Editor
 * widget. Each variant row contains a Region selector and a Content (WYSIWYG)
 * override. On render, the entire content is replaced because the Text
 * Editor widget outputs WYSIWYG HTML directly with no surrounding wrapper
 * we need to preserve.
 */
class KDNA_RC_Text_Editor_Extension extends KDNA_RC_Variants_Base {

	/**
	 * Field id for the variant content body.
	 *
	 * @var string
	 */
	const FIELD_CONTENT = 'kdna_rc_content';

	/**
	 * Elementor widget name targeted by this extension.
	 *
	 * @return string
	 */
	protected function widget_name() {
		return 'text-editor';
	}

	/**
	 * Elementor controls section the Regional Content panel is appended to.
	 *
	 * Section_editor is the Text Editor widget's main content section.
	 *
	 * @return string
	 */
	protected function controls_section() {
		return 'section_editor';
	}

	/**
	 * Add the Content (WYSIWYG) field to each repeater row.
	 *
	 * @param \Elementor\Repeater $repeater Repeater being built.
	 * @return void
	 */
	protected function register_variant_fields( $repeater ) {
		$repeater->add_control(
			self::FIELD_CONTENT,
			array(
				'label'      => __( 'Content', 'kdna-regional-content' ),
				'type'       => defined( 'Elementor\\Controls_Manager::WYSIWYG' ) ? \Elementor\Controls_Manager::WYSIWYG : 'wysiwyg',
				'default'    => '',
				'show_label' => false,
			)
		);
	}

	/**
	 * Build the variant HTML. The Text Editor renders the WYSIWYG content
	 * directly with no structural wrapper of our own to preserve, so the
	 * variant simply replaces the entire content body with the variant text.
	 *
	 * Falls back to the original content when the variant content is empty
	 * so editors do not accidentally show a blank widget by leaving the
	 * field unfilled.
	 *
	 * @param string $content Original rendered widget content.
	 * @param array  $variant Prepared variant row.
	 * @return string
	 */
	protected function transform_default_html( $content, array $variant ) {
		$body = isset( $variant[ self::FIELD_CONTENT ] ) ? (string) $variant[ self::FIELD_CONTENT ] : '';
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return $content;
		}
		return $body;
	}
}
