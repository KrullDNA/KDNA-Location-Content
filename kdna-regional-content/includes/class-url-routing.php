<?php
/**
 * Per-region and per-language URL routing.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_URL_Routing
 *
 * Lets a single WordPress post be reachable at multiple URLs:
 *   /about-us/                    bare URL (cookie-driven swap, today's behaviour)
 *   /au/about-us/                 region prefix forces region cookie + variant swap
 *   /fr/about-us/                 language prefix forces language cookie + variant swap
 *   /au/fr/about-us/              combined prefix forces both
 *
 * Implementation note: rather than registering N add_rewrite_rule() entries
 * per permalink structure (single post, page, CPT archive, taxonomy term,
 * paginated archive, custom permalink structure), this class strips the
 * KDNA prefix from REQUEST_URI before WordPress parses the request. WP
 * then runs its normal routing against the cleaned URL, so every existing
 * permalink context (pages, posts, CPTs, taxonomies, paginated archives,
 * search, feeds) works without per-context rules. The detected slugs are
 * forwarded into the request as query vars so the rest of the plugin's
 * existing detection and variant pipelines pick them up unchanged.
 *
 * The query vars (kdna_region and kdna_language) are still registered via
 * the public_query_vars filter so anything downstream that inspects
 * $wp->query_vars sees them.
 *
 * Slug snapshots: the configured region + language slugs are written to
 * a wp_options row. flush_rules_if_needed() compares the live slug list
 * against that snapshot and only calls flush_rewrite_rules() when the
 * configuration has actually changed, so admins do not pay the cost of a
 * flush on every Settings save.
 */
class KDNA_RC_URL_Routing {

	/**
	 * Option key holding the last-known slug snapshot.
	 *
	 * @var string
	 */
	const SNAPSHOT_OPTION = 'kdna_rc_url_routing_snapshot';

	/**
	 * AJAX action backing the Tools-tab manual flush button.
	 *
	 * @var string
	 */
	const AJAX_FLUSH = 'kdna_rc_flush_rewrite_rules';

	/**
	 * Detected region slug for the current request, populated by strip_prefix().
	 *
	 * @var string
	 */
	private $detected_region = '';

	/**
	 * Detected language slug for the current request, populated by strip_prefix().
	 *
	 * @var string
	 */
	private $detected_language = '';

	/**
	 * Whether prefix stripping touched REQUEST_URI on this request.
	 *
	 * @var bool
	 */
	private $prefix_was_present = false;

	/**
	 * Wire up hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// do_parse_request runs at the very top of WP::parse_request(); we
		// rewrite REQUEST_URI in time for WP's own URL-to-permalink resolver.
		add_filter( 'do_parse_request', array( $this, 'strip_prefix' ), 10, 3 );

		// parse_request runs after WP has parsed query vars from rewrite
		// rules. We inject our query vars there.
		add_action( 'parse_request', array( $this, 'inject_query_vars' ) );

		// On the 'wp' action the main query has run; we now know whether
		// the request resolved to anything (404 or otherwise) and can set
		// cookies / decide on optional redirects.
		add_action( 'wp', array( $this, 'after_request_resolved' ) );

		// Flush after region / language CRUD updates. Idempotent.
		add_action( 'update_option_kdna_rc_regions', array( $this, 'flush_rules_if_needed' ), 20 );
		add_action( 'add_option_kdna_rc_regions', array( $this, 'flush_rules_if_needed' ), 20 );
		add_action( 'update_option_kdna_rc_languages', array( $this, 'flush_rules_if_needed' ), 20 );
		add_action( 'add_option_kdna_rc_languages', array( $this, 'flush_rules_if_needed' ), 20 );

		// Admin AJAX: manual flush button on the Tools tab.
		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_FLUSH, array( $this, 'ajax_flush' ) );

			// URL-preview meta box on eligible post-edit screens.
			add_action( 'add_meta_boxes', array( $this, 'register_url_preview_meta_box' ) );
		}
	}

	/**
	 * Register kdna_region and kdna_language as recognised query vars.
	 *
	 * @param array $vars Existing public query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			$vars = array();
		}
		if ( ! in_array( 'kdna_region', $vars, true ) ) {
			$vars[] = 'kdna_region';
		}
		if ( ! in_array( 'kdna_language', $vars, true ) ) {
			$vars[] = 'kdna_language';
		}
		return $vars;
	}

	/**
	 * Get the configured region slugs, lowercased.
	 *
	 * @return array<int,string>
	 */
	public function region_slugs() {
		if ( ! class_exists( 'KDNA_RC_Regions' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			$slug = isset( $region['slug'] ) ? strtolower( (string) $region['slug'] ) : '';
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Get the configured language slugs, lowercased.
	 *
	 * @return array<int,string>
	 */
	public function language_slugs() {
		if ( ! class_exists( 'KDNA_RC_Languages' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
			$slug = isset( $language['slug'] ) ? strtolower( (string) $language['slug'] ) : '';
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Strip the KDNA prefix from REQUEST_URI before WordPress parses it.
	 *
	 * Order of attempts: combined prefix first (region/language), then
	 * single-region, then single-language. The first successful match
	 * stops further attempts so /au/fr/about/ resolves as combined and
	 * not as region=au with the rest "fr/about" leaking through.
	 *
	 * Validation: only strips a prefix when its slug is in the live
	 * configuration. Stale slugs (e.g. URLs to a removed region) fall
	 * through with REQUEST_URI untouched and WordPress 404s as normal.
	 *
	 * @param bool $do_parse Whether to continue parsing (we always say yes).
	 * @param mixed $wp     WP instance.
	 * @param mixed $extra  Extra query vars.
	 * @return bool
	 */
	public function strip_prefix( $do_parse, $wp = null, $extra = null ) {
		unset( $wp, $extra );

		if ( is_admin() ) {
			return $do_parse;
		}

		$regions   = $this->region_slugs();
		$languages = $this->language_slugs();
		if ( empty( $regions ) && empty( $languages ) ) {
			return $do_parse;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query       = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		// Strip the home_url path prefix if WordPress is installed in a
		// sub-directory.
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = trim( $home_path, '/' );

		$relative = ltrim( $path, '/' );
		if ( '' !== $home_path && 0 === strpos( $relative, $home_path . '/' ) ) {
			$relative = substr( $relative, strlen( $home_path ) + 1 );
		} elseif ( '' !== $home_path && $relative === $home_path ) {
			$relative = '';
		}

		$detected_region   = '';
		$detected_language = '';
		$remainder         = $relative;

		// Combined: region/language/...
		if ( ! empty( $regions ) && ! empty( $languages ) ) {
			$pattern = '#^(' . $this->slug_alternation( $regions ) . ')/(' . $this->slug_alternation( $languages ) . ')(?:/(.*))?$#i';
			if ( preg_match( $pattern, $relative, $m ) ) {
				$detected_region   = strtolower( $m[1] );
				$detected_language = strtolower( $m[2] );
				$remainder         = isset( $m[3] ) ? $m[3] : '';
			}
		}

		// Single region.
		if ( '' === $detected_region && '' === $detected_language && ! empty( $regions ) ) {
			$pattern = '#^(' . $this->slug_alternation( $regions ) . ')(?:/(.*))?$#i';
			if ( preg_match( $pattern, $relative, $m ) ) {
				$detected_region = strtolower( $m[1] );
				$remainder       = isset( $m[2] ) ? $m[2] : '';
			}
		}

		// Single language.
		if ( '' === $detected_region && '' === $detected_language && ! empty( $languages ) ) {
			$pattern = '#^(' . $this->slug_alternation( $languages ) . ')(?:/(.*))?$#i';
			if ( preg_match( $pattern, $relative, $m ) ) {
				$detected_language = strtolower( $m[1] );
				$remainder         = isset( $m[2] ) ? $m[2] : '';
			}
		}

		if ( '' === $detected_region && '' === $detected_language ) {
			return $do_parse;
		}

		// Rebuild REQUEST_URI without the KDNA prefix.
		$new_path = '/';
		if ( '' !== $home_path ) {
			$new_path .= $home_path . '/';
		}
		$new_path .= ltrim( $remainder, '/' );

		// Preserve a trailing slash if the original path had one (so
		// pretty-permalink contexts that depend on trailing slashes keep
		// resolving).
		if ( substr( $path, -1 ) === '/' && substr( $new_path, -1 ) !== '/' ) {
			$new_path .= '/';
		}

		if ( '' !== $query ) {
			$new_path .= '?' . $query;
		}

		$_SERVER['REQUEST_URI']        = $new_path;
		$this->detected_region         = $detected_region;
		$this->detected_language       = $detected_language;
		$this->prefix_was_present      = true;

		return $do_parse;
	}

	/**
	 * Inject the detected slugs into WP::query_vars so the rest of the
	 * plugin and site sees them as if they had come from a real rewrite
	 * rule capture.
	 *
	 * @param mixed $wp WP instance.
	 * @return void
	 */
	public function inject_query_vars( $wp ) {
		if ( ! $this->prefix_was_present || ! is_object( $wp ) ) {
			return;
		}
		if ( '' !== $this->detected_region ) {
			$wp->query_vars['kdna_region'] = $this->detected_region;
		}
		if ( '' !== $this->detected_language ) {
			$wp->query_vars['kdna_language'] = $this->detected_language;
		}
	}

	/**
	 * Build a regex alternation from a slug list with each slug quoted.
	 *
	 * @param array<int,string> $slugs Slug list.
	 * @return string
	 */
	private function slug_alternation( array $slugs ) {
		$slugs = array_map(
			function ( $s ) {
				return preg_quote( (string) $s, '#' );
			},
			$slugs
		);
		// Sort longest-first so multi-character slugs are tried before
		// shorter ones that share a prefix (e.g. zh-hant before zh).
		usort(
			$slugs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return implode( '|', $slugs );
	}

	/**
	 * Runs on the wp action: set cookies, 404 invalid prefixes, and apply
	 * any optional bare-URL redirects.
	 *
	 * @param mixed $wp WP instance (unused).
	 * @return void
	 */
	public function after_request_resolved( $wp = null ) {
		unset( $wp );

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// 1. URL prefix forced cookies.
		if ( $this->prefix_was_present ) {
			$this->apply_url_overrides_to_cookies();
			return; // Bare-URL redirect logic does not apply on already-prefixed URLs.
		}

		// 2. Optional bare-URL redirects (only for human visitors, never crawlers).
		$this->maybe_redirect_bare_url();
	}

	/**
	 * Set kdna_region / kdna_language cookies from the URL-derived slugs
	 * with full validation. Invalid slugs trigger a 404.
	 *
	 * Cookies are also written into $_COOKIE so the existing detector
	 * (Stage 4 / Stage 10) and the wp_head config printer pick the
	 * URL-derived values up immediately on this same request.
	 *
	 * @return void
	 */
	private function apply_url_overrides_to_cookies() {
		$valid = true;

		if ( '' !== $this->detected_region ) {
			$region = ( new KDNA_RC_Regions() )->get( $this->detected_region );
			if ( null === $region ) {
				$valid = false;
			} elseif ( class_exists( 'KDNA_RC_Detector' ) ) {
				( new KDNA_RC_Detector() )->set_cookie( $this->detected_region );
			}
		}

		if ( $valid && '' !== $this->detected_language ) {
			$language = ( new KDNA_RC_Languages() )->get( $this->detected_language );
			if ( null === $language ) {
				$valid = false;
			} elseif ( class_exists( 'KDNA_RC_Language_Detector' ) ) {
				( new KDNA_RC_Language_Detector() )->set_cookie( $this->detected_language );
			}
		}

		if ( ! $valid ) {
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Optional 301 redirect from the bare URL to the visitor's detected
	 * region / language URL. Off by default. Skipped for crawlers so
	 * search engines index whatever URL they were sent to.
	 *
	 * @return void
	 */
	private function maybe_redirect_bare_url() {
		$settings    = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$redir_region = ! empty( $settings['redirect_bare_to_region'] );
		$redir_lang   = ! empty( $settings['redirect_bare_to_language'] );

		if ( ! $redir_region && ! $redir_lang ) {
			return;
		}

		// Never redirect crawlers; we want them to index every URL.
		if ( $this->request_is_crawler() ) {
			return;
		}

		// Read the visitor's already-detected region / language. If the
		// detector hasn't run yet (no cookie), bail. We only redirect for
		// returning visitors who already have a cookie set; first-time
		// visitors fall through and the existing JS detection chain runs
		// against the bare URL.
		$region_cookie   = isset( $_COOKIE['kdna_region'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_region'] ) ) : '';
		$language_cookie = isset( $_COOKIE['kdna_language'] ) ? sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) ) : '';

		$prefix_parts = array();
		if ( $redir_region && '' !== $region_cookie && $this->slug_is_configured_region( $region_cookie ) ) {
			$prefix_parts[] = $region_cookie;
		}
		if ( $redir_lang && '' !== $language_cookie && $this->slug_is_configured_language( $language_cookie ) ) {
			$prefix_parts[] = $language_cookie;
		}

		if ( empty( $prefix_parts ) ) {
			return;
		}

		// Only redirect on GET requests that resolved to something
		// indexable; never on 404, search, feed, or POST.
		if ( ! is_singular() && ! is_home() && ! is_front_page() && ! is_archive() && ! is_page() ) {
			return;
		}
		if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		$current_url = home_url( add_query_arg( null, null ) );
		$prefix      = '/' . implode( '/', $prefix_parts ) . '/';
		$home_path   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path   = '/' . trim( $home_path, '/' );
		if ( '/' !== $home_path ) {
			$home_path .= '/';
		}

		$path  = (string) wp_parse_url( $current_url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $current_url, PHP_URL_QUERY );

		// Defensive against infinite loops: bail if the URL already
		// starts with the prefix we'd add.
		if ( 0 === strpos( $path, $home_path . trim( $prefix, '/' ) . '/' ) ) {
			return;
		}

		$rest = ltrim( substr( $path, strlen( $home_path ) ), '/' );
		$dest = $home_path . trim( $prefix, '/' ) . ( '' !== $rest ? '/' . $rest : '/' );
		if ( '' !== $query ) {
			$dest .= '?' . $query;
		}

		// Final guard: do not redirect if the destination matches the
		// current URL exactly.
		if ( $dest === $path . ( '' !== $query ? '?' . $query : '' ) ) {
			return;
		}

		wp_safe_redirect( $dest, 301 );
		exit;
	}

	/**
	 * Detect crawler user agents we should not redirect.
	 *
	 * Uses WordPress's wp_is_mobile-style approach: pattern match against
	 * a known list. The list is the common set; admins can extend via
	 * the `kdna_rc_crawler_agents` filter.
	 *
	 * @return bool
	 */
	private function request_is_crawler() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return true; // No UA: treat as bot.
		}

		$bots = apply_filters(
			'kdna_rc_crawler_agents',
			array(
				'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider',
				'applebot', 'facebookexternalhit', 'twitterbot', 'slackbot',
				'linkedinbot', 'pingdom', 'uptimerobot', 'ahrefsbot', 'semrushbot',
				'mj12bot', 'gptbot', 'oai-searchbot', 'chatgpt-user', 'perplexitybot',
				'claudebot', 'anthropic-ai',
			)
		);
		foreach ( $bots as $needle ) {
			if ( false !== strpos( $ua, strtolower( (string) $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a slug is currently a configured region.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private function slug_is_configured_region( $slug ) {
		return null !== ( new KDNA_RC_Regions() )->get( $slug );
	}

	/**
	 * Whether a slug is currently a configured language.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private function slug_is_configured_language( $slug ) {
		return null !== ( new KDNA_RC_Languages() )->get( $slug );
	}

	/**
	 * Compare the live slug list against the stored snapshot and call
	 * flush_rewrite_rules() only when slugs have changed.
	 *
	 * Idempotent and cheap to call from any CRUD handler.
	 *
	 * @return void
	 */
	public function flush_rules_if_needed() {
		$current = array(
			'regions'   => $this->region_slugs(),
			'languages' => $this->language_slugs(),
		);
		sort( $current['regions'] );
		sort( $current['languages'] );

		$stored = get_option( self::SNAPSHOT_OPTION, null );
		if ( is_array( $stored )
			&& isset( $stored['regions'], $stored['languages'] )
			&& $stored['regions'] === $current['regions']
			&& $stored['languages'] === $current['languages']
		) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::SNAPSHOT_OPTION, $current, false );
	}

	/**
	 * AJAX handler: manual flush from the Tools-tab button.
	 *
	 * @return void
	 */
	public function ajax_flush() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to flush rewrite rules.', 'kdna-regional-content' ) ), 403 );
		}
		// Force a flush even if the snapshot matches.
		delete_option( self::SNAPSHOT_OPTION );
		flush_rewrite_rules( false );
		$this->flush_rules_if_needed();
		wp_send_json_success( array( 'message' => __( 'Rewrite rules flushed.', 'kdna-regional-content' ) ) );
	}

	/**
	 * Build a list of every URL variant for a single post.
	 *
	 * Returns an array of associative entries:
	 *   array( 'label' => 'AU + FR', 'url' => '...', 'region' => 'au', 'language' => 'fr' )
	 *
	 * Includes the bare URL plus every region, every language, and every
	 * region+language combination.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array>
	 */
	public function build_post_url_variants( $post_id ) {
		$post_id = (int) $post_id;
		$bare    = (string) get_permalink( $post_id );
		if ( '' === $bare ) {
			return array();
		}

		$home   = home_url( '/' );
		$path   = (string) wp_parse_url( $bare, PHP_URL_PATH );
		$query  = (string) wp_parse_url( $bare, PHP_URL_QUERY );
		$home_path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );
		if ( '/' !== $home_path ) {
			$home_path .= '/';
		}

		// Strip home path so we can re-prefix per variant.
		$rest = ltrim( substr( $path, strlen( $home_path ) ), '/' );

		$build = function ( $prefix_parts ) use ( $home, $rest, $query ) {
			$prefix = '' !== $prefix_parts ? trim( $prefix_parts, '/' ) . '/' : '';
			$url    = trailingslashit( $home ) . $prefix . $rest;
			if ( '' !== $query ) {
				$url .= '?' . $query;
			}
			return $url;
		};

		$variants = array();
		$variants[] = array(
			'label'    => __( 'Bare', 'kdna-regional-content' ),
			'url'      => $bare,
			'region'   => '',
			'language' => '',
		);

		$regions   = $this->region_slugs();
		$languages = $this->language_slugs();

		foreach ( $regions as $region ) {
			$variants[] = array(
				'label'    => strtoupper( $region ),
				'url'      => $build( $region ),
				'region'   => $region,
				'language' => '',
			);
		}
		foreach ( $languages as $language ) {
			$variants[] = array(
				'label'    => strtoupper( $language ),
				'url'      => $build( $language ),
				'region'   => '',
				'language' => $language,
			);
		}
		foreach ( $regions as $region ) {
			foreach ( $languages as $language ) {
				$variants[] = array(
					'label'    => strtoupper( $region ) . ' + ' . strtoupper( $language ),
					'url'      => $build( $region . '/' . $language ),
					'region'   => $region,
					'language' => $language,
				);
			}
		}

		return $variants;
	}

	/**
	 * Estimate how many cache files this configuration could produce per post.
	 *
	 * Used by the Tools-tab capacity-planning notice.
	 *
	 * @return int
	 */
	public function estimate_variants_per_post() {
		$r = max( 1, count( $this->region_slugs() ) + 1 ); // +1 for bare
		$l = max( 1, count( $this->language_slugs() ) + 1 );
		// bare + region-only + language-only + combined approximated by r * l.
		return $r * $l;
	}

	/**
	 * Register the URL preview meta box on every eligible post type.
	 *
	 * Eligibility is the General-tab "Post types eligible for regional /
	 * language URLs" setting. Empty / unset means every public post type.
	 *
	 * @return void
	 */
	public function register_url_preview_meta_box() {
		$post_types = $this->eligible_post_types();
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'kdna_rc_url_preview',
				__( 'Regional & Language URLs', 'kdna-regional-content' ),
				array( $this, 'render_url_preview_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * The post types that should expose URL preview UI.
	 *
	 * @return array<int,string>
	 */
	public function eligible_post_types() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$set      = isset( $settings['url_routing_post_types'] ) ? (array) $settings['url_routing_post_types'] : array();
		$clean    = array();
		foreach ( $set as $slug ) {
			$slug = sanitize_key( $slug );
			if ( $slug && post_type_exists( $slug ) ) {
				$clean[] = $slug;
			}
		}
		if ( ! empty( $clean ) ) {
			return array_values( array_unique( $clean ) );
		}
		// Default: every public post type.
		return array_keys( get_post_types( array( 'public' => true ), 'names' ) );
	}

	/**
	 * Render the per-post URL preview meta box.
	 *
	 * Shows every URL variant for the post, each with a Copy button and a
	 * tooltip describing what visitors arriving at that URL see. Includes
	 * a "Test as visitor from" dropdown that opens the chosen URL in a
	 * new tab.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_url_preview_meta_box( $post ) {
		$variants = $this->build_post_url_variants( $post->ID );
		if ( empty( $variants ) ) {
			echo '<p>' . esc_html__( 'Save the post to generate a URL list.', 'kdna-regional-content' ) . '</p>';
			return;
		}

		$regions_handler   = new KDNA_RC_Regions();
		$languages_handler = new KDNA_RC_Languages();

		echo '<ul class="kdna-rc-url-list">';
		foreach ( $variants as $variant ) {
			$tooltip = '';
			$labels  = array();
			if ( '' !== $variant['region'] ) {
				$reg = $regions_handler->get( $variant['region'] );
				if ( $reg ) {
					$labels[] = $reg['name'];
				}
			}
			if ( '' !== $variant['language'] ) {
				$lang = $languages_handler->get( $variant['language'] );
				if ( $lang ) {
					$labels[] = $lang['name'];
				}
			}
			if ( ! empty( $labels ) ) {
				$tooltip = sprintf(
					/* translators: %s: comma-separated region/language names. */
					__( 'Visitors arriving here see %s variant content.', 'kdna-regional-content' ),
					implode( ' + ', $labels )
				);
			} else {
				$tooltip = __( 'Bare URL: visitor sees variant content based on their detected region and language.', 'kdna-regional-content' );
			}
			?>
			<li class="kdna-rc-url-row">
				<span class="kdna-rc-url-label"><?php echo esc_html( $variant['label'] ); ?></span>
				<a class="kdna-rc-url-link" href="<?php echo esc_url( $variant['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $tooltip ); ?>"><?php echo esc_html( $variant['url'] ); ?></a>
				<button type="button" class="button-link kdna-rc-url-copy" data-url="<?php echo esc_attr( $variant['url'] ); ?>" aria-label="<?php echo esc_attr__( 'Copy URL', 'kdna-regional-content' ); ?>"><?php echo esc_html__( 'Copy', 'kdna-regional-content' ); ?></button>
			</li>
			<?php
		}
		echo '</ul>';

		// "Test as visitor from" dropdown.
		echo '<p>';
		echo '<label for="kdna-rc-test-as-visitor"><strong>' . esc_html__( 'Test as visitor from', 'kdna-regional-content' ) . '</strong></label><br />';
		echo '<select id="kdna-rc-test-as-visitor" class="kdna-rc-test-as-visitor">';
		foreach ( $variants as $i => $variant ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $variant['url'] ),
				esc_html( $variant['label'] )
			);
		}
		echo '</select> ';
		echo '<button type="button" class="button button-secondary kdna-rc-test-as-visitor-go">' . esc_html__( 'Open', 'kdna-regional-content' ) . '</button>';
		echo '</p>';
	}
}
