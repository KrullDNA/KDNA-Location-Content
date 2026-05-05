<?php
/**
 * MaxMind GeoIP database wrapper.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_GeoIP
 *
 * Thin wrapper around MaxMind\Db\Reader for country lookups against the local
 * GeoLite2-Country database. Stage 2 only uses the metadata reader for the
 * status panel; full lookups arrive in Stage 4.
 */
class KDNA_RC_GeoIP {

	/**
	 * In-memory cache of country code lookups for the current request.
	 *
	 * @var array<string,string|null>
	 */
	private $lookup_cache = array();

	/**
	 * Reader instance, lazily created on first use.
	 *
	 * @var \MaxMind\Db\Reader|null
	 */
	private $reader = null;

	/**
	 * Return the absolute path to the .mmdb file in the uploads directory.
	 *
	 * The path is the same for every site and survives plugin updates because
	 * it lives outside the plugin folder.
	 *
	 * @return string
	 */
	public static function database_path() {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $base ) . 'kdna-regional-content/GeoLite2-Country.mmdb';
	}

	/**
	 * Return the absolute path to the plugin's uploads sub-directory.
	 *
	 * @return string
	 */
	public static function uploads_directory() {
		return dirname( self::database_path() );
	}

	/**
	 * Return true when a usable .mmdb file exists on disk.
	 *
	 * @return bool
	 */
	public static function database_exists() {
		$path = self::database_path();
		return is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Load and cache a Reader for the bundled .mmdb file.
	 *
	 * Returns null when no database has been downloaded yet so callers can
	 * fail soft and show a friendlier message.
	 *
	 * @return \MaxMind\Db\Reader|null
	 */
	public function reader() {
		if ( null !== $this->reader ) {
			return $this->reader;
		}

		if ( ! self::database_exists() ) {
			return null;
		}

		self::ensure_library_loaded();

		try {
			$this->reader = new \MaxMind\Db\Reader( self::database_path() );
		} catch ( \Throwable $e ) {
			$this->reader = null;
		}

		return $this->reader;
	}

	/**
	 * Look up the ISO 3166-1 alpha-2 country code for an IP address.
	 *
	 * Returns null when the database is missing, the IP is invalid, the
	 * lookup fails, or the record contains no country data. Results are
	 * cached per request so repeated lookups for the same IP are free.
	 *
	 * @param string $ip IP address.
	 * @return string|null
	 */
	public function country_code( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return null;
		}

		if ( array_key_exists( $ip, $this->lookup_cache ) ) {
			return $this->lookup_cache[ $ip ];
		}

		$result = null;
		$reader = $this->reader();

		if ( $reader && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			try {
				$record = $reader->get( $ip );
				if ( is_array( $record ) && isset( $record['country']['iso_code'] ) ) {
					$result = strtoupper( (string) $record['country']['iso_code'] );
				}
			} catch ( \Throwable $e ) {
				$result = null;
			}
		}

		$this->lookup_cache[ $ip ] = $result;
		return $result;
	}

	/**
	 * Expose the database metadata array, or null when the DB is missing.
	 *
	 * @return array|null
	 */
	public function metadata() {
		$reader = $this->reader();
		if ( ! $reader ) {
			return null;
		}

		try {
			$meta = $reader->metadata();
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'database_type' => isset( $meta->databaseType ) ? (string) $meta->databaseType : '',
			'build_epoch'   => isset( $meta->buildEpoch ) ? (int) $meta->buildEpoch : 0,
			'ip_version'    => isset( $meta->ipVersion ) ? (int) $meta->ipVersion : 0,
			'node_count'    => isset( $meta->nodeCount ) ? (int) $meta->nodeCount : 0,
			'languages'     => isset( $meta->languages ) ? (array) $meta->languages : array(),
		);
	}

	/**
	 * Close the underlying reader and clear the cache.
	 *
	 * Useful immediately after a database update so the next request reads
	 * the fresh file rather than a stale handle.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->reader ) {
			try {
				$this->reader->close();
			} catch ( \Throwable $e ) {
				// Reader already closed or never opened cleanly; nothing to do.
				unset( $e );
			}
			$this->reader = null;
		}
		$this->lookup_cache = array();
	}

	/**
	 * Ensure the bundled MaxMind autoloader has been registered exactly once.
	 *
	 * @return void
	 */
	public static function ensure_library_loaded() {
		if ( class_exists( '\MaxMind\Db\Reader' ) ) {
			return;
		}

		$autoload = KDNA_RC_PLUGIN_DIR . 'lib/maxmind-db-reader/autoload.php';
		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		}
	}
}
