<?php
/**
 * KDNA Dynamic Multilingual Field widget (text + WYSIWYG).
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Dynamic_Multilingual_Field_Widget
 *
 * Renders any KDNA Multilingual Text or KDNA Multilingual WYSIWYG field
 * from JetEngine. The default value renders as the visible content;
 * every other configured language is packed into a data-kdna-mlf-{slug}
 * attribute so the front-end JS can swap content client-side.
 *
 * WYSIWYG values are base64-encoded into the data attribute (the wrapper
 * carries data-kdna-mlf-encoded="1") because raw HTML in attributes
 * collides with stray quote characters and breaks the parser.
 */
class KDNA_RC_Dynamic_Multilingual_Field_Widget extends KDNA_RC_Dynamic_Multilingual_Base {

	/**
	 * Internal Elementor widget name.
	 *
	 * @return string
	 */
	public function get_name() { return 'kdna-rc-dynamic-mlf-field'; }

	/**
	 * Editor display title.
	 *
	 * @return string
	 */
	public function get_title() { return esc_html__( 'KDNA Dynamic Multilingual Field', 'kdna-regional-content' ); }

	/**
	 * Editor icon.
	 *
	 * @return string
	 */
	public function get_icon() { return 'eicon-post-content'; }

	/**
	 * Editor search keywords.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() { return array( 'kdna', 'multilingual', 'dynamic', 'field', 'translation' ); }

	/**
	 * Source field types this widget reads.
	 *
	 * @return string
	 */
	protected function source_field_type() { return 'kdna_rc_ml_text'; }

	/**
	 * Register controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'kdna_rc_mlf_section_content',
			array(
				'label' => esc_html__( 'Multilingual Field', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_shared_content_controls( array( 'kdna_rc_ml_text', 'kdna_rc_ml_wysiwyg' ) );

		$this->add_control(
			'tag',
			array(
				'label'   => esc_html__( 'HTML Tag', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'p'    => 'p',
					'div'  => 'div',
					'span' => 'span',
					'h1'   => 'h1',
					'h2'   => 'h2',
					'h3'   => 'h3',
					'h4'   => 'h4',
					'h5'   => 'h5',
					'h6'   => 'h6',
				),
				'default' => 'div',
			)
		);

		$this->end_controls_section();

		// Style controls.
		$this->start_controls_section(
			'kdna_rc_mlf_section_style',
			array(
				'label' => esc_html__( 'Style', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control( 'alignment', array(
			'label'     => esc_html__( 'Alignment', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::CHOOSE,
			'options'   => array(
				'left'    => array( 'title' => 'Left', 'icon' => 'eicon-text-align-left' ),
				'center'  => array( 'title' => 'Center', 'icon' => 'eicon-text-align-center' ),
				'right'   => array( 'title' => 'Right', 'icon' => 'eicon-text-align-right' ),
				'justify' => array( 'title' => 'Justify', 'icon' => 'eicon-text-align-justify' ),
			),
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf' => 'text-align: {{VALUE}};' ),
		) );

		$this->add_control( 'text_color', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf' => 'color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'typography',
			'selector' => '{{WRAPPER}} .kdna-rc-mlf',
		) );

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'margin', array(
			'label'      => esc_html__( 'Margin', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the front end.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$field    = isset( $settings['field_source'] ) ? sanitize_key( $settings['field_source'] ) : '';
		if ( '' === $field ) {
			return;
		}

		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$tag       = isset( $settings['tag'] ) ? sanitize_key( $settings['tag'] ) : 'div';
		$fallback  = isset( $settings['fallback_behaviour'] ) ? (string) $settings['fallback_behaviour'] : 'default';
		$placeholder = isset( $settings['fallback_placeholder'] ) ? (string) $settings['fallback_placeholder'] : '';

		$stored = get_post_meta( $post_id, $field, true );
		$values = $this->build_value_map( $stored );

		// Run shortcodes once, server-side, before encoding into attributes.
		foreach ( $values as $slug => $val ) {
			if ( is_string( $val ) && false !== strpos( $val, '[' ) ) {
				$values[ $slug ] = do_shortcode( $val );
			}
		}

		$default      = isset( $values['default'] ) ? (string) $values['default'] : '';
		$has_default  = '' !== trim( $default );
		$is_html_field = $this->detect_field_type_for( $field ) === 'kdna_rc_ml_wysiwyg';

		if ( ! $has_default && 'hide' === $fallback ) {
			return; // Hide entirely when default is empty and fallback is hide.
		}

		// Build data attributes for non-empty languages other than default.
		$attrs = array();
		foreach ( $values as $slug => $val ) {
			if ( 'default' === $slug ) { continue; }
			if ( ! is_string( $val ) || '' === trim( $val ) ) { continue; }
			if ( $is_html_field ) {
				$attrs[ 'data-kdna-mlf-' . $slug ] = base64_encode( $val ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- intentional opaque transport.
			} else {
				$attrs[ 'data-kdna-mlf-' . $slug ] = $val;
			}
		}

		$visible = '';
		if ( $has_default ) {
			$visible = $default;
		} elseif ( 'placeholder' === $fallback && '' !== $placeholder ) {
			$visible = $placeholder;
		}

		$wrapper_attrs = 'class="kdna-rc-mlf"';
		if ( $is_html_field ) {
			$wrapper_attrs .= ' data-kdna-mlf-encoded="1"';
		}
		foreach ( $attrs as $k => $v ) {
			$wrapper_attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}

		printf(
			'<%1$s %2$s>%3$s</%1$s>',
			esc_attr( $tag ),
			$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute keys / values escaped above.
			$is_html_field ? wp_kses_post( $visible ) : esc_html( $visible )
		);
	}

	/**
	 * Editor preview template.
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<#
		var tag = settings.tag || 'div';
		#>
		<{{ tag }} class="kdna-rc-mlf">
			<# if ( settings.field_source ) { #>{{ settings.field_source }}<# } else { #><?php echo esc_html__( 'Choose a multilingual field on the Content tab.', 'kdna-regional-content' ); ?><# } #>
		</{{ tag }}>
		<?php
	}

	/**
	 * Look up a field's stored type from the JetEngine config so we can
	 * decide whether to base64-encode HTML content.
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	private function detect_field_type_for( $field_name ) {
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return 'kdna_rc_ml_text';
		}
		foreach ( $option as $box ) {
			$fields = isset( $box['meta_fields'] ) && is_array( $box['meta_fields'] ) ? $box['meta_fields'] : array();
			foreach ( $fields as $field ) {
				if ( is_array( $field ) && isset( $field['name'] ) && $field['name'] === $field_name && isset( $field['type'] ) ) {
					return (string) $field['type'];
				}
			}
		}
		return 'kdna_rc_ml_text';
	}
}
