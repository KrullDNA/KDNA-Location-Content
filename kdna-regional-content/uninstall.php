<?php
/**
 * Uninstall handler for KDNA Regional Content.
 *
 * Removes every option, transient, scheduled event, post meta entry, and the
 * downloaded GeoLite2 database the plugin owns so a full uninstall leaves no
 * trace behind. Runs only when WordPress invokes it via the uninstall
 * lifecycle, never on plain deactivation.
 *
 * @package KDNA_Regional_Content
 */

// Bail if not invoked from the WordPress uninstall lifecycle.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the admin's data retention preference. The default behaviour is to
// keep everything in place so a delete-and-reinstall cycle preserves the
// configuration. Only when the admin has explicitly ticked
// "Delete data on uninstall" on the General tab does this script wipe data.
$kdna_rc_settings = get_option( 'kdna_rc_settings', array() );
if ( ! is_array( $kdna_rc_settings ) || empty( $kdna_rc_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// 1. Plugin options.
$options = array(
	'kdna_rc_settings',
	'kdna_rc_regions',
	'kdna_rc_db_status',
);
foreach ( $options as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

// 2. Post meta written by the post-level visibility module. Direct DB call
// because there can be many rows and looping by post_id would be slow on
// large sites.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_kdna_rc_regions' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// 3. Plugin transients (any key prefixed kdna_rc_).
$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'kdna_rc_' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'kdna_rc_' ) . '%'
	)
);
foreach ( (array) $rows as $option_name ) {
	$key = preg_replace( '/^_transient_(timeout_)?/', '', (string) $option_name );
	if ( '' !== $key ) {
		delete_transient( $key );
	}
}

// 4. Scheduled cron events.
$timestamp = wp_next_scheduled( 'kdna_rc_update_database' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'kdna_rc_update_database' );
}
wp_clear_scheduled_hook( 'kdna_rc_update_database' );

// 5. Downloaded GeoLite2 database and its parent directory.
$uploads = wp_upload_dir( null, false );
$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
if ( '' !== $base ) {
	$plugin_dir = trailingslashit( $base ) . 'kdna-regional-content';
	if ( is_dir( $plugin_dir ) ) {
		// Walk recursively without bringing in WP_Filesystem (uninstall.php
		// runs in a minimal bootstrap; native PHP is the most reliable path).
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			} else {
				@unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
		@rmdir( $plugin_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}
}
