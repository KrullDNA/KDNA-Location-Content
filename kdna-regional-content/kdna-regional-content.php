<?php
/**
 * Plugin Name:       KDNA Regional Content
 * Plugin URI:        https://krulldna.com/
 * Description:       Serves region-specific content variants and visibility rules in Elementor based on visitor geolocation, with full-page cache compatibility.
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Krull Design and Advertising (KDNA)
 * Author URI:        https://krulldna.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kdna-regional-content
 * Domain Path:       /languages
 *
 * @package KDNA_Regional_Content
 */

// Block direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 *
 * Centralised here so every class and view references the same values.
 * Each define() is guarded with `defined()` because at least one other
 * plugin in the KDNA family (kdna-reveal-card) declares constants under
 * the same KDNA_RC_ prefix, and we do not want either plugin's load order
 * to produce a "Constant already defined" warning.
 */
if ( ! defined( 'KDNA_RC_VERSION' ) ) {
	define( 'KDNA_RC_VERSION', '0.4.1' );
}
if ( ! defined( 'KDNA_RC_PLUGIN_FILE' ) ) {
	define( 'KDNA_RC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'KDNA_RC_PLUGIN_DIR' ) ) {
	define( 'KDNA_RC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'KDNA_RC_PLUGIN_URL' ) ) {
	define( 'KDNA_RC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'KDNA_RC_PLUGIN_BASENAME' ) ) {
	define( 'KDNA_RC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'KDNA_RC_TEXT_DOMAIN' ) ) {
	define( 'KDNA_RC_TEXT_DOMAIN', 'kdna-regional-content' );
}

// Single option key holding all general settings (per Stage 1 brief).
if ( ! defined( 'KDNA_RC_OPTION_SETTINGS' ) ) {
	define( 'KDNA_RC_OPTION_SETTINGS', 'kdna_rc_settings' );
}

/**
 * Auto-load KDNA_RC_* classes from the includes/ and admin/ folders.
 *
 * Maps a class name like KDNA_RC_Heading_Extension to a filename like
 * class-heading-extension.php and looks for it inside the directories below.
 * Falls through silently when the class is not one of ours so it does not
 * interfere with other plugins or core autoloaders.
 *
 * @param string $class_name Fully qualified class name being loaded.
 * @return void
 */
// The bare-prefix public KDNA_RC API class does not match the autoloader's
// KDNA_RC_ prefix rule, so include it explicitly.
require_once KDNA_RC_PLUGIN_DIR . 'includes/class-kdna-rc.php';

spl_autoload_register(
	function ( $class_name ) {
		// Only handle classes belonging to this plugin.
		if ( strpos( $class_name, 'KDNA_RC_' ) !== 0 ) {
			return;
		}

		// Strip the prefix, lowercase, swap underscores for hyphens.
		$relative = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'KDNA_RC_' ) ) ) );
		$filename = 'class-' . $relative . '.php';

		// Folders the autoloader will search, in order of likelihood.
		$paths = array(
			KDNA_RC_PLUGIN_DIR . 'includes/' . $filename,
			KDNA_RC_PLUGIN_DIR . 'includes/widget-extensions/' . $filename,
			KDNA_RC_PLUGIN_DIR . 'includes/jetengine-field-types/' . $filename,
			KDNA_RC_PLUGIN_DIR . 'includes/seo-adapters/' . $filename,
			KDNA_RC_PLUGIN_DIR . 'admin/' . $filename,
		);

		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Run on plugin activation.
 *
 * Seeds the settings option with safe defaults if it does not yet exist so the
 * settings page never sees an undefined value on first load.
 *
 * @return void
 */
function kdna_rc_activate() {
	if ( false === get_option( KDNA_RC_OPTION_SETTINGS ) ) {
		add_option(
			KDNA_RC_OPTION_SETTINGS,
			array(
				'maxmind_license_key'        => '',
				'db_update_schedule'         => 'kdna_rc_monthly',
				'default_region'             => '',
				'test_override_mode'         => 'admins',
				'trust_proxy_headers'        => true,
				'cookie_lifetime_days'       => 30,
				'restricted_post_types'      => array(),
				'single_post_behaviour'      => 'show',
				'single_post_redirect_url'   => '',
				'delete_on_uninstall'        => false,
			)
		);
	}

	// Seed the regions option so get_option returns an array on every call.
	if ( false === get_option( KDNA_RC_Regions::OPTION_KEY ) ) {
		add_option( KDNA_RC_Regions::OPTION_KEY, array() );
	}

	// Seed the languages option (Stage 10) for the same reason.
	if ( false === get_option( KDNA_RC_Languages::OPTION_KEY ) ) {
		add_option( KDNA_RC_Languages::OPTION_KEY, array() );
	}

	// Schedule the auto-update cron event. init() registers the custom
	// monthly schedule filter so wp_schedule_event() can resolve the slug
	// during the activation request (plugins_loaded does not fire here).
	if ( class_exists( 'KDNA_RC_Database_Updater' ) ) {
		$updater = new KDNA_RC_Database_Updater();
		$updater->init();
		$updater->reconcile_cron_schedule();
	}
}
register_activation_hook( __FILE__, 'kdna_rc_activate' );

/**
 * Run on plugin deactivation.
 *
 * Reserved for future scheduled-event teardown (added in Stage 2). Kept as a
 * stub now so the activation and deactivation pair sit together in one place.
 *
 * @return void
 */
function kdna_rc_deactivate() {
	if ( class_exists( 'KDNA_RC_Database_Updater' ) ) {
		KDNA_RC_Database_Updater::clear_scheduled_event();
	} else {
		wp_clear_scheduled_hook( 'kdna_rc_update_database' );
	}
}
register_deactivation_hook( __FILE__, 'kdna_rc_deactivate' );

/**
 * Boot the plugin once WordPress is ready.
 *
 * Defers initialisation to the plugins_loaded hook so translations and other
 * dependencies are available, then hands control to the singleton bootstrap.
 *
 * @return void
 */
function kdna_rc_bootstrap() {
	KDNA_RC_Plugin::instance();
}
add_action( 'plugins_loaded', 'kdna_rc_bootstrap' );
