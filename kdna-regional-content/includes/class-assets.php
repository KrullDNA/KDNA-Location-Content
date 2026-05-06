<?php
/**
 * Front-end assets, anti-flicker bootstrapping, and post-region meta output.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Assets
 *
 * Owns the front-end side of Stage 5:
 *   - prints the anti-flicker inline style and inline script in <head>
 *     at priority 1 (after the detector's window.kdnaRC config which is
 *     also at priority 1, registered earlier in the bootstrap),
 *   - emits the meta name="kdna-rc-post-regions" tag on single posts,
 *   - exposes the single-post redirect configuration to frontend.js,
 *   - enqueues frontend.js and frontend.css.
 */
class KDNA_RC_Assets {

	/**
	 * Wire up front-end hooks. Skipped in admin.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'print_anti_flicker' ), 1 );
		add_action( 'wp_head', array( $this, 'print_post_regions_meta' ), 1 );
		add_action( 'wp_head', array( $this, 'print_restricted_posts_map' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	/**
	 * Print the inline anti-flicker style and script in <head>.
	 *
	 * The CSS rule keeps every element with a data-kdna-show-in attribute
	 * invisible while the page is in the pending state. The script adds the
	 * pending class to <html> when the visitor's cookie is missing or does
	 * not match the configured default region, and arms an 800ms safety
	 * timeout so visitors are never stuck looking at blank space.
	 *
	 * Visibility is used (not display:none) so layout space is preserved
	 * and there is no scroll jump or content shift when the swap completes.
	 *
	 * @return void
	 */
	public function print_anti_flicker() {
		// CSS first so the rule is registered before any element with the
		// data attribute renders.
		echo "<style id=\"kdna-rc-pending-style\">\n";
		echo ".kdna-rc-pending [data-kdna-show-in],\n";
		echo ".kdna-rc-pending .kdna-rc-variant-wrapper,\n";
		echo ".kdna-rc-pending .kdna-rc-mlf { visibility: hidden; }\n";
		echo "</style>\n";

		// Read defaultRegion from window.kdnaRC (printed at priority 1 by the
		// detector before this script runs, because hook callbacks fire in
		// registration order at the same priority and the detector's hook is
		// registered first by the plugin bootstrap).
		?>
<script id="kdna-rc-pending-script">
(function () {
	var cfg = window.kdnaRC || {};
	var html = document.documentElement;

	// Region branch: enter pending when the cookie is missing or does not
	// match the configured default region. Default-region returning visitors
	// see zero hiding.
	var regionDefault = cfg.defaultRegion || '';
	var regionMatch = document.cookie.match(/(?:^|; )kdna_region=([^;]+)/);
	var regionCookie = regionMatch ? decodeURIComponent(regionMatch[1]) : '';
	var needRegionPending = !regionCookie || regionCookie !== regionDefault;

	// Language branch (Stage 10): same logic against the language cookie and
	// configured Default Language. When no languages are configured the
	// branch is silent so existing single-language sites are unchanged.
	var languageDefault = cfg.defaultLanguage || '';
	var languageCookieName = cfg.languageCookie || 'kdna_language';
	var languageMatch = document.cookie.match(new RegExp('(?:^|; )' + languageCookieName + '=([^;]+)'));
	var languageCookie = languageMatch ? decodeURIComponent(languageMatch[1]) : '';
	var hasLanguages = cfg.languages && cfg.languages.length > 0;
	var needLanguagePending = hasLanguages && (!languageCookie || (languageDefault && languageCookie !== languageDefault));

	if (needRegionPending || needLanguagePending) {
		html.classList.add('kdna-rc-pending');
		window.setTimeout(function () {
			html.classList.remove('kdna-rc-pending');
		}, 800);
	}
})();
</script>
		<?php
	}

	/**
	 * Print the post-regions meta tag on restricted single-post views.
	 *
	 * Stage 5 redirect handling reads this tag on the client because cached
	 * pages skip PHP, so we cannot do a server-side wp_redirect on the
	 * cached path. Visitors not in the listed regions are redirected by
	 * frontend.js when the admin has configured "Redirect to URL".
	 *
	 * @return void
	 */
	public function print_post_regions_meta() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$regions = KDNA_RC_Post_Visibility::get_post_regions( $post_id );
		if ( empty( $regions ) ) {
			return;
		}

		printf(
			'<meta name="kdna-rc-post-regions" content="%s" />' . "\n",
			esc_attr( implode( ',', $regions ) )
		);
	}

	/**
	 * Print the post-regions map as a JS global.
	 *
	 * Frontend.js reads this map and applies data-kdna-show-in to listing
	 * items at runtime, matched on the post-{id} class added by
	 * post_class(). Provides a robust fallback when the JetEngine PHP
	 * filter / action hooks do not fire (which varies by JetEngine version).
	 *
	 * Output is skipped entirely on admin pages and when no post is
	 * restricted, so the script tag never appears on sites that have not
	 * configured any post-level visibility.
	 *
	 * @return void
	 */
	public function print_restricted_posts_map() {
		$map = KDNA_RC_Post_Visibility::get_restricted_posts_map();
		if ( empty( $map ) ) {
			return;
		}

		// Per-post CSS rules so first-time non-default visitors do not see
		// restricted listing items flash before the JS hydration runs. The
		// rules only apply while <html> carries kdna-rc-pending; default-region
		// returning visitors never enter the pending state and the rules
		// remain inert for them.
		$selectors = array();
		foreach ( array_keys( $map ) as $post_id ) {
			$selectors[] = '.kdna-rc-pending .post-' . (int) $post_id;
		}
		if ( ! empty( $selectors ) ) {
			echo "<style id=\"kdna-rc-post-pending\">\n";
			echo esc_html( implode( ",\n", $selectors ) ) . " { visibility: hidden; }\n";
			echo "</style>\n";
		}

		echo "<script id=\"kdna-rc-post-regions\">window.kdnaRCPostRegions = " . wp_json_encode( $map ) . ";</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding is the appropriate escape.
	}

	/**
	 * Enqueue the front-end script and stylesheet.
	 *
	 * Localises the visibility-mode and redirect configuration the script
	 * needs. The cookie name and AJAX URL come from window.kdnaRC (printed
	 * by the detector) so we do not duplicate them here.
	 *
	 * @return void
	 */
	public function enqueue_frontend() {
		// Stage 10: flag-icons. Always enqueued so the Stage 11 Language
		// Selector widget is zero-config. The CSS is small (~70 KB minified)
		// and the SVG flags are loaded lazily by the browser only when the
		// matching .fi-XX class appears in the DOM.
		wp_enqueue_style(
			'kdna-rc-flag-icons',
			KDNA_RC_PLUGIN_URL . 'lib/flag-icons/css/flag-icons.min.css',
			array(),
			'7.5.0'
		);

		wp_enqueue_style(
			'kdna-rc-frontend',
			KDNA_RC_PLUGIN_URL . 'assets/css/frontend.css',
			array( 'kdna-rc-flag-icons' ),
			KDNA_RC_VERSION
		);

		wp_enqueue_script(
			'kdna-rc-frontend',
			KDNA_RC_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			KDNA_RC_VERSION,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);

		// Stage 11: Language Selector widget assets. Always enqueued on the
		// front end so the widget is zero-config wherever an editor drops
		// it. Both files are tiny.
		wp_enqueue_style(
			'kdna-rc-language-selector',
			KDNA_RC_PLUGIN_URL . 'assets/css/language-selector.css',
			array( 'kdna-rc-flag-icons' ),
			KDNA_RC_VERSION
		);

		wp_enqueue_script(
			'kdna-rc-language-selector',
			KDNA_RC_PLUGIN_URL . 'assets/js/language-selector.js',
			array( 'kdna-rc-frontend' ),
			KDNA_RC_VERSION,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);

		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$single_mode = isset( $settings['single_post_behaviour'] ) ? (string) $settings['single_post_behaviour'] : 'show';
		$redirect    = isset( $settings['single_post_redirect_url'] ) ? (string) $settings['single_post_redirect_url'] : '';
		if ( '' === $redirect ) {
			$redirect = home_url( '/' );
		}

		wp_localize_script(
			'kdna-rc-frontend',
			'kdnaRCFrontend',
			array(
				'visibilityMode' => 'hide', // Reserved for a future "remove from DOM" toggle.
				'singleMode'     => $single_mode, // 'show' or 'redirect'.
				'redirectUrl'    => esc_url_raw( $redirect ),
			)
		);
	}
}
