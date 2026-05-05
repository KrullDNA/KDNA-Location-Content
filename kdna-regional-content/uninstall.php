<?php
/**
 * Uninstall handler for KDNA Regional Content.
 *
 * Removes every option, transient, and scheduled event the plugin owns so a
 * full uninstall leaves no trace behind. Runs only when WordPress invokes it
 * via the uninstall lifecycle, never on plain deactivation.
 *
 * @package KDNA_Regional_Content
 */

// Bail if not invoked from the WordPress uninstall lifecycle.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options created by Stage 1.
delete_option( 'kdna_rc_settings' );

// Options reserved for later stages, deleted here pre-emptively so a clean
// uninstall continues to work as the plugin grows.
delete_option( 'kdna_rc_regions' );
delete_option( 'kdna_rc_db_status' );

// Clear any scheduled cron events the plugin may have registered.
$timestamp = wp_next_scheduled( 'kdna_rc_update_database' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'kdna_rc_update_database' );
}
