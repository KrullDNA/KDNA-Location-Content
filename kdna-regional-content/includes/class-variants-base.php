<?php
/**
 * Shared base class for Elementor widget variant extensions.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Variants_Base
 *
 * Each subclass extends a single Elementor widget (e.g. heading, text-editor)
 * with a "Regional Content" controls section containing a repeater of region
 * variants. On render, the original widget output becomes the default variant
 * inside a kdna-rc-variant-wrapper, with each configured variant appended as
 * a hidden sibling. The Stage 5 anti-flicker CSS hides every wrapper while
 * the page is in the pending state so the default never flashes for visitors
 * who require a swap.
 *
 * Subclasses provide:
 *   - widget_name(): the Elementor widget name slug ('heading', 'text-editor').
 *   - controls_section(): the section to inject controls AFTER ('section_title').
 *   - register_variant_fields( $repeater ): adds the type-specific repeater
 *     fields (Title + Link, or Content).
 *   - transform_default_html( $content, $variant ): returns the HTML for one
 *     variant, derived from the original widget output and the variant data.
 */
abstract class KDNA_RC_Variants_Base {

	/**
	 * Repeater control id used inside the widget settings.
	 *
	 * @var string
	 */
	const CTRL_REPEATER = 'kdna_rc_variants';

	/**
	 * Region selector field id inside each repeater row.
	 *
	 * @var string
	 */
	const FIELD_REGION = 'kdna_rc_region';

	/**
	 * Stage 11: Language repeater control id.
	 *
	 * @var string
	 */
	const LANG_REPEATER = 'kdna_rc_language_variants';

	/**
	 * Stage 11: Language selector field id inside each language repeater row.
	 *
	 * @var string
	 */
	const FIELD_LANGUAGE = 'kdna_rc_language';

	/**
	 * Return the Elementor widget name this extension targets.
	 *
	 * @return string
	 */
	abstract protected function widget_name();

	/**
	 * Return the controls section to inject AFTER (Elementor section id).
	 *
	 * @return string
	 */
	abstract protected function controls_section();

	/**
	 * Add the type-specific repeater fields (Title, Content, Link, etc).
	 *
	 * @param \Elementor\Repeater $repeater Elementor repeater being built.
	 * @return void
	 */
	abstract protected function register_variant_fields( $repeater );

	/**
	 * Build the HTML for a single variant from the original widget content.
	 *
	 * @param string $content Original rendered content from Elementor.
	 * @param array  $variant Variant data (sanitised).
	 * @return string
	 */
	abstract protected function transform_default_html( $content, array $variant );

	/**
	 * Wire up Elementor controls and the render filter.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'elementor/init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Bind the controls injection and the render filter once Elementor loads.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$hook = sprintf(
			'elementor/element/%1$s/%2$s/after_section_end',
			$this->widget_name(),
			$this->controls_section()
		);
		add_action( $hook, array( $this, 'register_controls' ), 10, 2 );
		// Stage 11: Language Content section sits alongside the Regional
		// Content section. Registered at priority 11 so it lands directly
		// below the regional one in the editor panel.
		add_action( $hook, array( $this, 'register_language_controls' ), 11, 2 );
		add_filter( 'elementor/widget/render_content', array( $this, 'render_content' ), 10, 2 );
	}

	/**
	 * Inject the Regional Content controls section into the target widget.
	 *
	 * Skips registration when no regions are configured so editors are not
	 * presented with an empty repeater.
	 *
	 * @param mixed $element Element_Base instance from Elementor.
	 * @param array $args    Hook args.
	 * @return void
	 */
	public function register_controls( $element, $args = array() ) {
		unset( $args );

		if ( ! is_object( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}

		$regions = ( new KDNA_RC_Regions() )->get_all();

		$element->start_controls_section(
			'kdna_rc_variants_section',
			array(
				'label' => __( 'Regional Content', 'kdna-regional-content' ),
				'tab'   => defined( 'Elementor\\Controls_Manager::TAB_CONTENT' ) ? \Elementor\Controls_Manager::TAB_CONTENT : 'content',
			)
		);

		if ( empty( $regions ) ) {
			$element->add_control(
				'kdna_rc_variants_no_regions',
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

		$choices = array( '' => __( 'Select a region...', 'kdna-regional-content' ) );
		foreach ( $regions as $region ) {
			$choices[ $region['slug'] ] = $region['name'];
		}

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			self::FIELD_REGION,
			array(
				'label'   => __( 'Region', 'kdna-regional-content' ),
				'type'    => defined( 'Elementor\\Controls_Manager::SELECT' ) ? \Elementor\Controls_Manager::SELECT : 'select',
				'options' => $choices,
				'default' => '',
			)
		);

		$this->register_variant_fields( $repeater );

		$element->add_control(
			self::CTRL_REPEATER,
			array(
				'label'       => __( 'Variants', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::REPEATER' ) ? \Elementor\Controls_Manager::REPEATER : 'repeater',
				'fields'      => $repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ ' . self::FIELD_REGION . ' }}}',
				'description' => __( 'Add one row per region. Visitors in that region see this variant; everyone else sees the widget\'s default content.', 'kdna-regional-content' ),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Inject the Language Content controls section into the target widget.
	 *
	 * Mirrors register_controls() but reads from KDNA_RC_Languages and stores
	 * its repeater on a separate control id (LANG_REPEATER), so the existing
	 * Region repeater is untouched. Same per-widget fields are appended via
	 * the existing register_variant_fields() abstract on the subclass.
	 *
	 * @param mixed $element Element_Base instance from Elementor.
	 * @param array $args    Hook args.
	 * @return void
	 */
	public function register_language_controls( $element, $args = array() ) {
		unset( $args );

		if ( ! is_object( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}

		$languages = ( new KDNA_RC_Languages() )->get_all();

		$element->start_controls_section(
			'kdna_rc_language_variants_section',
			array(
				'label' => __( 'Language Content', 'kdna-regional-content' ),
				'tab'   => defined( 'Elementor\\Controls_Manager::TAB_CONTENT' ) ? \Elementor\Controls_Manager::TAB_CONTENT : 'content',
			)
		);

		if ( empty( $languages ) ) {
			$element->add_control(
				'kdna_rc_language_variants_no_languages',
				array(
					'type'            => defined( 'Elementor\\Controls_Manager::RAW_HTML' ) ? \Elementor\Controls_Manager::RAW_HTML : 'raw_html',
					'raw'             => sprintf(
						/* translators: %s: link to the Languages admin tab. */
						__( 'No languages are configured yet. Add some on the %s.', 'kdna-regional-content' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=kdna-regional-content&tab=languages' ) ) . '" target="_blank">' . esc_html__( 'Languages tab', 'kdna-regional-content' ) . '</a>'
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
			$element->end_controls_section();
			return;
		}

		$choices = array( '' => __( 'Select a language...', 'kdna-regional-content' ) );
		foreach ( $languages as $language ) {
			$choices[ $language['slug'] ] = $language['name'];
		}

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			self::FIELD_LANGUAGE,
			array(
				'label'   => __( 'Language', 'kdna-regional-content' ),
				'type'    => defined( 'Elementor\\Controls_Manager::SELECT' ) ? \Elementor\Controls_Manager::SELECT : 'select',
				'options' => $choices,
				'default' => '',
			)
		);

		$this->register_variant_fields( $repeater );

		$element->add_control(
			self::LANG_REPEATER,
			array(
				'label'       => __( 'Variants', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::REPEATER' ) ? \Elementor\Controls_Manager::REPEATER : 'repeater',
				'fields'      => $repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ ' . self::FIELD_LANGUAGE . ' }}}',
				'description' => __( 'Add one row per language. When a visitor uses that language, this variant wins over any matching regional variant.', 'kdna-regional-content' ),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Filter callback wrapping the widget output with the variant tree.
	 *
	 * @param string $content Original rendered widget content.
	 * @param mixed  $widget  Widget instance.
	 * @return string
	 */
	public function render_content( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return $content;
		}
		if ( $widget->get_name() !== $this->widget_name() ) {
			return $content;
		}

		$settings = method_exists( $widget, 'get_settings_for_display' ) ? $widget->get_settings_for_display() : array();
		$variants = isset( $settings[ self::CTRL_REPEATER ] ) && is_array( $settings[ self::CTRL_REPEATER ] ) ? $settings[ self::CTRL_REPEATER ] : array();
		$lang_variants = isset( $settings[ self::LANG_REPEATER ] ) && is_array( $settings[ self::LANG_REPEATER ] ) ? $settings[ self::LANG_REPEATER ] : array();

		$prepared      = $this->prepare_variants( $variants );
		$prepared_lang = $this->prepare_language_variants( $lang_variants );

		if ( empty( $prepared ) && empty( $prepared_lang ) ) {
			return $content;
		}

		return $this->wrap( $content, $prepared, $prepared_lang );
	}

	/**
	 * Validate and normalise the raw variant rows from Elementor settings.
	 *
	 * Drops rows missing a region slug or whose region is no longer
	 * configured, deduplicates by slug (first occurrence wins), and decorates
	 * each surviving row with the resolved region record.
	 *
	 * @param array $variants Raw repeater rows.
	 * @return array<int,array>
	 */
	protected function prepare_variants( array $variants ) {
		$regions_handler = new KDNA_RC_Regions();
		$seen            = array();
		$out             = array();

		foreach ( $variants as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row[ self::FIELD_REGION ] ) ? sanitize_key( (string) $row[ self::FIELD_REGION ] ) : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$region = $regions_handler->get( $slug );
			if ( null === $region ) {
				continue;
			}
			$seen[ $slug ]  = true;
			$row['_region'] = $region;
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * Stage 11: Validate and normalise language repeater rows.
	 *
	 * Mirrors prepare_variants() against the KDNA_RC_Languages handler so
	 * language variants follow the same uniqueness, validation, and
	 * decoration rules as regional ones.
	 *
	 * @param array $variants Raw language repeater rows.
	 * @return array<int,array>
	 */
	protected function prepare_language_variants( array $variants ) {
		$languages_handler = new KDNA_RC_Languages();
		$seen              = array();
		$out               = array();

		foreach ( $variants as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row[ self::FIELD_LANGUAGE ] ) ? sanitize_key( (string) $row[ self::FIELD_LANGUAGE ] ) : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$language = $languages_handler->get( $slug );
			if ( null === $language ) {
				continue;
			}
			$seen[ $slug ]    = true;
			$row['_language'] = $language;
			$out[]            = $row;
		}
		return $out;
	}

	/**
	 * Wrap the original content with the default variant plus appended variants.
	 *
	 * The wrapper is a thin div so it interacts cleanly with both classic
	 * and atomic Elementor markup. The default variant carries the original
	 * unmodified content; each variant is built by transform_default_html().
	 *
	 * @param string $content  Original rendered content.
	 * @param array  $variants Prepared variants.
	 * @return string
	 */
	protected function wrap( $content, array $variants, array $language_variants = array() ) {
		$out  = '<div class="kdna-rc-variant-wrapper">';
		$out .= '<div class="kdna-rc-variant kdna-rc-default" data-kdna-region="default">' . $content . '</div>';

		foreach ( $variants as $variant ) {
			$variant_html = $this->transform_default_html( $content, $variant );
			$attrs        = $this->variant_attributes( $variant );
			$out         .= '<div class="kdna-rc-variant" ' . $attrs . ' style="display:none">' . $variant_html . '</div>';
		}

		// Stage 11: language variants. Same shape as regional siblings but
		// tagged with data-kdna-language so the JS swap can prefer them.
		foreach ( $language_variants as $variant ) {
			$variant_html = $this->transform_default_html( $content, $variant );
			$attrs        = $this->language_variant_attributes( $variant );
			$out         .= '<div class="kdna-rc-variant" ' . $attrs . ' style="display:none">' . $variant_html . '</div>';
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * Build the attribute string for a non-default variant wrapper.
	 *
	 * Always emits data-kdna-region; emits lang and dir only when the region
	 * configuration sets them.
	 *
	 * @param array $variant Variant row including the resolved _region.
	 * @return string
	 */
	protected function variant_attributes( array $variant ) {
		$region = isset( $variant['_region'] ) ? $variant['_region'] : array();
		$slug   = isset( $region['slug'] ) ? (string) $region['slug'] : '';
		$lang   = isset( $region['language'] ) ? (string) $region['language'] : '';
		$dir    = isset( $region['direction'] ) && 'rtl' === $region['direction'] ? 'rtl' : '';

		$parts   = array();
		$parts[] = 'data-kdna-region="' . esc_attr( $slug ) . '"';
		if ( '' !== $lang ) {
			$parts[] = 'lang="' . esc_attr( $lang ) . '"';
		}
		if ( '' !== $dir ) {
			$parts[] = 'dir="' . esc_attr( $dir ) . '"';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Stage 11: build the attribute string for a language variant wrapper.
	 *
	 * Always emits data-kdna-language; emits a lang attribute mirroring the
	 * variant slug when no other language tag is configured, and dir="rtl"
	 * for language slugs known to be right-to-left.
	 *
	 * @param array $variant Variant row including the resolved _language.
	 * @return string
	 */
	protected function language_variant_attributes( array $variant ) {
		$language = isset( $variant['_language'] ) ? $variant['_language'] : array();
		$slug     = isset( $language['slug'] ) ? (string) $language['slug'] : '';

		// Map of slugs (lowercase) known to render right-to-left so the
		// rendered variant gets the right dir attribute without admins
		// having to think about it.
		$rtl_slugs = array( 'ar', 'fa', 'he', 'ur', 'ps', 'sd', 'ckb', 'yi' );

		$parts   = array();
		$parts[] = 'data-kdna-language="' . esc_attr( $slug ) . '"';
		if ( '' !== $slug ) {
			$parts[] = 'lang="' . esc_attr( $slug ) . '"';
		}
		if ( '' !== $slug && in_array( strtolower( $slug ), $rtl_slugs, true ) ) {
			$parts[] = 'dir="rtl"';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Load an HTML fragment into a fresh DOMDocument and return both.
	 *
	 * Wraps the input in a UTF-8 hint so accented characters survive the
	 * round trip. Caller is expected to extract the desired nodes via the
	 * stub <kdna-rc-root> wrapper this method adds.
	 *
	 * @param string $html HTML fragment.
	 * @return array { 0: DOMDocument, 1: DOMElement|null root node }
	 */
	protected function dom_from_fragment( $html ) {
		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$wrapped = '<?xml encoding="UTF-8"?><kdna-rc-root>' . $html . '</kdna-rc-root>';

		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $dom->getElementsByTagName( 'kdna-rc-root' )->item( 0 );
		return array( $dom, $root );
	}

	/**
	 * Serialise the children of the stub root back to an HTML string.
	 *
	 * @param \DOMDocument $dom  Document.
	 * @param \DOMElement  $root Stub root from dom_from_fragment().
	 * @return string
	 */
	protected function dom_to_html( $dom, $root ) {
		$out = '';
		if ( ! $root ) {
			return $out;
		}
		foreach ( $root->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out .= $dom->saveHTML( $node );
		}
		return $out;
	}
}
