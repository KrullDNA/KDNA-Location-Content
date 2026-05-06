<?php
/**
 * Multilingual JetEngine custom field type base class.
 *
 * Targets JetEngine 3.x. JetEngine's custom-field-type API is not formally
 * documented and does shift between versions, so this class registers via
 * the most commonly used filter and action names and falls back to a
 * direct save_post hook for the persistence path. If the JetEngine field
 * type dropdown does not display the new types on a particular install,
 * the post meta save path still works on any post that has a meta key
 * matching one of the pre-declared multilingual field names.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Multilingual_Base
 *
 * Shared behaviour for the three multilingual field types:
 *   - registers the type with JetEngine so the editor sees it in the
 *     field-type dropdown,
 *   - intercepts the meta-box render so editors see our tabbed UI,
 *   - sanitises and saves the per-language array on save_post,
 *   - provides a static reader so the matching dynamic widgets can
 *     pull a normalised array out of post meta in one call.
 *
 * Storage shape (one post meta row per multilingual field):
 *   array(
 *       'default' => 'value',
 *       'fr'      => 'value',
 *       'de'      => 'value',
 *       ...
 *   );
 */
abstract class KDNA_RC_Multilingual_Base {

	/**
	 * Return the unique field-type slug as JetEngine sees it.
	 *
	 * Stored on each meta-box field configuration row. Must be unique
	 * across every field-type plugin on the site.
	 *
	 * @return string
	 */
	abstract public function field_type_slug();

	/**
	 * Display label for the field-type dropdown.
	 *
	 * @return string
	 */
	abstract public function field_type_label();

	/**
	 * Render the per-language input for one tab.
	 *
	 * @param string $name    Form field name attribute.
	 * @param string $value   Current value for that language (or default).
	 * @param string $tab_key Either 'default' or a language slug.
	 * @param array  $args    Field args from JetEngine.
	 * @return void
	 */
	abstract protected function render_input( $name, $value, $tab_key, array $args );

	/**
	 * Sanitise a single language's value before storage.
	 *
	 * @param mixed $value Raw posted value.
	 * @return mixed
	 */
	abstract protected function sanitise_value( $value );

	/**
	 * Wire JetEngine integration hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Add the type to the JetEngine field-type dropdown.
		add_filter( 'jet-engine/meta-boxes/raw-fields-list', array( $this, 'register_in_field_list' ) );

		// Render the field. We use both action and filter names that
		// different JetEngine builds emit so the UI shows up regardless of
		// the host's specific version.
		add_action( 'jet-engine/meta-boxes/render-field/' . $this->field_type_slug(), array( $this, 'render_field' ), 10, 2 );
		add_filter( 'jet-engine/meta-boxes/field-args', array( $this, 'normalise_field_args' ), 10, 2 );

		// Persist on save_post for every post type. The handler itself
		// inspects the JetEngine config to find any field of our type
		// that lives on the post being saved.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Add this field type to the JetEngine field-type dropdown.
	 *
	 * @param array $list Existing field-type slug => label map.
	 * @return array
	 */
	public function register_in_field_list( $list ) {
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $this->field_type_slug() ] = $this->field_type_label();
		return $list;
	}

	/**
	 * Normalise field args so JetEngine treats our type sensibly when it
	 * iterates the registered fields list.
	 *
	 * @param array $args Field args.
	 * @param array $row  Meta-box row.
	 * @return array
	 */
	public function normalise_field_args( $args, $row = array() ) {
		unset( $row );
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( isset( $args['type'] ) && $args['type'] === $this->field_type_slug() ) {
			// Force JetEngine to treat the value as our serialised array
			// at the data layer. JetEngine's "object_type" hint helps its
			// listing components avoid auto-casting our array to a string.
			$args['object_type'] = 'array';
		}
		return $args;
	}

	/**
	 * Render the tabbed editor UI for a single field on a post.
	 *
	 * Called by JetEngine via its render-field action. We output a self-
	 * contained block that the multilingual-fields.js script binds tab
	 * switching, completion indicators, and (for image / wysiwyg) the
	 * media + editor wiring against.
	 *
	 * @param array $args  Field args.
	 * @param mixed $value Current meta value (already unserialised by JetEngine).
	 * @return void
	 */
	public function render_field( $args, $value = null ) {
		$name      = isset( $args['name'] ) ? (string) $args['name'] : '';
		$languages = ( new KDNA_RC_Languages() )->get_all();
		$values    = self::normalise_stored_value( $value );

		// Always render the Default tab plus one tab per configured
		// language. Order matches the Languages-tab order.
		$tabs = array();
		$tabs[] = array(
			'key'   => 'default',
			'name'  => __( 'Default', 'kdna-regional-content' ),
			'flag'  => '',
			'value' => isset( $values['default'] ) ? $values['default'] : '',
		);
		foreach ( $languages as $language ) {
			$slug = $language['slug'];
			$tabs[] = array(
				'key'   => $slug,
				'name'  => $language['name'],
				'flag'  => isset( $language['flag'] ) ? $language['flag'] : '',
				'value' => isset( $values[ $slug ] ) ? $values[ $slug ] : '',
			);
		}

		wp_nonce_field( 'kdna_rc_mlf_' . $name, 'kdna_rc_mlf_nonce_' . $name );

		echo '<div class="kdna-rc-mlf-editor" data-field-name="' . esc_attr( $name ) . '" data-type="' . esc_attr( $this->field_type_slug() ) . '">';
		echo '<ul class="kdna-rc-mlf-tablist" role="tablist">';
		foreach ( $tabs as $i => $tab ) {
			$tab_id   = 'kdna-rc-mlf-' . esc_attr( $name ) . '-' . esc_attr( $tab['key'] );
			$active   = 0 === $i ? ' is-active' : '';
			$selected = 0 === $i ? 'true' : 'false';
			$flag_cls = '' !== $tab['flag'] ? ' fi fi-' . esc_attr( $tab['flag'] ) : '';
			$has_val  = $this->is_value_present( $tab['value'] );
			echo '<li class="kdna-rc-mlf-tab' . $active . '" role="tab" aria-selected="' . $selected . '" tabindex="' . ( 0 === $i ? '0' : '-1' ) . '" data-tab="' . esc_attr( $tab['key'] ) . '" aria-controls="' . esc_attr( $tab_id ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $active and $selected are class/literal strings only.
			if ( '' !== $flag_cls ) {
				echo '<span class="kdna-rc-mlf-tab-flag' . $flag_cls . '" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			echo '<span class="kdna-rc-mlf-tab-label">' . esc_html( $tab['name'] ) . '</span>';
			echo '<span class="kdna-rc-mlf-tab-status ' . ( $has_val ? 'is-filled' : 'is-empty' ) . '" aria-hidden="true" title="' . ( $has_val ? esc_attr__( 'Has value', 'kdna-regional-content' ) : esc_attr__( 'Empty (will fall back to default)', 'kdna-regional-content' ) ) . '"></span>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="kdna-rc-mlf-panels">';
		foreach ( $tabs as $i => $tab ) {
			$tab_id  = 'kdna-rc-mlf-' . esc_attr( $name ) . '-' . esc_attr( $tab['key'] );
			$hidden  = 0 === $i ? '' : ' hidden';
			$input_name = $name . '[' . $tab['key'] . ']';
			echo '<div class="kdna-rc-mlf-panel" id="' . esc_attr( $tab_id ) . '" role="tabpanel" data-tab="' . esc_attr( $tab['key'] ) . '"' . $hidden . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden is literal.
			$this->render_input( $input_name, $tab['value'], $tab['key'], is_array( $args ) ? $args : array() );
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * save_post handler that walks JetEngine's field config for the post
	 * type, finds every field whose type matches ours, and writes a
	 * sanitised serialised array to the corresponding post meta key.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! is_object( $post ) || empty( $post->post_type ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = $this->discover_fields_for_post_type( $post->post_type );
		if ( empty( $fields ) ) {
			return;
		}

		$languages = ( new KDNA_RC_Languages() )->get_all();
		$slugs     = array_map(
			function ( $l ) { return $l['slug']; },
			$languages
		);

		foreach ( $fields as $field_name ) {
			$nonce_field = 'kdna_rc_mlf_nonce_' . $field_name;
			if ( empty( $_POST[ $nonce_field ] ) ) {
				continue;
			}
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), 'kdna_rc_mlf_' . $field_name ) ) {
				continue;
			}
			if ( ! isset( $_POST[ $field_name ] ) ) {
				continue;
			}

			$raw = wp_unslash( $_POST[ $field_name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per-tab below.
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$clean = array();
			$clean['default'] = $this->sanitise_value( isset( $raw['default'] ) ? $raw['default'] : '' );
			foreach ( $slugs as $slug ) {
				$clean[ $slug ] = $this->sanitise_value( isset( $raw[ $slug ] ) ? $raw[ $slug ] : '' );
			}

			update_post_meta( $post_id, $field_name, $clean );
		}
	}

	/**
	 * Find every field on a post type that has our field-type slug.
	 *
	 * Reads JetEngine's meta-box configuration directly from its option
	 * row. Falls back to an empty array on any structural variance, so
	 * older or newer JetEngine versions never throw fatals here.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int,string>
	 */
	private function discover_fields_for_post_type( $post_type ) {
		$out = array();

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
			$applies_to = isset( $box['args']['allowed_post_type'] ) ? (array) $box['args']['allowed_post_type'] : array();
			if ( empty( $applies_to ) ) {
				$applies_to = isset( $box['args']['post_type'] ) ? (array) $box['args']['post_type'] : array();
			}
			if ( ! in_array( $post_type, $applies_to, true ) ) {
				continue;
			}
			$fields = isset( $box['meta_fields'] ) && is_array( $box['meta_fields'] ) ? $box['meta_fields'] : array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( $type === $this->field_type_slug() && '' !== $name ) {
					$out[] = $name;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Coerce stored or fresh-form input into the canonical multilingual
	 * shape so render_field() never has to deal with mixed types.
	 *
	 * @param mixed $value Raw stored / submitted value.
	 * @return array<string,mixed>
	 */
	public static function normalise_stored_value( $value ) {
		$out = array( 'default' => '' );
		foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
			$out[ $language['slug'] ] = '';
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $val ) {
				$out[ (string) $key ] = $val;
			}
		} elseif ( is_string( $value ) || is_numeric( $value ) ) {
			// Field hasn't been migrated to the multilingual shape yet:
			// treat the legacy value as the Default tab content.
			$out['default'] = $value;
		}

		return $out;
	}

	/**
	 * Whether a per-tab value is present (drives the tab completion dot).
	 *
	 * @param mixed $value Tab value.
	 * @return bool
	 */
	protected function is_value_present( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		return false;
	}

	/**
	 * Resolve a stored multilingual value to the visitor's preferred
	 * language, falling back to the default tab.
	 *
	 * Used by the matching dynamic widgets at render time.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $language Visitor language slug, or empty for default.
	 * @return mixed
	 */
	public static function resolve_value( $post_id, $meta_key, $language = '' ) {
		$raw    = get_post_meta( (int) $post_id, $meta_key, true );
		$values = self::normalise_stored_value( $raw );

		$language = sanitize_key( $language );
		if ( '' !== $language && isset( $values[ $language ] ) ) {
			$present = $values[ $language ];
			if ( is_string( $present ) && '' !== trim( $present ) ) {
				return $present;
			}
			if ( is_numeric( $present ) && (int) $present > 0 ) {
				return $present;
			}
		}
		return isset( $values['default'] ) ? $values['default'] : '';
	}
}
