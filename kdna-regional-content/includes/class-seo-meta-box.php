<?php
/**
 * Regional & Language SEO meta box.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Meta_Box
 *
 * Adds a "Regional & Language SEO" meta box on every post type that
 * (a) has Yoast meta enabled and (b) is eligible for Stage 14 URL
 * routing. The box renders a tabbed UI: Default (read-only, shows
 * Yoast's current values) plus one tab per configured region and one
 * tab per configured language. Editors fill in the fields they want
 * to override; empty fields fall back to Yoast's default at render
 * time via the matching post-meta-not-set behaviour in
 * KDNA_RC_Yoast_Integration.
 *
 * Storage: each non-empty override is written to a suffixed meta key
 * such as `_yoast_wpseo_title_au` or `_yoast_wpseo_metadesc_fr`.
 * Empty values delete the row so subsequent loads correctly fall
 * through to Yoast's default rather than storing empty strings that
 * would override.
 *
 * Targets Yoast SEO 21.x.
 */
class KDNA_RC_SEO_Meta_Box {

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'kdna_rc_seo_meta_box';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'kdna_rc_seo_meta_nonce';

	/**
	 * The Yoast meta keys this meta box manages, mapped to the input
	 * type used to render and sanitise each one.
	 *
	 * Single source of truth for the fields list. Adding a new field
	 * here is enough; render + save loops over the map.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public static function fields() {
		return array(
			'_yoast_wpseo_title'             => array( 'label' => __( 'SEO Title', 'kdna-regional-content' ), 'type' => 'text' ),
			'_yoast_wpseo_metadesc'          => array( 'label' => __( 'Meta Description', 'kdna-regional-content' ), 'type' => 'textarea' ),
			'_yoast_wpseo_focuskw'           => array( 'label' => __( 'Focus Keyphrase', 'kdna-regional-content' ), 'type' => 'text' ),
			'_yoast_wpseo_canonical'         => array( 'label' => __( 'Canonical URL', 'kdna-regional-content' ), 'type' => 'url' ),
			'_yoast_wpseo_opengraph-title'   => array( 'label' => __( 'OG Title', 'kdna-regional-content' ), 'type' => 'text' ),
			'_yoast_wpseo_opengraph-description' => array( 'label' => __( 'OG Description', 'kdna-regional-content' ), 'type' => 'textarea' ),
			'_yoast_wpseo_opengraph-image-id' => array( 'label' => __( 'OG Image', 'kdna-regional-content' ), 'type' => 'media' ),
			'_yoast_wpseo_localbusiness_address' => array( 'label' => __( 'Local Business Address', 'kdna-regional-content' ), 'type' => 'textarea' ),
			'_yoast_wpseo_localbusiness_phone' => array( 'label' => __( 'Local Business Phone', 'kdna-regional-content' ), 'type' => 'text' ),
		);
	}

	/**
	 * Wire admin hooks. No-ops when Yoast is not active.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 30, 2 );
	}

	/**
	 * Register the meta box on every eligible post type.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = $this->eligible_post_types();
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'kdna_rc_seo_meta',
				__( 'Regional & Language SEO', 'kdna-regional-content' ),
				array( $this, 'render' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Eligible post types: intersect Stage 14's URL-routing whitelist with
	 * any post type Yoast manages.
	 *
	 * @return array<int,string>
	 */
	public function eligible_post_types() {
		if ( ! class_exists( 'KDNA_RC_URL_Routing' ) ) {
			return array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		}
		return ( new KDNA_RC_URL_Routing() )->eligible_post_types();
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$regions   = ( new KDNA_RC_Regions() )->get_all();
		$languages = ( new KDNA_RC_Languages() )->get_all();

		// Build the tab list: Default first, then each region, then each language.
		$tabs = array();
		$tabs[] = array( 'key' => 'default', 'label' => __( 'Default', 'kdna-regional-content' ), 'flag' => '', 'kind' => 'default' );
		foreach ( $regions as $region ) {
			$tabs[] = array(
				'key'   => 'r-' . $region['slug'],
				'label' => $region['name'],
				'flag'  => '', // Region flags are country-mapped on the user side; left empty to keep tabs uncluttered.
				'kind'  => 'region',
				'slug'  => $region['slug'],
			);
		}
		foreach ( $languages as $language ) {
			$tabs[] = array(
				'key'   => 'l-' . $language['slug'],
				'label' => $language['name'],
				'flag'  => isset( $language['flag'] ) ? $language['flag'] : '',
				'kind'  => 'language',
				'slug'  => $language['slug'],
			);
		}

		echo '<div class="kdna-rc-mlf-editor kdna-rc-seo-editor" data-post-id="' . esc_attr( $post->ID ) . '">';
		echo '<ul class="kdna-rc-mlf-tablist" role="tablist">';
		foreach ( $tabs as $i => $tab ) {
			$has_value = $this->tab_has_value( $post->ID, $tab );
			$active    = 0 === $i ? ' is-active' : '';
			$selected  = 0 === $i ? 'true' : 'false';
			$flag_cls  = '' !== $tab['flag'] ? ' fi fi-' . esc_attr( $tab['flag'] ) : '';
			echo '<li class="kdna-rc-mlf-tab' . $active . '" role="tab" aria-selected="' . $selected . '" tabindex="' . ( 0 === $i ? '0' : '-1' ) . '" data-tab="' . esc_attr( $tab['key'] ) . '" aria-controls="kdna-rc-seo-tab-' . esc_attr( $tab['key'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $active and $selected are literal class strings.
			if ( '' !== $flag_cls ) {
				echo '<span class="kdna-rc-mlf-tab-flag' . $flag_cls . '" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_attr() above.
			}
			echo '<span class="kdna-rc-mlf-tab-label">' . esc_html( $tab['label'] ) . '</span>';
			if ( 'default' !== $tab['kind'] ) {
				echo '<span class="kdna-rc-mlf-tab-status ' . ( $has_value ? 'is-filled' : 'is-empty' ) . '" aria-hidden="true"></span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="kdna-rc-mlf-panels">';
		foreach ( $tabs as $i => $tab ) {
			$hidden = 0 === $i ? '' : ' hidden';
			echo '<div class="kdna-rc-mlf-panel" id="kdna-rc-seo-tab-' . esc_attr( $tab['key'] ) . '" role="tabpanel" data-tab="' . esc_attr( $tab['key'] ) . '"' . $hidden . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden is literal.
			$this->render_panel( $post->ID, $tab );
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the form (or read-only view for Default) for a single tab.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tab     Tab descriptor.
	 * @return void
	 */
	private function render_panel( $post_id, array $tab ) {
		$is_default = 'default' === $tab['kind'];
		$slug       = isset( $tab['slug'] ) ? $tab['slug'] : '';

		if ( $is_default ) {
			echo '<p class="description">' . esc_html__( 'These are the values Yoast SEO currently uses for this post. Edit them on the Yoast meta box above. They are the fall-back when a region or language tab leaves a field blank.', 'kdna-regional-content' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Override Yoast values for visitors arriving via this region or language URL. Leave a field blank to keep the Default value.', 'kdna-regional-content' ) . '</p>';
		}

		echo '<table class="form-table"><tbody>';
		foreach ( self::fields() as $key => $cfg ) {
			$default_value  = (string) get_post_meta( $post_id, $key, true );
			$override_value = '' !== $slug ? get_post_meta( $post_id, $key . '_' . $slug, true ) : '';
			$display_value  = $is_default ? $default_value : ( '' !== (string) $override_value ? $override_value : '' );

			$input_name = '' !== $slug ? 'kdna_rc_seo[' . $slug . '][' . $key . ']' : '';

			echo '<tr><th scope="row"><label>' . esc_html( $cfg['label'] ) . '</label></th><td>';
			$this->render_input( $cfg['type'], $input_name, $display_value, $is_default );
			if ( ! $is_default && '' !== $default_value ) {
				echo '<p class="description"><em>' . esc_html__( 'Default:', 'kdna-regional-content' ) . '</em> ';
				if ( 'media' === $cfg['type'] ) {
					$thumb = wp_get_attachment_image_url( (int) $default_value, 'thumbnail' );
					echo $thumb ? '<img src="' . esc_url( $thumb ) . '" style="max-height:48px;border:1px solid #ddd;" alt="" />' : esc_html( $default_value );
				} else {
					echo esc_html( $default_value );
				}
				echo '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render a single input. Default tab inputs are disabled.
	 *
	 * @param string $type        Field type.
	 * @param string $name        Form field name (empty for default tab).
	 * @param mixed  $value       Current value.
	 * @param bool   $is_default  Whether the panel is the read-only default.
	 * @return void
	 */
	private function render_input( $type, $name, $value, $is_default ) {
		$disabled = $is_default ? ' disabled' : '';

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea name="%1$s" rows="3" class="large-text"%2$s>%3$s</textarea>',
					esc_attr( $name ),
					$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
					esc_textarea( is_string( $value ) ? $value : '' )
				);
				break;

			case 'media':
				$attachment_id = absint( is_scalar( $value ) ? $value : 0 );
				$thumb_url     = $attachment_id > 0 ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
				$has_image     = $attachment_id > 0 && '' !== $thumb_url;

				if ( $is_default ) {
					echo $has_image ? '<img src="' . esc_url( $thumb_url ) . '" style="max-height:120px;border:1px solid #ddd;" alt="" />' : '<em>' . esc_html__( 'No image set.', 'kdna-regional-content' ) . '</em>';
					break;
				}

				echo '<div class="kdna-rc-mlf-image-picker' . ( $has_image ? ' has-image' : '' ) . '">';
				echo '<div class="kdna-rc-mlf-image-preview">';
				if ( $has_image ) {
					echo '<img src="' . esc_url( $thumb_url ) . '" alt="" />';
				} else {
					echo '<span class="kdna-rc-mlf-image-empty">' . esc_html__( 'No image selected', 'kdna-regional-content' ) . '</span>';
				}
				echo '</div>';
				echo '<p class="kdna-rc-mlf-image-actions">';
				echo '<button type="button" class="button kdna-rc-mlf-image-choose">' . esc_html__( 'Select Image', 'kdna-regional-content' ) . '</button> ';
				echo '<button type="button" class="button-link kdna-rc-mlf-image-remove"' . ( $has_image ? '' : ' style="display:none"' ) . '>' . esc_html__( 'Remove', 'kdna-regional-content' ) . '</button>';
				echo '</p>';
				printf(
					'<input type="hidden" class="kdna-rc-mlf-image-id" name="%1$s" value="%2$s" />',
					esc_attr( $name ),
					esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' )
				);
				echo '</div>';
				break;

			case 'url':
				printf(
					'<input type="url" name="%1$s" value="%2$s" class="regular-text"%3$s />',
					esc_attr( $name ),
					esc_attr( is_string( $value ) ? $value : '' ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" name="%1$s" value="%2$s" class="regular-text"%3$s />',
					esc_attr( $name ),
					esc_attr( is_string( $value ) ? $value : '' ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
				);
				break;
		}
	}

	/**
	 * Whether any field on a tab has an override value (drives the dot).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tab     Tab descriptor.
	 * @return bool
	 */
	private function tab_has_value( $post_id, array $tab ) {
		if ( 'default' === $tab['kind'] ) {
			return false;
		}
		$slug = isset( $tab['slug'] ) ? $tab['slug'] : '';
		if ( '' === $slug ) {
			return false;
		}
		foreach ( array_keys( self::fields() ) as $key ) {
			$value = get_post_meta( $post_id, $key . '_' . $slug, true );
			if ( '' !== (string) $value && '0' !== (string) $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Persist the meta box on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! is_object( $post ) ) { return; }
		if ( empty( $_POST[ self::NONCE_NAME ] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, $this->eligible_post_types(), true ) ) { return; }

		$payload = isset( $_POST['kdna_rc_seo'] ) && is_array( $_POST['kdna_rc_seo'] ) ? wp_unslash( $_POST['kdna_rc_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per-field below.
		if ( empty( $payload ) ) { return; }

		$fields = self::fields();
		foreach ( $payload as $slug => $values ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || ! is_array( $values ) ) { continue; }

			foreach ( $fields as $key => $cfg ) {
				$meta_key = $key . '_' . $slug;
				$raw      = isset( $values[ $key ] ) ? $values[ $key ] : '';
				$clean    = $this->sanitise_value( $cfg['type'], $raw );

				// Empty -> delete so render falls back to Yoast default.
				if ( '' === $clean || ( 'media' === $cfg['type'] && 0 === (int) $clean ) ) {
					delete_post_meta( $post_id, $meta_key );
					continue;
				}
				update_post_meta( $post_id, $meta_key, $clean );
			}
		}
	}

	/**
	 * Sanitise a single field value per its type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private function sanitise_value( $type, $value ) {
		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
			case 'media':
				return absint( is_scalar( $value ) ? $value : 0 );
			case 'url':
				return esc_url_raw( is_scalar( $value ) ? (string) $value : '' );
			case 'text':
			default:
				return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	/**
	 * Read the override value for a key + slug, falling back to Yoast's default.
	 *
	 * Used by KDNA_RC_Yoast_Integration at render time.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $base_key Yoast meta key (e.g. _yoast_wpseo_title).
	 * @param string $slug     Region or language slug.
	 * @return string
	 */
	public static function read_override( $post_id, $base_key, $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}
		$value = get_post_meta( (int) $post_id, $base_key . '_' . $slug, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return (string) $value;
		}
		return '';
	}
}
