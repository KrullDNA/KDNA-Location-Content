<?php
/**
 * Elementor element visibility controls and renderer.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Elementor_Visibility
 *
 * Adds a "Regional Visibility" controls section to every Elementor widget,
 * section, and container, then writes a data-kdna-show-in attribute onto
 * the rendered wrapper. The matching client-side filter in frontend.js
 * removes (or hides) wrappers whose value does not include the visitor's
 * region. Server-side rendering is left untouched so WP Rocket and other
 * full-page caches keep working.
 */
class KDNA_RC_Elementor_Visibility {

	/**
	 * Switcher control id.
	 *
	 * @var string
	 */
	const CTRL_ENABLED = 'kdna_rc_visibility_enabled';

	/**
	 * Multi-select control id.
	 *
	 * @var string
	 */
	const CTRL_REGIONS = 'kdna_rc_visibility_regions';

	/**
	 * Wire up Elementor controls injection and render hooks.
	 *
	 * Bails when Elementor is not active so non-Elementor sites incur no
	 * overhead and the controls do not appear on irrelevant element types.
	 *
	 * @return void
	 */
	public function init() {
		// Register on plugins_loaded callback's tail so Elementor classes are
		// available; skip cleanly when the page builder is not installed.
		add_action( 'elementor/init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Bind the controls and render hooks once Elementor has loaded.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Controls injection points specified in the brief: common controls
		// for widgets, advanced for sections, layout for containers.
		add_action( 'elementor/element/common/_section_style/after_section_end', array( $this, 'register_controls' ), 10, 2 );
		add_action( 'elementor/element/section/section_advanced/after_section_end', array( $this, 'register_controls' ), 10, 2 );
		add_action( 'elementor/element/container/section_layout/after_section_end', array( $this, 'register_controls' ), 10, 2 );

		// Render hooks: write the data attribute onto the wrapper before the
		// element is output to the page.
		add_action( 'elementor/frontend/widget/before_render', array( $this, 'apply_visibility' ) );
		add_action( 'elementor/frontend/section/before_render', array( $this, 'apply_visibility' ) );
		add_action( 'elementor/frontend/container/before_render', array( $this, 'apply_visibility' ) );
	}

	/**
	 * Inject the Regional Visibility controls section into an element.
	 *
	 * Receives the element instance (Element_Base) and the args used to call
	 * the controls registration hook. We declare a fresh tab so editors find
	 * regional rules in a predictable place across every element type.
	 *
	 * @param mixed $element Element_Base instance from Elementor.
	 * @param array $args    Hook args (unused).
	 * @return void
	 */
	public function register_controls( $element, $args = array() ) {
		unset( $args );

		if ( ! is_object( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}

		$regions = ( new KDNA_RC_Regions() )->get_all();
		if ( empty( $regions ) ) {
			// Still register the section so editors see why nothing happens,
			// but offer a hint instead of an empty multi-select.
			$element->start_controls_section(
				'kdna_rc_visibility_section',
				array(
					'label' => __( 'Regional Visibility', 'kdna-regional-content' ),
					'tab'   => defined( 'Elementor\\Controls_Manager::TAB_ADVANCED' ) ? \Elementor\Controls_Manager::TAB_ADVANCED : 'advanced',
				)
			);

			$element->add_control(
				'kdna_rc_visibility_no_regions',
				array(
					'type'            => defined( 'Elementor\\Controls_Manager::RAW_HTML' ) ? \Elementor\Controls_Manager::RAW_HTML : 'raw_html',
					'raw'             => sprintf(
						/* translators: %s: link to the Regions admin tab. */
						__( 'No regions are configured yet. Add some on the %s.', 'kdna-regional-content' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=kdna-regional-content&tab=regions' ) ) . '" target="_blank">' . esc_html__( 'Regions tab', 'kdna-regional-content' ) . '</a>'
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);

			$element->end_controls_section();
			return;
		}

		$choices = array();
		foreach ( $regions as $region ) {
			$choices[ $region['slug'] ] = $region['name'];
		}

		$element->start_controls_section(
			'kdna_rc_visibility_section',
			array(
				'label' => __( 'Regional Visibility', 'kdna-regional-content' ),
				'tab'   => defined( 'Elementor\\Controls_Manager::TAB_ADVANCED' ) ? \Elementor\Controls_Manager::TAB_ADVANCED : 'advanced',
			)
		);

		$element->add_control(
			self::CTRL_ENABLED,
			array(
				'label'        => __( 'Restrict by Region', 'kdna-regional-content' ),
				'type'         => defined( 'Elementor\\Controls_Manager::SWITCHER' ) ? \Elementor\Controls_Manager::SWITCHER : 'switcher',
				'label_on'     => __( 'On', 'kdna-regional-content' ),
				'label_off'    => __( 'Off', 'kdna-regional-content' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'When on, this element shows only for visitors in the regions selected below. Other regions never see it.', 'kdna-regional-content' ),
			)
		);

		$element->add_control(
			self::CTRL_REGIONS,
			array(
				'label'       => __( 'Show in Regions', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::SELECT2' ) ? \Elementor\Controls_Manager::SELECT2 : 'select2',
				'multiple'    => true,
				'options'     => $choices,
				'default'     => array(),
				'label_block' => true,
				'condition'   => array(
					self::CTRL_ENABLED => 'yes',
				),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Add data-kdna-show-in to the wrapper when restrictions are active.
	 *
	 * Uses _wrapper as the attribute target which works in both classic and
	 * atomic markup modes for sections and containers, and for widgets too
	 * (the widget outer .elementor-widget div). Inner-wrapper handling per
	 * has_widget_inner_wrapper() is irrelevant here because we are tagging
	 * the outer element the JS filter walks.
	 *
	 * @param mixed $element Element_Base instance from Elementor.
	 * @return void
	 */
	public function apply_visibility( $element ) {
		if ( ! is_object( $element ) || ! method_exists( $element, 'get_settings_for_display' ) ) {
			return;
		}

		$enabled = '';
		$regions = array();

		if ( method_exists( $element, 'get_settings_for_display' ) ) {
			$settings = $element->get_settings_for_display();
			$enabled  = isset( $settings[ self::CTRL_ENABLED ] ) ? (string) $settings[ self::CTRL_ENABLED ] : '';
			$regions  = isset( $settings[ self::CTRL_REGIONS ] ) ? (array) $settings[ self::CTRL_REGIONS ] : array();
		}

		if ( 'yes' !== $enabled || empty( $regions ) ) {
			return;
		}

		$slugs = array_values( array_filter( array_map( 'sanitize_key', $regions ) ) );
		if ( empty( $slugs ) ) {
			return;
		}

		// Pick the right attribute key for this element type. Widgets use a
		// different default render-attribute key in atomic mode, but the
		// _wrapper key always exists and is what we want. For widgets in
		// atomic mode (no inner wrapper) the data attribute still lands on
		// the outer .elementor-widget node, which is what the JS filter
		// walks. has_widget_inner_wrapper() is checked here for completeness
		// so future tweaks can vary behaviour cleanly.
		$has_inner = method_exists( $element, 'has_widget_inner_wrapper' ) ? (bool) $element->has_widget_inner_wrapper() : true;
		unset( $has_inner ); // No behavioural branch for now; hook is documented for clarity.

		if ( method_exists( $element, 'add_render_attribute' ) ) {
			$element->add_render_attribute( '_wrapper', 'data-kdna-show-in', implode( ',', $slugs ) );
		}
	}
}
