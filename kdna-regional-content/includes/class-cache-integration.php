<?php
/**
 * WP Rocket integration and admin "Clear All Caches" handler.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Cache_Integration
 *
 * Two responsibilities:
 *
 *   1. Auto-detect WP Rocket and exclude the kdna_rc_detect_region AJAX
 *      endpoint from the page cache so first-time visitors always see a
 *      fresh detection response.
 *   2. Provide a "Clear All Caches" admin AJAX action that flushes WP Rocket
 *      (when present) and the plugin's own transients.
 */
class KDNA_RC_Cache_Integration {

	/**
	 * AJAX action name backing the Clear All Caches button.
	 *
	 * @var string
	 */
	const AJAX_CLEAR = 'kdna_rc_clear_caches';

	/**
	 * Wire up filters and the admin AJAX handler.
	 *
	 * @return void
	 */
	public function init() {
		// WP Rocket exclusion filters. Both names cover different versions of
		// the cache plugin; safe to register both because each filter only
		// fires when the corresponding code path executes.
		add_filter( 'rocket_cache_reject_uri', array( $this, 'add_rocket_exclusion' ) );
		add_filter( 'rocket_exclude_urls', array( $this, 'add_rocket_exclusion' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_CLEAR, array( $this, 'ajax_clear_caches' ) );
		}
	}

	/**
	 * Add the detection AJAX endpoint to the WP Rocket exclusion list.
	 *
	 * Both rocket_cache_reject_uri and rocket_exclude_urls expect arrays of
	 * regex-compatible URI fragments. Adding the same pattern to both is
	 * harmless and means our exclusion survives whichever filter WP Rocket
	 * actually consults on a given site.
	 *
	 * @param array|mixed $excluded Existing list.
	 * @return array
	 */
	public function add_rocket_exclusion( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}

		$pattern = '/wp-admin/admin-ajax\\.php.*action=' . preg_quote( KDNA_RC_Detector::AJAX_ACTION, '/' );
		if ( ! in_array( $pattern, $excluded, true ) ) {
			$excluded[] = $pattern;
		}

		return $excluded;
	}

	/**
	 * Whether WP Rocket is currently active on this site.
	 *
	 * @return bool
	 */
	public static function is_wp_rocket_active() {
		return defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' );
	}

	/**
	 * AJAX handler for the Clear All Caches button.
	 *
	 * Clears WP Rocket's full-page cache when present, deletes plugin-owned
	 * transients, and triggers a generic action so other caching layers can
	 * hook in.
	 *
	 * @return void
	 */
	public function ajax_clear_caches() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to clear caches.', 'kdna-regional-content' ) ),
				403
			);
		}

		$cleared = $this->clear_caches();
		wp_send_json_success(
			array(
				'message' => __( 'Caches cleared.', 'kdna-regional-content' ),
				'cleared' => $cleared,
			)
		);
	}

	/**
	 * Run the cache clearing routine and report which integrations were touched.
	 *
	 * @return array<int,string>
	 */
	public function clear_caches() {
		$cleared = array();

		// WP Rocket page cache.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$cleared[] = 'wp-rocket';
		}

		// Generic object cache flush so any cached lookups (region matches,
		// option values) come back fresh on the next request.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$cleared[] = 'object-cache';
		}

		// Plugin-owned transients. Future stages may add more so we
		// pre-emptively clean a kdna_rc_ prefix from both transient and
		// site_transient tables.
		$this->delete_transients_with_prefix( 'kdna_rc_' );
		$cleared[] = 'transients';

		/**
		 * Fires after the plugin clears its caches.
		 *
		 * Other cache layers can hook in here to flush their own stores.
		 *
		 * @param array $cleared List of integration slugs already cleared.
		 */
		do_action( 'kdna_rc_caches_cleared', $cleared );

		return $cleared;
	}

	/**
	 * Delete every transient whose key starts with the given prefix.
	 *
	 * Works against both per-site transients and network site_transients,
	 * and respects external object caches by deleting through the helper
	 * functions when WP_Cache is in use.
	 *
	 * @param string $prefix Transient key prefix (without leading underscores).
	 * @return void
	 */
	private function delete_transients_with_prefix( $prefix ) {
		global $wpdb;

		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return;
		}

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( $prefix ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
			)
		);

		foreach ( (array) $rows as $option_name ) {
			$key = preg_replace( '/^_transient_(timeout_)?/', '', (string) $option_name );
			if ( '' !== $key ) {
				delete_transient( $key );
			}
		}
	}
}
