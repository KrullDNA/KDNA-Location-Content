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
	 * Enqueue the admin stylesheet on this plugin's settings page only.
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
