<?php
/**
 * Icon widget regional content variant extension.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Icon_Extension
 *
 * Adds a Regional Content controls section to the Elementor Icon widget.
 * Each variant supplies an Icon picker and an optional Link override; the
 * widget output is wrapped in a kdna-rc-variant-wrapper with the original
 * as the default and one hidden variant per row.
 */
class KDNA_RC_Icon_Extension extends KDNA_RC_Variants_Base {

	const FIELD_ICON = 'kdna_rc_icon';
	const FIELD_LINK = 'kdna_rc_link';

	/**
	 * Elementor widget name targeted by this extension.
	 *
	 * @return string
	 */
	protected function widget_name() {
		return 'icon';
	}

	/**
	 * Elementor controls section the Regional Content panel is appended to.
	 *
	 * @return string
	 */
	protected function controls_section() {
		return 'section_icon';
	}

	/**
	 * Add Icon and Link override fields to each repeater row.
	 *
	 * @param \Elementor\Repeater $repeater Repeater being built.
	 * @return void
	 */
	protected function register_variant_fields( $repeater ) {
		$repeater->add_control(
			self::FIELD_ICON,
			array(
				'label'       => __( 'Icon', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::ICONS' ) ? \Elementor\Controls_Manager::ICONS : 'icons',
				'default'     => array(
					'value'   => '',
					'library' => '',
				),
				'label_block' => true,
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
	}

	/**
	 * Build the variant HTML by replacing the icon glyph and any link wrapper.
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

		// The icon node is whichever <i> or <svg> sits inside .elementor-icon
		// (or the wrapper itself in atomic markup). Find the first child of
		// the .elementor-icon element.
		$xpath = new \DOMXPath( $dom );
		$holder = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-icon ')]" )->item( 0 );
		if ( ! $holder ) {
			$holder = $root;
		}

		// Icon override.
		$icon = isset( $variant[ self::FIELD_ICON ] ) && is_array( $variant[ self::FIELD_ICON ] ) ? $variant[ self::FIELD_ICON ] : array();
		if ( ! empty( $icon['value'] ) ) {
			$icon_html = $this->render_icon_html( $icon );
			if ( '' !== $icon_html ) {
				while ( $holder->firstChild ) {
					$holder->removeChild( $holder->firstChild );
				}
				$fragment = $this->fragment_from_html( $dom, $icon_html );
				if ( $fragment ) {
					$holder->appendChild( $fragment );
				}
			}
		}

		// Link override: the .elementor-icon node is itself the <a> when a
		// link is configured, or a <div> otherwise. Convert / update as needed.
		$link = isset( $variant[ self::FIELD_LINK ] ) && is_array( $variant[ self::FIELD_LINK ] ) ? $variant[ self::FIELD_LINK ] : array();
		$url  = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
		if ( '' !== $url ) {
			$tag  = strtolower( $holder->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$node = $holder;
			if ( 'a' !== $tag ) {
				$node = $this->convert_to_anchor( $dom, $holder );
			}
			$node->setAttribute( 'href', $url );
			if ( ! empty( $link['is_external'] ) ) {
				$node->setAttribute( 'target', '_blank' );
			}
			$rel = array();
			if ( ! empty( $link['is_external'] ) ) {
				$rel[] = 'noopener';
			}
			if ( ! empty( $link['nofollow'] ) ) {
				$rel[] = 'nofollow';
			}
			if ( ! empty( $rel ) ) {
				$node->setAttribute( 'rel', implode( ' ', array_unique( $rel ) ) );
			}
		}

		return $this->dom_to_html( $dom, $root );
	}

	/**
	 * Replace a non-anchor element with an <a> while preserving children + attrs.
	 *
	 * @param \DOMDocument $dom  Document.
	 * @param \DOMElement  $node Element to convert.
	 * @return \DOMElement Newly created anchor in the same position.
	 */
	private function convert_to_anchor( $dom, $node ) {
		$anchor = $dom->createElement( 'a' );
		foreach ( $node->attributes as $attr ) {
			$anchor->setAttribute( $attr->nodeName, $attr->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		while ( $node->firstChild ) {
			$anchor->appendChild( $node->firstChild );
		}
		$node->parentNode->replaceChild( $anchor, $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $anchor;
	}

	/**
	 * Render the Elementor icon settings to an HTML string.
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
			$frag = $dom->createDocumentFragment();
			$frag->appendChild( $dom->createTextNode( wp_strip_all_tags( $html ) ) );
			return $frag;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $fragment;
	}
}
