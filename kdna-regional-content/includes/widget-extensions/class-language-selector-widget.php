<?php
/**
 * KDNA Language Selector Elementor widget.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Language_Selector_Widget
 *
 * Custom Elementor widget rendering an accessible language selector.
 *
 * Why a custom widget rather than a native <select>: the Style tab below
 * exposes full Elementor styling for the trigger button (typography,
 * borders, padding, hover/focus states, box shadow), the dropdown panel
 * (position, width, padding, shadow), the option rows (hover and selected
 * states), and the dropdown arrow icon. None of that is reachable on a
 * native <select> across browsers, so we render an ARIA combobox and let
 * Elementor style every part of it.
 *
 * Implementation lives in three sections of this file:
 *   1. register_controls() : Content + Style controls.
 *   2. render()            : PHP server-side render for the front end.
 *   3. content_template()  : Underscore.js template for the editor preview.
 *
 * The widget interacts with the Stage 10 KDNA_RC_Language_Detector AJAX
 * endpoint (kdna_rc_set_language) and with the Stage 5+ frontend.js
 * variant swap. Selection behaviour is configurable: page reload by
 * default (recommended for v1, guarantees PHP-rendered theme strings
 * also refresh) or a live in-page swap that re-runs the variant pass.
 */
class KDNA_RC_Language_Selector_Widget extends \Elementor\Widget_Base {

	/**
	 * Internal Elementor widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'kdna-rc-language-selector';
	}

	/**
	 * Editor display title.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'KDNA Language Selector', 'kdna-regional-content' );
	}

	/**
	 * Editor icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-globe';
	}

	/**
	 * Editor categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'kdna-widgets' );
	}

	/**
	 * Keywords to surface the widget in the editor's search.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'kdna', 'language', 'selector', 'translate', 'i18n', 'flag' );
	}

	/**
	 * Disable the inner widget wrapper when atomic markup is enabled.
	 *
	 * Per the Elementor Atomic conventions documented in the brief: when
	 * e_optimized_markup is active the rendered widget should not include
	 * .elementor-widget-container. Returning false here lets our render()
	 * output the dropdown root directly inside .elementor-widget.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper() {
		if ( method_exists( '\\Elementor\\Plugin', 'instance' ) ) {
			$features = \Elementor\Plugin::instance()->experiments;
			if ( $features && method_exists( $features, 'is_feature_active' ) && $features->is_feature_active( 'e_optimized_markup' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Register every editor control for this widget.
	 *
	 * Content controls drive behaviour. Style controls below drive
	 * presentation across the trigger button, dropdown panel, option rows,
	 * and arrow icon, with hover/focus states and responsive variants.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Register the Content tab controls.
	 *
	 * @return void
	 */
	private function register_content_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_content',
			array(
				'label' => esc_html__( 'Language Selector', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'display_mode',
			array(
				'label'   => esc_html__( 'Display Mode', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'text'      => array(
						'title' => esc_html__( 'Text Only', 'kdna-regional-content' ),
						'icon'  => 'eicon-typography',
					),
					'flag'      => array(
						'title' => esc_html__( 'Flag Only', 'kdna-regional-content' ),
						'icon'  => 'eicon-flag',
					),
					'flag_text' => array(
						'title' => esc_html__( 'Text and Flag', 'kdna-regional-content' ),
						'icon'  => 'eicon-globe',
					),
				),
				'default' => 'flag_text',
				'toggle'  => false,
			)
		);

		$this->add_control(
			'show_current_first',
			array(
				'label'        => esc_html__( 'Show Current Language First', 'kdna-regional-content' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'kdna-regional-content' ),
				'label_off'    => esc_html__( 'Off', 'kdna-regional-content' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$language_options = array();
		foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
			$language_options[ $language['slug'] ] = $language['name'];
		}

		$this->add_control(
			'languages_to_include',
			array(
				'label'       => esc_html__( 'Languages to Include', 'kdna-regional-content' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $language_options,
				'default'     => array(),
				'label_block' => true,
				'description' => esc_html__( 'Leave empty to show every configured language. Order follows the Languages tab.', 'kdna-regional-content' ),
			)
		);

		$this->add_control(
			'on_select',
			array(
				'label'   => esc_html__( 'Behaviour on Selection', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'reload' => array(
						'title' => esc_html__( 'Reload page (recommended)', 'kdna-regional-content' ),
						'icon'  => 'eicon-loop',
					),
					'live'   => array(
						'title' => esc_html__( 'Live swap (no reload)', 'kdna-regional-content' ),
						'icon'  => 'eicon-arrow-right',
					),
				),
				'default' => 'reload',
				'toggle'  => false,
			)
		);

		$this->add_control(
			'on_select_description',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Reload guarantees PHP-rendered theme strings refresh too. Live swap is faster but only updates widgets that have language variants.', 'kdna-regional-content' ),
				'content_classes' => 'elementor-control-field-description',
			)
		);

		$this->add_control(
			'dropdown_position',
			array(
				'label'   => esc_html__( 'Dropdown Position', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'below' => esc_html__( 'Below trigger', 'kdna-regional-content' ),
					'above' => esc_html__( 'Above trigger', 'kdna-regional-content' ),
				),
				'default' => 'below',
			)
		);

		$this->add_control(
			'dropdown_width',
			array(
				'label'   => esc_html__( 'Dropdown Width', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'auto'    => esc_html__( 'Auto (match trigger)', 'kdna-regional-content' ),
					'fixed'   => esc_html__( 'Fixed width', 'kdna-regional-content' ),
				),
				'default' => 'auto',
			)
		);

		$this->add_control(
			'dropdown_width_fixed',
			array(
				'label'      => esc_html__( 'Fixed Width', 'kdna-regional-content' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array( 'min' => 100, 'max' => 600 ),
					'em' => array( 'min' => 5, 'max' => 40 ),
					'%'  => array( 'min' => 25, 'max' => 100 ),
				),
				'default'    => array( 'unit' => 'px', 'size' => 220 ),
				'condition'  => array( 'dropdown_width' => 'fixed' ),
				'selectors'  => array(
					'{{WRAPPER}} .kdna-rc-ls-panel' => 'min-width: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'arrow_icon',
			array(
				'label'   => esc_html__( 'Dropdown Arrow Icon', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'fas fa-chevron-down',
					'library' => 'fa-solid',
				),
				'skin'    => 'inline',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the Style tab controls.
	 *
	 * Three sections: trigger button (with hover/focus tabs), dropdown
	 * panel (with options state tabs), and arrow icon. Every visible part
	 * of the widget is reachable here.
	 *
	 * @return void
	 */
	private function register_style_controls() {
		$this->register_trigger_style_controls();
		$this->register_flag_style_controls();
		$this->register_dropdown_panel_style_controls();
		$this->register_dropdown_option_style_controls();
		$this->register_arrow_style_controls();
	}

	/**
	 * Trigger button: typography, colours, border, padding, shadow, hover/focus states.
	 *
	 * @return void
	 */
	private function register_trigger_style_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_trigger_style',
			array(
				'label' => esc_html__( 'Trigger Button', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'trigger_typography',
				'selector' => '{{WRAPPER}} .kdna-rc-ls-trigger',
			)
		);

		$this->add_responsive_control(
			'trigger_padding',
			array(
				'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .kdna-rc-ls-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'trigger_text_flag_gap',
			array(
				'label'      => esc_html__( 'Flag and Text Spacing', 'kdna-regional-content' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array(
					'{{WRAPPER}} .kdna-rc-ls-trigger,{{WRAPPER}} .kdna-rc-ls-option' => '--kdna-rc-ls-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'trigger_state_tabs' );

		$this->start_controls_tab( 'trigger_state_normal', array( 'label' => esc_html__( 'Normal', 'kdna-regional-content' ) ) );
		$this->add_control( 'trigger_text_color', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger' => 'color: {{VALUE}};' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Background::get_type(), array(
			'name'     => 'trigger_background',
			'types'    => array( 'classic', 'gradient' ),
			'selector' => '{{WRAPPER}} .kdna-rc-ls-trigger',
			'fields_options' => array( 'background' => array( 'default' => 'classic' ) ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'trigger_border',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-trigger',
		) );
		$this->add_responsive_control( 'trigger_border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-trigger' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'trigger_shadow',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-trigger',
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'trigger_state_hover', array( 'label' => esc_html__( 'Hover', 'kdna-regional-content' ) ) );
		$this->add_control( 'trigger_text_color_hover', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:hover' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_bg_color_hover', array(
			'label'     => esc_html__( 'Background Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:hover' => 'background-color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_border_color_hover', array(
			'label'     => esc_html__( 'Border Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:hover' => 'border-color: {{VALUE}};' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'trigger_shadow_hover',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-trigger:hover',
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'trigger_state_focus', array( 'label' => esc_html__( 'Focus', 'kdna-regional-content' ) ) );
		$this->add_control( 'trigger_text_color_focus', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:focus,{{WRAPPER}} .kdna-rc-ls-trigger:focus-visible' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_bg_color_focus', array(
			'label'     => esc_html__( 'Background Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:focus,{{WRAPPER}} .kdna-rc-ls-trigger:focus-visible' => 'background-color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_outline_focus', array(
			'label'       => esc_html__( 'Focus Outline', 'kdna-regional-content' ),
			'type'        => \Elementor\Controls_Manager::COLOR,
			'description' => esc_html__( 'Used as the focus outline. Leave blank for the browser default.', 'kdna-regional-content' ),
			'selectors'   => array( '{{WRAPPER}} .kdna-rc-ls-trigger:focus-visible' => 'outline-color: {{VALUE}}; outline-style: solid; outline-width: 2px; outline-offset: 2px;' ),
		) );
		$this->end_controls_tab();

		$this->end_controls_tabs();
		$this->end_controls_section();
	}

	/**
	 * Flag size controls (only relevant in flag-bearing display modes).
	 *
	 * @return void
	 */
	private function register_flag_style_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_flag_style',
			array(
				'label'      => esc_html__( 'Flag', 'kdna-regional-content' ),
				'tab'        => \Elementor\Controls_Manager::TAB_STYLE,
				'conditions' => array(
					'terms' => array(
						array(
							'name'     => 'display_mode',
							'operator' => 'in',
							'value'    => array( 'flag', 'flag_text' ),
						),
					),
				),
			)
		);

		$this->add_responsive_control( 'flag_width', array(
			'label'      => esc_html__( 'Width', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 12, 'max' => 64 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 22 ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-flag' => 'width: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'flag_height', array(
			'label'      => esc_html__( 'Height', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 8, 'max' => 64 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 16 ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-flag' => 'height: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_control( 'flag_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-flag' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Dropdown panel: background, border, padding, shadow.
	 *
	 * @return void
	 */
	private function register_dropdown_panel_style_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_panel_style',
			array(
				'label' => esc_html__( 'Dropdown Panel', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control( \Elementor\Group_Control_Background::get_type(), array(
			'name'     => 'panel_background',
			'types'    => array( 'classic', 'gradient' ),
			'selector' => '{{WRAPPER}} .kdna-rc-ls-panel',
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'panel_border',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-panel',
		) );

		$this->add_responsive_control( 'panel_border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'panel_padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-panel' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'panel_shadow',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-panel',
		) );

		$this->add_responsive_control( 'panel_offset', array(
			'label'      => esc_html__( 'Offset from Trigger', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 4 ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-panel' => '--kdna-rc-ls-offset: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Dropdown option rows: typography + per-state colours.
	 *
	 * @return void
	 */
	private function register_dropdown_option_style_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_option_style',
			array(
				'label' => esc_html__( 'Dropdown Options', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'option_typography',
			'selector' => '{{WRAPPER}} .kdna-rc-ls-option',
		) );

		$this->add_responsive_control( 'option_padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-option' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->start_controls_tabs( 'option_state_tabs' );

		$this->start_controls_tab( 'option_state_normal', array( 'label' => esc_html__( 'Normal', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_text_color', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'option_bg_color', array(
			'label'     => esc_html__( 'Background Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option' => 'background-color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'option_state_hover', array( 'label' => esc_html__( 'Hover', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_text_color_hover', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option:hover,{{WRAPPER}} .kdna-rc-ls-option.is-active' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'option_bg_color_hover', array(
			'label'     => esc_html__( 'Background Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option:hover,{{WRAPPER}} .kdna-rc-ls-option.is-active' => 'background-color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'option_state_selected', array( 'label' => esc_html__( 'Selected', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_text_color_selected', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option[aria-selected="true"]' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'option_bg_color_selected', array(
			'label'     => esc_html__( 'Background Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-option[aria-selected="true"]' => 'background-color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->end_controls_tabs();
		$this->end_controls_section();
	}

	/**
	 * Dropdown arrow icon: size, colour, hover colour, spacing.
	 *
	 * @return void
	 */
	private function register_arrow_style_controls() {
		$this->start_controls_section(
			'kdna_rc_lang_section_arrow_style',
			array(
				'label' => esc_html__( 'Dropdown Arrow', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control( 'arrow_size', array(
			'label'      => esc_html__( 'Size', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 8, 'max' => 32 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 12 ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-arrow' => 'font-size: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'arrow_spacing', array(
			'label'      => esc_html__( 'Spacing from Text', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 8 ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-ls-arrow' => 'margin-left: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_control( 'arrow_color', array(
			'label'     => esc_html__( 'Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-arrow' => 'color: {{VALUE}};' ),
		) );

		$this->add_control( 'arrow_color_hover', array(
			'label'     => esc_html__( 'Hover Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-ls-trigger:hover .kdna-rc-ls-arrow' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Resolve the language list to render based on the include filter.
	 *
	 * Returns the configured languages in their saved order, filtered to the
	 * "languages_to_include" set when one was supplied. Returning everything
	 * when the include list is empty matches the v1 default.
	 *
	 * @param array $settings Widget settings.
	 * @return array<int,array>
	 */
	private function resolve_languages( array $settings ) {
		$all     = ( new KDNA_RC_Languages() )->get_all();
		$include = isset( $settings['languages_to_include'] ) && is_array( $settings['languages_to_include'] )
			? array_filter( array_map( 'sanitize_key', $settings['languages_to_include'] ) )
			: array();

		if ( empty( $include ) ) {
			return $all;
		}

		$index = array_flip( $include );
		$out   = array();
		foreach ( $all as $language ) {
			if ( isset( $index[ $language['slug'] ] ) ) {
				$out[] = $language;
			}
		}
		return $out;
	}

	/**
	 * Determine which language is the visitor's current selection.
	 *
	 * Reads the kdna_language cookie when the request actually carries one,
	 * falls back to the configured Default Language, and finally to the
	 * first language in the rendered list. Returns null when nothing is
	 * configured, in which case render() bails early.
	 *
	 * @param array<int,array> $languages Rendered language list.
	 * @return array|null
	 */
	private function resolve_current_language( array $languages ) {
		if ( empty( $languages ) ) {
			return null;
		}

		$cookie = '';
		if ( ! empty( $_COOKIE['kdna_language'] ) ) {
			$cookie = sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) );
		}
		if ( '' !== $cookie ) {
			foreach ( $languages as $language ) {
				if ( $language['slug'] === $cookie ) {
					return $language;
				}
			}
		}

		$settings     = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$default_slug = is_array( $settings ) && isset( $settings['default_language'] ) ? (string) $settings['default_language'] : '';
		if ( '' !== $default_slug ) {
			foreach ( $languages as $language ) {
				if ( $language['slug'] === $default_slug ) {
					return $language;
				}
			}
		}

		return $languages[0];
	}

	/**
	 * Front-end PHP render.
	 *
	 * Outputs an ARIA combobox: a button trigger that toggles a listbox
	 * panel of options. Each option carries data-slug / data-flag / data-name
	 * so the front-end JS can update the trigger label after a live swap
	 * without re-querying the server.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$languages = $this->resolve_languages( is_array( $settings ) ? $settings : array() );

		if ( empty( $languages ) ) {
			if ( \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
				echo '<p class="elementor-panel-alert elementor-panel-alert-info">'
					. esc_html__( 'No languages configured. Add some on the Languages tab in Regional Content.', 'kdna-regional-content' )
					. '</p>';
			}
			return;
		}

		$current = $this->resolve_current_language( $languages );

		$display_mode      = isset( $settings['display_mode'] ) ? (string) $settings['display_mode'] : 'flag_text';
		$show_current      = ! empty( $settings['show_current_first'] ) && 'yes' === $settings['show_current_first'];
		$on_select         = isset( $settings['on_select'] ) && 'live' === $settings['on_select'] ? 'live' : 'reload';
		$position          = isset( $settings['dropdown_position'] ) && 'above' === $settings['dropdown_position'] ? 'above' : 'below';

		// Sort: show the current language first when configured. Stable sort.
		if ( $show_current && $current ) {
			$ordered = array( $current );
			foreach ( $languages as $language ) {
				if ( $language['slug'] !== $current['slug'] ) {
					$ordered[] = $language;
				}
			}
			$languages = $ordered;
		}

		$this->add_render_attribute(
			'wrapper',
			array(
				'class'                  => array(
					'kdna-rc-ls',
					'kdna-rc-ls--mode-' . $display_mode,
					'kdna-rc-ls--pos-' . $position,
				),
				'data-on-select'         => $on_select,
				'data-current'           => $current ? $current['slug'] : '',
			)
		);

		$trigger_id = 'kdna-rc-ls-trigger-' . $this->get_id();
		$listbox_id = 'kdna-rc-ls-listbox-' . $this->get_id();

		?>
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?>>
			<button type="button"
				class="kdna-rc-ls-trigger"
				id="<?php echo esc_attr( $trigger_id ); ?>"
				aria-haspopup="listbox"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
				aria-label="<?php echo esc_attr__( 'Choose language', 'kdna-regional-content' ); ?>">
				<?php if ( $current && in_array( $display_mode, array( 'flag', 'flag_text' ), true ) && '' !== $current['flag'] ) : ?>
					<span class="kdna-rc-ls-flag fi fi-<?php echo esc_attr( $current['flag'] ); ?>" aria-hidden="true"></span>
				<?php endif; ?>
				<?php if ( $current && in_array( $display_mode, array( 'text', 'flag_text' ), true ) ) : ?>
					<span class="kdna-rc-ls-label"><?php echo esc_html( $current['name'] ); ?></span>
				<?php endif; ?>
				<span class="kdna-rc-ls-arrow" aria-hidden="true">
					<?php
					if ( ! empty( $settings['arrow_icon'] ) ) {
						\Elementor\Icons_Manager::render_icon( $settings['arrow_icon'], array( 'aria-hidden' => 'true' ) );
					}
					?>
				</span>
			</button>

			<ul class="kdna-rc-ls-panel"
				id="<?php echo esc_attr( $listbox_id ); ?>"
				role="listbox"
				aria-labelledby="<?php echo esc_attr( $trigger_id ); ?>"
				hidden>
				<?php foreach ( $languages as $language ) :
					$is_selected = $current && $language['slug'] === $current['slug'];
					?>
					<li class="kdna-rc-ls-option<?php echo $is_selected ? ' is-active' : ''; ?>"
						role="option"
						tabindex="-1"
						aria-selected="<?php echo $is_selected ? 'true' : 'false'; ?>"
						data-slug="<?php echo esc_attr( $language['slug'] ); ?>"
						data-name="<?php echo esc_attr( $language['name'] ); ?>"
						data-flag="<?php echo esc_attr( $language['flag'] ); ?>">
						<?php if ( in_array( $display_mode, array( 'flag', 'flag_text' ), true ) && '' !== $language['flag'] ) : ?>
							<span class="kdna-rc-ls-flag fi fi-<?php echo esc_attr( $language['flag'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<?php if ( in_array( $display_mode, array( 'text', 'flag_text' ), true ) ) : ?>
							<span class="kdna-rc-ls-label"><?php echo esc_html( $language['name'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Underscore.js template for the editor preview.
	 *
	 * Editor preview cannot read the live cookie, so it always shows the
	 * first language as the trigger label. The styling controls still
	 * apply because the markup mirrors the front-end render.
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<#
		var displayMode  = settings.display_mode || 'flag_text';
		var position     = settings.dropdown_position === 'above' ? 'above' : 'below';
		var showFlag     = displayMode === 'flag' || displayMode === 'flag_text';
		var showText     = displayMode === 'text' || displayMode === 'flag_text';
		var availableLanguages = ( window.kdnaRC && window.kdnaRC.languages ) ? window.kdnaRC.languages : [];
		var includeRaw = settings.languages_to_include || [];
		var languages = [];
		if ( includeRaw && includeRaw.length ) {
			var includeMap = {};
			_.each( includeRaw, function ( slug ) { includeMap[ slug ] = true; } );
			_.each( availableLanguages, function ( lang ) { if ( includeMap[ lang.slug ] ) { languages.push( lang ); } } );
		} else {
			languages = availableLanguages.slice();
		}
		var current = languages.length ? languages[0] : null;
		#>
		<# if ( ! languages.length ) { #>
			<p class="elementor-panel-alert elementor-panel-alert-info">
				<?php echo esc_html__( 'No languages configured. Add some on the Languages tab in Regional Content.', 'kdna-regional-content' ); ?>
			</p>
		<# } else { #>
		<div class="kdna-rc-ls kdna-rc-ls--mode-{{ displayMode }} kdna-rc-ls--pos-{{ position }}" data-current="{{ current.slug }}">
			<button type="button" class="kdna-rc-ls-trigger" aria-haspopup="listbox" aria-expanded="false">
				<# if ( showFlag && current.flag ) { #>
					<span class="kdna-rc-ls-flag fi fi-{{ current.flag }}" aria-hidden="true"></span>
				<# } #>
				<# if ( showText ) { #>
					<span class="kdna-rc-ls-label">{{{ current.name }}}</span>
				<# } #>
				<span class="kdna-rc-ls-arrow" aria-hidden="true">
					<#
					var iconHTML = elementor.helpers.renderIcon( view, settings.arrow_icon, { 'aria-hidden': 'true' }, 'i', 'object' );
					if ( iconHTML && iconHTML.rendered ) { #>{{{ iconHTML.value }}}<# }
					#>
				</span>
			</button>
			<ul class="kdna-rc-ls-panel" role="listbox" hidden>
				<# _.each( languages, function ( lang ) { #>
					<li class="kdna-rc-ls-option<# if ( current && lang.slug === current.slug ) { #> is-active<# } #>"
						role="option"
						aria-selected="<# if ( current && lang.slug === current.slug ) { #>true<# } else { #>false<# } #>"
						data-slug="{{ lang.slug }}">
						<# if ( showFlag && lang.flag ) { #>
							<span class="kdna-rc-ls-flag fi fi-{{ lang.flag }}" aria-hidden="true"></span>
						<# } #>
						<# if ( showText ) { #>
							<span class="kdna-rc-ls-label">{{{ lang.name }}}</span>
						<# } #>
					</li>
				<# } ); #>
			</ul>
		</div>
		<# } #>
		<?php
	}
}
