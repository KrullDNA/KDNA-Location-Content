<?php
/**
 * Region-switch banner: polite, dismissible prompt shown once per
 * visitor when their IP-detected region differs from the URL they are
 * currently on.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Region_Banner
 *
 * Shows a small banner at the top of the page when the visitor's
 * IP-detected region does not match the region of the URL they
 * landed on. Two buttons:
 *
 *   - "Yes, switch": navigate to the equivalent URL on the visitor's
 *     detected region's prefix. Cookie set so the banner does not
 *     re-appear.
 *   - "No thanks": hide the banner and set the dismiss cookie.
 *
 * Either action sets a "seen" cookie (kdna_region_banner_seen) so the
 * banner is shown at most once per visitor per cookie lifetime (30 days
 * by default; configurable via the shared Cookie Lifetime setting).
 *
 * Deliberate non-feature: this does NOT auto-redirect. Auto-redirects
 * confuse search engine crawlers (Googlebot crawls from US IPs and
 * would never see non-US content), break shared links (an Australian
 * sending a product link to a New Zealander should land on that exact
 * product, not the NZ homepage), and frustrate VPN / travel cases.
 *
 * The banner is rendered server-side as an empty shell; all dynamic
 * logic (deciding whether to show, computing the target URL,
 * substituting the {region} macro into the message) happens in JS so
 * the same cached HTML serves every visitor.
 */
class KDNA_RC_Region_Banner {

	/**
	 * Cookie name set when the banner has been shown or dismissed.
	 *
	 * @var string
	 */
	const SEEN_COOKIE = 'kdna_region_banner_seen';

	/**
	 * Default message template. {region} is substituted with the
	 * detected region's display name at render time in JS.
	 *
	 * @var string
	 */
	const DEFAULT_MESSAGE = 'Looks like you\'re in {region}. Would you like to switch to our {region} site?';

	/**
	 * Wire hooks. Front-end only.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_body_open', array( $this, 'render_shell' ), 5 );
		// wp_body_open is the modern hook (WP 5.2+). Themes that have not
		// added the wp_body_open() call still get the banner because we
		// also hook wp_footer as a fall-back — the JS waits for DOM
		// readiness so insertion order is forgiving.
		add_action( 'wp_footer', array( $this, 'render_shell_fallback' ), 1 );
	}

	/**
	 * Whether the General-tab toggle is on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		return is_array( $settings ) && ! empty( $settings['region_banner_enabled'] );
	}

	/**
	 * Resolve the configured message template (or default).
	 *
	 * @return string
	 */
	public function message_template() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$value    = is_array( $settings ) && ! empty( $settings['region_banner_message'] ) ? (string) $settings['region_banner_message'] : '';
		return '' !== trim( $value ) ? $value : self::DEFAULT_MESSAGE;
	}

	/**
	 * Yes / accept button label.
	 *
	 * @return string
	 */
	public function yes_label() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$value    = is_array( $settings ) && ! empty( $settings['region_banner_yes'] ) ? (string) $settings['region_banner_yes'] : '';
		return '' !== trim( $value ) ? $value : __( 'Yes, switch', 'kdna-regional-content' );
	}

	/**
	 * No / dismiss button label.
	 *
	 * @return string
	 */
	public function no_label() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$value    = is_array( $settings ) && ! empty( $settings['region_banner_no'] ) ? (string) $settings['region_banner_no'] : '';
		return '' !== trim( $value ) ? $value : __( 'No thanks', 'kdna-regional-content' );
	}

	/**
	 * Enqueue the banner CSS + JS.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'kdna-rc-region-banner',
			KDNA_RC_PLUGIN_URL . 'assets/css/region-banner.css',
			array( 'kdna-rc-flag-icons' ),
			KDNA_RC_VERSION
		);

		wp_enqueue_script(
			'kdna-rc-region-banner',
			KDNA_RC_PLUGIN_URL . 'assets/js/region-banner.js',
			array( 'kdna-rc-frontend' ),
			KDNA_RC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'kdna-rc-region-banner',
			'kdnaRCRegionBanner',
			array(
				'seenCookie'   => self::SEEN_COOKIE,
				'message'      => $this->message_template(),
				'yesLabel'     => $this->yes_label(),
				'noLabel'      => $this->no_label(),
				'peekAction'   => KDNA_RC_Detector::AJAX_ACTION,
				'cookieDays'   => (int) ( new KDNA_RC_Detector() )->get_cookie_lifetime_days(),
				'closeLabel'   => __( 'Close', 'kdna-regional-content' ),
			)
		);
	}

	/**
	 * Print the banner DOM shell. JS fills in text and unhides it.
	 *
	 * @return void
	 */
	public function render_shell() {
		?>
<div id="kdna-rc-region-banner" class="kdna-rc-region-banner" role="region" aria-label="Region switcher" hidden>
	<div class="kdna-rc-region-banner__inner">
		<span class="kdna-rc-region-banner__flag" aria-hidden="true"></span>
		<p class="kdna-rc-region-banner__message"></p>
		<div class="kdna-rc-region-banner__actions">
			<a class="kdna-rc-region-banner__yes" href="#" role="button"></a>
			<button class="kdna-rc-region-banner__no" type="button"></button>
		</div>
		<button class="kdna-rc-region-banner__close" type="button" aria-label="<?php echo esc_attr__( 'Close', 'kdna-regional-content' ); ?>">&times;</button>
	</div>
</div>
		<?php
	}

	/**
	 * Fallback shell render: only emits if wp_body_open never fired.
	 *
	 * @return void
	 */
	public function render_shell_fallback() {
		// If the shell is already in the DOM (via wp_body_open) the JS
		// detects the duplicate id and ignores this one. Cheap.
		?>
<script id="kdna-rc-region-banner-fallback">
(function () {
	if ( ! document.getElementById( 'kdna-rc-region-banner' ) ) {
		var el = document.createElement( 'div' );
		el.id = 'kdna-rc-region-banner';
		el.className = 'kdna-rc-region-banner';
		el.setAttribute( 'role', 'region' );
		el.setAttribute( 'aria-label', 'Region switcher' );
		el.hidden = true;
		el.innerHTML = '<div class="kdna-rc-region-banner__inner">' +
			'<span class="kdna-rc-region-banner__flag" aria-hidden="true"></span>' +
			'<p class="kdna-rc-region-banner__message"></p>' +
			'<div class="kdna-rc-region-banner__actions">' +
			'<a class="kdna-rc-region-banner__yes" href="#" role="button"></a>' +
			'<button class="kdna-rc-region-banner__no" type="button"></button>' +
			'</div>' +
			'<button class="kdna-rc-region-banner__close" type="button" aria-label="Close">&times;</button>' +
			'</div>';
		document.body.insertBefore( el, document.body.firstChild );
	}
})();
</script>
		<?php
	}
}
