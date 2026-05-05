<?php
/**
 * Button widget regional content variant extension.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Button_Extension
 *
 * Adds a Regional Content controls section to the Elementor Button widget.
 * Each variant row supplies a region selector plus optional Text, Link, and
 * Icon overrides. On render, the original button output becomes the default
 * variant inside a kdna-rc-variant-wrapper, with each configured variant
 * appended as a hidden sibling.
 */
class KDNA_RC_Button_Extension extends KDNA_RC_Variants_Base {

	const FIELD_TEXT = 'kdna_rc_text';
	const FIELD_LINK = 'kdna_rc_link';
	const FIELD_ICON = 'kdna_rc_icon';

	/**
	 * Elementor widget name targeted by this extension.
	 *
	 * @return string
	 */
	protected function widget_name() {
		return 'button';
	}

	/**
	 * Elementor controls section the Regional Content panel is appended to.
	 *
	 * @return string
	 */
	protected function controls_section() {
		return 'section_button';
	}

	/**
	 * Add Text, Link, and Icon override fields to each repeater row.
	 *
	 * @param \Elementor\Repeater $repeater Repeater being built.
	 * @return void
	 */
	protected function register_variant_fields( $repeater ) {
		$repeater->add_control(
			self::FIELD_TEXT,
			array(
				'label'       => __( 'Text', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::TEXT' ) ? \Elementor\Controls_Manager::TEXT : 'text',
				'default'     => '',
				'placeholder' => __( 'Button label shown to visitors in this region', 'kdna-regional-content' ),
			)
		);

		$repeater->add_control(
			self::FIELD_LINK,
			array(
				'label'       => __( 'Link', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::URL' ) ? \Elementor\Controls_Manager::URL : 'url',
				'placeholder' => 'https://example.com',
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
				'description' => __( 'Optional. Leave blank to keep the widget\'s default link.', 'kdna-regional-content' ),
			)
		);

		$repeater->add_control(
			self::FIELD_ICON,
			array(
				'label'            => __( 'Icon', 'kdna-regional-content' ),
				'type'             => defined( 'Elementor\\Controls_Manager::ICONS' ) ? \Elementor\Controls_Manager::ICONS : 'icons',
				'default'          => array(
					'value'   => '',
					'library' => '',
				),
				'description'      => __( 'Optional. Leave blank to keep the widget\'s default icon (or no icon).', 'kdna-regional-content' ),
				'skin'             => 'inline',
				'label_block'      => true,
				'exclude_inline_options' => array( 'svg' ),
			)
		);
	}

	/**
	 * Build the variant HTML by patching button text, href, and icon.
	 *
	 * @param string $content Original rendered widget content.
	 * @param array  $variant Prepared variant row.
	 * @return string
	 */
	protected function transform_default_html( $content, array $variant ) {
		list( $dom, $root ) = $this->dom_from_fragment( $content );
		if ( ! $root ) {
			return $content;
		}

		$button = $root->getElementsByTagName( 'a' )->item( 0 );
		if ( null === $button ) {
			// Some Elementor button variants render as a <button> element.
			$button = $root->getElementsByTagName( 'button' )->item( 0 );
		}
		if ( null === $button ) {
			return $content;
		}

		// Text override.
		$text = isset( $variant[ self::FIELD_TEXT ] ) ? (string) $variant[ self::FIELD_TEXT ] : '';
		if ( '' !== $text ) {
			$this->replace_button_text( $dom, $root, $button, $text );
		}

		// Link override (only on <a>).
		if ( 'a' === strtolower( $button->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$link = isset( $variant[ self::FIELD_LINK ] ) && is_array( $variant[ self::FIELD_LINK ] ) ? $variant[ self::FIELD_LINK ] : array();
			$url  = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
			if ( '' !== $url ) {
				$button->setAttribute( 'href', $url );
				if ( ! empty( $link['is_external'] ) ) {
					$button->setAttribute( 'target', '_blank' );
				}
				$rel = array();
				if ( ! empty( $link['is_external'] ) ) {
					$rel[] = 'noopener';
				}
				if ( ! empty( $link['nofollow'] ) ) {
					$rel[] = 'nofollow';
				}
				if ( ! empty( $rel ) ) {
					$button->setAttribute( 'rel', implode( ' ', array_unique( $rel ) ) );
				}
			}
		}

		// Icon override.
		$icon = isset( $variant[ self::FIELD_ICON ] ) && is_array( $variant[ self::FIELD_ICON ] ) ? $variant[ self::FIELD_ICON ] : array();
		if ( ! empty( $icon['value'] ) ) {
			$this->replace_button_icon( $dom, $root, $button, $icon );
		}

		return $this->dom_to_html( $dom, $root );
	}

	/**
	 * Replace the button text inside .elementor-button-text (or button textContent).
	 *
	 * @param \DOMDocument $dom    Document.
	 * @param \DOMElement  $root   Root.
	 * @param \DOMElement  $button Anchor or button element.
	 * @param string       $text   New text.
	 * @return void
	 */
	private function replace_button_text( $dom, $root, $button, $text ) {
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-button-text ')]", $button );
		if ( $nodes && $nodes->length > 0 ) {
			$target = $nodes->item( 0 );
			while ( $target->firstChild ) {
				$target->removeChild( $target->firstChild );
			}
			$target->appendChild( $dom->createTextNode( $text ) );
			return;
		}

		// No dedicated text span (atomic markup or custom skin); replace the
		// button textContent while preserving any icon descendants.
		$icon_node = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-button-icon ')]", $button )->item( 0 );
		while ( $button->firstChild ) {
			$button->removeChild( $button->firstChild );
		}
		if ( $icon_node ) {
			$button->appendChild( $icon_node );
		}
		$button->appendChild( $dom->createTextNode( $text ) );
	}

	/**
	 * Replace the icon inside the button. Inserts an icon span when the
	 * button has no existing icon to swap.
	 *
	 * @param \DOMDocument $dom    Document.
	 * @param \DOMElement  $root   Root.
	 * @param \DOMElement  $button Anchor or button element.
	 * @param array        $icon   Icon settings.
	 * @return void
	 */
	private function replace_button_icon( $dom, $root, $button, array $icon ) {
		$icon_html = $this->render_icon_html( $icon );
		if ( '' === $icon_html ) {
			return;
		}

		$xpath  = new \DOMXPath( $dom );
		$holder = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-button-icon ')]", $button )->item( 0 );

		if ( $holder ) {
			while ( $holder->firstChild ) {
				$holder->removeChild( $holder->firstChild );
			}
			$fragment = $this->fragment_from_html( $dom, $icon_html );
			if ( $fragment ) {
				$holder->appendChild( $fragment );
			}
			return;
		}

		// No icon holder yet; build one and prepend it inside the content wrapper.
		$wrapper = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-button-content-wrapper ')]", $button )->item( 0 );
		if ( ! $wrapper ) {
			$wrapper = $button;
		}
		$span = $dom->createElement( 'span' );
		$span->setAttribute( 'class', 'elementor-button-icon' );
		$fragment = $this->fragment_from_html( $dom, $icon_html );
		if ( $fragment ) {
			$span->appendChild( $fragment );
		}
		$wrapper->insertBefore( $span, $wrapper->firstChild );
	}

	/**
	 * Render the Elementor icon settings to an HTML string.
	 *
	 * Captures the output of Elementor's Icons_Manager so we get the same
	 * markup the editor would produce (including SVG when applicable).
	 *
	 * @param array $icon Icon settings.
	 * @return string
	 */
	private function render_icon_html( array $icon ) {
		if ( empty( $icon['value'] ) ) {
			return '';
		}
		if ( ! class_exists( '\\Elementor\\Icons_Manager' ) ) {
			$class = is_string( $icon['value'] ) ? $icon['value'] : '';
			return '' !== $class ? '<i class="' . esc_attr( $class ) . '" aria-hidden="true"></i>' : '';
		}

		ob_start();
		\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Parse an HTML string into a DocumentFragment owned by $dom.
	 *
	 * @param \DOMDocument $dom Owner document.
	 * @param string       $html HTML to parse.
	 * @return \DOMDocumentFragment|null
	 */
	private function fragment_from_html( $dom, $html ) {
		$fragment = $dom->createDocumentFragment();
		$prev     = libxml_use_internal_errors( true );
		try {
			$fragment->appendXML( $html );
		} catch ( \Throwable $e ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			// Fall back to text node so we never lose the variant icon entirely.
			$text = $dom->createTextNode( wp_strip_all_tags( $html ) );
			$frag = $dom->createDocumentFragment();
			$frag->appendChild( $text );
			return $frag;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $fragment;
	}
}
