<?php
/**
 * KDNA Region Selector Elementor widget.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Region_Selector_Widget
 *
 * Elementor widget rendering a dropdown that lets the visitor swap regions.
 * Parallels KDNA_RC_Language_Selector_Widget in shape (Content + Style
 * controls, ARIA combobox pattern, flag/text/flag_text modes) but differs
 * in navigation semantics:
 *
 *   - Language Selector: POSTs to the ajax endpoint to set the cookie,
 *     then reloads / live-swaps.
 *   - Region Selector: renders real <a href="/{region}/{same-path}">
 *     links. Clicks navigate the browser directly to the equivalent URL
 *     on the target region's prefix. KDNA_RC_URL_Routing handles the
 *     cookie sync server-side on the new request, so no AJAX is needed
 *     and the links are SEO-friendly (crawlable, right-click / open in
 *     new tab both work).
 *
 * Server-computed hrefs are correct for the URL being served. On sites
 * that fragment-cache the header separately from the page, the client
 * JS rewrites hrefs from window.location.pathname on load so cached
 * headers still point at the current page.
 */
class KDNA_RC_Region_Selector_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'kdna-rc-region-selector';
	}

	public function get_title() {
		return esc_html__( 'KDNA Region Selector', 'kdna-regional-content' );
	}

	public function get_icon() {
		return 'eicon-map-pin';
	}

	public function get_categories() {
		return array( 'kdna-widgets' );
	}

	public function get_keywords() {
		return array( 'kdna', 'region', 'selector', 'country', 'geo', 'flag', 'switcher' );
	}

	public function has_widget_inner_wrapper(): bool {
		if ( method_exists( '\\Elementor\\Plugin', 'instance' ) ) {
			$features = \Elementor\Plugin::instance()->experiments;
			if ( $features && method_exists( $features, 'is_feature_active' ) && $features->is_feature_active( 'e_optimized_markup' ) ) {
				return false;
			}
		}
		return true;
	}

	protected function register_controls() {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	private function register_content_controls() {
		$this->start_controls_section(
			'kdna_rc_region_section_content',
			array(
				'label' => esc_html__( 'Region Selector', 'kdna-regional-content' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control( 'display_mode', array(
			'label'   => esc_html__( 'Trigger Display', 'kdna-regional-content' ),
			'type'    => \Elementor\Controls_Manager::CHOOSE,
			'options' => array(
				'icon'      => array( 'title' => esc_html__( 'Icon Only', 'kdna-regional-content' ), 'icon' => 'eicon-globe' ),
				'flag'      => array( 'title' => esc_html__( 'Flag Only', 'kdna-regional-content' ), 'icon' => 'eicon-flag' ),
				'text'      => array( 'title' => esc_html__( 'Text Only', 'kdna-regional-content' ), 'icon' => 'eicon-typography' ),
				'flag_text' => array( 'title' => esc_html__( 'Text and Flag', 'kdna-regional-content' ), 'icon' => 'eicon-post-title' ),
			),
			'default' => 'icon',
			'toggle'  => false,
			'description' => esc_html__( 'What the visible header trigger shows. The dropdown always shows the flag and name for each region.', 'kdna-regional-content' ),
		) );

		$this->add_control( 'trigger_icon', array(
			'label'     => esc_html__( 'Trigger Icon', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::ICONS,
			'default'   => array( 'value' => 'fas fa-globe', 'library' => 'fa-solid' ),
			'skin'      => 'inline',
			'condition' => array( 'display_mode' => 'icon' ),
		) );

		$this->add_control( 'show_current_first', array(
			'label'        => esc_html__( 'Show Current Region First', 'kdna-regional-content' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$region_options = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			$region_options[ $region['slug'] ] = $region['name'];
		}

		$this->add_control( 'regions_to_include', array(
			'label'       => esc_html__( 'Regions to Include', 'kdna-regional-content' ),
			'type'        => \Elementor\Controls_Manager::SELECT2,
			'multiple'    => true,
			'options'     => $region_options,
			'default'     => array(),
			'label_block' => true,
			'description' => esc_html__( 'Leave empty to show every configured region.', 'kdna-regional-content' ),
		) );

		$this->add_control( 'dropdown_position', array(
			'label'   => esc_html__( 'Dropdown Position', 'kdna-regional-content' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => array(
				'below' => esc_html__( 'Below trigger', 'kdna-regional-content' ),
				'above' => esc_html__( 'Above trigger', 'kdna-regional-content' ),
			),
			'default' => 'below',
		) );

		$this->add_control( 'dropdown_width', array(
			'label'   => esc_html__( 'Dropdown Width', 'kdna-regional-content' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => array(
				'auto'  => esc_html__( 'Auto (match trigger)', 'kdna-regional-content' ),
				'fixed' => esc_html__( 'Fixed width', 'kdna-regional-content' ),
			),
			'default' => 'auto',
		) );

		$this->add_control( 'dropdown_width_fixed', array(
			'label'      => esc_html__( 'Fixed Width', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em', '%' ),
			'range'      => array(
				'px' => array( 'min' => 100, 'max' => 600 ),
				'em' => array( 'min' => 5,   'max' => 40 ),
				'%'  => array( 'min' => 25,  'max' => 100 ),
			),
			'default'    => array( 'unit' => 'px', 'size' => 220 ),
			'condition'  => array( 'dropdown_width' => 'fixed' ),
			'selectors'  => array(
				'{{WRAPPER}} .kdna-rc-rs-panel' => 'min-width: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->add_control( 'arrow_icon', array(
			'label'     => esc_html__( 'Dropdown Arrow Icon', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::ICONS,
			'default'   => array( 'value' => 'fas fa-chevron-down', 'library' => 'fa-solid' ),
			'skin'      => 'inline',
			'condition' => array( 'display_mode!' => 'icon' ),
		) );

		$this->end_controls_section();
	}

	private function register_style_controls() {
		// Trigger button styling
		$this->start_controls_section( 'kdna_rc_region_section_trigger_style', array(
			'label' => esc_html__( 'Trigger Button', 'kdna-regional-content' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'trigger_typography',
			'selector' => '{{WRAPPER}} .kdna-rc-rs-trigger',
		) );

		$this->start_controls_tabs( 'kdna_rc_region_trigger_states' );

		$this->start_controls_tab( 'trigger_normal_tab', array( 'label' => esc_html__( 'Normal', 'kdna-regional-content' ) ) );

		$this->add_control( 'trigger_color', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-trigger' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_bg', array(
			'label'     => esc_html__( 'Background', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-trigger' => 'background-color: {{VALUE}};' ),
		) );

		$this->end_controls_tab();

		$this->start_controls_tab( 'trigger_hover_tab', array( 'label' => esc_html__( 'Hover', 'kdna-regional-content' ) ) );

		$this->add_control( 'trigger_color_hover', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-trigger:hover' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'trigger_bg_hover', array(
			'label'     => esc_html__( 'Background', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-trigger:hover' => 'background-color: {{VALUE}};' ),
		) );

		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'trigger_border',
			'selector' => '{{WRAPPER}} .kdna-rc-rs-trigger',
		) );

		$this->add_responsive_control( 'trigger_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-rs-trigger' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'trigger_padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-rs-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->end_controls_section();

		// Dropdown panel styling
		$this->start_controls_section( 'kdna_rc_region_section_panel_style', array(
			'label' => esc_html__( 'Dropdown Panel', 'kdna-regional-content' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'panel_bg', array(
			'label'     => esc_html__( 'Background', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-panel' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'panel_border',
			'selector' => '{{WRAPPER}} .kdna-rc-rs-panel',
		) );

		$this->add_responsive_control( 'panel_radius', array(
			'label'      => esc_html__( 'Border Radius', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-rs-panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'panel_shadow',
			'selector' => '{{WRAPPER}} .kdna-rc-rs-panel',
		) );

		$this->end_controls_section();

		// Option row styling
		$this->start_controls_section( 'kdna_rc_region_section_option_style', array(
			'label' => esc_html__( 'Options', 'kdna-regional-content' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'option_typography',
			'selector' => '{{WRAPPER}} .kdna-rc-rs-option',
		) );

		$this->start_controls_tabs( 'kdna_rc_region_option_states' );
		$this->start_controls_tab( 'option_normal_tab', array( 'label' => esc_html__( 'Normal', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_color', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-option a' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'option_hover_tab', array( 'label' => esc_html__( 'Hover', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_color_hover', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-option:hover a' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'option_bg_hover', array(
			'label'     => esc_html__( 'Background', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-option:hover' => 'background-color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();

		$this->start_controls_tab( 'option_active_tab', array( 'label' => esc_html__( 'Selected', 'kdna-regional-content' ) ) );
		$this->add_control( 'option_color_active', array(
			'label'     => esc_html__( 'Text Colour', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-option.is-active a' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'option_bg_active', array(
			'label'     => esc_html__( 'Background', 'kdna-regional-content' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .kdna-rc-rs-option.is-active' => 'background-color: {{VALUE}};' ),
		) );
		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->add_responsive_control( 'option_padding', array(
			'label'      => esc_html__( 'Padding', 'kdna-regional-content' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em' ),
			'selectors'  => array( '{{WRAPPER}} .kdna-rc-rs-option' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	/**
	 * Derive a flag-icons code (lowercased 2-letter ISO) for a region.
	 *
	 * Regions don't store a flag field the way languages do — they store
	 * a countries[] array of ISO codes because a region can be a group
	 * (e.g. "EU" covering 27 countries). Best-effort: use the first
	 * country's ISO code, lowercased, so single-country regions like
	 * Australia (['AU']) render as "au" for `fi fi-au`.
	 *
	 * Returns '' when no ISO country is available, in which case the
	 * flag span is skipped entirely so the row stays clean.
	 *
	 * @param array $region Region record.
	 * @return string
	 */
	private function region_flag_code( array $region ) {
		if ( empty( $region['countries'] ) || ! is_array( $region['countries'] ) ) {
			return '';
		}
		$first = strtolower( (string) reset( $region['countries'] ) );
		if ( 'uk' === $first ) { $first = 'gb'; }
		return preg_match( '/^[a-z]{2}$/', $first ) ? $first : '';
	}

	/**
	 * Resolve region list to render based on the include filter.
	 *
	 * @param array $settings Widget settings.
	 * @return array<int,array>
	 */
	private function resolve_regions( array $settings ) {
		$all     = ( new KDNA_RC_Regions() )->get_all();
		$include = isset( $settings['regions_to_include'] ) && is_array( $settings['regions_to_include'] )
			? array_filter( array_map( 'sanitize_key', $settings['regions_to_include'] ) )
			: array();

		if ( empty( $include ) ) {
			return $all;
		}
		$index = array_flip( $include );
		$out   = array();
		foreach ( $all as $region ) {
			if ( isset( $region['slug'] ) && isset( $index[ $region['slug'] ] ) ) {
				$out[] = $region;
			}
		}
		return $out;
	}

	/**
	 * Which region is currently active.
	 *
	 * URL query var (set by KDNA_RC_URL_Routing for /au/ style prefixes)
	 * first, then the kdna_region cookie, then the first configured region.
	 *
	 * @param array<int,array> $regions Rendered region list.
	 * @return array|null
	 */
	private function resolve_current_region( array $regions ) {
		if ( empty( $regions ) ) {
			return null;
		}

		$slug = '';
		global $wp;
		if ( $wp instanceof WP && ! empty( $wp->query_vars['kdna_region'] ) ) {
			$slug = sanitize_key( (string) $wp->query_vars['kdna_region'] );
		}
		if ( '' === $slug && ! empty( $_COOKIE['kdna_region'] ) ) {
			$slug = sanitize_key( wp_unslash( $_COOKIE['kdna_region'] ) );
		}
		if ( '' !== $slug ) {
			foreach ( $regions as $region ) {
				if ( $region['slug'] === $slug ) {
					return $region;
				}
			}
		}

		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$default  = is_array( $settings ) && ! empty( $settings['default_region'] ) ? (string) $settings['default_region'] : '';
		if ( '' !== $default ) {
			foreach ( $regions as $region ) {
				if ( $region['slug'] === $default ) {
					return $region;
				}
			}
		}
		return $regions[0];
	}

	/**
	 * Build the URL a click on the target region should navigate to.
	 *
	 * Takes the current request path, strips any /{region}/ or
	 * /{region}/{language}/ prefix the URL already carries, then prepends
	 * the target region (preserving any language segment). Query string
	 * and fragment are preserved.
	 *
	 * @param string $target_slug Region slug to switch to.
	 * @return string
	 */
	private function build_target_url( $target_slug ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query       = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$relative  = ltrim( $path, '/' );
		if ( '' !== $home_path && 0 === strpos( $relative, $home_path . '/' ) ) {
			$relative = substr( $relative, strlen( $home_path ) + 1 );
		} elseif ( '' !== $home_path && $relative === $home_path ) {
			$relative = '';
		}

		$region_slugs   = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			if ( ! empty( $region['slug'] ) ) {
				$region_slugs[] = strtolower( (string) $region['slug'] );
			}
		}
		$language_slugs = array();
		if ( class_exists( 'KDNA_RC_Languages' ) ) {
			foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
				if ( ! empty( $language['slug'] ) ) {
					$language_slugs[] = strtolower( (string) $language['slug'] );
				}
			}
		}

		$parts        = '' === $relative ? array() : explode( '/', $relative );
		$existing_lang = '';
		if ( ! empty( $parts ) && in_array( strtolower( $parts[0] ), $region_slugs, true ) ) {
			array_shift( $parts );
		}
		if ( ! empty( $parts ) && in_array( strtolower( $parts[0] ), $language_slugs, true ) ) {
			$existing_lang = strtolower( array_shift( $parts ) );
		}

		$new_segments = array( $target_slug );
		if ( '' !== $existing_lang ) {
			$new_segments[] = $existing_lang;
		}
		foreach ( $parts as $segment ) {
			if ( '' !== $segment ) {
				$new_segments[] = $segment;
			}
		}

		$new_path = '/';
		if ( '' !== $home_path ) {
			$new_path .= $home_path . '/';
		}
		$new_path .= implode( '/', $new_segments );

		// Mirror trailing-slash behaviour of the request.
		if ( substr( $path, -1 ) === '/' && substr( $new_path, -1 ) !== '/' ) {
			$new_path .= '/';
		}
		if ( '' !== $query ) {
			$new_path .= '?' . $query;
		}
		return $new_path;
	}

	/**
	 * Front-end render.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$regions  = $this->resolve_regions( is_array( $settings ) ? $settings : array() );

		if ( empty( $regions ) ) {
			if ( \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
				echo '<p class="elementor-panel-alert elementor-panel-alert-info">'
					. esc_html__( 'No regions configured. Add some on the Regions tab in Regional Content.', 'kdna-regional-content' )
					. '</p>';
			}
			return;
		}

		$current      = $this->resolve_current_region( $regions );
		$display_mode = isset( $settings['display_mode'] ) ? (string) $settings['display_mode'] : 'flag_text';
		$show_current = ! empty( $settings['show_current_first'] ) && 'yes' === $settings['show_current_first'];
		$position     = isset( $settings['dropdown_position'] ) && 'above' === $settings['dropdown_position'] ? 'above' : 'below';

		if ( $show_current && $current ) {
			$ordered = array( $current );
			foreach ( $regions as $region ) {
				if ( $region['slug'] !== $current['slug'] ) {
					$ordered[] = $region;
				}
			}
			$regions = $ordered;
		}

		$this->add_render_attribute( 'wrapper', array(
			'class'        => array(
				'kdna-rc-rs',
				'kdna-rc-rs--mode-' . $display_mode,
				'kdna-rc-rs--pos-' . $position,
			),
			'data-current' => $current ? $current['slug'] : '',
		) );

		$trigger_id = 'kdna-rc-rs-trigger-' . $this->get_id();
		$listbox_id = 'kdna-rc-rs-listbox-' . $this->get_id();

		?>
		<div <?php $this->print_render_attribute_string( 'wrapper' ); ?>>
			<button type="button"
				class="kdna-rc-rs-trigger"
				id="<?php echo esc_attr( $trigger_id ); ?>"
				aria-haspopup="listbox"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
				aria-label="<?php echo esc_attr__( 'Choose region', 'kdna-regional-content' ); ?>">
				<?php
				$current_flag = $current ? $this->region_flag_code( $current ) : '';
				if ( 'icon' === $display_mode ) : ?>
					<span class="kdna-rc-rs-icon" aria-hidden="true">
						<?php if ( ! empty( $settings['trigger_icon'] ) ) {
							\Elementor\Icons_Manager::render_icon( $settings['trigger_icon'], array( 'aria-hidden' => 'true' ) );
						} ?>
					</span>
				<?php else : ?>
					<?php if ( in_array( $display_mode, array( 'flag', 'flag_text' ), true ) && '' !== $current_flag ) : ?>
						<span class="kdna-rc-rs-flag fi fi-<?php echo esc_attr( $current_flag ); ?>" aria-hidden="true"></span>
					<?php endif; ?>
					<?php if ( $current && in_array( $display_mode, array( 'text', 'flag_text' ), true ) ) : ?>
						<span class="kdna-rc-rs-label"><?php echo esc_html( $current['name'] ); ?></span>
					<?php endif; ?>
					<span class="kdna-rc-rs-arrow" aria-hidden="true">
						<?php if ( ! empty( $settings['arrow_icon'] ) ) {
							\Elementor\Icons_Manager::render_icon( $settings['arrow_icon'], array( 'aria-hidden' => 'true' ) );
						} ?>
					</span>
				<?php endif; ?>
			</button>

			<ul class="kdna-rc-rs-panel"
				id="<?php echo esc_attr( $listbox_id ); ?>"
				role="listbox"
				aria-labelledby="<?php echo esc_attr( $trigger_id ); ?>"
				hidden>
				<?php foreach ( $regions as $region ) :
					$is_selected = $current && $region['slug'] === $current['slug'];
					$href        = $this->build_target_url( $region['slug'] );
					$flag_code   = $this->region_flag_code( $region );
					?>
					<li class="kdna-rc-rs-option<?php echo $is_selected ? ' is-active' : ''; ?>"
						role="option"
						aria-selected="<?php echo $is_selected ? 'true' : 'false'; ?>"
						data-slug="<?php echo esc_attr( $region['slug'] ); ?>">
						<a href="<?php echo esc_url( $href ); ?>" class="kdna-rc-rs-link">
							<?php if ( '' !== $flag_code ) : ?>
								<span class="kdna-rc-rs-flag fi fi-<?php echo esc_attr( $flag_code ); ?>" aria-hidden="true"></span>
							<?php else : ?>
								<span class="kdna-rc-rs-flag kdna-rc-rs-flag--empty" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="kdna-rc-rs-label"><?php echo esc_html( $region['name'] ); ?></span>
							<span class="kdna-rc-rs-tick" aria-hidden="true">
								<?php if ( $is_selected ) : ?>
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 8.5 6.5 12 13 4.5"/></svg>
								<?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Editor preview template.
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<#
		var displayMode = settings.display_mode || 'flag_text';
		var position    = settings.dropdown_position === 'above' ? 'above' : 'below';
		var showFlag    = displayMode === 'flag' || displayMode === 'flag_text';
		var showText    = displayMode === 'text' || displayMode === 'flag_text';
		var available   = ( window.kdnaRC && window.kdnaRC.regions ) ? window.kdnaRC.regions : [];
		var includeRaw  = settings.regions_to_include || [];
		var regions     = [];
		if ( includeRaw && includeRaw.length ) {
			var includeMap = {};
			_.each( includeRaw, function ( slug ) { includeMap[ slug ] = true; } );
			_.each( available, function ( r ) { if ( includeMap[ r.slug ] ) { regions.push( r ); } } );
		} else {
			regions = available.slice();
		}
		var current = regions.length ? regions[0] : null;
		#>
		<# if ( ! regions.length ) { #>
			<p class="elementor-panel-alert elementor-panel-alert-info">
				<?php echo esc_html__( 'No regions configured. Add some on the Regions tab in Regional Content.', 'kdna-regional-content' ); ?>
			</p>
		<# } else { #>
		<div class="kdna-rc-rs kdna-rc-rs--mode-{{ displayMode }} kdna-rc-rs--pos-{{ position }}" data-current="{{ current.slug }}">
			<button type="button" class="kdna-rc-rs-trigger" aria-haspopup="listbox" aria-expanded="false">
				<# if ( 'icon' === displayMode ) { #>
					<span class="kdna-rc-rs-icon" aria-hidden="true">
						<#
						var triggerIconHTML = elementor.helpers.renderIcon( view, settings.trigger_icon, { 'aria-hidden': 'true' }, 'i', 'object' );
						if ( triggerIconHTML && triggerIconHTML.rendered ) { #>{{{ triggerIconHTML.value }}}<# }
						#>
					</span>
				<# } else { #>
					<# if ( showFlag && current.flag ) { #>
						<span class="kdna-rc-rs-flag fi fi-{{ current.flag }}" aria-hidden="true"></span>
					<# } #>
					<# if ( showText ) { #>
						<span class="kdna-rc-rs-label">{{{ current.name }}}</span>
					<# } #>
					<span class="kdna-rc-rs-arrow" aria-hidden="true">
						<#
						var iconHTML = elementor.helpers.renderIcon( view, settings.arrow_icon, { 'aria-hidden': 'true' }, 'i', 'object' );
						if ( iconHTML && iconHTML.rendered ) { #>{{{ iconHTML.value }}}<# }
						#>
					</span>
				<# } #>
			</button>
			<ul class="kdna-rc-rs-panel" role="listbox" hidden>
				<# _.each( regions, function ( r ) {
					var isSelected = current && r.slug === current.slug;
					#>
					<li class="kdna-rc-rs-option<# if ( isSelected ) { #> is-active<# } #>"
						role="option"
						aria-selected="<# if ( isSelected ) { #>true<# } else { #>false<# } #>"
						data-slug="{{ r.slug }}">
						<a href="#" class="kdna-rc-rs-link">
							<# if ( r.flag ) { #>
								<span class="kdna-rc-rs-flag fi fi-{{ r.flag }}" aria-hidden="true"></span>
							<# } else { #>
								<span class="kdna-rc-rs-flag kdna-rc-rs-flag--empty" aria-hidden="true"></span>
							<# } #>
							<span class="kdna-rc-rs-label">{{{ r.name }}}</span>
							<span class="kdna-rc-rs-tick" aria-hidden="true">
								<# if ( isSelected ) { #>
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 8.5 6.5 12 13 4.5"/></svg>
								<# } #>
							</span>
						</a>
					</li>
				<# } ); #>
			</ul>
		</div>
		<# } #>
		<?php
	}
}
