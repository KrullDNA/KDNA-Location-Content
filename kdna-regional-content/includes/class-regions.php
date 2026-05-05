<?php
/**
 * Regions and groups storage and AJAX handlers.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Regions
 *
 * Single source of truth for the regions list. Persists to the
 * kdna_rc_regions option as a serialised array of associative arrays so the
 * full ordered list lives behind one option lookup. Provides CRUD plus the
 * three admin AJAX endpoints used by the Regions tab.
 *
 * Stored shape (each element):
 *   [
 *     'slug'      => 'australia',
 *     'name'      => 'Australia',
 *     'type'      => 'single' | 'group',
 *     'countries' => [ 'AU' ],
 *     'language'  => 'en-AU',
 *     'direction' => 'ltr' | 'rtl',
 *   ]
 */
class KDNA_RC_Regions {

	/**
	 * Option key holding the serialised regions array.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'kdna_rc_regions';

	/**
	 * AJAX action name for saving (insert or update) a region.
	 *
	 * @var string
	 */
	const AJAX_SAVE = 'kdna_rc_save_region';

	/**
	 * AJAX action name for deleting a region.
	 *
	 * @var string
	 */
	const AJAX_DELETE = 'kdna_rc_delete_region';

	/**
	 * AJAX action name for reordering regions.
	 *
	 * @var string
	 */
	const AJAX_REORDER = 'kdna_rc_reorder_regions';

	/**
	 * Cache of the bundled country list, indexed by alpha-2 code.
	 *
	 * @var array<string,string>|null
	 */
	private static $country_index = null;

	/**
	 * Wire up admin AJAX handlers.
	 *
	 * Called from KDNA_RC_Plugin::init() when in admin context.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_DELETE, array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_' . self::AJAX_REORDER, array( $this, 'ajax_reorder' ) );
	}

	/**
	 * Return every configured region in display order.
	 *
	 * @return array<int,array>
	 */
	public function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $region ) {
			if ( is_array( $region ) && ! empty( $region['slug'] ) ) {
				$clean[] = $this->normalise_for_read( $region );
			}
		}
		return $clean;
	}

	/**
	 * Return a single region by slug, or null when not found.
	 *
	 * @param string $slug Region slug.
	 * @return array|null
	 */
	public function get( $slug ) {
		$slug = $this->sanitise_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->get_all() as $region ) {
			if ( $region['slug'] === $slug ) {
				return $region;
			}
		}
		return null;
	}

	/**
	 * Insert or update a region.
	 *
	 * Returns the saved region on success, or WP_Error with a code that
	 * identifies which validation rule was violated. Treats the input as
	 * unsanitised: callers can pass raw POST data straight in.
	 *
	 * The optional $original_slug parameter lets the AJAX layer rename a
	 * region by passing the previous slug; the row is replaced in place so
	 * its position in the order is preserved.
	 *
	 * @param array  $input         Raw region data.
	 * @param string $original_slug Existing slug when renaming, or empty.
	 * @return array|WP_Error
	 */
	public function save( array $input, $original_slug = '' ) {
		$region = $this->sanitise( $input );
		$errors = $this->validate( $region );
		if ( $errors instanceof WP_Error ) {
			return $errors;
		}

		$all = $this->get_all();

		$original_slug = $this->sanitise_slug( $original_slug );

		// Detect uniqueness conflicts: when the slug already exists and the
		// caller has not flagged it as a rename of that same row, refuse.
		foreach ( $all as $existing ) {
			if ( $existing['slug'] === $region['slug'] && $existing['slug'] !== $original_slug ) {
				return new WP_Error( 'kdna_rc_slug_taken', __( 'That slug is already in use by another region.', 'kdna-regional-content' ) );
			}
		}

		$replaced = false;
		if ( '' !== $original_slug ) {
			foreach ( $all as $i => $existing ) {
				if ( $existing['slug'] === $original_slug ) {
					$all[ $i ] = $region;
					$replaced  = true;
					break;
				}
			}
		}

		if ( ! $replaced ) {
			$all[] = $region;
		}

		update_option( self::OPTION_KEY, $all, false );
		return $region;
	}

	/**
	 * Remove a region by slug.
	 *
	 * @param string $slug Region slug.
	 * @return bool True when a region was removed, false when none matched.
	 */
	public function delete( $slug ) {
		$slug = $this->sanitise_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$all      = $this->get_all();
		$filtered = array();
		$removed  = false;
		foreach ( $all as $region ) {
			if ( $region['slug'] === $slug ) {
				$removed = true;
				continue;
			}
			$filtered[] = $region;
		}

		if ( $removed ) {
			update_option( self::OPTION_KEY, $filtered, false );

			// Clear the default region setting when the deleted region was the default.
			$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
			if ( is_array( $settings ) && isset( $settings['default_region'] ) && $settings['default_region'] === $slug ) {
				$settings['default_region'] = '';
				update_option( KDNA_RC_OPTION_SETTINGS, $settings, false );
			}
		}

		return $removed;
	}

	/**
	 * Persist a new ordering for the regions list.
	 *
	 * Accepts an ordered list of slugs. Any region whose slug appears in the
	 * input keeps its data; regions not mentioned are appended in their
	 * existing relative order so a partial reorder cannot lose data.
	 *
	 * @param array<int,string> $slugs Slugs in their new order.
	 * @return bool True on success.
	 */
	public function reorder( array $slugs ) {
		$all = $this->get_all();
		if ( empty( $all ) ) {
			return false;
		}

		$by_slug = array();
		foreach ( $all as $region ) {
			$by_slug[ $region['slug'] ] = $region;
		}

		$ordered = array();
		foreach ( $slugs as $slug ) {
			$slug = $this->sanitise_slug( $slug );
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}

		// Append any regions the client did not include so we never lose rows.
		foreach ( $by_slug as $region ) {
			$ordered[] = $region;
		}

		update_option( self::OPTION_KEY, $ordered, false );
		return true;
	}

	/**
	 * Generate a unique slug from a free-text name.
	 *
	 * Falls back to "region", then appends a numeric suffix until the slug
	 * is free. The exclude argument lets the rename path keep its current
	 * slug without colliding with itself.
	 *
	 * @param string $name        Display name.
	 * @param string $exclude_slug Slug to ignore during uniqueness check.
	 * @return string
	 */
	public function generate_unique_slug( $name, $exclude_slug = '' ) {
		$base = $this->sanitise_slug( sanitize_title( $name ) );
		if ( '' === $base ) {
			$base = 'region';
		}

		$existing = array();
		foreach ( $this->get_all() as $region ) {
			$existing[ $region['slug'] ] = true;
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
	 * Return the bundled country list as code => name.
	 *
	 * Loaded from data/countries.json on first call and cached for the rest
	 * of the request.
	 *
	 * @return array<string,string>
	 */
	public static function country_list() {
		if ( is_array( self::$country_index ) ) {
			return self::$country_index;
		}

		$path = KDNA_RC_PLUGIN_DIR . 'data/countries.json';
		$out  = array();
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $json ) ) {
				foreach ( $json as $row ) {
					if ( ! isset( $row['code'], $row['name'] ) ) {
						continue;
					}
					$code         = strtoupper( (string) $row['code'] );
					$out[ $code ] = (string) $row['name'];
				}
			}
		}
		self::$country_index = $out;
		return $out;
	}

	/**
	 * Look up a single country name.
	 *
	 * @param string $code ISO 3166-1 alpha-2 code.
	 * @return string Display name, or the code itself when unknown.
	 */
	public static function country_name( $code ) {
		$code = strtoupper( (string) $code );
		$list = self::country_list();
		return isset( $list[ $code ] ) ? $list[ $code ] : $code;
	}

	/**
	 * AJAX: save a region.
	 *
	 * Expects a serialised form payload in the `region` POST field. Returns
	 * either the saved region (under data.region) or a WP_Error message.
	 *
	 * @return void
	 */
	public function ajax_save() {
		$this->guard_ajax();

		$raw           = isset( $_POST['region'] ) && is_array( $_POST['region'] ) ? wp_unslash( $_POST['region'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$original_slug = isset( $_POST['original_slug'] ) ? sanitize_key( wp_unslash( $_POST['original_slug'] ) ) : '';

		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No region data was submitted.', 'kdna-regional-content' ) ), 400 );
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
				'message' => __( 'Region saved.', 'kdna-regional-content' ),
				'region'  => $result,
				'regions' => $this->get_all(),
			)
		);
	}

	/**
	 * AJAX: delete a region.
	 *
	 * @return void
	 */
	public function ajax_delete() {
		$this->guard_ajax();

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'No region slug was supplied.', 'kdna-regional-content' ) ), 400 );
		}

		$ok = $this->delete( $slug );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Region not found.', 'kdna-regional-content' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Region deleted.', 'kdna-regional-content' ),
				'regions' => $this->get_all(),
			)
		);
	}

	/**
	 * AJAX: reorder regions.
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
				'message' => __( 'Order saved.', 'kdna-regional-content' ),
				'regions' => $this->get_all(),
			)
		);
	}

	/**
	 * Shared nonce and capability check for every AJAX handler.
	 *
	 * Bails with a 403 response when the caller is not an admin or the nonce
	 * is missing, so each handler stays focused on its happy path.
	 *
	 * @return void
	 */
	private function guard_ajax() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage regions.', 'kdna-regional-content' ) ),
				403
			);
		}
	}

	/**
	 * Coerce a stored region into a tidy associative array on read.
	 *
	 * Older versions or hand-edited data may be missing keys we expect; this
	 * keeps the rest of the codebase free of defensive checks.
	 *
	 * @param array $region Stored region.
	 * @return array
	 */
	private function normalise_for_read( array $region ) {
		return array(
			'slug'             => isset( $region['slug'] ) ? (string) $region['slug'] : '',
			'name'             => isset( $region['name'] ) ? (string) $region['name'] : '',
			'type'             => ( isset( $region['type'] ) && 'group' === $region['type'] ) ? 'group' : 'single',
			'countries'        => isset( $region['countries'] ) && is_array( $region['countries'] ) ? array_values( array_filter( array_map( 'strtoupper', $region['countries'] ) ) ) : array(),
			'language'         => isset( $region['language'] ) ? (string) $region['language'] : '',
			'direction'        => ( isset( $region['direction'] ) && 'rtl' === $region['direction'] ) ? 'rtl' : 'ltr',
			'default_language' => isset( $region['default_language'] ) ? (string) $region['default_language'] : '',
		);
	}

	/**
	 * Sanitise a raw region payload into a storable shape.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	private function sanitise( array $input ) {
		$name      = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		$slug_raw  = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$slug      = $this->sanitise_slug( $slug_raw );
		$type      = ( isset( $input['type'] ) && 'group' === $input['type'] ) ? 'group' : 'single';
		$language  = isset( $input['language'] ) ? sanitize_text_field( (string) $input['language'] ) : '';
		$direction = ( isset( $input['direction'] ) && 'rtl' === $input['direction'] ) ? 'rtl' : 'ltr';

		$countries_raw = array();
		if ( isset( $input['countries'] ) ) {
			if ( is_array( $input['countries'] ) ) {
				$countries_raw = $input['countries'];
			} elseif ( is_string( $input['countries'] ) ) {
				// Comma-separated codes are accepted as a convenience for
				// hand-crafted requests; the UI always sends an array.
				$countries_raw = preg_split( '/[\s,]+/', $input['countries'] );
			}
		}

		$valid_codes = self::country_list();
		$countries   = array();
		foreach ( $countries_raw as $code ) {
			$code = strtoupper( trim( (string) $code ) );
			if ( '' === $code ) {
				continue;
			}
			if ( isset( $valid_codes[ $code ] ) && ! in_array( $code, $countries, true ) ) {
				$countries[] = $code;
			}
		}

		// Single-country regions store at most one code so the editor cannot
		// accidentally end up with a "Single" region containing a long list.
		if ( 'single' === $type && count( $countries ) > 1 ) {
			$countries = array( $countries[0] );
		}

		// Generate a slug when the editor leaves it blank.
		if ( '' === $slug ) {
			$slug = $this->generate_unique_slug( $name, isset( $input['original_slug'] ) ? (string) $input['original_slug'] : '' );
		}

		// Stage 10: optional Default Language slug per region. Only persisted
		// when it points at a configured language; unknown values fall back
		// to the empty string so deleted languages do not linger as stale
		// pointers.
		$default_language = '';
		if ( isset( $input['default_language'] ) ) {
			$candidate = sanitize_key( (string) $input['default_language'] );
			if ( '' !== $candidate && class_exists( 'KDNA_RC_Languages' ) ) {
				$languages_handler = new KDNA_RC_Languages();
				if ( null !== $languages_handler->get( $candidate ) ) {
					$default_language = $candidate;
				}
			}
		}

		return array(
			'slug'             => $slug,
			'name'             => $name,
			'type'             => $type,
			'countries'        => $countries,
			'language'         => $this->sanitise_language_tag( $language ),
			'direction'        => $direction,
			'default_language' => $default_language,
		);
	}

	/**
	 * Reject anything that is not a sensible BCP 47 style tag.
	 *
	 * Accepts forms like `en`, `en-GB`, `pt-BR`, `zh-Hans-CN`. Strips
	 * everything else so we never emit garbage as a `lang` attribute.
	 *
	 * @param string $value Raw language tag.
	 * @return string
	 */
	private function sanitise_language_tag( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Run validation rules over a fully-sanitised region.
	 *
	 * @param array $region Sanitised region.
	 * @return true|WP_Error
	 */
	private function validate( array $region ) {
		if ( '' === $region['name'] ) {
			return new WP_Error( 'kdna_rc_name_required', __( 'A display name is required.', 'kdna-regional-content' ) );
		}
		if ( '' === $region['slug'] ) {
			return new WP_Error( 'kdna_rc_slug_required', __( 'A slug is required.', 'kdna-regional-content' ) );
		}
		if ( empty( $region['countries'] ) ) {
			return new WP_Error( 'kdna_rc_countries_required', __( 'Select at least one country for this region.', 'kdna-regional-content' ) );
		}
		return true;
	}

	/**
	 * Reduce arbitrary input to a safe slug.
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
}
