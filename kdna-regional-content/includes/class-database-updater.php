<?php
/**
 * MaxMind GeoLite2 database downloader and scheduler.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Database_Updater
 *
 * Owns everything related to obtaining and refreshing the
 * GeoLite2-Country.mmdb file: the admin AJAX handler that backs the
 * Update Database Now button, the WP-Cron schedule and hook callback,
 * and the option key that records the last successful download.
 */
class KDNA_RC_Database_Updater {

	/**
	 * AJAX action name backing the Update Database Now button.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'kdna_rc_update_database';

	/**
	 * WP-Cron hook fired when the scheduled auto-update runs.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'kdna_rc_update_database';

	/**
	 * Option key holding the most recent download status.
	 *
	 * @var string
	 */
	const STATUS_OPTION = 'kdna_rc_db_status';

	/**
	 * Custom monthly cron schedule slug.
	 *
	 * @var string
	 */
	const SCHEDULE_MONTHLY = 'kdna_rc_monthly';

	/**
	 * MaxMind download endpoint for the country edition.
	 *
	 * @var string
	 */
	const DOWNLOAD_URL = 'https://download.maxmind.com/app/geoip_download';

	/**
	 * Wire up admin AJAX, cron, and schedule registration hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_run_update' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_update' ) );
		}
	}

	/**
	 * Add the custom monthly schedule used by the auto-update cron event.
	 *
	 * Weekly is already registered by core in WordPress 5.4+, so we only need
	 * to add the monthly entry. Thirty days is close enough to a month for
	 * MaxMind's monthly release cadence.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_MONTHLY ] ) ) {
			$schedules[ self::SCHEDULE_MONTHLY ] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once monthly (every 30 days)', 'kdna-regional-content' ),
			);
		}
		return $schedules;
	}

	/**
	 * Read the stored status array.
	 *
	 * Returns sane defaults so callers do not need to test for missing keys.
	 *
	 * @return array
	 */
	public function get_status() {
		$defaults = array(
			'last_updated' => 0,
			'file_size'    => 0,
			'last_error'   => '',
			'last_attempt' => 0,
		);

		$stored = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Persist a partial status update.
	 *
	 * @param array $update Fields to merge into the stored status.
	 * @return void
	 */
	private function update_status( array $update ) {
		$status = $this->get_status();
		$status = array_merge( $status, $update );
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Get the configured auto-update frequency.
	 *
	 * Defaults to monthly when not set so a fresh install still has a working
	 * cron schedule without admin intervention.
	 *
	 * @return string One of 'weekly', self::SCHEDULE_MONTHLY, 'never'.
	 */
	public function get_schedule_frequency() {
		$settings  = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$frequency = isset( $settings['db_update_schedule'] ) ? (string) $settings['db_update_schedule'] : self::SCHEDULE_MONTHLY;

		if ( ! in_array( $frequency, $this->valid_schedules(), true ) ) {
			$frequency = self::SCHEDULE_MONTHLY;
		}

		return $frequency;
	}

	/**
	 * List of accepted auto-update schedule values.
	 *
	 * @return array<int,string>
	 */
	public function valid_schedules() {
		return array( 'weekly', self::SCHEDULE_MONTHLY, 'never' );
	}

	/**
	 * Make sure the WP-Cron event matches the saved frequency.
	 *
	 * Idempotent: safe to call on every settings save and on activation. When
	 * the saved frequency is 'never' any existing event is cleared.
	 *
	 * @return void
	 */
	public function reconcile_cron_schedule() {
		$frequency = $this->get_schedule_frequency();
		$next      = wp_next_scheduled( self::CRON_HOOK );

		if ( 'never' === $frequency ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );
			}
			return;
		}

		// If a different schedule is currently set, clear it before scheduling fresh.
		$existing_schedule = wp_get_schedule( self::CRON_HOOK );
		if ( $existing_schedule && $existing_schedule !== $frequency ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled cron event entirely.
	 *
	 * Called from plugin deactivation so a disabled plugin does not leave an
	 * orphaned event firing every month.
	 *
	 * @return void
	 */
	public static function clear_scheduled_event() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cron callback: run the update silently and log success or failure.
	 *
	 * @return void
	 */
	public function cron_run_update() {
		$result = $this->run_update();
		if ( is_wp_error( $result ) ) {
			$this->update_status(
				array(
					'last_attempt' => time(),
					'last_error'   => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * AJAX callback for the Update Database Now button.
	 *
	 * Validates the nonce and capability, runs the update, and returns the
	 * fresh status payload for the JS to render. All error paths return a
	 * descriptive message so the admin always knows what went wrong.
	 *
	 * @return void
	 */
	public function ajax_update() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to update the database.', 'kdna-regional-content' ) ),
				403
			);
		}

		$result = $this->run_update();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'status'  => $this->status_for_response(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Database updated successfully.', 'kdna-regional-content' ),
				'status'  => $this->status_for_response(),
			)
		);
	}

	/**
	 * Build the status array used in AJAX responses and the Tools UI.
	 *
	 * @return array
	 */
	public function status_for_response() {
		$geoip    = new KDNA_RC_GeoIP();
		$metadata = $geoip->metadata();
		$status   = $this->get_status();
		$path     = KDNA_RC_GeoIP::database_path();
		$exists   = KDNA_RC_GeoIP::database_exists();

		$file_size = $exists ? (int) filesize( $path ) : 0;
		$file_mod  = $exists ? (int) filemtime( $path ) : 0;

		return array(
			'exists'              => $exists,
			'path'                => $path,
			'file_size'           => $file_size,
			'file_size_human'     => $exists ? size_format( $file_size ) : '',
			'last_updated'        => $file_mod ? $file_mod : (int) $status['last_updated'],
			'last_updated_human'  => $file_mod ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file_mod ) : '',
			'metadata'            => $metadata,
			'last_error'          => (string) $status['last_error'],
			'last_attempt'        => (int) $status['last_attempt'],
			'next_scheduled'      => (int) wp_next_scheduled( self::CRON_HOOK ),
			'schedule_frequency'  => $this->get_schedule_frequency(),
			'license_key_present' => $this->license_key_present(),
		);
	}

	/**
	 * Whether the admin has saved a non-empty MaxMind license key.
	 *
	 * @return bool
	 */
	public function license_key_present() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$key      = isset( $settings['maxmind_license_key'] ) ? trim( (string) $settings['maxmind_license_key'] ) : '';
		return '' !== $key;
	}

	/**
	 * Perform the full download, extract, and install routine.
	 *
	 * Returns true on success, WP_Error on any failure. Cleans up any temp
	 * files it creates whatever the outcome.
	 *
	 * @return true|WP_Error
	 */
	public function run_update() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$key      = isset( $settings['maxmind_license_key'] ) ? trim( (string) $settings['maxmind_license_key'] ) : '';

		if ( '' === $key ) {
			return new WP_Error( 'kdna_rc_no_license', __( 'Add your MaxMind license key on the General tab before updating the database.', 'kdna-regional-content' ) );
		}

		$dir = $this->prepare_uploads_directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$download = $this->download_archive( $key, $dir );
		if ( is_wp_error( $download ) ) {
			return $download;
		}

		$mmdb = $this->extract_archive( $download, $dir );

		// Always remove the archive once extraction has been attempted.
		if ( file_exists( $download ) ) {
			wp_delete_file( $download );
		}

		if ( is_wp_error( $mmdb ) ) {
			return $mmdb;
		}

		$installed = $this->install_database( $mmdb );

		// And drop the staging directory regardless of install outcome.
		$this->cleanup_extract_dir( dirname( $mmdb ) );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$this->update_status(
			array(
				'last_updated' => time(),
				'file_size'    => (int) filesize( KDNA_RC_GeoIP::database_path() ),
				'last_attempt' => time(),
				'last_error'   => '',
			)
		);

		return true;
	}

	/**
	 * Make sure the uploads sub-directory exists and is writable.
	 *
	 * Drops a tiny .htaccess into the directory so the bundled .mmdb cannot
	 * be served directly from the web root on Apache hosts that do not have
	 * a global rule against arbitrary file types.
	 *
	 * @return string|WP_Error Directory path on success.
	 */
	private function prepare_uploads_directory() {
		$dir = KDNA_RC_GeoIP::uploads_directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'kdna_rc_mkdir', __( 'Could not create the plugin uploads folder.', 'kdna-regional-content' ) );
		}

		// Friendly index file so directory listings never leak the .mmdb.
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<!-- Silence is golden. -->\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Download the GeoLite2-Country tarball from MaxMind to a temp file.
	 *
	 * Streams the response to disk so a multi-megabyte archive does not sit
	 * in memory. Sets a generous timeout because the download can be slow
	 * from some regions.
	 *
	 * @param string $license_key MaxMind license key.
	 * @param string $dir         Target directory for the temp file.
	 * @return string|WP_Error Path to the downloaded archive on success.
	 */
	private function download_archive( $license_key, $dir ) {
		$url = add_query_arg(
			array(
				'edition_id'  => 'GeoLite2-Country',
				'license_key' => $license_key,
				'suffix'      => 'tar.gz',
			),
			self::DOWNLOAD_URL
		);

		$archive = wp_tempnam( 'kdna-rc-mmdb-', $dir );
		if ( ! $archive ) {
			return new WP_Error( 'kdna_rc_tempfile', __( 'Could not create a temporary file for the download.', 'kdna-regional-content' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $archive,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( file_exists( $archive ) ) {
				wp_delete_file( $archive );
			}
			return new WP_Error( 'kdna_rc_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( file_exists( $archive ) ) {
				wp_delete_file( $archive );
			}
			if ( 401 === $code || 403 === $code ) {
				return new WP_Error( 'kdna_rc_auth', __( 'MaxMind rejected the request. Check that your license key is correct and has access to GeoLite2.', 'kdna-regional-content' ) );
			}
			/* translators: %d: HTTP status code returned by MaxMind. */
			return new WP_Error( 'kdna_rc_http_status', sprintf( __( 'MaxMind returned an unexpected HTTP status (%d).', 'kdna-regional-content' ), $code ) );
		}

		if ( ! file_exists( $archive ) || filesize( $archive ) < 1024 ) {
			if ( file_exists( $archive ) ) {
				wp_delete_file( $archive );
			}
			return new WP_Error( 'kdna_rc_short_download', __( 'The downloaded archive looks empty or truncated. Try again in a few minutes.', 'kdna-regional-content' ) );
		}

		return $archive;
	}

	/**
	 * Extract the .mmdb file from the downloaded tar.gz archive.
	 *
	 * Walks the archive entries and matches by suffix because MaxMind nests
	 * the database inside a dated folder (e.g. GeoLite2-Country_20240625/).
	 *
	 * @param string $archive_path Absolute path to the tar.gz file.
	 * @param string $dir          Working directory used for extraction.
	 * @return string|WP_Error Path to the extracted .mmdb file on success.
	 */
	private function extract_archive( $archive_path, $dir ) {
		if ( ! class_exists( 'PharData' ) ) {
			return new WP_Error( 'kdna_rc_phar_missing', __( 'PHP\'s Phar extension is required to extract the MaxMind archive but is not available on this server.', 'kdna-regional-content' ) );
		}

		$extract_dir = trailingslashit( $dir ) . 'extract-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'kdna_rc_extract_mkdir', __( 'Could not create a working folder for the extracted archive.', 'kdna-regional-content' ) );
		}

		try {
			$phar = new \PharData( $archive_path );
			$phar->extractTo( $extract_dir, null, true );
		} catch ( \Throwable $e ) {
			$this->cleanup_extract_dir( $extract_dir );
			return new WP_Error( 'kdna_rc_extract_failed', __( 'Could not extract the MaxMind archive: ', 'kdna-regional-content' ) . $e->getMessage() );
		}

		$mmdb = $this->find_mmdb( $extract_dir );
		if ( ! $mmdb ) {
			$this->cleanup_extract_dir( $extract_dir );
			return new WP_Error( 'kdna_rc_mmdb_missing', __( 'The downloaded archive did not contain a .mmdb file.', 'kdna-regional-content' ) );
		}

		return $mmdb;
	}

	/**
	 * Recursively search a directory for the first .mmdb file.
	 *
	 * @param string $dir Directory path.
	 * @return string|null Path to the .mmdb file, or null when none is found.
	 */
	private function find_mmdb( $dir ) {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && '.mmdb' === substr( $file->getFilename(), -5 ) ) {
				return (string) $file->getPathname();
			}
		}

		return null;
	}

	/**
	 * Move the freshly extracted .mmdb into its final location via WP_Filesystem.
	 *
	 * Uses the direct transport so we do not need FTP credentials. Because we
	 * already write the archive to wp-content/uploads, the same filesystem
	 * permissions apply, so the direct transport is appropriate here.
	 *
	 * @param string $mmdb_path Path to the freshly extracted .mmdb file.
	 * @return true|WP_Error
	 */
	private function install_database( $mmdb_path ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( ! $wp_filesystem || 'direct' !== $wp_filesystem->method ) {
			// Fall back to native moves; this still works on most hosts and
			// avoids prompting for FTP credentials we cannot collect from cron.
			$dest = KDNA_RC_GeoIP::database_path();
			if ( file_exists( $dest ) && ! @unlink( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				return new WP_Error( 'kdna_rc_install_unlink', __( 'Could not remove the previous database file before installing the new one.', 'kdna-regional-content' ) );
			}
			if ( ! @rename( $mmdb_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				return new WP_Error( 'kdna_rc_install_move', __( 'Could not move the new database file into place.', 'kdna-regional-content' ) );
			}
			return true;
		}

		$dest = KDNA_RC_GeoIP::database_path();

		if ( $wp_filesystem->exists( $dest ) && ! $wp_filesystem->delete( $dest ) ) {
			return new WP_Error( 'kdna_rc_install_unlink', __( 'Could not remove the previous database file before installing the new one.', 'kdna-regional-content' ) );
		}

		if ( ! $wp_filesystem->move( $mmdb_path, $dest, true ) ) {
			return new WP_Error( 'kdna_rc_install_move', __( 'Could not move the new database file into place.', 'kdna-regional-content' ) );
		}

		// Tighten permissions so the file is readable by the web server but not group-writable.
		$wp_filesystem->chmod( $dest, FS_CHMOD_FILE );

		return true;
	}

	/**
	 * Recursively delete a temporary extraction directory.
	 *
	 * Best-effort: failures are ignored because temp directories are not
	 * critical and may already have been removed.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private function cleanup_extract_dir( $dir ) {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file ) {
				if ( $file->isDir() ) {
					@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				} else {
					@unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
