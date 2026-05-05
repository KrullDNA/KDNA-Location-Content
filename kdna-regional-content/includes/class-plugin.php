<?php
/**
 * Main plugin bootstrap.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Plugin
 *
 * Singleton bootstrap that wires up admin and front-end components. Stage 1
 * only loads the admin settings page; later stages add detection, Elementor
 * integration, and asset management here.
 */
final class KDNA_RC_Plugin {

	/**
	 * Holds the single instance of this class.
	 *
	 * @var KDNA_RC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings page handler.
	 *
	 * @var KDNA_RC_Settings|null
	 */
	private $settings = null;

	/**
	 * Database updater handler.
	 *
	 * @var KDNA_RC_Database_Updater|null
	 */
	private $database_updater = null;

	/**
	 * Regions handler.
	 *
	 * @var KDNA_RC_Regions|null
	 */
	private $regions = null;

	/**
	 * Visitor detection handler.
	 *
	 * @var KDNA_RC_Detector|null
	 */
	private $detector = null;

	/**
	 * Elementor element visibility handler.
	 *
	 * @var KDNA_RC_Elementor_Visibility|null
	 */
	private $elementor_visibility = null;

	/**
	 * Post-level visibility handler.
	 *
	 * @var KDNA_RC_Post_Visibility|null
	 */
	private $post_visibility = null;

	/**
	 * JetEngine integration.
	 *
	 * @var KDNA_RC_JetEngine_Integration|null
	 */
	private $jetengine = null;

	/**
	 * Front-end assets handler.
	 *
	 * @var KDNA_RC_Assets|null
	 */
	private $assets = null;

	/**
	 * Return the singleton instance, creating it on first call.
	 *
	 * @return KDNA_RC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Private constructor enforces the singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Disallow cloning so the singleton stays single.
	 */
	private function __clone() {
	}

	/**
	 * Disallow unserialisation so the singleton stays single.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'KDNA_RC_Plugin cannot be unserialised.' );
	}

	/**
	 * Wire up hooks and instantiate child components.
	 *
	 * Called once from instance() on first construction. Keeps load order
	 * predictable and makes future stages easy to slot in.
	 *
	 * @return void
	 */
	private function init() {
		// Load translations as early as possible.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Database updater registers the cron schedule filter and the cron
		// callback in every context (admin, front-end, cron) so scheduled
		// runs work even when no admin is logged in.
		$this->database_updater = new KDNA_RC_Database_Updater();
		$this->database_updater->init();

		// Regions handler. Instantiated everywhere so future stages (variant
		// rendering, detection) can use it on the front end too. AJAX handlers
		// register only once, gated below to admin.
		$this->regions = new KDNA_RC_Regions();
		if ( is_admin() ) {
			$this->regions->init();
		}

		// Visitor detection. init() registers public AJAX (priv + nopriv),
		// the early ?region= override handler, and the wp_head inline
		// configuration printer. Safe to run in every context.
		$this->detector = new KDNA_RC_Detector();
		$this->detector->init();

		// Stage 5 visibility layer. Each handler is harmless when its
		// integration target is missing (Elementor, JetEngine), so they
		// can be instantiated unconditionally.
		$this->elementor_visibility = new KDNA_RC_Elementor_Visibility();
		$this->elementor_visibility->init();

		$this->post_visibility = new KDNA_RC_Post_Visibility();
		$this->post_visibility->init();

		$this->jetengine = new KDNA_RC_JetEngine_Integration();
		$this->jetengine->init();

		$this->assets = new KDNA_RC_Assets();
		$this->assets->init();

		// Boot the admin settings page only inside wp-admin.
		if ( is_admin() ) {
			$this->settings = new KDNA_RC_Settings();
			$this->settings->init();
		}

		// Keep the cron event in sync whenever settings are saved so a
		// schedule change takes effect on the next request.
		add_action( 'update_option_' . KDNA_RC_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_' . KDNA_RC_OPTION_SETTINGS, array( $this, 'on_settings_added' ), 10, 2 );
	}

	/**
	 * Load the plugin text domain so all strings can be translated.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			KDNA_RC_TEXT_DOMAIN,
			false,
			dirname( KDNA_RC_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Convenience accessor for the settings handler.
	 *
	 * @return KDNA_RC_Settings|null
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Convenience accessor for the database updater.
	 *
	 * @return KDNA_RC_Database_Updater|null
	 */
	public function database_updater() {
		return $this->database_updater;
	}

	/**
	 * Convenience accessor for the regions handler.
	 *
	 * @return KDNA_RC_Regions|null
	 */
	public function regions() {
		return $this->regions;
	}

	/**
	 * Convenience accessor for the visitor detection handler.
	 *
	 * @return KDNA_RC_Detector|null
	 */
	public function detector() {
		return $this->detector;
	}

	/**
	 * Reconcile the WP-Cron event whenever the settings option is updated.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function on_settings_updated( $old_value, $new_value ) {
		unset( $old_value, $new_value );
		if ( $this->database_updater ) {
			$this->database_updater->reconcile_cron_schedule();
		}
	}

	/**
	 * Reconcile the WP-Cron event when the settings option is created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function on_settings_added( $option, $value ) {
		unset( $option, $value );
		if ( $this->database_updater ) {
			$this->database_updater->reconcile_cron_schedule();
		}
	}
}
