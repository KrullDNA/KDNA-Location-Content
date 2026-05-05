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

		// Boot the admin settings page only inside wp-admin.
		if ( is_admin() ) {
			$this->settings = new KDNA_RC_Settings();
			$this->settings->init();
		}
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
}
