<?php
/**
 * KDNA Dynamic Multilingual Link widget.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Dynamic_Multilingual_Link_Widget
 *
 * Renders an anchor whose href and link text are both pulled from
 * Multilingual Text fields. The editor selects two fields: one for the
 * URL, one for the visible label. Per-language data attributes carry
 * data-kdna-mlf-url-{slug} and data-kdna-mlf-text-{slug}; the front-end
 * JS swaps both at language change.
 */
class KDNA_RC_Dynamic_Multilingual_Link_Widget extends KDNA_RC_Dynamic_Multilingual_Base {

	/**
	 * Internal Elementor widget name.
	 *
	 * @return string
	 */
	public function get_name() { return 'kdna-rc-dynamic-mlf-link'; }

	/**
	 * Editor display title.
	 *
	 * @return string
	 */
	public function get_title() { return esc_html__( 'KDNA Dynamic Multilingual Link', 'kdna-regional-content' ); }

	/**
	 * Editor icon.
	 *
	 * @return string
	 */
	public function get_icon() { return 'eicon-link'; }

	/**
	 * Editor search keywords.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() { return array( 'kdna', 'multilingual', 'dynamic', 'link', 'cta' ); }

	/**
	 * Source field type slug for the shared base.
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
				'label' => esc_html__( 'Multilingual Link', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$choices = $this->discover_jetengine_fields( array( 'kdna_rc_ml_text' ) );

		if ( empty( $choices ) ) {
			$this->add_control(
				'kdna_rc_no_fields_notice',
				array(
					'type'            => \Elementor\Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'No KDNA Multilingual Text fields found. Create some in JetEngine first.', 'kdna-regional-content' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
		}

		$select = array_merge( array( '' => esc_html__( 'Select a field...', 'kdna-regional-content' ) ), $choices );

		$this->add_control(
			'url_field',
			array(
				'label'       => esc_html__( 'URL Field', 'kdna-regional-content' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $select,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'text_field',
			array(
				'label'       => esc_html__( 'Text Field', 'kdna-regional-content' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $select,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'open_in_new_tab',
			array(
				'label'        => esc_html__( 'Open in new tab', 'kdna-regional-content' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'rel_nofollow',
			array(
				'label'        => esc_html__( 'Add rel="nofollow"', 'kdna-regional-content' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'fallback_behaviour',
			array(
				'label'   => esc_html__( 'When language has no value', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'default' => array( 'title' => esc_html__( 'Show Default', 'kdna-regional-content' ), 'icon' => 'eicon-text' ),
					'hide'    => array( 'title' => esc_html__( 'Hide widget', 'kdna-regional-content' ), 'icon' => 'eicon-eye' ),
				),
				'default' => 'default',
				'toggle'  => false,
			)
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'kdna_rc_mlf_section_style',
			array(
				'label' => esc_html__( 'Style', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'typography',
			'selector' => '{{WRAPPER}} .kdna-rc-mlf a',
		) );

		$this->start_controls_tabs( 'link_state_tabs' );

		$this->start_controls_tab( 'link_state_normal', array( 'label' => esc_html__( 'Normal', 'kdna-regional-content' ) ) );
		$this->add_control( 'link_color', array(
			'label'     => esc_html__( 'Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf a' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'link_state_hover', array( 'label' => esc_html__( 'Hover', 'kdna-regional-content' ) ) );
		$this->add_control( 'link_color_hover', array(
			'label'     => esc_html__( 'Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf a:hover' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the front end.
	 *
	 * @return void
	 */
	protected function render() {
		$settings   = $this->get_settings_for_display();
		$url_field  = isset( $settings['url_field'] ) ? sanitize_key( $settings['url_field'] ) : '';
		$text_field = isset( $settings['text_field'] ) ? sanitize_key( $settings['text_field'] ) : '';
		$post_id    = $this->resolve_post_id();
		if ( '' === $url_field || '' === $text_field || $post_id <= 0 ) {
			return;
		}

		$urls  = $this->build_value_map( get_post_meta( $post_id, $url_field, true ) );
		$texts = $this->build_value_map( get_post_meta( $post_id, $text_field, true ) );

		$default_url  = isset( $urls['default'] ) ? (string) $urls['default'] : '';
		$default_text = isset( $texts['default'] ) ? (string) $texts['default'] : '';

		$has_default = '' !== trim( $default_url ) && '' !== trim( $default_text );
		$fallback    = isset( $settings['fallback_behaviour'] ) ? (string) $settings['fallback_behaviour'] : 'default';
		if ( ! $has_default && 'hide' === $fallback ) {
			return;
		}

		$open_new = ! empty( $settings['open_in_new_tab'] ) && 'yes' === $settings['open_in_new_tab'];
		$nofollow = ! empty( $settings['rel_nofollow'] ) && 'yes' === $settings['rel_nofollow'];

		$wrapper_attrs = 'class="kdna-rc-mlf"';
		// Pack each language's URL + text as separate attributes.
		foreach ( $urls as $slug => $val ) {
			if ( 'default' === $slug ) { continue; }
			if ( ! is_string( $val ) || '' === trim( $val ) ) { continue; }
			$wrapper_attrs .= ' data-kdna-mlf-url-' . esc_attr( $slug ) . '="' . esc_attr( $val ) . '"';
		}
		foreach ( $texts as $slug => $val ) {
			if ( 'default' === $slug ) { continue; }
			if ( ! is_string( $val ) || '' === trim( $val ) ) { continue; }
			$wrapper_attrs .= ' data-kdna-mlf-text-' . esc_attr( $slug ) . '="' . esc_attr( $val ) . '"';
		}

		$rel_parts = array();
		if ( $open_new ) { $rel_parts[] = 'noopener'; }
		if ( $nofollow ) { $rel_parts[] = 'nofollow'; }

		echo '<div ' . $wrapper_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes built via esc_attr above.
		if ( $has_default ) {
			printf(
				'<a href="%1$s"%2$s%3$s>%4$s</a>',
				esc_url( $default_url ),
				$open_new ? ' target="_blank"' : '',
				! empty( $rel_parts ) ? ' rel="' . esc_attr( implode( ' ', $rel_parts ) ) . '"' : '',
				esc_html( $default_text )
			);
		}
		echo '</div>';
	}

	/**
	 * Editor preview template.
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<div class="kdna-rc-mlf">
			<# if ( settings.url_field && settings.text_field ) { #>
				<a href="#">{{ settings.text_field }}</a>
			<# } else { #>
				<p><?php echo esc_html__( 'Choose a URL field and a Text field on the Content tab.', 'kdna-regional-content' ); ?></p>
			<# } #>
		</div>
		<?php
	}
}
