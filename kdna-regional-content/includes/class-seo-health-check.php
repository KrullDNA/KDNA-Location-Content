<?php
/**
 * SEO health check for the Tools tab.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Health_Check
 *
 * Scans the site for common misconfigurations that break the Stage 14
 * + Stage 15 SEO contract. Reports pass / warning / fail entries with
 * a remediation hint per check. Backs the Tools-tab "Run SEO health
 * check" button.
 */
class KDNA_RC_SEO_Health_Check {

	const AJAX_ACTION = 'kdna_rc_seo_health_check';

	/**
	 * Wire admin AJAX.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_run' ) );
	}

	/**
	 * Run all checks and return a list of findings.
	 *
	 * @return void
	 */
	public function ajax_run() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'kdna-regional-content' ) ), 403 );
		}

		$findings = array();
		$findings[] = $this->check_yoast_active();
		$findings[] = $this->check_regions_configured();
		$findings[] = $this->check_languages_configured();
		$findings[] = $this->check_default_region();
		$findings[] = $this->check_default_language();
		$findings[] = $this->check_hreflang_enabled();
		$findings[] = $this->check_canonical_strategy_documented();
		$findings[] = $this->check_sitemap_mode();
		$findings[] = $this->check_url_routing_post_types();
		$findings[] = $this->check_rewrite_snapshot();

		wp_send_json_success( array( 'findings' => array_values( array_filter( $findings ) ) ) );
	}

	/**
	 * Helper to produce a finding row.
	 *
	 * @param string $level   One of 'pass' | 'warn' | 'fail'.
	 * @param string $title   Short title.
	 * @param string $message Longer message + remediation.
	 * @return array
	 */
	private function finding( $level, $title, $message ) {
		return array(
			'level'   => $level,
			'title'   => $title,
			'message' => $message,
		);
	}

	/**
	 * Yoast active.
	 *
	 * @return array
	 */
	private function check_yoast_active() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return $this->finding( 'pass', __( 'Yoast SEO active', 'kdna-regional-content' ), sprintf( __( 'Yoast %s detected.', 'kdna-regional-content' ), WPSEO_VERSION ) );
		}
		return $this->finding( 'fail', __( 'Yoast SEO not detected', 'kdna-regional-content' ), __( 'Install and activate Yoast SEO. The plugin\'s regional / language SEO meta box, canonical strategy, and Yoast filter integration depend on Yoast being present.', 'kdna-regional-content' ) );
	}

	/**
	 * Regions configured.
	 *
	 * @return array
	 */
	private function check_regions_configured() {
		$count = count( ( new KDNA_RC_Regions() )->get_all() );
		if ( $count > 0 ) {
			return $this->finding( 'pass', __( 'Regions configured', 'kdna-regional-content' ), sprintf( __( '%d region(s) configured.', 'kdna-regional-content' ), $count ) );
		}
		return $this->finding( 'warn', __( 'No regions configured', 'kdna-regional-content' ), __( 'Add at least one region under Regional Content > Regions for the URL routing and hreflang output to do anything.', 'kdna-regional-content' ) );
	}

	/**
	 * Languages configured.
	 *
	 * @return array
	 */
	private function check_languages_configured() {
		$count = count( ( new KDNA_RC_Languages() )->get_all() );
		if ( $count > 0 ) {
			return $this->finding( 'pass', __( 'Languages configured', 'kdna-regional-content' ), sprintf( __( '%d language(s) configured.', 'kdna-regional-content' ), $count ) );
		}
		return $this->finding( 'warn', __( 'No languages configured', 'kdna-regional-content' ), __( 'Add at least one language under Regional Content > Languages so language URL prefixes resolve and hreflang annotations include language alternates.', 'kdna-regional-content' ) );
	}

	/**
	 * Default region set.
	 *
	 * @return array
	 */
	private function check_default_region() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( ! empty( $settings['default_region'] ) ) {
			return $this->finding( 'pass', __( 'Default region set', 'kdna-regional-content' ), sprintf( __( 'Default region: %s.', 'kdna-regional-content' ), (string) $settings['default_region'] ) );
		}
		return $this->finding( 'warn', __( 'Default region not set', 'kdna-regional-content' ), __( 'Set a Default Region under Regional Content > General. Visitors whose IP does not resolve to a configured region fall back to the default; without one they see no regional content.', 'kdna-regional-content' ) );
	}

	/**
	 * Default language set (only fails when languages exist but no default).
	 *
	 * @return array
	 */
	private function check_default_language() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$count    = count( ( new KDNA_RC_Languages() )->get_all() );
		if ( 0 === $count ) {
			return null;
		}
		if ( ! empty( $settings['default_language'] ) ) {
			return $this->finding( 'pass', __( 'Default language set', 'kdna-regional-content' ), sprintf( __( 'Default language: %s.', 'kdna-regional-content' ), (string) $settings['default_language'] ) );
		}
		return $this->finding( 'warn', __( 'Default language not set', 'kdna-regional-content' ), __( 'Pick a Default Language under Regional Content > General so the detection chain has a fallback when no other step matches.', 'kdna-regional-content' ) );
	}

	/**
	 * hreflang generation enabled.
	 *
	 * @return array
	 */
	private function check_hreflang_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$enabled  = ! array_key_exists( 'hreflang_enabled', (array) $settings ) || ! empty( $settings['hreflang_enabled'] );
		if ( $enabled ) {
			return $this->finding( 'pass', __( 'hreflang tags enabled', 'kdna-regional-content' ), __( 'Tags emit on every singular post and the home page.', 'kdna-regional-content' ) );
		}
		return $this->finding( 'warn', __( 'hreflang tags disabled', 'kdna-regional-content' ), __( 'Re-enable under Regional Content > General unless you manage hreflang externally.', 'kdna-regional-content' ) );
	}

	/**
	 * Canonical strategy is documented.
	 *
	 * @return array
	 */
	private function check_canonical_strategy_documented() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$strategy = isset( $settings['canonical_strategy'] ) ? (string) $settings['canonical_strategy'] : 'bare';
		$label    = 'each' === $strategy ? __( 'Each regional URL is its own canonical', 'kdna-regional-content' ) : __( 'Bare URL is canonical', 'kdna-regional-content' );
		return $this->finding( 'pass', __( 'Canonical strategy', 'kdna-regional-content' ), $label );
	}

	/**
	 * Sitemap integration mode.
	 *
	 * @return array
	 */
	private function check_sitemap_mode() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$mode     = isset( $settings['sitemap_mode'] ) ? (string) $settings['sitemap_mode'] : 'extend';
		if ( 'disabled' === $mode ) {
			return $this->finding( 'warn', __( 'Sitemap integration disabled', 'kdna-regional-content' ), __( 'Search engines will not discover regional / language URL variants. Turn on under Regional Content > General > Sitemap integration mode.', 'kdna-regional-content' ) );
		}
		return $this->finding( 'pass', __( 'Sitemap integration', 'kdna-regional-content' ), sprintf( __( 'Mode: %s.', 'kdna-regional-content' ), $mode ) );
	}

	/**
	 * URL routing post-type whitelist.
	 *
	 * @return array
	 */
	private function check_url_routing_post_types() {
		if ( ! class_exists( 'KDNA_RC_URL_Routing' ) ) {
			return null;
		}
		$types = ( new KDNA_RC_URL_Routing() )->eligible_post_types();
		if ( empty( $types ) ) {
			return $this->finding( 'fail', __( 'No post types eligible for URL routing', 'kdna-regional-content' ), __( 'Tick at least one post type under Regional Content > General > Post types eligible for regional URLs.', 'kdna-regional-content' ) );
		}
		return $this->finding( 'pass', __( 'Post types eligible for routing', 'kdna-regional-content' ), implode( ', ', $types ) );
	}

	/**
	 * Rewrite-rules snapshot freshness.
	 *
	 * @return array
	 */
	private function check_rewrite_snapshot() {
		$snapshot = get_option( KDNA_RC_URL_Routing::SNAPSHOT_OPTION, null );
		if ( null === $snapshot ) {
			return $this->finding( 'warn', __( 'Rewrite rules not flushed', 'kdna-regional-content' ), __( 'Click "Flush rewrite rules" on the Tools tab once after activating, or after changing regions / languages, so the URL routing rules pick up the latest configuration.', 'kdna-regional-content' ) );
		}
		return $this->finding( 'pass', __( 'Rewrite rules flushed', 'kdna-regional-content' ), __( 'Snapshot recorded; routes will refresh automatically when regions or languages change.', 'kdna-regional-content' ) );
	}
}
