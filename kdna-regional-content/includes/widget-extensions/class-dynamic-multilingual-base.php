<?php
/**
 * Shared base for the three Dynamic Multilingual Elementor widgets.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Dynamic_Multilingual_Base
 *
 * Renders a multilingual JetEngine field on the front end. Server-side
 * rendering packs every per-language value into data-kdna-mlf-{slug}
 * attributes so frontend.js can swap content client-side without an AJAX
 * round trip. Default language is the visible content; the JS swap walks
 * the page once language detection completes.
 *
 * Subclasses pick:
 *   - the field types they accept (Text, Image, WYSIWYG),
 *   - the markup of the visible default value,
 *   - the encoding rule for data attributes (Image stores resolved URLs;
 *     WYSIWYG base64-encodes HTML; Text stores raw strings).
 *
 * Empty values do NOT produce a data attribute, so the JS swap naturally
 * falls back to the default. The fallback Behaviour control on the
 * Content tab decides what happens when the chosen language is empty.
 */
abstract class KDNA_RC_Dynamic_Multilingual_Base extends \Elementor\Widget_Base {

	/**
	 * Return the slug used by the JetEngine field type this widget reads.
	 *
	 * @return string
	 */
	abstract protected function source_field_type();

	/**
	 * Editor categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'kdna-widgets' );
	}

	/**
	 * Disable the inner widget wrapper when atomic markup is enabled.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		if ( method_exists( '\\Elementor\\Plugin', 'instance' ) ) {
			$features = \Elementor\Plugin::instance()->experiments;
			if ( $features && method_exists( $features, 'is_feature_active' ) && $features->is_feature_active( 'e_optimized_markup' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Discover JetEngine fields of the requested types across every meta box.
	 *
	 * Used to populate the Field Source dropdown. Returns a slug => label
	 * map sorted by label so editors find the field they want quickly.
	 *
	 * @param array<int,string> $accept_types Slugs to keep.
	 * @return array<string,string>
	 */
	protected function discover_jetengine_fields( array $accept_types ) {
		$out    = array();
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return $out;
		}

		foreach ( $option as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			$fields = isset( $box['meta_fields'] ) && is_array( $box['meta_fields'] ) ? $box['meta_fields'] : array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' === $name || ! in_array( $type, $accept_types, true ) ) {
					continue;
				}
				$label   = isset( $field['title'] ) ? (string) $field['title'] : $name;
				$out[ $name ] = sprintf( '%1$s (%2$s)', $label, $name );
			}
		}

		asort( $out );
		return $out;
	}

	/**
	 * Add the shared Field Source + fallback behaviour controls.
	 *
	 * @param array<int,string> $accept_types Field type slugs the dropdown will list.
	 * @return void
	 */
	protected function add_shared_content_controls( array $accept_types ) {
		$choices = $this->discover_jetengine_fields( $accept_types );

		if ( empty( $choices ) ) {
			$this->add_control(
				'kdna_rc_no_fields_notice',
				array(
					'type'            => \Elementor\Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'No JetEngine multilingual fields found. Create one under JetEngine, Meta Boxes first.', 'kdna-regional-content' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
		}

		$this->add_control(
			'field_source',
			array(
				'label'       => esc_html__( 'Field Source', 'kdna-regional-content' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array_merge( array( '' => esc_html__( 'Select a field...', 'kdna-regional-content' ) ), $choices ),
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'fallback_behaviour',
			array(
				'label'   => esc_html__( 'When language has no value', 'kdna-regional-content' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'default'     => array(
						'title' => esc_html__( 'Show Default', 'kdna-regional-content' ),
						'icon'  => 'eicon-text',
					),
					'hide'        => array(
						'title' => esc_html__( 'Hide widget', 'kdna-regional-content' ),
						'icon'  => 'eicon-eye',
					),
					'placeholder' => array(
						'title' => esc_html__( 'Placeholder', 'kdna-regional-content' ),
						'icon'  => 'eicon-typography',
					),
				),
				'default' => 'default',
				'toggle'  => false,
			)
		);

		$this->add_control(
			'fallback_placeholder',
			array(
				'label'     => esc_html__( 'Placeholder Text', 'kdna-regional-content' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array( 'fallback_behaviour' => 'placeholder' ),
			)
		);
	}

	/**
	 * Resolve the post ID this widget is rendering against.
	 *
	 * Inside JetEngine listings the global $post is the loop item; on a
	 * single post page get_queried_object_id() is reliable. We use both.
	 *
	 * @return int
	 */
	protected function resolve_post_id() {
		$post_id = (int) get_the_ID();
		if ( $post_id > 0 ) {
			return $post_id;
		}
		return (int) get_queried_object_id();
	}

	/**
	 * Build the visitor-language-keyed map for one stored multilingual value.
	 *
	 * Returns array of slug => display value. The 'default' key is always
	 * present. Empty values (per is_value_present()) are stripped from the
	 * map so the JS swap naturally falls through to default.
	 *
	 * @param mixed $stored Raw stored value (already unserialised by WP).
	 * @return array<string,mixed>
	 */
	protected function build_value_map( $stored ) {
		$normalised = KDNA_RC_Multilingual_Base::normalise_stored_value( $stored );
		$out        = array();
		foreach ( $normalised as $slug => $value ) {
			if ( $this->is_displayable( $value ) ) {
				$out[ $slug ] = $value;
			}
		}
		// Always carry 'default' even when empty so render_default() has a
		// stable key to read from.
		if ( ! isset( $out['default'] ) ) {
			$out['default'] = isset( $normalised['default'] ) ? $normalised['default'] : '';
		}
		return $out;
	}

	/**
	 * Whether a per-language value is considered "has content".
	 *
	 * @param mixed $value Tab value.
	 * @return bool
	 */
	protected function is_displayable( $value ) {
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		return ! empty( $value );
	}
}
