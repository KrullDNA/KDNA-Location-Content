<?php
/**
 * Languages module storage and AJAX handlers.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Languages
 *
 * Single source of truth for the languages list. Modelled on
 * KDNA_RC_Regions: serialised array stored under one option key, the same
 * CRUD method shape, and a triplet of admin AJAX endpoints for
 * add / edit / delete / reorder driven by the Languages admin tab.
 *
 * Stored shape (each element):
 *   [
 *     'slug' => 'fr',
 *     'name' => 'Français',
 *     'flag' => 'fr',   // ISO 3166-1 alpha-2 code consumed by flag-icons.
 *   ]
 */
class KDNA_RC_Languages {

	/**
	 * Option key holding the serialised languages array.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'kdna_rc_languages';

	/**
	 * AJAX action for save (insert or update).
	 *
	 * @var string
	 */
	const AJAX_SAVE = 'kdna_rc_save_language';

	/**
	 * AJAX action for delete.
	 *
	 * @var string
	 */
	const AJAX_DELETE = 'kdna_rc_delete_language';

	/**
	 * AJAX action for reorder.
	 *
	 * @var string
	 */
	const AJAX_REORDER = 'kdna_rc_reorder_languages';

	/**
	 * Cache of the bundled language library, keyed by slug.
	 *
	 * @var array<string,array>|null
	 */
	private static $library_index = null;

	/**
	 * Wire up admin AJAX endpoints. Called from KDNA_RC_Plugin in admin context.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_DELETE, array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_' . self::AJAX_REORDER, array( $this, 'ajax_reorder' ) );
	}

	/**
	 * Return every configured language in display order.
	 *
	 * @return array<int,array>
	 */
	public function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $language ) {
			if ( is_array( $language ) && ! empty( $language['slug'] ) ) {
				$clean[] = $this->normalise_for_read( $language );
			}
		}
		return $clean;
	}

	/**
	 * Return a single language by slug, or null when not found.
	 *
	 * @param string $slug Language slug.
	 * @return array|null
	 */
	public function get( $slug ) {
		$slug = $this->sanitise_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $this->get_all() as $language ) {
			if ( $language['slug'] === $slug ) {
				return $language;
			}
		}
		return null;
	}

	/**
	 * Insert or update a language.
	 *
	 * Mirrors KDNA_RC_Regions::save(): the optional $original_slug parameter
	 * lets the AJAX layer rename a language without losing its position in
	 * the order.
	 *
	 * @param array  $input         Raw language data.
	 * @param string $original_slug Existing slug when renaming, or empty.
	 * @return array|WP_Error
	 */
	public function save( array $input, $original_slug = '' ) {
		$language = $this->sanitise( $input );
		$errors   = $this->validate( $language );
		if ( $errors instanceof WP_Error ) {
			return $errors;
		}

		$all           = $this->get_all();
		$original_slug = $this->sanitise_slug( $original_slug );

		foreach ( $all as $existing ) {
			if ( $existing['slug'] === $language['slug'] && $existing['slug'] !== $original_slug ) {
				return new WP_Error( 'kdna_rc_lang_slug_taken', __( 'That language slug is already in use.', 'kdna-regional-content' ) );
			}
		}

		$replaced = false;
		if ( '' !== $original_slug ) {
			foreach ( $all as $i => $existing ) {
				if ( $existing['slug'] === $original_slug ) {
					$all[ $i ] = $language;
					$replaced  = true;
					break;
				}
			}
		}
		if ( ! $replaced ) {
			$all[] = $language;
		}

		update_option( self::OPTION_KEY, $all, false );
		return $language;
	}

	/**
	 * Remove a language by slug.
	 *
	 * Also clears the configured Default Language setting and any
	 * region-level default_language pointer that referenced the removed slug
	 * so admin UIs never show a stale value.
	 *
	 * @param string $slug Language slug.
	 * @return bool
	 */
	public function delete( $slug ) {
		$slug = $this->sanitise_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$all      = $this->get_all();
		$filtered = array();
		$removed  = false;
		foreach ( $all as $language ) {
			if ( $language['slug'] === $slug ) {
				$removed = true;
				continue;
			}
			$filtered[] = $language;
		}

		if ( ! $removed ) {
			return false;
		}

		update_option( self::OPTION_KEY, $filtered, false );

		// Clear the Default Language setting when the removed slug was it.
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( is_array( $settings ) && isset( $settings['default_language'] ) && $settings['default_language'] === $slug ) {
			$settings['default_language'] = '';
			update_option( KDNA_RC_OPTION_SETTINGS, $settings, false );
		}

		// Clear per-region default_language pointers.
		$regions_handler = new KDNA_RC_Regions();
		foreach ( $regions_handler->get_all() as $region ) {
			if ( ! empty( $region['default_language'] ) && $region['default_language'] === $slug ) {
				$region['default_language'] = '';
				$regions_handler->save( $region, $region['slug'] );
			}
		}

		return true;
	}

	/**
	 * Persist a new ordering for the languages list.
	 *
	 * @param array<int,string> $slugs Slugs in their new order.
	 * @return bool
	 */
	public function reorder( array $slugs ) {
		$all = $this->get_all();
		if ( empty( $all ) ) {
			return false;
		}

		$by_slug = array();
		foreach ( $all as $language ) {
			$by_slug[ $language['slug'] ] = $language;
		}

		$ordered = array();
		foreach ( $slugs as $slug ) {
			$slug = $this->sanitise_slug( $slug );
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
		foreach ( $by_slug as $language ) {
			$ordered[] = $language;
		}

		update_option( self::OPTION_KEY, $ordered, false );
		return true;
	}

	/**
	 * Generate a unique slug from a free-text name.
	 *
	 * @param string $name         Display name.
	 * @param string $exclude_slug Slug to ignore during uniqueness check.
	 * @return string
	 */
	public function generate_unique_slug( $name, $exclude_slug = '' ) {
		$base = $this->sanitise_slug( sanitize_title( $name ) );
		if ( '' === $base ) {
			$base = 'lang';
		}

		$existing = array();
		foreach ( $this->get_all() as $language ) {
			$existing[ $language['slug'] ] = true;
		}
		if ( '' !== $exclude_slug ) {
			unset( $existing[ $exclude_slug ] );
		}

		$slug    = $base;
		$counter = 2;
		while ( isset( $existing[ $slug ] ) ) {
			$slug = $base . '-' . $counter;
			++$counter;
		}
		return $slug;
	}

	/**
	 * Return the bundled language library as slug => row.
	 *
	 * Library entries serve as pre-set starter values when an admin uses the
	 * "Import from Library" button on the Languages tab. Loaded once per
	 * request from data/languages.json.
	 *
	 * @return array<string,array>
	 */
	public static function library() {
		if ( is_array( self::$library_index ) ) {
			return self::$library_index;
		}

		$path = KDNA_RC_PLUGIN_DIR . 'data/languages.json';
		$out  = array();
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $json ) ) {
				foreach ( $json as $row ) {
					if ( ! isset( $row['slug'], $row['name'] ) ) {
						continue;
					}
					$slug         = strtolower( (string) $row['slug'] );
					$out[ $slug ] = array(
						'slug' => $slug,
						'name' => (string) $row['name'],
						'flag' => isset( $row['flag'] ) ? strtolower( (string) $row['flag'] ) : '',
					);
				}
			}
		}
		self::$library_index = $out;
		return $out;
	}

	/**
	 * AJAX: save a language.
	 *
	 * @return void
	 */
	public function ajax_save() {
		$this->guard_ajax();

		$raw           = isset( $_POST['language'] ) && is_array( $_POST['language'] ) ? wp_unslash( $_POST['language'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$original_slug = isset( $_POST['original_slug'] ) ? sanitize_key( wp_unslash( $_POST['original_slug'] ) ) : '';

		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No language data was submitted.', 'kdna-regional-content' ) ), 400 );
		}

		$result = $this->save( $raw, $original_slug );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Language saved.', 'kdna-regional-content' ),
				'language'  => $result,
				'languages' => $this->get_all(),
			)
		);
	}

	/**
	 * AJAX: delete a language.
	 *
	 * @return void
	 */
	public function ajax_delete() {
		$this->guard_ajax();

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'No language slug was supplied.', 'kdna-regional-content' ) ), 400 );
		}

		if ( ! $this->delete( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Language not found.', 'kdna-regional-content' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Language deleted.', 'kdna-regional-content' ),
				'languages' => $this->get_all(),
			)
		);
	}

	/**
	 * AJAX: reorder languages.
	 *
	 * @return void
	 */
	public function ajax_reorder() {
		$this->guard_ajax();

		$slugs = isset( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $slugs ) ) {
			wp_send_json_error( array( 'message' => __( 'No order was supplied.', 'kdna-regional-content' ) ), 400 );
		}

		$this->reorder( $slugs );
		wp_send_json_success(
			array(
				'message'   => __( 'Order saved.', 'kdna-regional-content' ),
				'languages' => $this->get_all(),
			)
		);
	}

	/**
	 * Shared nonce + capability check for the AJAX handlers.
	 *
	 * @return void
	 */
	private function guard_ajax() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage languages.', 'kdna-regional-content' ) ),
				403
			);
		}
	}

	/**
	 * Coerce a stored language row into a tidy associative array on read.
	 *
	 * @param array $language Stored row.
	 * @return array
	 */
	private function normalise_for_read( array $language ) {
		return array(
			'slug' => isset( $language['slug'] ) ? (string) $language['slug'] : '',
			'name' => isset( $language['name'] ) ? (string) $language['name'] : '',
			'flag' => isset( $language['flag'] ) ? strtolower( (string) $language['flag'] ) : '',
		);
	}

	/**
	 * Sanitise a raw payload into a storable shape.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	private function sanitise( array $input ) {
		$name     = isset( $input['name'] ) ? trim( wp_strip_all_tags( (string) $input['name'] ) ) : '';
		$slug_raw = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$slug     = $this->sanitise_slug( $slug_raw );
		$flag     = isset( $input['flag'] ) ? $this->sanitise_flag_code( (string) $input['flag'] ) : '';

		if ( '' === $slug ) {
			$slug = $this->generate_unique_slug( $name, isset( $input['original_slug'] ) ? (string) $input['original_slug'] : '' );
		}

		return array(
			'slug' => $slug,
			'name' => $name,
			'flag' => $flag,
		);
	}

	/**
	 * Validate a sanitised language row.
	 *
	 * @param array $language Sanitised row.
	 * @return true|WP_Error
	 */
	private function validate( array $language ) {
		if ( '' === $language['name'] ) {
			return new WP_Error( 'kdna_rc_lang_name_required', __( 'A display name is required.', 'kdna-regional-content' ) );
		}
		if ( '' === $language['slug'] ) {
			return new WP_Error( 'kdna_rc_lang_slug_required', __( 'A slug is required.', 'kdna-regional-content' ) );
		}
		return true;
	}

	/**
	 * Reduce arbitrary input to a safe slug.
	 *
	 * Languages slugs are commonly two-letter ISO 639-1 codes but the field
	 * also accepts longer values such as zh-hans for script variants.
	 *
	 * @param string $value Raw slug.
	 * @return string
	 */
	private function sanitise_slug( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
		$value = trim( (string) $value, '-_' );
		return (string) $value;
	}

	/**
	 * Reduce a flag value to a clean ISO 3166-1 alpha-2 code (or empty).
	 *
	 * Empty is acceptable: the UI just renders nothing and JS skips the
	 * flag span when a language has no code configured.
	 *
	 * @param string $value Raw flag input.
	 * @return string
	 */
	private function sanitise_flag_code( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^[a-z]{2}$/', $value ) ) {
			return '';
		}
		return $value;
	}
}

