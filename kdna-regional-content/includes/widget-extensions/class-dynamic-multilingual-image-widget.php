<?php
/**
 * KDNA Dynamic Multilingual Image widget.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Dynamic_Multilingual_Image_Widget
 *
 * Renders a KDNA Multilingual Image field. Each language's attachment
 * ID is resolved server-side to a URL via wp_get_attachment_image_url()
 * at the configured image size, then packed as a data attribute on the
 * widget wrapper. The visible <img> shows the default language and the
 * front-end JS swaps src + alt when the visitor's language has a value.
 */
class KDNA_RC_Dynamic_Multilingual_Image_Widget extends KDNA_RC_Dynamic_Multilingual_Base {

	/**
	 * Internal Elementor widget name.
	 *
	 * @return string
	 */
	public function get_name() { return 'kdna-rc-dynamic-mlf-image'; }

	/**
	 * Editor display title.
	 *
	 * @return string
	 */
	public function get_title() { return esc_html__( 'KDNA Dynamic Multilingual Image', 'kdna-regional-content' ); }

	/**
	 * Editor icon.
	 *
	 * @return string
	 */
	public function get_icon() { return 'eicon-image'; }

	/**
	 * Editor search keywords.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() { return array( 'kdna', 'multilingual', 'dynamic', 'image' ); }

	/**
	 * Source field type this widget reads.
	 *
	 * @return string
	 */
	protected function source_field_type() { return 'kdna_rc_ml_image'; }

	/**
	 * Register controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'kdna_rc_mlf_section_content',
			array(
				'label' => esc_html__( 'Multilingual Image', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_shared_content_controls( array( 'kdna_rc_ml_image' ) );

		$this->add_group_control( \Elementor\Group_Control_Image_Size::get_type(), array(
			'name'      => 'image_size',
			'default'   => 'large',
			'separator' => 'before',
		) );

		$this->add_control(
			'alt_fallback',
			array(
				'label'       => esc_html__( 'Alt Text Fallback', 'kdna-regional-content' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => esc_html__( 'Used when the WordPress attachment has no alt text set.', 'kdna-regional-content' ),
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

		$this->add_responsive_control( 'width', array(
			'label'      => esc_html__( 'Width', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', '%', 'vw' ),
			'range'      => array( 'px' => array( 'min' => 50, 'max' => 1600 ), '%' => array( 'min' => 10, 'max' => 100 ) ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf img' => 'width: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'height', array(
			'label'      => esc_html__( 'Height', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'vh' ),
			'range'      => array( 'px' => array( 'min' => 50, 'max' => 1200 ) ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf img' => 'height: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_control( 'object_fit', array(
			'label'     => esc_html__( 'Object Fit', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'options'   => array(
				''        => esc_html__( 'Default', 'kdna-regional-content' ),
				'cover'   => 'cover',
				'contain' => 'contain',
				'fill'    => 'fill',
				'none'    => 'none',
			),
			'default'   => '',
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf img' => 'object-fit: {{VALUE}};' ),
		) );

		$this->add_control( 'object_position', array(
			'label'     => esc_html__( 'Object Position', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'options'   => array(
				'center center' => 'Center',
				'top left'      => 'Top Left',
				'top center'    => 'Top Center',
				'top right'     => 'Top Right',
				'center left'   => 'Center Left',
				'center right'  => 'Center Right',
				'bottom left'   => 'Bottom Left',
				'bottom center' => 'Bottom Center',
				'bottom right'  => 'Bottom Right',
			),
			'default'   => 'center center',
			'condition' => array( 'object_fit!' => '' ),
			'selectors' => array( '{{WRAPPER}} .kdna-rc-mlf img' => 'object-position: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'image_border',
			'selector' => '{{WRAPPER}} .kdna-rc-mlf img',
		) );

		$this->add_responsive_control( 'image_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-mlf img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
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

		$size_settings = isset( $settings['image_size_size'] ) ? (string) $settings['image_size_size'] : 'large';
		$fallback      = isset( $settings['fallback_behaviour'] ) ? (string) $settings['fallback_behaviour'] : 'default';
		$alt_fallback  = isset( $settings['alt_fallback'] ) ? (string) $settings['alt_fallback'] : '';

		$stored = get_post_meta( $post_id, $field, true );
		$values = $this->build_value_map( $stored );

		// Resolve attachment IDs to URLs and alt text per language.
		$resolved = array();
		foreach ( $values as $slug => $att_id ) {
			$id = is_numeric( $att_id ) ? (int) $att_id : 0;
			if ( $id <= 0 ) { continue; }
			$url = wp_get_attachment_image_url( $id, $size_settings );
			if ( ! $url ) { continue; }
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === $alt ) { $alt = $alt_fallback; }
			$resolved[ $slug ] = array( 'url' => $url, 'alt' => $alt );
		}

		$has_default = isset( $resolved['default'] );
		if ( ! $has_default && 'hide' === $fallback ) {
			return;
		}

		$default_url = $has_default ? $resolved['default']['url'] : '';
		$default_alt = $has_default ? $resolved['default']['alt'] : $alt_fallback;

		$wrapper_attrs = 'class="kdna-rc-mlf"';
		foreach ( $resolved as $slug => $data ) {
			if ( 'default' === $slug ) { continue; }
			$wrapper_attrs .= ' data-kdna-mlf-' . esc_attr( $slug ) . '="' . esc_attr( $data['url'] ) . '"';
			$wrapper_attrs .= ' data-kdna-mlf-alt-' . esc_attr( $slug ) . '="' . esc_attr( $data['alt'] ) . '"';
		}

		echo '<div ' . $wrapper_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs assembled with esc_attr above.
		if ( '' !== $default_url ) {
			printf(
				'<img src="%1$s" alt="%2$s" loading="lazy" />',
				esc_url( $default_url ),
				esc_attr( $default_alt )
			);
		} elseif ( 'placeholder' === $fallback ) {
			$placeholder = isset( $settings['fallback_placeholder'] ) ? (string) $settings['fallback_placeholder'] : '';
			echo esc_html( $placeholder );
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
			<# if ( settings.field_source ) { #>
				<img src="<?php echo esc_url( admin_url( 'images/wordpress-logo.svg' ) ); ?>" alt="" />
			<# } else { #>
				<p><?php echo esc_html__( 'Choose a multilingual image field on the Content tab.', 'kdna-regional-content' ); ?></p>
			<# } #>
		</div>
		<?php
	}
}
