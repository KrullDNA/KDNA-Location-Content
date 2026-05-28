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
		// Note: the tabs array is built lazily inside ensure_tabs() so the
		// __() calls run on admin_menu / admin_init (after the `init`
		// action), not on plugins_loaded. WP 6.7+ warns about translation
		// loading triggered before `init` and this defer is the fix.
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Lazy-build the tabs array.
	 *
	 * Called from any code path that needs the tabs (register_menu,
	 * current_tab, render_page, JS localisation). Idempotent.
	 *
	 * @return void
	 */
	private function ensure_tabs() {
		if ( ! empty( $this->tabs ) ) {
			return;
		}
		$this->tabs = array(
			'general'   => __( 'General', 'kdna-regional-content' ),
			'regions'   => __( 'Regions', 'kdna-regional-content' ),
			'languages' => __( 'Languages', 'kdna-regional-content' ),
			'tools'     => __( 'Tools', 'kdna-regional-content' ),
		);
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
					'maxmind_license_key'      => '',
					'default_region'           => '',
					'restricted_post_types'    => array(),
					'single_post_behaviour'    => 'show',
					'single_post_redirect_url' => '',
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

		// Stage 5: post-level region visibility settings, appended to the
		// General tab so editors find every region-related toggle in one
		// place. A separate section keeps them visually grouped.
		add_settings_section(
			'kdna_rc_section_post_visibility',
			__( 'Post-level Region Visibility', 'kdna-regional-content' ),
			array( $this, 'render_section_post_visibility' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'restricted_post_types',
			__( 'Post Types with Region Visibility', 'kdna-regional-content' ),
			array( $this, 'render_field_restricted_post_types' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_post_visibility'
		);

		add_settings_field(
			'single_post_behaviour',
			__( 'Single Post Behaviour for Restricted Posts', 'kdna-regional-content' ),
			array( $this, 'render_field_single_post_behaviour' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_post_visibility'
		);

		// Data retention controls. The default keeps everything on uninstall
		// so an accidental Delete from the Plugins screen does not wipe a
		// site's region configuration.
		add_settings_section(
			'kdna_rc_section_data_retention',
			__( 'Data Retention', 'kdna-regional-content' ),
			array( $this, 'render_section_data_retention' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'delete_on_uninstall',
			__( 'Delete data on uninstall', 'kdna-regional-content' ),
			array( $this, 'render_field_delete_on_uninstall' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_data_retention',
			array( 'label_for' => 'kdna_rc_delete_on_uninstall' )
		);

		// Stage 10: Default Language fallback. Appended to the General tab
		// at the bottom so existing fields keep their order.
		add_settings_section(
			'kdna_rc_section_languages_general',
			__( 'Language Behaviour', 'kdna-regional-content' ),
			array( $this, 'render_section_languages_general' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'default_language',
			__( 'Default Language', 'kdna-regional-content' ),
			array( $this, 'render_field_default_language' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_languages_general',
			array( 'label_for' => 'kdna_rc_default_language' )
		);

		// Stage 13 toggles: search cross-language and REST resolver.
		add_settings_field(
			'search_across_all_languages',
			__( 'Search across all language variants', 'kdna-regional-content' ),
			array( $this, 'render_field_search_across_all' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_languages_general',
			array( 'label_for' => 'kdna_rc_search_across_all_languages' )
		);

		add_settings_field(
			'rest_resolve_multilingual',
			__( 'Resolve Multilingual fields in REST API by Accept-Language header', 'kdna-regional-content' ),
			array( $this, 'render_field_rest_resolve_multilingual' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_languages_general',
			array( 'label_for' => 'kdna_rc_rest_resolve_multilingual' )
		);

		// Stage 14: URL routing settings (canonical strategy, redirects,
		// eligible post types). Live in their own section to keep the
		// General tab readable.
		add_settings_section(
			'kdna_rc_section_url_routing',
			__( 'URL Routing & SEO', 'kdna-regional-content' ),
			array( $this, 'render_section_url_routing' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'canonical_strategy',
			__( 'Canonical URL strategy', 'kdna-regional-content' ),
			array( $this, 'render_field_canonical_strategy' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing'
		);

		add_settings_field(
			'redirect_bare_to_region',
			__( 'Redirect bare URLs to regional URLs', 'kdna-regional-content' ),
			array( $this, 'render_field_redirect_bare_region' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing',
			array( 'label_for' => 'kdna_rc_redirect_bare_to_region' )
		);

		add_settings_field(
			'redirect_bare_to_language',
			__( 'Redirect bare URLs to language URLs', 'kdna-regional-content' ),
			array( $this, 'render_field_redirect_bare_language' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing',
			array( 'label_for' => 'kdna_rc_redirect_bare_to_language' )
		);

		add_settings_field(
			'url_routing_post_types',
			__( 'Post types eligible for regional / language URLs', 'kdna-regional-content' ),
			array( $this, 'render_field_url_routing_post_types' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing'
		);

		// Stage 15 SEO settings.
		add_settings_field(
			'hreflang_enabled',
			__( 'Generate hreflang tags', 'kdna-regional-content' ),
			array( $this, 'render_field_hreflang_enabled' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing',
			array( 'label_for' => 'kdna_rc_hreflang_enabled' )
		);

		add_settings_field(
			'sitemap_mode',
			__( 'Sitemap integration mode', 'kdna-regional-content' ),
			array( $this, 'render_field_sitemap_mode' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing'
		);

		add_settings_field(
			'google_analytics_integration',
			__( 'Google Analytics integration', 'kdna-regional-content' ),
			array( $this, 'render_field_google_analytics_integration' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_url_routing',
			array( 'label_for' => 'kdna_rc_google_analytics_integration' )
		);

		// Region-switch banner. Lives in its own section so the toggle and
		// its three text-overrides (message template + Yes / No labels)
		// read as a single feature rather than four unrelated fields.
		add_settings_section(
			'kdna_rc_section_region_banner',
			__( 'Region-switch Banner', 'kdna-regional-content' ),
			array( $this, 'render_section_region_banner' ),
			self::PAGE_SLUG . '-general'
		);

		add_settings_field(
			'region_banner_enabled',
			__( 'Show region-switch banner', 'kdna-regional-content' ),
			array( $this, 'render_field_region_banner_enabled' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_region_banner',
			array( 'label_for' => 'kdna_rc_region_banner_enabled' )
		);

		add_settings_field(
			'region_banner_message',
			__( 'Banner message', 'kdna-regional-content' ),
			array( $this, 'render_field_region_banner_message' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_region_banner',
			array( 'label_for' => 'kdna_rc_region_banner_message' )
		);

		add_settings_field(
			'region_banner_yes',
			__( 'Yes button label', 'kdna-regional-content' ),
			array( $this, 'render_field_region_banner_yes' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_region_banner',
			array( 'label_for' => 'kdna_rc_region_banner_yes' )
		);

		add_settings_field(
			'region_banner_no',
			__( 'No button label', 'kdna-regional-content' ),
			array( $this, 'render_field_region_banner_no' ),
			self::PAGE_SLUG . '-general',
			'kdna_rc_section_region_banner',
			array( 'label_for' => 'kdna_rc_region_banner_no' )
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

		// Stage 5: post types participating in region visibility. Hidden
		// presence flag lets us distinguish "user unticked everything" from
		// "form did not include this section" so existing values survive
		// saves from other tabs.
		if ( array_key_exists( 'restricted_post_types_present', $input ) ) {
			$raw   = isset( $input['restricted_post_types'] ) && is_array( $input['restricted_post_types'] ) ? $input['restricted_post_types'] : array();
			$valid = array();
			foreach ( $raw as $slug ) {
				$slug = sanitize_key( $slug );
				if ( $slug && post_type_exists( $slug ) && ! in_array( $slug, $valid, true ) ) {
					$valid[] = $slug;
				}
			}
			$clean['restricted_post_types'] = $valid;
		}

		if ( array_key_exists( 'single_post_behaviour', $input ) ) {
			$mode = sanitize_key( wp_unslash( $input['single_post_behaviour'] ) );
			if ( ! in_array( $mode, array( 'show', 'redirect' ), true ) ) {
				$mode = 'show';
			}
			$clean['single_post_behaviour'] = $mode;
		}

		if ( array_key_exists( 'single_post_redirect_url', $input ) ) {
			$url = esc_url_raw( wp_unslash( $input['single_post_redirect_url'] ) );
			$clean['single_post_redirect_url'] = $url;
		}

		// Data retention toggle. Hidden presence flag so an unticked
		// checkbox saves cleanly as false instead of being ignored.
		if ( array_key_exists( 'delete_on_uninstall_present', $input ) ) {
			$clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );
		}

		// Stage 10 default language. Accepts the empty string (no default)
		// or a slug that maps to a configured language; stale values are
		// dropped so admin UIs never display a missing slug.
		if ( array_key_exists( 'default_language', $input ) ) {
			$value             = sanitize_key( wp_unslash( $input['default_language'] ) );
			$languages_handler = new KDNA_RC_Languages();
			if ( '' === $value || null !== $languages_handler->get( $value ) ) {
				$clean['default_language'] = $value;
			}
		}

		// Stage 13 toggles. Hidden presence flags so an unticked checkbox
		// saves cleanly as false.
		if ( array_key_exists( 'search_across_all_languages_present', $input ) ) {
			$clean['search_across_all_languages'] = ! empty( $input['search_across_all_languages'] );
		}
		if ( array_key_exists( 'rest_resolve_multilingual_present', $input ) ) {
			$clean['rest_resolve_multilingual'] = ! empty( $input['rest_resolve_multilingual'] );
		}

		// Stage 14 URL routing.
		if ( array_key_exists( 'canonical_strategy', $input ) ) {
			$value = sanitize_key( wp_unslash( $input['canonical_strategy'] ) );
			$clean['canonical_strategy'] = ( 'each' === $value ) ? 'each' : 'bare';
		}
		if ( array_key_exists( 'redirect_bare_to_region_present', $input ) ) {
			$clean['redirect_bare_to_region'] = ! empty( $input['redirect_bare_to_region'] );
		}
		if ( array_key_exists( 'redirect_bare_to_language_present', $input ) ) {
			$clean['redirect_bare_to_language'] = ! empty( $input['redirect_bare_to_language'] );
		}
		if ( array_key_exists( 'url_routing_post_types_present', $input ) ) {
			$raw   = isset( $input['url_routing_post_types'] ) && is_array( $input['url_routing_post_types'] ) ? $input['url_routing_post_types'] : array();
			$valid = array();
			foreach ( $raw as $slug ) {
				$slug = sanitize_key( $slug );
				if ( $slug && post_type_exists( $slug ) && ! in_array( $slug, $valid, true ) ) {
					$valid[] = $slug;
				}
			}
			$clean['url_routing_post_types'] = $valid;
		}

		// Stage 15 SEO settings.
		if ( array_key_exists( 'hreflang_enabled_present', $input ) ) {
			$clean['hreflang_enabled'] = ! empty( $input['hreflang_enabled'] );
		}
		if ( array_key_exists( 'sitemap_mode', $input ) ) {
			$mode = sanitize_key( wp_unslash( $input['sitemap_mode'] ) );
			$clean['sitemap_mode'] = in_array( $mode, array( 'extend', 'supplementary', 'disabled' ), true ) ? $mode : 'extend';
		}

		if ( array_key_exists( 'google_analytics_integration_present', $input ) ) {
			$clean['google_analytics_integration'] = ! empty( $input['google_analytics_integration'] );
		}

		// Region-switch banner toggle + text overrides. The labels are
		// sanitize_text_field — single-line, no HTML — because they are
		// rendered into a button/anchor with textContent in JS.
		if ( array_key_exists( 'region_banner_enabled_present', $input ) ) {
			$clean['region_banner_enabled'] = ! empty( $input['region_banner_enabled'] );
		}
		if ( array_key_exists( 'region_banner_message', $input ) ) {
			$clean['region_banner_message'] = sanitize_text_field( wp_unslash( $input['region_banner_message'] ) );
		}
		if ( array_key_exists( 'region_banner_yes', $input ) ) {
			$clean['region_banner_yes'] = sanitize_text_field( wp_unslash( $input['region_banner_yes'] ) );
		}
		if ( array_key_exists( 'region_banner_no', $input ) ) {
			$clean['region_banner_no'] = sanitize_text_field( wp_unslash( $input['region_banner_no'] ) );
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
	 * Render the introductory copy for the post-level visibility section.
	 *
	 * @return void
	 */
	public function render_section_post_visibility() {
		echo '<p>' . esc_html__( 'Tick the post types whose editors should see a Regional Visibility meta box. Editors then pick which regions are allowed to see each post.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the post-types checkbox group.
	 *
	 * @return void
	 */
	public function render_field_restricted_post_types() {
		$settings  = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$selected  = isset( $settings[ KDNA_RC_Post_Visibility::SETTING_KEY ] ) ? (array) $settings[ KDNA_RC_Post_Visibility::SETTING_KEY ] : array();
		$idx       = array_flip( $selected );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Hidden presence flag so an empty selection still saves cleanly.
		printf(
			'<input type="hidden" name="%1$s[restricted_post_types_present]" value="1" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS )
		);

		if ( empty( $post_types ) ) {
			echo '<p>' . esc_html__( 'No public post types found.', 'kdna-regional-content' ) . '</p>';
			return;
		}

		echo '<fieldset>';
		foreach ( $post_types as $type ) {
			$slug    = (string) $type->name;
			$label   = isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : $slug;
			$checked = isset( $idx[ $slug ] ) ? ' checked' : '';
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="%1$s[restricted_post_types][]" value="%2$s"%3$s /> %4$s <code style="font-size:11px; color:#6c7079;">%2$s</code></label>',
				esc_attr( KDNA_RC_OPTION_SETTINGS ),
				esc_attr( $slug ),
				$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal " checked" string above.
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'A Regional Visibility meta box appears in the editor sidebar for the selected post types. Posts with no regions ticked show everywhere.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Single Post Behaviour radio group plus URL field.
	 *
	 * @return void
	 */
	public function render_field_single_post_behaviour() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$mode     = isset( $settings['single_post_behaviour'] ) ? (string) $settings['single_post_behaviour'] : 'show';
		$url      = isset( $settings['single_post_redirect_url'] ) ? (string) $settings['single_post_redirect_url'] : '';
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		echo '<fieldset>';
		printf(
			'<label style="display:block; margin-bottom:4px;"><input type="radio" name="%1$s[single_post_behaviour]" value="show"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( 'show', $mode, false ),
			esc_html__( 'Show anyway (the post stays visible regardless of region)', 'kdna-regional-content' )
		);
		printf(
			'<label style="display:block; margin-bottom:4px;"><input type="radio" name="%1$s[single_post_behaviour]" value="redirect"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( 'redirect', $mode, false ),
			esc_html__( 'Redirect visitors not in the allowed regions to:', 'kdna-regional-content' )
		);
		printf(
			'<p style="margin-top:6px;"><input type="url" name="%1$s[single_post_redirect_url]" value="%2$s" class="regular-text" placeholder="%3$s" /></p>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			esc_attr( $url ),
			esc_attr( home_url( '/' ) )
		);
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'The redirect runs client-side from a meta tag, so it works on cached pages. Visitors not in any allowed region are sent to the URL above.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the introductory copy for the data retention section.
	 *
	 * @return void
	 */
	public function render_section_data_retention() {
		echo '<p>' . esc_html__( 'Choose what happens to your settings, regions, and downloaded database when the plugin is uninstalled. The default keeps everything in place so reinstalling the plugin restores your configuration.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Delete on uninstall checkbox.
	 *
	 * @return void
	 */
	public function render_field_delete_on_uninstall() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['delete_on_uninstall'] );

		printf(
			'<input type="hidden" name="%1$s[delete_on_uninstall_present]" value="1" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS )
		);
		printf(
			'<label><input type="checkbox" id="kdna_rc_delete_on_uninstall" name="%1$s[delete_on_uninstall]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Remove all plugin settings, regions, post visibility meta, and the downloaded GeoLite2 database when the plugin is uninstalled', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default. Tick this only if you intend to remove the plugin permanently and want a clean wipe. Deactivation never deletes data; only an explicit Delete from the Plugins screen runs the uninstaller.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the introductory copy for the language behaviour section.
	 *
	 * @return void
	 */
	public function render_section_languages_general() {
		echo '<p>' . esc_html__( 'Pick the fallback language used when the visitor browser does not advertise a configured language and their region does not specify one. Manage the languages list under the Languages tab.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Default Language dropdown.
	 *
	 * @return void
	 */
	public function render_field_default_language() {
		$settings  = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current   = isset( $settings['default_language'] ) ? (string) $settings['default_language'] : '';
		$languages = ( new KDNA_RC_Languages() )->get_all();

		echo '<select id="kdna_rc_default_language" name="' . esc_attr( KDNA_RC_OPTION_SETTINGS ) . '[default_language]">';
		echo '<option value=""' . selected( '', $current, false ) . '>' . esc_html__( 'No default language', 'kdna-regional-content' ) . '</option>';
		foreach ( $languages as $language ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $language['slug'] ),
				selected( $current, $language['slug'], false ),
				esc_html( $language['name'] )
			);
		}
		echo '</select>';

		if ( empty( $languages ) ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'languages',
				),
				admin_url( 'admin.php' )
			);
			echo '<p class="description">';
			printf(
				/* translators: %s: link to the Languages tab. */
				esc_html__( 'No languages yet. Add some on the %s.', 'kdna-regional-content' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Languages tab', 'kdna-regional-content' ) . '</a>'
			);
			echo '</p>';
		}
	}

	/**
	 * Render the Search across all language variants checkbox (Stage 13).
	 *
	 * @return void
	 */
	public function render_field_search_across_all() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['search_across_all_languages'] );

		printf(
			'<input type="hidden" name="%1$s[search_across_all_languages_present]" value="1" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS )
		);
		printf(
			'<label><input type="checkbox" id="kdna_rc_search_across_all_languages" name="%1$s[search_across_all_languages]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Match search terms against every configured language, not only the visitor\'s current language.', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default. Useful for sites where users search in mixed languages, but increases query cost.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the REST API resolver checkbox (Stage 13).
	 *
	 * @return void
	 */
	public function render_field_rest_resolve_multilingual() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = array_key_exists( 'rest_resolve_multilingual', (array) $settings ) ? (bool) $settings['rest_resolve_multilingual'] : true;

		printf(
			'<input type="hidden" name="%1$s[rest_resolve_multilingual_present]" value="1" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS )
		);
		printf(
			'<label><input type="checkbox" id="kdna_rc_rest_resolve_multilingual" name="%1$s[rest_resolve_multilingual]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Replace serialised Multilingual values in REST responses with the language matching the request\'s Accept-Language header.', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'On by default. Append ?multilingual=raw to a REST URL to bypass the resolver and receive the raw serialised array instead.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the introductory copy for the URL Routing & SEO section.
	 *
	 * @return void
	 */
	public function render_section_url_routing() {
		echo '<p>' . esc_html__( 'Lets every post be reachable at a regional or language URL prefix (for example /au/about/, /fr/about/, /au/fr/about/). The URL prefix forces the visitor cookie and overrides IP / browser detection. Bare URLs continue to work as today.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the canonical strategy radio.
	 *
	 * @return void
	 */
	public function render_field_canonical_strategy() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['canonical_strategy'] ) && 'each' === $settings['canonical_strategy'] ? 'each' : 'bare';

		$choices = array(
			'bare' => __( 'Bare URL is canonical (recommended for most sites)', 'kdna-regional-content' ),
			'each' => __( 'Each regional URL is its own canonical', 'kdna-regional-content' ),
		);
		echo '<fieldset>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="radio" name="%1$s[canonical_strategy]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( KDNA_RC_OPTION_SETTINGS ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Stored in Stage 14; the canonical tag is emitted in Stage 15 (Yoast integration). The "Bare URL is canonical" option avoids duplicate-content concerns and pairs with hreflang annotations. The "Each is its own canonical" option is stronger for regional ranking signals but only safe when content varies substantially per region.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the bare-to-region redirect toggle.
	 *
	 * @return void
	 */
	public function render_field_redirect_bare_region() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['redirect_bare_to_region'] );

		printf( '<input type="hidden" name="%1$s[redirect_bare_to_region_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );
		printf(
			'<label><input type="checkbox" id="kdna_rc_redirect_bare_to_region" name="%1$s[redirect_bare_to_region]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Redirect bare URLs (e.g. /about-us/) to the visitor\'s detected regional URL (e.g. /au/about-us/) when the cookie is set.', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default. Crawlers (Googlebot, Bingbot, etc.) are never redirected so they index whatever URL they were sent to.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the bare-to-language redirect toggle.
	 *
	 * @return void
	 */
	public function render_field_redirect_bare_language() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['redirect_bare_to_language'] );

		printf( '<input type="hidden" name="%1$s[redirect_bare_to_language_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );
		printf(
			'<label><input type="checkbox" id="kdna_rc_redirect_bare_to_language" name="%1$s[redirect_bare_to_language]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Redirect bare URLs to the visitor\'s detected language URL when the cookie is set. Crawlers exempt.', 'kdna-regional-content' )
		);
	}

	/**
	 * Render the post-type whitelist.
	 *
	 * @return void
	 */
	public function render_field_url_routing_post_types() {
		$settings   = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$selected   = isset( $settings['url_routing_post_types'] ) ? (array) $settings['url_routing_post_types'] : array();
		$idx        = array_flip( $selected );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		printf( '<input type="hidden" name="%1$s[url_routing_post_types_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );

		echo '<fieldset>';
		foreach ( $post_types as $type ) {
			$slug    = (string) $type->name;
			$label   = isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : $slug;
			$checked = isset( $idx[ $slug ] ) ? ' checked' : '';
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="%1$s[url_routing_post_types][]" value="%2$s"%3$s /> %4$s <code style="font-size:11px; color:#6c7079;">%2$s</code></label>',
				esc_attr( KDNA_RC_OPTION_SETTINGS ),
				esc_attr( $slug ),
				$checked,
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Tick the post types that should expose regional / language URLs. Unticked types only resolve at the bare URL. Leave everything unticked to allow every public post type (the default).', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the hreflang toggle (Stage 15).
	 *
	 * @return void
	 */
	public function render_field_hreflang_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! array_key_exists( 'hreflang_enabled', (array) $settings ) || ! empty( $settings['hreflang_enabled'] );

		printf( '<input type="hidden" name="%1$s[hreflang_enabled_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );
		printf(
			'<label><input type="checkbox" id="kdna_rc_hreflang_enabled" name="%1$s[hreflang_enabled]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Emit <link rel="alternate" hreflang="..."> tags for every region / language URL variant on singular pages.', 'kdna-regional-content' )
		);
		echo '<p class="description">' . esc_html__( 'On by default. Yoast Premium users with hreflang management enabled can leave this off; the plugin auto-detects Yoast Premium and skips its own output to avoid duplicate tags.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the sitemap-mode radio (Stage 15).
	 *
	 * @return void
	 */
	public function render_field_sitemap_mode() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['sitemap_mode'] ) ? (string) $settings['sitemap_mode'] : 'extend';

		$choices = array(
			'extend'        => __( 'Extend Yoast sitemap (recommended)', 'kdna-regional-content' ),
			'supplementary' => __( 'Supplementary /kdna-rc-sitemap.xml', 'kdna-regional-content' ),
			'disabled'      => __( 'Disabled', 'kdna-regional-content' ),
		);

		echo '<fieldset>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="radio" name="%1$s[sitemap_mode]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( KDNA_RC_OPTION_SETTINGS ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Extend mode injects xhtml:link siblings into Yoast\'s existing per-post-type sitemap. Supplementary mode adds a parallel /kdna-rc-sitemap.xml referenced from robots.txt and Yoast\'s sitemap index.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the Google Analytics integration toggle (v0.2.0).
	 *
	 * @return void
	 */
	public function render_field_google_analytics_integration() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['google_analytics_integration'] );

		printf( '<input type="hidden" name="%1$s[google_analytics_integration_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );
		printf(
			'<label><input type="checkbox" id="kdna_rc_google_analytics_integration" name="%1$s[google_analytics_integration]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Push the visitor\'s resolved region and language into Google Analytics 4 as user properties and a kdna_resolution custom event.', 'kdna-regional-content' )
		);
		echo '<div class="description" style="margin-top:8px;">';
		echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Setup steps:', 'kdna-regional-content' ) . '</strong></p>';
		echo '<ol style="margin:0 0 6px 18px;">';
		echo '<li>' . esc_html__( 'Install Google Analytics 4 on the site (Site Kit, MonsterInsights, Google Tag Manager, or raw gtag.js — anything that defines window.gtag).', 'kdna-regional-content' ) . '</li>';
		echo '<li>' . wp_kses(
			__( 'In GA4 <em>Admin &rarr; Custom Definitions &rarr; Create custom dimension</em>, create two event-scoped dimensions named <code>kdna_region</code> and <code>kdna_language</code>.', 'kdna-regional-content' ),
			array( 'em' => array(), 'code' => array() )
		) . '</li>';
		echo '<li>' . esc_html__( 'Wait 24 hours for GA4 to start populating data. Then break any report down by kdna_region or kdna_language.', 'kdna-regional-content' ) . '</li>';
		echo '</ol>';
		echo '<p style="margin:0;"><em>' . esc_html__( 'Safe to leave on even if GA is not yet installed: the snippet is a no-op until window.gtag exists.', 'kdna-regional-content' ) . '</em></p>';
		echo '</div>';
	}

	/**
	 * Render the intro copy for the region-switch banner section.
	 *
	 * @return void
	 */
	public function render_section_region_banner() {
		echo '<p>' . esc_html__( 'Show a small, dismissible prompt at the top of the page when the visitor\'s IP-detected region differs from the URL they landed on. Visitors choose whether to switch; nothing redirects automatically (auto-redirects break shared links and confuse search engines).', 'kdna-regional-content' ) . '</p>';
		echo '<p>' . esc_html__( 'Shown at most once per visitor; the dismiss cookie matches the Cookie Lifetime setting above.', 'kdna-regional-content' ) . '</p>';
	}

	/**
	 * Render the region-switch banner enable toggle.
	 *
	 * @return void
	 */
	public function render_field_region_banner_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['region_banner_enabled'] );

		printf( '<input type="hidden" name="%1$s[region_banner_enabled_present]" value="1" />', esc_attr( KDNA_RC_OPTION_SETTINGS ) );
		printf(
			'<label><input type="checkbox" id="kdna_rc_region_banner_enabled" name="%1$s[region_banner_enabled]" value="1"%2$s /> %3$s</label>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			checked( $current, true, false ),
			esc_html__( 'Show the banner when the detected region differs from the URL the visitor is on.', 'kdna-regional-content' )
		);
	}

	/**
	 * Render the region-switch banner message-template field.
	 *
	 * @return void
	 */
	public function render_field_region_banner_message() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['region_banner_message'] ) ? (string) $settings['region_banner_message'] : '';

		printf(
			'<textarea id="kdna_rc_region_banner_message" name="%1$s[region_banner_message]" rows="2" class="large-text" placeholder="%2$s">%3$s</textarea>',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			esc_attr( KDNA_RC_Region_Banner::DEFAULT_MESSAGE ),
			esc_textarea( $current )
		);
		echo '<p class="description">' . wp_kses(
			__( 'Use the <code>{region}</code> macro anywhere in the message; it is replaced with the detected region\'s display name (for example <em>New Zealand</em>) at view time. Leave blank to use the default.', 'kdna-regional-content' ),
			array(
				'code' => array(),
				'em'   => array(),
			)
		) . '</p>';
	}

	/**
	 * Render the region-switch banner Yes-button label field.
	 *
	 * @return void
	 */
	public function render_field_region_banner_yes() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['region_banner_yes'] ) ? (string) $settings['region_banner_yes'] : '';

		printf(
			'<input type="text" id="kdna_rc_region_banner_yes" name="%1$s[region_banner_yes]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			esc_attr( $current ),
			esc_attr__( 'Yes, switch', 'kdna-regional-content' )
		);
	}

	/**
	 * Render the region-switch banner No-button label field.
	 *
	 * @return void
	 */
	public function render_field_region_banner_no() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$current  = isset( $settings['region_banner_no'] ) ? (string) $settings['region_banner_no'] : '';

		printf(
			'<input type="text" id="kdna_rc_region_banner_no" name="%1$s[region_banner_no]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( KDNA_RC_OPTION_SETTINGS ),
			esc_attr( $current ),
			esc_attr__( 'No thanks', 'kdna-regional-content' )
		);
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
			'kdna-rc-flag-icons',
			KDNA_RC_PLUGIN_URL . 'lib/flag-icons/css/flag-icons.min.css',
			array(),
			'7.5.0'
		);

		wp_enqueue_style(
			'kdna-rc-admin',
			KDNA_RC_PLUGIN_URL . 'admin/admin-style.css',
			array( 'kdna-rc-flag-icons' ),
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
					'updateDatabase'     => KDNA_RC_Database_Updater::AJAX_ACTION,
					'saveRegion'         => KDNA_RC_Regions::AJAX_SAVE,
					'deleteRegion'       => KDNA_RC_Regions::AJAX_DELETE,
					'reorderRegions'     => KDNA_RC_Regions::AJAX_REORDER,
					'testDetection'      => KDNA_RC_Detector::AJAX_TEST_ACTION,
					'clearCaches'        => KDNA_RC_Cache_Integration::AJAX_CLEAR,
					'saveLanguage'       => KDNA_RC_Languages::AJAX_SAVE,
					'deleteLanguage'     => KDNA_RC_Languages::AJAX_DELETE,
					'reorderLanguages'   => KDNA_RC_Languages::AJAX_REORDER,
					'testLangDetection'  => KDNA_RC_Language_Detector::AJAX_TEST_ACTION,
					'migrationFields'    => 'kdna_rc_migration_fields',
					'migrationStart'     => KDNA_RC_Migration_Tool::AJAX_START,
					'migrationBatch'     => KDNA_RC_Migration_Tool::AJAX_BATCH,
					'auditScan'          => KDNA_RC_Field_Audit_Tool::AJAX_SCAN,
					'auditBulkAdd'       => KDNA_RC_Field_Audit_Tool::AJAX_BULK_ADD,
					'flushRules'         => KDNA_RC_URL_Routing::AJAX_FLUSH,
					'seoHealthCheck'     => KDNA_RC_SEO_Health_Check::AJAX_ACTION,
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
					'clearing'        => __( 'Clearing caches...', 'kdna-regional-content' ),
					'cleared'         => __( 'Caches cleared.', 'kdna-regional-content' ),
					'newLanguage'     => __( 'New language', 'kdna-regional-content' ),
					'untitledLanguage' => __( 'Untitled language', 'kdna-regional-content' ),
					'savingLanguage'  => __( 'Saving...', 'kdna-regional-content' ),
					'languageSaved'   => __( 'Language saved.', 'kdna-regional-content' ),
					'languageDeleted' => __( 'Language deleted.', 'kdna-regional-content' ),
					'libraryNoResults' => __( 'No matching languages.', 'kdna-regional-content' ),
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
		$this->ensure_tabs();
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

		$this->ensure_tabs();
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
		$this->ensure_tabs();
		return $this->tabs;
	}
}
