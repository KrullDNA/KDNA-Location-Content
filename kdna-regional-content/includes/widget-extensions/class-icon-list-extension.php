<?php
/**
 * Icon List widget regional visibility extension (per-item).
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Icon_List_Extension
 *
 * Unlike the other widget extensions, Icon List does not get a variant
 * repeater. Instead, each existing item in the widget's icon_list repeater
 * gains a "Show in Regions" multi-select. On render, the corresponding <li>
 * receives a data-kdna-show-in attribute and the Stage 5 visibility filter
 * (in frontend.js) handles hiding it for visitors not in the listed regions.
 *
 * Items with no regions ticked show everywhere (no attribute is added).
 */
class KDNA_RC_Icon_List_Extension {

	/**
	 * Repeater field name on each Icon List item (regions).
	 *
	 * @var string
	 */
	const FIELD_NAME = 'kdna_rc_show_in';

	/**
	 * Stage 11: Repeater field name on each Icon List item (languages).
	 *
	 * @var string
	 */
	const FIELD_LANG_NAME = 'kdna_rc_show_in_languages';

	/**
	 * Wire up Elementor hooks once the page builder is available.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'elementor/init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Bind the controls extension and the render filter.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'elementor/element/icon-list/section_icon_list/after_section_end', array( $this, 'inject_per_item_field' ), 10, 2 );
		add_filter( 'elementor/widget/render_content', array( $this, 'inject_li_attributes' ), 10, 2 );
	}

	/**
	 * Append a new repeater field to the icon_list control.
	 *
	 * Reads the existing fields, adds a Show in Regions multi-select, and
	 * pushes the merged set back via update_control. Idempotent: bails when
	 * the field is already registered, which is important because Elementor
	 * triggers the section hooks on every panel render.
	 *
	 * @param mixed $element Element_Base instance.
	 * @param array $args    Hook args (unused).
	 * @return void
	 */
	public function inject_per_item_field( $element, $args = array() ) {
		unset( $args );

		if ( ! is_object( $element ) || ! method_exists( $element, 'get_controls' ) || ! method_exists( $element, 'update_control' ) ) {
			return;
		}

		$control = $element->get_controls( 'icon_list' );
		if ( empty( $control ) || empty( $control['fields'] ) || ! is_array( $control['fields'] ) ) {
			return;
		}

		$fields  = $control['fields'];
		$has_reg = false;
		$has_lng = false;
		foreach ( $fields as $field ) {
			$name = is_array( $field ) && isset( $field['name'] ) ? $field['name'] : '';
			if ( self::FIELD_NAME === $name ) {
				$has_reg = true;
			}
			if ( self::FIELD_LANG_NAME === $name ) {
				$has_lng = true;
			}
		}

		$regions   = ( new KDNA_RC_Regions() )->get_all();
		$languages = ( new KDNA_RC_Languages() )->get_all();
		$dirty     = false;

		if ( ! $has_reg && ! empty( $regions ) ) {
			$options = array();
			foreach ( $regions as $region ) {
				$options[ $region['slug'] ] = $region['name'];
			}
			$fields[] = array(
				'name'        => self::FIELD_NAME,
				'label'       => __( 'Show in Regions', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::SELECT2' ) ? \Elementor\Controls_Manager::SELECT2 : 'select2',
				'multiple'    => true,
				'options'     => $options,
				'default'     => array(),
				'label_block' => true,
				'description' => __( 'Leave blank to show this item in all regions.', 'kdna-regional-content' ),
			);
			$dirty = true;
		}

		if ( ! $has_lng && ! empty( $languages ) ) {
			$options = array();
			foreach ( $languages as $language ) {
				$options[ $language['slug'] ] = $language['name'];
			}
			$fields[] = array(
				'name'        => self::FIELD_LANG_NAME,
				'label'       => __( 'Restrict to Languages', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::SELECT2' ) ? \Elementor\Controls_Manager::SELECT2 : 'select2',
				'multiple'    => true,
				'options'     => $options,
				'default'     => array(),
				'label_block' => true,
				'description' => __( 'Leave blank to show this item in all languages.', 'kdna-regional-content' ),
			);
			$dirty = true;
		}

		if ( $dirty ) {
			$element->update_control(
				'icon_list',
				array( 'fields' => $fields )
			);
		}
	}

	/**
	 * Inject data-kdna-show-in on each <li> based on the item's region setting.
	 *
	 * The Nth list item in the rendered HTML corresponds to the Nth row of
	 * the icon_list repeater. Items with no region restrictions are left
	 * untouched so the existing Stage 5 visibility JS only acts on the rows
	 * editors explicitly restricted.
	 *
	 * @param string $content Rendered widget content.
	 * @param mixed  $widget  Widget instance.
	 * @return string
	 */
	public function inject_li_attributes( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'icon-list' !== $widget->get_name() ) {
			return $content;
		}

		$settings = method_exists( $widget, 'get_settings_for_display' ) ? $widget->get_settings_for_display() : array();
		$items    = isset( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ? $settings['icon_list'] : array();
		if ( empty( $items ) ) {
			return $content;
		}

		// Index restrictions by item position so we can match against the
		// Nth <li>. Region and language restrictions are tracked separately
		// because they map to different attributes the JS reads.
		$region_restrictions   = array();
		$language_restrictions = array();
		foreach ( array_values( $items ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$region_slugs = isset( $item[ self::FIELD_NAME ] ) ? (array) $item[ self::FIELD_NAME ] : array();
			$region_slugs = array_values( array_filter( array_map( 'sanitize_key', $region_slugs ) ) );
			if ( ! empty( $region_slugs ) ) {
				$region_restrictions[ $i ] = $region_slugs;
			}

			$lang_slugs = isset( $item[ self::FIELD_LANG_NAME ] ) ? (array) $item[ self::FIELD_LANG_NAME ] : array();
			$lang_slugs = array_values( array_filter( array_map( 'sanitize_key', $lang_slugs ) ) );
			if ( ! empty( $lang_slugs ) ) {
				$language_restrictions[ $i ] = $lang_slugs;
			}
		}

		if ( empty( $region_restrictions ) && empty( $language_restrictions ) ) {
			return $content;
		}

		list( $dom, $root ) = $this->dom_from_fragment( $content );
		if ( ! $root ) {
			return $content;
		}

		$lis = $root->getElementsByTagName( 'li' );
		foreach ( $region_restrictions as $index => $slugs ) {
			$node = $lis->item( $index );
			if ( $node ) {
				$node->setAttribute( 'data-kdna-show-in', implode( ',', $slugs ) );
			}
		}
		foreach ( $language_restrictions as $index => $slugs ) {
			$node = $lis->item( $index );
			if ( $node ) {
				$node->setAttribute( 'data-kdna-show-in-languages', implode( ',', $slugs ) );
			}
		}

		return $this->dom_to_html( $dom, $root );
	}

	/**
	 * Load an HTML fragment into a fresh DOMDocument and return both.
	 *
	 * Mirrors the helper on KDNA_RC_Variants_Base; duplicated here so this
	 * class stays standalone (Icon List does not extend the variants base).
	 *
	 * @param string $html HTML fragment.
	 * @return array { 0: \DOMDocument, 1: \DOMElement|null root node }
	 */
	private function dom_from_fragment( $html ) {
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
	private function dom_to_html( $dom, $root ) {
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
