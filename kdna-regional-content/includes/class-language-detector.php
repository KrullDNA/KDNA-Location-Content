<?php
/**
 * Visitor language detection, cookie management, and AJAX endpoints.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Language_Detector
 *
 * Mirrors KDNA_RC_Detector for the language layer:
 *   - kdna_language cookie holds the resolved language slug.
 *   - ?lang= URL override gated by the same Test Override Mode setting as
 *     ?region=, so admins-only by default and optionally all visitors.
 *   - Public AJAX endpoint kdna_rc_set_language that the Language Selector
 *     widget (Stage 11) calls when the visitor picks a language.
 *   - Tools-tab AJAX endpoint kdna_rc_test_language_detection that probes
 *     the chain with a fake Accept-Language header so admins can debug
 *     priority logic.
 *
 * Browser-language matching happens client-side because cached pages skip
 * PHP. The PHP layer here computes priority steps 1, 3 and 4 (override,
 * region's mapped default, configured default) and exposes the language
 * list to JS so step 2 (browser match) can run without a round trip.
 */
class KDNA_RC_Language_Detector {

	/**
	 * Cookie name carrying the resolved language slug.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'kdna_language';

	/**
	 * Public AJAX action used by the Language Selector widget to commit a choice.
	 *
	 * @var string
	 */
	const AJAX_SET_ACTION = 'kdna_rc_set_language';

	/**
	 * Admin AJAX action used by the Tools tab Test Language Detection field.
	 *
	 * @var string
	 */
	const AJAX_TEST_ACTION = 'kdna_rc_test_language_detection';

	/**
	 * Lazy languages handler.
	 *
	 * @var KDNA_RC_Languages|null
	 */
	private $languages = null;

	/**
	 * Lazy regions handler.
	 *
	 * @var KDNA_RC_Regions|null
	 */
	private $regions = null;

	/**
	 * Wire up hooks.
	 *
	 * Public AJAX is registered on every request so widget calls work for
	 * both logged-in and anonymous visitors. The override handler runs on
	 * init priority 1 so the cookie is set before any output, matching the
	 * region detector's pattern.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_SET_ACTION, array( $this, 'ajax_set_language' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_SET_ACTION, array( $this, 'ajax_set_language' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_TEST_ACTION, array( $this, 'ajax_test_detection' ) );
		}

		add_action( 'init', array( $this, 'handle_url_override' ), 1 );
	}

	/**
	 * Public AJAX endpoint: commit a language choice from the Language Selector.
	 *
	 * Validates the slug against configured languages, sets the cookie, and
	 * returns success. Used by the Language Selector widget in Stage 11.
	 *
	 * @return void
	 */
	public function ajax_set_language() {
		check_ajax_referer( 'kdna_rc_set_language', 'nonce' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'No language slug supplied.', 'kdna-regional-content' ) ), 400 );
		}

		$language = $this->languages()->get( $slug );
		if ( null === $language ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'kdna-regional-content' ) ), 400 );
		}

		$this->set_cookie( $language['slug'] );
		nocache_headers();

		wp_send_json_success(
			array(
				'slug' => $language['slug'],
				'name' => $language['name'],
				'flag' => $language['flag'],
			)
		);
	}

	/**
	 * Admin AJAX endpoint: probe the detection chain with a fake header.
	 *
	 * Returns the resolved slug and which step of the chain matched (1
	 * override, 2 browser, 3 region default, 4 configured default, none).
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

		$accept_language = isset( $_POST['accept_language'] ) ? sanitize_text_field( wp_unslash( $_POST['accept_language'] ) ) : '';
		$override        = isset( $_POST['override'] ) ? sanitize_key( wp_unslash( $_POST['override'] ) ) : '';
		$region_slug     = isset( $_POST['region'] ) ? sanitize_key( wp_unslash( $_POST['region'] ) ) : '';
		$first_visit     = ! empty( $_POST['first_visit'] );

		$result = $this->resolve_for_test( $accept_language, $override, $region_slug, $first_visit );
		wp_send_json_success( $result );
	}

	/**
	 * Run the detection chain in admin test mode without touching cookies.
	 *
	 * @param string $accept_language Simulated Accept-Language header.
	 * @param string $override        Simulated ?lang= override slug.
	 * @param string $region_slug     Simulated region for step 3.
	 * @param bool   $first_visit     When false, pretend the cookie already holds default.
	 * @return array
	 */
	public function resolve_for_test( $accept_language, $override, $region_slug, $first_visit ) {
		$languages = $this->languages()->get_all();

		// Step 1: override.
		if ( '' !== $override ) {
			$language = $this->languages()->get( $override );
			if ( $language ) {
				return $this->test_payload( $language, 'override', $languages );
			}
		}

		// Step 2: simulate browser language matching from Accept-Language.
		$browser = $this->match_accept_language( $accept_language, $languages );
		if ( $browser ) {
			return $this->test_payload( $browser, 'browser', $languages );
		}

		// Step 3: region default.
		if ( '' !== $region_slug ) {
			$region = $this->regions()->get( $region_slug );
			if ( $region && ! empty( $region['default_language'] ) ) {
				$language = $this->languages()->get( $region['default_language'] );
				if ( $language ) {
					return $this->test_payload( $language, 'region', $languages );
				}
			}
		}

		// Step 4: configured default.
		$default_slug = $this->configured_default_slug();
		if ( '' !== $default_slug ) {
			$language = $this->languages()->get( $default_slug );
			if ( $language ) {
				return $this->test_payload( $language, 'default', $languages );
			}
		}

		return array(
			'slug'   => '',
			'name'   => '',
			'flag'   => '',
			'source' => 'none',
			'steps'  => array(
				'override' => '' === $override ? 'skipped' : 'unknown slug',
				'browser'  => '' === $accept_language ? 'no header' : 'no match',
				'region'   => '' === $region_slug ? 'no region' : ( $this->regions()->get( $region_slug ) ? 'region has no default_language' : 'unknown region' ),
				'default'  => '' === $default_slug ? 'not configured' : 'unknown slug',
			),
			'first_visit' => (bool) $first_visit,
		);
	}

	/**
	 * Build the test response payload for a resolved language.
	 *
	 * @param array  $language Language row.
	 * @param string $source   Step that matched.
	 * @param array  $list     Full language list for context.
	 * @return array
	 */
	private function test_payload( array $language, $source, array $list ) {
		unset( $list );
		return array(
			'slug'   => $language['slug'],
			'name'   => $language['name'],
			'flag'   => $language['flag'],
			'source' => $source,
		);
	}

	/**
	 * Match an Accept-Language header against the configured languages.
	 *
	 * Walks the header in q-value order, normalises regional variants
	 * (en-AU → en when en-AU is not configured), and returns the first
	 * configured language that matches.
	 *
	 * @param string $header     Accept-Language header value.
	 * @param array  $languages  Configured languages.
	 * @return array|null Resolved language row, or null when nothing matches.
	 */
	public function match_accept_language( $header, array $languages ) {
		if ( '' === $header || empty( $languages ) ) {
			return null;
		}

		$by_slug      = array();
		$by_lang_only = array();
		foreach ( $languages as $language ) {
			$slug                  = strtolower( $language['slug'] );
			$by_slug[ $slug ]      = $language;
			$primary               = preg_split( '/[-_]/', $slug )[0];
			if ( ! isset( $by_lang_only[ $primary ] ) ) {
				$by_lang_only[ $primary ] = $language;
			}
		}

		// Parse "en-GB,en;q=0.9,fr;q=0.8" into ranked locales.
		$entries = array();
		foreach ( explode( ',', $header ) as $part ) {
			$bits  = explode( ';', trim( $part ) );
			$tag   = strtolower( trim( $bits[0] ) );
			$q     = 1.0;
			for ( $i = 1; $i < count( $bits ); $i++ ) {
				$kv = explode( '=', trim( $bits[ $i ] ), 2 );
				if ( 2 === count( $kv ) && 'q' === $kv[0] ) {
					$q = (float) $kv[1];
				}
			}
			if ( '' === $tag || '*' === $tag ) {
				continue;
			}
			$entries[] = array( $tag, $q );
		}
		usort(
			$entries,
			function ( $a, $b ) {
				if ( $a[1] === $b[1] ) {
					return 0;
				}
				return $a[1] < $b[1] ? 1 : -1;
			}
		);

		foreach ( $entries as $entry ) {
			$tag = $entry[0];
			if ( isset( $by_slug[ $tag ] ) ) {
				return $by_slug[ $tag ];
			}
			$primary = preg_split( '/[-_]/', $tag )[0];
			if ( isset( $by_slug[ $primary ] ) ) {
				return $by_slug[ $primary ];
			}
			if ( isset( $by_lang_only[ $primary ] ) ) {
				return $by_lang_only[ $primary ];
			}
		}

		return null;
	}

	/**
	 * Read the kdna_language cookie value if present.
	 *
	 * @return string Language slug, or empty string when no cookie is set.
	 */
	public function get_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
	}

	/**
	 * Set the kdna_language cookie.
	 *
	 * @param string $slug Language slug.
	 * @return void
	 */
	public function set_cookie( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$lifetime_days = $this->cookie_lifetime_days();
		$expires       = time() + ( $lifetime_days * DAY_IN_SECONDS );

		setcookie(
			self::COOKIE_NAME,
			$slug,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $slug;
	}

	/**
	 * Read the ?lang= URL override, applying the override-mode permission rules.
	 *
	 * @return string Language slug, or empty string when override is not in play.
	 */
	public function read_url_override() {
		if ( empty( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$mode = $this->override_mode();
		if ( 'disabled' === $mode ) {
			return '';
		}
		if ( 'admins' === $mode && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handle ?lang= on the front end before any output is sent.
	 *
	 * @return void
	 */
	public function handle_url_override() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( wp_doing_cron() ) {
			return;
		}
		$slug = $this->read_url_override();
		if ( '' === $slug ) {
			return;
		}
		$language = $this->languages()->get( $slug );
		if ( $language ) {
			$this->set_cookie( $language['slug'] );
		}
	}

	/**
	 * Configured Default Language slug, or empty string.
	 *
	 * @return string
	 */
	public function configured_default_slug() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		return is_array( $settings ) && isset( $settings['default_language'] ) ? (string) $settings['default_language'] : '';
	}

	/**
	 * Configured override mode (shared with the region detector setting).
	 *
	 * @return string
	 */
	public function override_mode() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$mode     = is_array( $settings ) && isset( $settings['test_override_mode'] ) ? (string) $settings['test_override_mode'] : 'admins';
		if ( ! in_array( $mode, KDNA_RC_Detector::OVERRIDE_MODES, true ) ) {
			$mode = 'admins';
		}
		return $mode;
	}

	/**
	 * Configured cookie lifetime, shared with the region cookie.
	 *
	 * @return int
	 */
	public function cookie_lifetime_days() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$days     = is_array( $settings ) && isset( $settings['cookie_lifetime_days'] ) ? (int) $settings['cookie_lifetime_days'] : KDNA_RC_Detector::DEFAULT_COOKIE_DAYS;
		if ( $days < 1 ) {
			$days = 1;
		}
		if ( $days > 365 ) {
			$days = 365;
		}
		return $days;
	}

	/**
	 * Lazy-load the languages handler.
	 *
	 * @return KDNA_RC_Languages
	 */
	private function languages() {
		if ( null === $this->languages ) {
			$this->languages = new KDNA_RC_Languages();
		}
		return $this->languages;
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
