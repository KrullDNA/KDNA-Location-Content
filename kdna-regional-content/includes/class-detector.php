<?php
/**
 * Visitor detection, cookie management, and AJAX endpoint.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Detector
 *
 * Resolves a visitor's region using the MaxMind country database, manages
 * the kdna_region cookie, and exposes a public AJAX endpoint plus a small
 * inline configuration object for the front-end script.
 *
 * The flow on the front end:
 *   1. wp_head prints window.kdnaRC at priority 1 (defaultRegion, ajaxUrl,
 *      nonce, override slug).
 *   2. The Stage 5 anti-flicker script (and Stage 5+ frontend.js) reads the
 *      cookie. If the cookie is missing it fires AJAX to this class's
 *      kdna_rc_detect_region action.
 *   3. The AJAX endpoint detects the IP, matches it against configured
 *      regions, sets the cookie server-side, and returns
 *      { slug, lang, dir } for the JS to act on.
 */
class KDNA_RC_Detector {

	/**
	 * Cookie name carrying the resolved region slug.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'kdna_region';

	/**
	 * Public AJAX action used by the front end.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'kdna_rc_detect_region';

	/**
	 * AJAX action used by the Tools tab for the Test Detection field.
	 *
	 * @var string
	 */
	const AJAX_TEST_ACTION = 'kdna_rc_test_detection';

	/**
	 * Default cookie lifetime in days when the admin has not configured one.
	 *
	 * @var int
	 */
	const DEFAULT_COOKIE_DAYS = 30;

	/**
	 * Allowed values for the Test Override Mode setting.
	 *
	 * @var array<int,string>
	 */
	const OVERRIDE_MODES = array( 'admins', 'all', 'disabled' );

	/**
	 * Lazy GeoIP wrapper.
	 *
	 * @var KDNA_RC_GeoIP|null
	 */
	private $geoip = null;

	/**
	 * Lazy regions handler.
	 *
	 * @var KDNA_RC_Regions|null
	 */
	private $regions = null;

	/**
	 * Wire up hooks.
	 *
	 * Public AJAX is registered on every request so cron and front-end
	 * detection both work. The override handler runs on init (very early)
	 * so the cookie can be set before any output. The inline config is
	 * printed at wp_head priority 1 so Stage 5's anti-flicker block can
	 * rely on it being defined.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_detect_region' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_detect_region' ) );

		// Test Detection (Tools tab) uses a separate, admin-only handler so
		// the public endpoint never has to deal with arbitrary IP input.
		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_TEST_ACTION, array( $this, 'ajax_test_detection' ) );
		}

		add_action( 'init', array( $this, 'handle_url_override' ), 1 );
		add_action( 'wp_head', array( $this, 'print_inline_config' ), 1 );
	}

	/**
	 * Public AJAX endpoint: detect the region for the current visitor.
	 *
	 * Returns JSON { slug, lang, dir, source } where source identifies which
	 * branch resolved the region (cookie, override, geoip, default, or none).
	 * The cookie is set server-side so subsequent requests skip the detector.
	 *
	 * @return void
	 */
	public function ajax_detect_region() {
		// Peek mode: detect the visitor's region without setting the
		// cookie. Used by KDNA_RC_Region_Banner to know the IP-derived
		// region even when the URL prefix has already forced a different
		// region cookie. Pass `peek=1` on the request.
		$peek = isset( $_REQUEST['peek'] ) && '' !== (string) $_REQUEST['peek']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public endpoint by design.

		error_log( '[KDNA RC DEBUG] ajax_detect_region entered. peek=' . ( $peek ? '1' : '0' ) . ' build=peek-fix-v2' );

		// In peek mode skip the URL-override + cookie tiers of the
		// resolution chain: we specifically want the visitor's
		// IP-derived region so the banner can detect a real geographic
		// mismatch. Without this short-circuit a returning visitor
		// whose cookie matches the URL would always resolve to that
		// cookie value and the banner could never appear.
		$result = $peek ? $this->resolve_geoip_region() : $this->resolve_visitor_region();

		if ( ! $peek && $result && ! empty( $result['slug'] ) ) {
			$this->set_cookie( $result['slug'] );
		}

		// Always allow this endpoint to be called cross-page (the JS calls it
		// from any cached page) but never let proxies cache the response.
		nocache_headers();

		wp_send_json_success(
			array(
				'slug'   => isset( $result['slug'] ) ? $result['slug'] : '',
				'lang'   => isset( $result['lang'] ) ? $result['lang'] : '',
				'dir'    => isset( $result['dir'] ) ? $result['dir'] : 'ltr',
				'source' => isset( $result['source'] ) ? $result['source'] : 'none',
				'peek'   => $peek,
			)
		);
	}

	/**
	 * Admin AJAX endpoint: probe the detector with an arbitrary IP address.
	 *
	 * Used by the Tools tab Test Detection field. Returns the country code,
	 * country name, and matched region (if any) for the supplied IP without
	 * touching the visitor cookie.
	 *
	 * @return void
	 */
	public function ajax_test_detection() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to test detection.', 'kdna-regional-content' ) ),
				403
			);
		}

		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a valid IPv4 or IPv6 address.', 'kdna-regional-content' ) ),
				400
			);
		}

		$country_code = $this->geoip()->country_code( $ip );
		$country_name = $country_code ? KDNA_RC_Regions::country_name( $country_code ) : '';
		$region       = $country_code ? $this->match_country_to_region( $country_code ) : null;

		$default_slug = $this->default_region_slug();
		$default      = $default_slug ? $this->regions()->get( $default_slug ) : null;
		$used_default = ( null === $region && null !== $default );
		if ( $used_default ) {
			$region = $default;
		}

		wp_send_json_success(
			array(
				'ip'           => $ip,
				'country_code' => $country_code ? $country_code : '',
				'country_name' => $country_name,
				'region'       => $region ? array(
					'slug'      => $region['slug'],
					'name'      => $region['name'],
					'language'  => $region['language'],
					'direction' => $region['direction'],
				) : null,
				'used_default' => $used_default,
			)
		);
	}

	/**
	 * Resolve the visiting user's region, in priority order.
	 *
	 * 1. ?region= URL override (when override mode allows it for this user).
	 * 2. Existing kdna_region cookie that maps to a configured region.
	 * 3. GeoIP lookup against the visitor IP.
	 * 4. Configured default region.
	 *
	 * Returns a minimal payload (slug, lang, dir, source) so the AJAX
	 * response can be tiny.
	 *
	 * @return array
	 */
	public function resolve_visitor_region() {
		error_log( '[KDNA RC DEBUG] resolve_visitor_region called. backtrace caller=' . wp_debug_backtrace_summary( null, 2, false ) );

		// 1. URL override.
		$override = $this->read_url_override();
		if ( $override ) {
			$region = $this->regions()->get( $override );
			if ( $region ) {
				error_log( '[KDNA RC DEBUG] resolve_visitor_region: source=override slug=' . $region['slug'] );
				return $this->payload( $region, 'override' );
			}
			error_log( '[KDNA RC DEBUG] resolve_visitor_region: override=' . $override . ' but region not configured, falling through' );
		}

		// 2. Existing cookie.
		$cookie = $this->get_cookie();
		if ( $cookie ) {
			$region = $this->regions()->get( $cookie );
			if ( $region ) {
				error_log( '[KDNA RC DEBUG] resolve_visitor_region: source=cookie slug=' . $region['slug'] );
				return $this->payload( $region, 'cookie' );
			}
			error_log( '[KDNA RC DEBUG] resolve_visitor_region: cookie=' . $cookie . ' but region not configured, falling through' );
		}

		// 3. GeoIP.
		$ip   = $this->get_visitor_ip();
		$code = $ip ? $this->geoip()->country_code( $ip ) : null;
		error_log( '[KDNA RC DEBUG] resolve_visitor_region: geoip ip=' . ( $ip ? $ip : 'none' ) . ' country=' . ( $code ? $code : 'none' ) );
		if ( $code ) {
			$region = $this->match_country_to_region( $code );
			if ( $region ) {
				error_log( '[KDNA RC DEBUG] resolve_visitor_region: source=geoip slug=' . $region['slug'] );
				return $this->payload( $region, 'geoip' );
			}
		}

		// 4. Default region.
		$default_slug = $this->default_region_slug();
		if ( $default_slug ) {
			$region = $this->regions()->get( $default_slug );
			if ( $region ) {
				error_log( '[KDNA RC DEBUG] resolve_visitor_region: source=default slug=' . $region['slug'] );
				return $this->payload( $region, 'default' );
			}
		}

		error_log( '[KDNA RC DEBUG] resolve_visitor_region: source=none' );
		return array(
			'slug'   => '',
			'lang'   => '',
			'dir'    => 'ltr',
			'source' => 'none',
		);
	}

	/**
	 * Resolve the visitor's region using only their IP address, ignoring
	 * any existing kdna_region cookie or ?region= URL override.
	 *
	 * The region-switch banner needs the IP-derived region so it can spot
	 * a geographic mismatch (visitor in AU but viewing /nz/ content).
	 * resolve_visitor_region() can't answer that question because the
	 * cookie tier short-circuits before geoip on returning visitors. Falls
	 * back to the configured default region when geoip yields no match;
	 * returns the empty payload only when there is no signal at all.
	 *
	 * @return array
	 */
	public function resolve_geoip_region() {
		$ip   = $this->get_visitor_ip();
		$code = $ip ? $this->geoip()->country_code( $ip ) : null;
		error_log( '[KDNA RC DEBUG] resolve_geoip_region called. ip=' . ( $ip ? $ip : 'none' ) . ' country=' . ( $code ? $code : 'none' ) );
		if ( $code ) {
			$region = $this->match_country_to_region( $code );
			if ( $region ) {
				error_log( '[KDNA RC DEBUG] resolve_geoip_region: source=geoip slug=' . $region['slug'] );
				return $this->payload( $region, 'geoip' );
			}
		}

		$default_slug = $this->default_region_slug();
		if ( $default_slug ) {
			$region = $this->regions()->get( $default_slug );
			if ( $region ) {
				return $this->payload( $region, 'default' );
			}
		}

		return array(
			'slug'   => '',
			'lang'   => '',
			'dir'    => 'ltr',
			'source' => 'none',
		);
	}

	/**
	 * Build the response payload for a resolved region.
	 *
	 * @param array  $region Region row.
	 * @param string $source Resolution source label.
	 * @return array
	 */
	private function payload( array $region, $source ) {
		return array(
			'slug'   => $region['slug'],
			'lang'   => $region['language'],
			'dir'    => $region['direction'],
			'source' => $source,
		);
	}

	/**
	 * Determine the visitor's IP address using the configured priority list.
	 *
	 * Honours the Trust Proxy Headers setting: when proxy headers are not
	 * trusted, only REMOTE_ADDR is read so attackers cannot spoof their
	 * country with a forged X-Forwarded-For header.
	 *
	 * @return string Visitor IP address, or an empty string when nothing valid was found.
	 */
	public function get_visitor_ip() {
		$trust_proxy = $this->get_setting( 'trust_proxy_headers', true );

		if ( $trust_proxy ) {
			$candidates = array(
				'HTTP_CF_CONNECTING_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
				'REMOTE_ADDR',
			);
		} else {
			$candidates = array( 'REMOTE_ADDR' );
		}

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// X-Forwarded-For is a comma-separated list with the original
			// client first. Split off the first entry and validate it.
			if ( false !== strpos( $value, ',' ) ) {
				$parts = array_map( 'trim', explode( ',', $value ) );
				$value = $parts[0];
			}
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Match a country ISO alpha-2 code to the first region that contains it.
	 *
	 * Regions are scanned in display order so the editor's ordering (which
	 * is preserved in the kdna_rc_regions option) controls priority.
	 *
	 * @param string $iso_code Alpha-2 country code.
	 * @return array|null Region row, or null when no region claims this country.
	 */
	public function match_country_to_region( $iso_code ) {
		$iso_code = strtoupper( (string) $iso_code );
		if ( '' === $iso_code ) {
			return null;
		}

		foreach ( $this->regions()->get_all() as $region ) {
			if ( in_array( $iso_code, $region['countries'], true ) ) {
				return $region;
			}
		}
		return null;
	}

	/**
	 * Read the kdna_region cookie value if present.
	 *
	 * @return string Region slug, or empty string when no cookie is set.
	 */
	public function get_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}
		$raw = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		return $raw;
	}

	/**
	 * Set the kdna_region cookie.
	 *
	 * Uses the array form of setcookie() so we can apply SameSite=Lax. Skips
	 * the call entirely when headers have already been sent (e.g. when the
	 * AJAX handler is invoked after some plugin echoed unexpected output).
	 *
	 * @param string $slug Region slug to store.
	 * @return void
	 */
	public function set_cookie( $slug ) {
		$slug = sanitize_key( (string) $slug );
		error_log( '[KDNA RC DEBUG] set_cookie called with slug=' . $slug . ' headers_sent=' . ( headers_sent() ? 'yes' : 'no' ) );
		if ( '' === $slug ) {
			return;
		}

		if ( headers_sent() ) {
			error_log( '[KDNA RC DEBUG] set_cookie: headers already sent, cannot write cookie!' );
			return;
		}

		$lifetime_days = $this->get_cookie_lifetime_days();
		$expires       = time() + ( $lifetime_days * DAY_IN_SECONDS );

		setcookie(
			self::COOKIE_NAME,
			$slug,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false, // JS reads this cookie to drive the variant swap.
				'samesite' => 'Lax',
			)
		);

		// Update the in-request cookie too so anything else reading $_COOKIE
		// during this same request sees the fresh value.
		$_COOKIE[ self::COOKIE_NAME ] = $slug;
	}

	/**
	 * Clear the kdna_region cookie.
	 *
	 * @return void
	 */
	public function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	/**
	 * Read the ?region= URL override, applying the override-mode permission rules.
	 *
	 * Returns the slug only when (a) the parameter is present, (b) the
	 * override mode allows the current user to use it, and (c) the slug
	 * passes basic sanitisation. The slug is not validated against the
	 * regions list here so callers can decide what to do with an unknown
	 * value.
	 *
	 * @return string Region slug, or empty string when override is not in play.
	 */
	public function read_url_override() {
		if ( empty( $_GET['region'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			error_log( '[KDNA RC DEBUG] read_url_override: no $_GET[region]' );
			return '';
		}

		$raw_value = isset( $_GET['region'] ) ? (string) wp_unslash( $_GET['region'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode      = $this->get_override_mode();
		$is_admin  = current_user_can( 'manage_options' );
		error_log( sprintf( '[KDNA RC DEBUG] read_url_override: $_GET[region]=%s mode=%s manage_options=%s user_id=%d', $raw_value, $mode, $is_admin ? 'yes' : 'no', get_current_user_id() ) );

		if ( 'disabled' === $mode ) {
			error_log( '[KDNA RC DEBUG] read_url_override: mode=disabled, returning empty' );
			return '';
		}
		if ( 'admins' === $mode && ! $is_admin ) {
			error_log( '[KDNA RC DEBUG] read_url_override: mode=admins but user lacks manage_options, returning empty' );
			return '';
		}

		$sanitized = sanitize_key( wp_unslash( $_GET['region'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		error_log( '[KDNA RC DEBUG] read_url_override: returning slug=' . $sanitized );
		return $sanitized;
	}

	/**
	 * Handle ?region=XX on the front end before any output is sent.
	 *
	 * When the override resolves to a real region we set the cookie so the
	 * choice persists across pages without needing the parameter every time.
	 * The URL itself is left untouched so deep links continue to work.
	 *
	 * @return void
	 */
	public function handle_url_override() {
		error_log( sprintf( '[KDNA RC DEBUG] handle_url_override fired: request_uri=%s is_admin=%s doing_ajax=%s doing_cron=%s', isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', is_admin() ? 'yes' : 'no', wp_doing_ajax() ? 'yes' : 'no', wp_doing_cron() ? 'yes' : 'no' ) );

		if ( is_admin() && ! wp_doing_ajax() ) {
			error_log( '[KDNA RC DEBUG] handle_url_override: bailing on admin request' );
			return;
		}
		if ( wp_doing_cron() ) {
			error_log( '[KDNA RC DEBUG] handle_url_override: bailing on cron' );
			return;
		}
		$slug = $this->read_url_override();
		if ( '' === $slug ) {
			error_log( '[KDNA RC DEBUG] handle_url_override: read_url_override returned empty, not setting cookie' );
			return;
		}
		$region = $this->regions()->get( $slug );
		if ( $region ) {
			error_log( '[KDNA RC DEBUG] handle_url_override: matched region, calling set_cookie(' . $region['slug'] . ')' );
			$this->set_cookie( $region['slug'] );
		} else {
			error_log( '[KDNA RC DEBUG] handle_url_override: slug=' . $slug . ' is not a configured region, not setting cookie' );
		}
	}

	/**
	 * Print the inline configuration object inside <head>.
	 *
	 * Lives at wp_head priority 1 so it is defined before any other plugin
	 * scripts (including the Stage 5 anti-flicker block) attempt to read it.
	 * Skipped on admin pages so the JSON object never leaks to wp-admin.
	 *
	 * @return void
	 */
	public function print_inline_config() {
		if ( is_admin() ) {
			return;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );
		if ( '/' !== $home_path ) {
			$home_path .= '/';
		}

		$payload = array(
			'defaultRegion' => $this->default_region_slug(),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'cookieName'    => self::COOKIE_NAME,
			'detectAction'  => self::AJAX_ACTION,
			'nonce'         => wp_create_nonce( 'kdna_rc_detect' ),
			'homePath'      => $home_path,
		);

		// Stage 10: Language layer. Bundled additively so existing keys are
		// untouched. The Language Selector widget (Stage 11) and the
		// frontend.js detection chain both read from these.
		if ( class_exists( 'KDNA_RC_Languages' ) && class_exists( 'KDNA_RC_Language_Detector' ) ) {
			$languages_handler = new KDNA_RC_Languages();
			$languages_list    = array();
			foreach ( $languages_handler->get_all() as $language ) {
				$languages_list[] = array(
					'slug' => $language['slug'],
					'name' => $language['name'],
					'flag' => $language['flag'],
				);
			}

			$current_language = '';
			if ( ! empty( $_COOKIE[ KDNA_RC_Language_Detector::COOKIE_NAME ] ) ) {
				$current_language = sanitize_key( wp_unslash( $_COOKIE[ KDNA_RC_Language_Detector::COOKIE_NAME ] ) );
			}

			$settings        = get_option( KDNA_RC_OPTION_SETTINGS, array() );
			$default_lang    = is_array( $settings ) && isset( $settings['default_language'] ) ? (string) $settings['default_language'] : '';
			$region_to_lang  = array();
			foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
				if ( ! empty( $region['default_language'] ) ) {
					$region_to_lang[ $region['slug'] ] = $region['default_language'];
				}
			}

			$payload['defaultLanguage']   = $default_lang;
			$payload['currentLanguage']   = $current_language;
			$payload['languages']         = $languages_list;
			$payload['languageCookie']    = KDNA_RC_Language_Detector::COOKIE_NAME;
			$payload['setLanguageAction'] = KDNA_RC_Language_Detector::AJAX_SET_ACTION;
			$payload['setLanguageNonce']  = wp_create_nonce( 'kdna_rc_set_language' );
			$payload['regionLanguageMap'] = $region_to_lang;
		}

		// Expose configured regions (used by the region-mismatch banner
		// in v0.2.1). Always emitted, even on language-less sites,
		// because the banner depends on regions rather than languages.
		$regions_list = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			$regions_list[] = array(
				'slug' => $region['slug'],
				'name' => $region['name'],
			);
		}
		$payload['regions']      = $regions_list;
		$payload['regionCookie'] = self::COOKIE_NAME;

		echo "<script id=\"kdna-rc-config\">window.kdnaRC = " . wp_json_encode( $payload ) . ";</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding is the appropriate escape here.
	}

	/**
	 * Get the configured default region slug, or empty string when unset.
	 *
	 * @return string
	 */
	public function default_region_slug() {
		return (string) $this->get_setting( 'default_region', '' );
	}

	/**
	 * Get the configured Test Override Mode value.
	 *
	 * @return string One of 'admins', 'all', 'disabled'.
	 */
	public function get_override_mode() {
		$mode = (string) $this->get_setting( 'test_override_mode', 'admins' );
		if ( ! in_array( $mode, self::OVERRIDE_MODES, true ) ) {
			$mode = 'admins';
		}
		return $mode;
	}

	/**
	 * Get the cookie lifetime in days, clamped to a sensible range.
	 *
	 * @return int
	 */
	public function get_cookie_lifetime_days() {
		$days = (int) $this->get_setting( 'cookie_lifetime_days', self::DEFAULT_COOKIE_DAYS );
		if ( $days < 1 ) {
			$days = 1;
		}
		if ( $days > 365 ) {
			$days = 365;
		}
		return $days;
	}

	/**
	 * Read a single key from the kdna_rc_settings option, with a default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Default to return when missing.
	 * @return mixed
	 */
	private function get_setting( $key, $fallback ) {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $fallback;
		}
		return $settings[ $key ];
	}

	/**
	 * Lazy-load the GeoIP wrapper.
	 *
	 * @return KDNA_RC_GeoIP
	 */
	private function geoip() {
		if ( null === $this->geoip ) {
			$this->geoip = new KDNA_RC_GeoIP();
		}
		return $this->geoip;
	}

	/**
	 * Lazy-load the regions handler.
	 *
	 * @return KDNA_RC_Regions
	 */
	private function regions() {
		if ( null === $this->regions ) {
			$this->regions = new KDNA_RC_Regions();
		}
		return $this->regions;
	}
}
