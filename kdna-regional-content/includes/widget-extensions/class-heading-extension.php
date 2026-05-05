<?php
/**
 * Heading widget regional content variant extension.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Heading_Extension
 *
 * Adds a Regional Content controls section to the Elementor Heading widget.
 * Each variant row contains a Region selector, a Title text override, and a
 * Link override (URL). On render, the heading text and link in the original
 * markup are replaced with the variant values via DOMDocument so structural
 * classes added by Elementor (size modifiers, alignment) survive the swap.
 */
class KDNA_RC_Heading_Extension extends KDNA_RC_Variants_Base {

	/**
	 * Field id for the variant title text.
	 *
	 * @var string
	 */
	const FIELD_TITLE = 'kdna_rc_title';

	/**
	 * Field id for the variant link override.
	 *
	 * @var string
	 */
	const FIELD_LINK = 'kdna_rc_link';

	/**
	 * Elementor widget name targeted by this extension.
	 *
	 * @return string
	 */
	protected function widget_name() {
		return 'heading';
	}

	/**
	 * Elementor controls section the Regional Content panel is appended to.
	 *
	 * Section_title is the Heading widget's main content section. Adding the
	 * Regional Content panel after it keeps the editor flow natural: title
	 * first, regional overrides immediately below.
	 *
	 * @return string
	 */
	protected function controls_section() {
		return 'section_title';
	}

	/**
	 * Add the Title and Link fields to each repeater row.
	 *
	 * @param \Elementor\Repeater $repeater Repeater being built.
	 * @return void
	 */
	protected function register_variant_fields( $repeater ) {
		$repeater->add_control(
			self::FIELD_TITLE,
			array(
				'label'       => __( 'Title', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::TEXTAREA' ) ? \Elementor\Controls_Manager::TEXTAREA : 'textarea',
				'default'     => '',
				'rows'        => 3,
				'placeholder' => __( 'Heading text shown to visitors in this region', 'kdna-regional-content' ),
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
	 * Build the variant HTML by patching the heading text and link.
	 *
	 * Replaces the deepest text content of the heading element with the
	 * variant title, then updates the href on the inner anchor when a link
	 * override is present. When the original markup has no anchor and the
	 * variant supplies one, wraps the heading text in a fresh anchor.
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

		$heading = $this->find_heading( $root );
		if ( null === $heading ) {
			return $content;
		}

		$title = isset( $variant[ self::FIELD_TITLE ] ) ? (string) $variant[ self::FIELD_TITLE ] : '';
		$link  = isset( $variant[ self::FIELD_LINK ] ) && is_array( $variant[ self::FIELD_LINK ] ) ? $variant[ self::FIELD_LINK ] : array();

		$this->apply_title_and_link( $dom, $heading, $title, $link );

		return $this->dom_to_html( $dom, $root );
	}

	/**
	 * Find the first h1-h6 element under the stub root.
	 *
	 * @param \DOMElement $root Stub root from dom_from_fragment().
	 * @return \DOMElement|null
	 */
	private function find_heading( $root ) {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$nodes = $root->getElementsByTagName( $tag );
			if ( $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}
		return null;
	}

	/**
	 * Apply the variant title and link override to the heading element.
	 *
	 * Behaviour:
	 *   - If the heading already contains an <a>, replace the anchor's text
	 *     with the variant title (when set) and update its href / target /
	 *     rel attributes when the variant provides a URL.
	 *   - Otherwise, replace the heading's textContent with the variant
	 *     title; if the variant supplies a URL, wrap the new text in a fresh
	 *     anchor so the link is honoured.
	 *
	 * @param \DOMDocument $dom     Owner document.
	 * @param \DOMElement  $heading Heading element being patched.
	 * @param string       $title   Variant title text.
	 * @param array        $link    Variant link spec (Elementor URL control shape).
	 * @return void
	 */
	private function apply_title_and_link( $dom, $heading, $title, array $link ) {
		$url      = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
		$external = ! empty( $link['is_external'] );
		$nofollow = ! empty( $link['nofollow'] );

		$anchor = null;
		foreach ( $heading->getElementsByTagName( 'a' ) as $a ) {
			$anchor = $a;
			break;
		}

		if ( $anchor ) {
			if ( '' !== $title ) {
				$this->set_text( $dom, $anchor, $title );
			}
			if ( '' !== $url ) {
				$anchor->setAttribute( 'href', $url );
				$this->apply_link_target_rel( $anchor, $external, $nofollow );
			}
			return;
		}

		// No existing anchor; replace heading text directly. If a URL is
		// provided in the variant, wrap the new text in a fresh anchor so
		// the override is honoured.
		if ( '' === $url ) {
			if ( '' !== $title ) {
				$this->set_text( $dom, $heading, $title );
			}
			return;
		}

		$new_anchor = $dom->createElement( 'a' );
		$new_anchor->setAttribute( 'href', $url );
		$this->apply_link_target_rel( $new_anchor, $external, $nofollow );
		$new_anchor->appendChild( $dom->createTextNode( '' !== $title ? $title : $heading->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		while ( $heading->firstChild ) {
			$heading->removeChild( $heading->firstChild );
		}
		$heading->appendChild( $new_anchor );
	}

	/**
	 * Replace an element's children with a single text node.
	 *
	 * @param \DOMDocument $dom  Owner document.
	 * @param \DOMElement  $node Element to update.
	 * @param string       $text New text content.
	 * @return void
	 */
	private function set_text( $dom, $node, $text ) {
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		$node->appendChild( $dom->createTextNode( $text ) );
	}

	/**
	 * Set target and rel attributes on a link to match the Elementor URL flags.
	 *
	 * @param \DOMElement $anchor   Anchor element.
	 * @param bool        $external External flag.
	 * @param bool        $nofollow Nofollow flag.
	 * @return void
	 */
	private function apply_link_target_rel( $anchor, $external, $nofollow ) {
		if ( $external ) {
			$anchor->setAttribute( 'target', '_blank' );
		}
		$rel_parts = array();
		if ( $external ) {
			$rel_parts[] = 'noopener';
		}
		if ( $nofollow ) {
			$rel_parts[] = 'nofollow';
		}
		if ( ! empty( $rel_parts ) ) {
			$anchor->setAttribute( 'rel', implode( ' ', array_unique( $rel_parts ) ) );
		}
	}
}
