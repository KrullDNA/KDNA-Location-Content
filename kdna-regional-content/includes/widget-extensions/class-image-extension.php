<?php
/**
 * Image widget regional content variant extension.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Image_Extension
 *
 * Adds a Regional Content controls section to the Elementor Image widget.
 * Each variant supplies an Image (media picker), Alt, and Link override.
 * On render the widget output is wrapped in a kdna-rc-variant-wrapper with
 * the original as the default and one hidden variant per row.
 */
class KDNA_RC_Image_Extension extends KDNA_RC_Variants_Base {

	const FIELD_IMAGE = 'kdna_rc_image';
	const FIELD_ALT   = 'kdna_rc_alt';
	const FIELD_LINK  = 'kdna_rc_link';

	/**
	 * Elementor widget name targeted by this extension.
	 *
	 * @return string
	 */
	protected function widget_name() {
		return 'image';
	}

	/**
	 * Elementor controls section the Regional Content panel is appended to.
	 *
	 * @return string
	 */
	protected function controls_section() {
		return 'section_image';
	}

	/**
	 * Add Image, Alt, and Link override fields to each repeater row.
	 *
	 * @param \Elementor\Repeater $repeater Repeater being built.
	 * @return void
	 */
	protected function register_variant_fields( $repeater ) {
		$repeater->add_control(
			self::FIELD_IMAGE,
			array(
				'label'   => __( 'Image', 'kdna-regional-content' ),
				'type'    => defined( 'Elementor\\Controls_Manager::MEDIA' ) ? \Elementor\Controls_Manager::MEDIA : 'media',
				'default' => array(
					'url' => '',
				),
			)
		);

		$repeater->add_control(
			self::FIELD_ALT,
			array(
				'label'       => __( 'Alt Text', 'kdna-regional-content' ),
				'type'        => defined( 'Elementor\\Controls_Manager::TEXT' ) ? \Elementor\Controls_Manager::TEXT : 'text',
				'default'     => '',
				'placeholder' => __( 'Alt text for screen readers', 'kdna-regional-content' ),
				'description' => __( 'Optional. Leave blank to keep the original image\'s alt text.', 'kdna-regional-content' ),
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
				'description' => __( 'Optional. Leave blank to keep the widget\'s default link behaviour.', 'kdna-regional-content' ),
			)
		);
	}

	/**
	 * Build the variant HTML by patching the image src/alt and any link wrapper.
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

		$img = $root->getElementsByTagName( 'img' )->item( 0 );
		if ( null === $img ) {
			return $content;
		}

		$image = isset( $variant[ self::FIELD_IMAGE ] ) && is_array( $variant[ self::FIELD_IMAGE ] ) ? $variant[ self::FIELD_IMAGE ] : array();
		$alt   = isset( $variant[ self::FIELD_ALT ] ) ? trim( (string) $variant[ self::FIELD_ALT ] ) : '';
		$link  = isset( $variant[ self::FIELD_LINK ] ) && is_array( $variant[ self::FIELD_LINK ] ) ? $variant[ self::FIELD_LINK ] : array();

		// Image source override.
		$src = isset( $image['url'] ) ? trim( (string) $image['url'] ) : '';
		if ( '' !== $src ) {
			$img->setAttribute( 'src', $src );
			// Source-set is tied to the original image dimensions; clear it so
			// browsers do not pick a stale URL from the original asset.
			if ( $img->hasAttribute( 'srcset' ) ) {
				$img->removeAttribute( 'srcset' );
			}
			if ( $img->hasAttribute( 'sizes' ) ) {
				$img->removeAttribute( 'sizes' );
			}
			// Update width/height when the editor picked an attachment we can size.
			if ( ! empty( $image['id'] ) && function_exists( 'wp_get_attachment_image_src' ) ) {
				$meta = wp_get_attachment_image_src( (int) $image['id'], 'full' );
				if ( is_array( $meta ) && isset( $meta[1], $meta[2] ) ) {
					$img->setAttribute( 'width', (string) (int) $meta[1] );
					$img->setAttribute( 'height', (string) (int) $meta[2] );
				}
			}
			// If the variant supplies an attachment id, also update alt from
			// the attachment metadata when the variant alt field is blank.
			if ( '' === $alt && ! empty( $image['id'] ) && function_exists( 'get_post_meta' ) ) {
				$attached_alt = get_post_meta( (int) $image['id'], '_wp_attachment_image_alt', true );
				if ( is_string( $attached_alt ) && '' !== $attached_alt ) {
					$alt = $attached_alt;
				}
			}
		}

		// Alt-text override.
		if ( '' !== $alt ) {
			$img->setAttribute( 'alt', $alt );
		}

		// Link override: wrap or replace the surrounding anchor.
		$url = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
		if ( '' !== $url ) {
			$existing_anchor = null;
			$parent          = $img->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $parent && 'a' === strtolower( $parent->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$existing_anchor = $parent;
			}

			if ( $existing_anchor ) {
				$existing_anchor->setAttribute( 'href', $url );
			} else {
				$anchor = $dom->createElement( 'a' );
				$anchor->setAttribute( 'href', $url );
				$img->parentNode->insertBefore( $anchor, $img ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$anchor->appendChild( $img );
				$existing_anchor = $anchor;
			}

			if ( ! empty( $link['is_external'] ) ) {
				$existing_anchor->setAttribute( 'target', '_blank' );
			}
			$rel = array();
			if ( ! empty( $link['is_external'] ) ) {
				$rel[] = 'noopener';
			}
			if ( ! empty( $link['nofollow'] ) ) {
				$rel[] = 'nofollow';
			}
			if ( ! empty( $rel ) ) {
				$existing_anchor->setAttribute( 'rel', implode( ' ', array_unique( $rel ) ) );
			}
		}

		return $this->dom_to_html( $dom, $root );
	}
}
