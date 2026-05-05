<?php
/**
 * Admin notices for misconfigured plugin states.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Admin_Notices
 *
 * Surfaces four configuration warnings on every admin page so misconfigured
 * sites do not silently fail:
 *
 *   1. MaxMind license key is missing.
 *   2. GeoLite2 database is missing or older than 60 days.
 *   3. No regions configured.
 *   4. No default region set.
 *
 * Notices auto-resolve once the underlying issue is fixed; nothing to dismiss.
 * They are scoped to manage_options users so editors and contributors are
 * not bothered.
 */
class KDNA_RC_Admin_Notices {

	/**
	 * How old (in days) a database file may be before we warn the admin.
	 *
	 * @var int
	 */
	const STALE_DB_DAYS = 60;

	/**
	 * Wire up the admin_notices hook.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Render all applicable notices.
	 *
	 * Each branch is independent so multiple issues can be displayed at
	 * once. Capability check happens once at the top so non-admins never
	 * see any of them.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$this->maybe_notice_license( $settings );
		$this->maybe_notice_database();
		$this->maybe_notice_no_regions();
		$this->maybe_notice_no_default( $settings );
	}

	/**
	 * Build a deep link to a specific tab on the plugin's settings page.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function settings_url( $tab ) {
		return add_query_arg(
			array(
				'page' => KDNA_RC_Settings::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render a single notice with a contextual link.
	 *
	 * @param string $type    'warning' or 'error'.
	 * @param string $message Already-translated message.
	 * @param string $url     URL the link points to.
	 * @param string $label   Visible link label.
	 * @return void
	 */
	private function render_notice( $type, $message, $url, $label ) {
		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s &nbsp; <a href="%4$s">%5$s</a></p></div>',
			esc_attr( $type ),
			esc_html__( 'KDNA Regional Content:', 'kdna-regional-content' ),
			esc_html( $message ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Notice 1: MaxMind license key is missing.
	 *
	 * @param array $settings Cached settings.
	 * @return void
	 */
	private function maybe_notice_license( array $settings ) {
		$key = isset( $settings['maxmind_license_key'] ) ? trim( (string) $settings['maxmind_license_key'] ) : '';
		if ( '' !== $key ) {
			return;
		}
		$this->render_notice(
			'warning',
			__( 'Add a MaxMind license key to start detecting visitor regions.', 'kdna-regional-content' ),
			$this->settings_url( 'general' ),
			__( 'Open the General tab', 'kdna-regional-content' )
		);
	}

	/**
	 * Notice 2: database missing or older than 60 days.
	 *
	 * @return void
	 */
	private function maybe_notice_database() {
		$path = KDNA_RC_GeoIP::database_path();
		if ( ! KDNA_RC_GeoIP::database_exists() ) {
			$this->render_notice(
				'warning',
				__( 'The GeoLite2-Country database is missing. Visitor detection cannot run until you download it.', 'kdna-regional-content' ),
				$this->settings_url( 'tools' ),
				__( 'Open the Tools tab', 'kdna-regional-content' )
			);
			return;
		}

		$mtime = (int) filemtime( $path );
		$age   = time() - $mtime;
		if ( $age > self::STALE_DB_DAYS * DAY_IN_SECONDS ) {
			$this->render_notice(
				'warning',
				sprintf(
					/* translators: %d: number of days the database has not been refreshed. */
					__( 'The GeoLite2-Country database has not been refreshed for over %d days. Run an update to keep country lookups accurate.', 'kdna-regional-content' ),
					self::STALE_DB_DAYS
				),
				$this->settings_url( 'tools' ),
				__( 'Open the Tools tab', 'kdna-regional-content' )
			);
		}
	}

	/**
	 * Notice 3: no regions configured.
	 *
	 * @return void
	 */
	private function maybe_notice_no_regions() {
		$regions = ( new KDNA_RC_Regions() )->get_all();
		if ( ! empty( $regions ) ) {
			return;
		}
		$this->render_notice(
			'warning',
			__( 'No regions are configured. Add at least one region so the plugin has something to match visitors against.', 'kdna-regional-content' ),
			$this->settings_url( 'regions' ),
			__( 'Open the Regions tab', 'kdna-regional-content' )
		);
	}

	/**
	 * Notice 4: regions exist but no default region is set.
	 *
	 * Only fires when at least one region exists, so a fresh install does
	 * not produce two simultaneous "no default" + "no regions" notices.
	 *
	 * @param array $settings Cached settings.
	 * @return void
	 */
	private function maybe_notice_no_default( array $settings ) {
		$default = isset( $settings['default_region'] ) ? (string) $settings['default_region'] : '';
		if ( '' !== $default ) {
			return;
		}
		$regions = ( new KDNA_RC_Regions() )->get_all();
		if ( empty( $regions ) ) {
			return;
		}
		$this->render_notice(
			'warning',
			__( 'No default region is set. Visitors whose country does not match any configured region will see no regional content.', 'kdna-regional-content' ),
			$this->settings_url( 'general' ),
			__( 'Open the General tab', 'kdna-regional-content' )
		);
	}
}
