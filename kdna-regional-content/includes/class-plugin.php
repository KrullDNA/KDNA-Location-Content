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
