<?php
/**
 * Admin settings page handler.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Settings
 *
 * Registers the top-level Regional Content admin menu, the three-tab settings
 * page, and the Settings API fields shown on each tab. Stage 1 only renders
 * the MaxMind License Key field on the General tab; further fields are added
 * in later stages.
 */
class KDNA_RC_Settings {

	/**
	 * Slug used for the admin page and as the Settings API option group.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'kdna-regional-content';

	/**
	 * List of valid tab slugs in display order.
	 *
	 * Kept on the class so the wrapper view and the activation logic share one
	 * source of truth.
	 *
	 * @var array<string,string>
	 */
	private $tabs = array();

	/**
	 * Wire up the admin hooks needed for the settings page.
	 *
	 * Called from KDNA_RC_Plugin::init() inside is_admin().
	 *
	 * @return void
	 */
	public function init() {
		$this->tabs = array(
			'general' => __( 'General', 'kdna-regional-content' ),
			'regions' => __( 'Regions', 'kdna-regional-content' ),
			'tools'   => __( 'Tools', 'kdna-regional-content' ),
		);

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the top-level Regional Content menu in the WordPress admin.
	 *
	 * The slug doubles as the page identifier passed back to render_page().
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Regional Content', 'kdna-regional-content' ),
			__( 'Regional Content', 'kdna-regional-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-site-alt3',
			76
		);
	}

	/**
	 * Register the option, settings sections, and fields used on the General tab.
	 *
	 * Uses a single option key (kdna_rc_settings) holding an associative array
	 * so future settings can be added without bloating the options table.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'kdna_rc_settings_group',
			KDNA_RC_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitise_settings' ),
				'default'           => array(
					'maxmind_license_key' => '',
					'default_region'      => '',
				),
			)
		);

		add_settings_section(
			'kdna_rc_section_maxmind',
			__( 'MaxMind GeoIP', 'kdna-regional-content' ),
			array( $this, 'render_section_maxmind' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'maxmind_license_key',
			__( 'MaxMind License Key', 'kdna-regional-content' ),
			array( $this, 'render_field_license_key' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_maxmind',
			array( 'label_for' => 'kdna_rc_maxmind_license_key' )
		);

		// General tab: default region used when no configured region matches
		// the visitor. Populated from the regions list saved on the Regions tab.
		add_settings_section(
			'kdna_rc_section_regions_general',
			__( 'Region Behaviour', 'kdna-regional-content' ),
			array( $this, 'render_section_regions_general' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'default_region',
			__( 'Default Region', 'kdna-regional-content' ),
			array( $this, 'render_field_default_region' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_regions_general',
			array( 'label_for' => 'kdna_rc_default_region' )
		);

		// General tab: detection behaviour controls.
		add_settings_section(
			'kdna_rc_section_detection',
			__( 'Detection Behaviour', 'kdna-regional-content' ),
			array( $this, 'render_section_detection' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'test_override_mode',
			__( 'Test Override Mode', 'kdna-regional-content' ),
			array( $this, 'render_field_override_mode' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_detection'
		);

		add_settings_field(
			'trust_proxy_headers',
			__( 'Trust Proxy Headers', 'kdna-regional-content' ),
			array( $this, 'render_field_trust_proxy' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_detection',
			array( 'label_for' => 'kdna_rc_trust_proxy_headers' )
		);

		add_settings_field(
			'cookie_lifetime_days',
			__( 'Cookie Lifetime', 'kdna-regional-content' ),
			array( $this, 'render_field_cookie_lifetime' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_detection',
			array( 'label_for' => 'kdna_rc_cookie_lifetime' )
		);

		// Tools tab: auto-update schedule lives inside the same option key
		// but is rendered on the Tools page so the UI stays grouped sensibly.
		add_settings_section(
			'kdna_rc_section_db_schedule',
			__( 'Auto-update Schedule', 'kdna-regional-content' ),
			array( $this, 'render_section_db_schedule' ),
			self::PAGE_SLUG . '-tools'
		);

		add_settings_field(
			'db_update_schedule',
			__( 'Auto-update Frequency', 'kdna-regional-content' ),
			array( $this, 'render_field_db_schedule' ),
			self::PAGE_SLUG . '-tools',
			'kdna_rc_section_db_schedule',
			array( 'label_for' => 'kdna_rc_db_update_schedule' )
		);
	}

	/**
	 * Sanitise every key in the settings array before it lands in wp_options.
	 *
	 * Only known keys survive; unknown ones are dropped to keep the option
	 * shape predictable. Existing values are preserved when the new payload
	 * does not include a key, so partial saves from later stages do not erase
	 * unrelated fields.
	 *
	 * @param array $input Raw POST data from the settings form.
	 * @return array Cleaned settings ready for storage.
	 */
	public function sanitise_settings( $input ) {
		$existing = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$clean = $existing;

		if ( array_key_exists( 'maxmind_license_key', $input ) ) {
			// License keys are short alphanumeric tokens; trim and strip tags
			// is sufficient and keeps any future formatting MaxMind chooses.
			$clean['maxmind_license_key'] = sanitize_text_field( wp_unslash( $input['maxmind_license_key'] ) );
		}

		if ( array_key_exists( 'db_update_schedule', $input ) ) {
			$value   = sanitize_key( wp_unslash( $input['db_update_schedule'] ) );
			$updater = new KDNA_RC_Database_Updater();
			if ( in_array( $value, $updater->valid_schedules(), true ) ) {
				$clean['db_update_schedule'] = $value;
			}
		}

		if ( array_key_exists( 'default_region', $input ) ) {
			$value           = sanitize_key( wp_unslash( $input['default_region'] ) );
			$regions_handler = new KDNA_RC_Regions();
			// Only accept the empty string (no default) or a slug that maps to
			// a real region; this prevents stale values lingering after a
			// region is renamed or deleted.
			if ( '' === $value || null !== $regions_handler->get( $value ) ) {
				$clean['default_region'] = $value;
			}
		}

		if ( array_key_exists( 'test_override_mode', $input ) ) {
			$value = sanitize_key( wp_unslash( $input['test_override_mode'] ) );
			if ( in_array( $value, KDNA_RC_Detector::OVERRIDE_MODES, true ) ) {
				$clean['test_override_mode'] = $value;
			}
		}

		// Checkboxes are absent from POST when unchecked, so we cannot test
		// for the key alone. The submitted form always includes a hidden
		// kdna_rc_settings[trust_proxy_present] flag so we know the General
		// tab was the source.
		if ( array_key_exists( 'trust_proxy_present', $input ) ) {
			$clean['trust_proxy_headers'] = ! empty( $input['trust_proxy_headers'] );
		}

		if ( array_key_exists( 'cookie_lifetime_days', $input ) ) {
			$days = (int) $input['cookie_lifetime_days'];
			if ( $days < 1 ) {
				$days = 1;
			}
			if ( $days > 365 ) {
				$days = 365;
			}
			$clean['cookie_lifetime_days'] = $days;
		}

		return $clean;
	}

	/**
	 * Render the introductory copy at the top of the MaxMind settings section.
	 *
	 * @return void
	 */
	public function render_section_maxmind() {
		echo '<p>' . esc_html__( 'Enter your MaxMind license key. The key is required to download the GeoLite2 country database used for visitor detection. Sign up for a free account at maxmind.com to obtain one.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the password-style input for the MaxMind license key.
	 *
	 * @return void
	 */
	public function render_field_license_key() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$value    = isset( $settings['maxmind_license_key'] ) ? (string) $settings['maxmind_license_key'] : '';

		printf(
			'<input type="password" id="kdna_rc_maxmind_license_key" name="%1$s[maxmind_license_key]" value="%2$s" class="regular-text" autocomplete="new-password" spellcheck="false" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			esc_attr( $value )
		);

		echo '<p class="description">' . esc_html__( 'Stored securely in the WordPress options table. Used only to authenticate database downloads with MaxMind.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the introductory copy for the Region Behaviour section.
	 *
	 * @return void
	 */
	public function render_section_regions_general() {
		echo '<p>' . esc_html__( 'Choose which region visitors fall into when their country is not part of any configured region. Manage the regions list under the Regions tab.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Default Region dropdown.
	 *
	 * Falls back to a polite empty state when no regions exist so admins are
	 * not left looking at a blank dropdown wondering what to do.
	 *
	 * @return void
	 */
	public function render_field_default_region() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['default_region'] ) ? (string) $settings['default_region'] : '';
		$regions  = ( new KDNA_RC_Regions() )->get_all();

		echo '<select id="kdna_rc_default_region" name="' . esc_attr( KDNA_RC_OPTION_SETTINGS ) . '[default_region]">';
		echo '<option value=""' . selected( '', $current, false ) . '>' . esc_html__( 'No default (visitors with unmatched countries see no region content)', 'kdna-regional-content' ) . '</option>';
		foreach ( $regions as $region ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $region['slug'] ),
				selected( $current, $region['slug'], false ),
				esc_html( $region['name'] )
			);
		}
		echo '</select>';

		if ( empty( $regions ) ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'regions',
				),
				admin_url( 'admin.php' )
			);
			echo '<p class="description">';
			printf(
				/* translators: %s: link to the Regions tab. */
				esc_html__( 'No regions yet. Add one on the %s.', 'kdna-regional-content' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Regions tab', 'kdna-regional-content' ) . '</a>'
			);
			echo '</p>';
		}
	}

	/**
	 * Render the introductory copy for the Detection Behaviour section.
	 *
	 * @return void
	 */
	public function render_section_detection() {
		echo '<p>' . esc_html__( 'Controls how the plugin identifies the visitor and how long their region choice persists between visits.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Test Override Mode radio group.
	 *
	 * @return void
	 */
	public function render_field_override_mode() {
		$current = (string) ( get_option( KDNA_RC_OPTION_SETTINGS, array() )['test_override_mode'] ?? 'admins' );
		$choices = array(
			'admins'   => __( 'Admins only (recommended for production)', 'kdna-regional-content' ),
			'all'      => __( 'All visitors (useful on staging)', 'kdna-regional-content' ),
			'disabled' => __( 'Disabled (the ?region= URL parameter is ignored)', 'kdna-regional-content' ),
		);

		echo '<fieldset>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="radio" name="%1$s[test_override_mode]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( KDNA_RC_OPTION_SETTINGS ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Add ?region=slug to any URL to force a specific region for testing. The chosen region is stored in the visitor cookie for subsequent pages.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Trust Proxy Headers checkbox.
	 *
	 * @return void
	 */
	public function render_field_trust_proxy() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		// Default to true because the typical KDNA hosting stack lives behind Cloudflare.
		$current  = array_key_exists( 'trust_proxy_headers', (array) $settings ) ? (bool) $settings['trust_proxy_headers'] : true;

		printf(
			'<input type="hidden" name="%1$s[trust_proxy_present]" value="1" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS )
		);
		printf(
			'<label><input type="checkbox" id="kdna_rc_trust_proxy_headers" name="%1$s[trust_proxy_headers]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Read the visitor IP from CF-Connecting-IP, X-Forwarded-For, and X-Real-IP headers', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'Enable when the site is behind Cloudflare or another reverse proxy. Disable when the server is reachable directly so attackers cannot spoof their country with a forged header.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Cookie Lifetime number input.
	 *
	 * @return void
	 */
	public function render_field_cookie_lifetime() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['cookie_lifetime_days'] ) ? (int) $settings['cookie_lifetime_days'] : KDNA_RC_Detector::DEFAULT_COOKIE_DAYS;

		printf(
			'<input type="number" id="kdna_rc_cookie_lifetime" name="%1$s[cookie_lifetime_days]" value="%2$d" min="1" max="365" step="1" class="small-text" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			(int) $current
		);
		echo ' <span>' . esc_html__( 'days', 'kdna-regional-content' ) . '</span>';
		echo '<p class="description">' . esc_html__( 'How long the kdna_region cookie persists in the visitor browser. Between 1 and 365 days. Default 30.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the introductory copy for the auto-update schedule section.
	 *
	 * @return void
	 */
	public function render_section_db_schedule() {
		echo '<p>' . esc_html__( 'Choose how often WordPress should check MaxMind for a fresh GeoLite2 database. The official release cadence is monthly, so weekly is rarely useful.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the auto-update schedule dropdown.
	 *
	 * @return void
	 */
	public function render_field_db_schedule() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['db_update_schedule'] ) ? (string) $settings['db_update_schedule'] : KDNA_RC_Database_Updater::SCHEDULE_MONTHLY;

		$choices = array(
			'weekly'                                => __( 'Weekly', 'kdna-regional-content' ),
			KDNA_RC_Database_Updater::SCHEDULE_MONTHLY => __( 'Monthly (recommended)', 'kdna-regional-content' ),
			'never'                                 => __( 'Never (manual updates only)', 'kdna-regional-content' ),
		);

		echo '<select id="kdna_rc_db_update_schedule" name="' . esc_attr( KDNA_RC_OPTION_SETTINGS ) . '[db_update_schedule]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Enqueue the admin stylesheet and script on this plugin's settings page only.
	 *
	 * Localises the AJAX action names, nonce, and labels the JS needs so no
	 * configuration leaks to other admin screens.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'kdna-rc-admin',
			KDNA_RC_PLUGIN_URL . 'admin/admin-style.css',
			array(),
			KDNA_RC_VERSION
		);

		wp_enqueue_script(
			'kdna-rc-admin',
			KDNA_RC_PLUGIN_URL . 'admin/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			KDNA_RC_VERSION,
			true
		);

		$countries = KDNA_RC_Regions::country_list();
		$country_payload = array();
		foreach ( $countries as $code => $name ) {
			$country_payload[] = array(
				'code' => $code,
				'name' => $name,
			);
		}

		wp_localize_script(
			'kdna-rc-admin',
			'kdnaRCAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'kdna_rc_admin' ),
				'actions'   => array(
					'updateDatabase' => KDNA_RC_Database_Updater::AJAX_ACTION,
					'saveRegion'     => KDNA_RC_Regions::AJAX_SAVE,
					'deleteRegion'   => KDNA_RC_Regions::AJAX_DELETE,
					'reorderRegions' => KDNA_RC_Regions::AJAX_REORDER,
					'testDetection'  => KDNA_RC_Detector::AJAX_TEST_ACTION,
				),
				'countries' => $country_payload,
				'i18n'      => array(
					'updating'        => __( 'Updating database, please wait...', 'kdna-regional-content' ),
					'success'         => __( 'Database updated successfully.', 'kdna-regional-content' ),
					'failure'         => __( 'Database update failed.', 'kdna-regional-content' ),
					'network'         => __( 'A network error stopped the update. Try again.', 'kdna-regional-content' ),
					'savingRegion'    => __( 'Saving...', 'kdna-regional-content' ),
					'regionSaved'     => __( 'Region saved.', 'kdna-regional-content' ),
					'regionDeleted'   => __( 'Region deleted.', 'kdna-regional-content' ),
					'reorderSaved'    => __( 'Order saved.', 'kdna-regional-content' ),
					'noResults'       => __( 'No countries match.', 'kdna-regional-content' ),
					'newRegion'       => __( 'New region', 'kdna-regional-content' ),
					'untitledRegion'  => __( 'Untitled region', 'kdna-regional-content' ),
					'singleSummary'   => __( 'Single country', 'kdna-regional-content' ),
					/* translators: %d: number of countries. */
					'groupSummaryOne' => __( 'Group, %d country', 'kdna-regional-content' ),
					/* translators: %d: number of countries. */
					'groupSummaryMany' => __( 'Group, %d countries', 'kdna-regional-content' ),
					'testDetecting'   => __( 'Looking up...', 'kdna-regional-content' ),
					'testNoMatch'     => __( 'No region matches this country.', 'kdna-regional-content' ),
					'testNoCountry'   => __( 'GeoIP could not resolve this IP. Make sure the database is up to date.', 'kdna-regional-content' ),
				),
			)
		);
	}

	/**
	 * Determine which tab is currently active from the query string.
	 *
	 * Falls back to the first tab whenever the requested tab is missing or
	 * unrecognised so the page never renders an empty body.
	 *
	 * @return string Tab slug.
	 */
	public function current_tab() {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
		if ( $requested && isset( $this->tabs[ $requested ] ) ) {
			return $requested;
		}
		return 'general';
	}

	/**
	 * Build the URL for a given tab on the settings page.
	 *
	 * @param string $tab Tab slug.
	 * @return string Admin URL.
	 */
	public function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the settings page wrapper which includes the active tab view.
	 *
	 * Kept thin so views own all markup. Capability is checked once here
	 * before any view is loaded.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kdna-regional-content' ) );
		}

		$tabs        = $this->tabs;
		$current_tab = $this->current_tab();

		include KDNA_RC_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Expose the tab list to view files that need to render a tab nav.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return $this->tabs;
	}
}
