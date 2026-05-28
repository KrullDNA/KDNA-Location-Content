<?php
/**
 * Google Analytics 4 integration.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Google_Analytics_Integration
 *
 * When the General-tab "Google Analytics integration" toggle is on, this
 * class prints a tiny inline script in wp_footer that reads the
 * plugin's resolved region and language out of window.kdnaRCResolved
 * (populated by Stage 10 frontend.js once detection finishes) and pushes
 * them into Google Analytics in two ways:
 *
 *   1. gtag('set', 'user_properties', {...}) so the region and language
 *      attach to every subsequent event in the session (clicks, scrolls,
 *      conversions, e-commerce, the whole graph).
 *   2. gtag('event', 'kdna_resolution', {...}) as a one-shot custom event
 *      so the resolution itself is logged and reportable.
 *
 * The snippet does nothing when gtag is undefined, so it is safe to
 * leave on even when Google Analytics is not loaded yet (development,
 * cookie-banner deferral, etc.). It is also safe to run multiple times
 * because user_properties is idempotent and the event re-fires only
 * when the language actually changes (via the kdna-rc-language-changed
 * DOM event from Stage 11).
 *
 * Setup steps for the admin, also documented in the field description:
 *   - Install Google Analytics on the site via gtag.js, Google Tag
 *     Manager, or any GA4 plugin (Site Kit, MonsterInsights, etc.).
 *   - In GA4 Admin -> Custom Definitions, create two event-scoped
 *     custom dimensions: kdna_region and kdna_language.
 *   - Wait 24 hours for GA4 to start populating them in reports.
 *   - Build any GA4 report (Acquisition, Engagement, Monetization)
 *     and add kdna_region as a secondary dimension or filter.
 */
class KDNA_RC_Google_Analytics_Integration {

	/**
	 * Wire the footer printer when the setting is on.
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
		// wp_footer at priority 999 runs after every common GA loader
		// (Site Kit, MonsterInsights, GTM, raw gtag.js) so gtag() is
		// almost always defined by the time the snippet executes. If
		// it is not, the snippet checks before calling.
		add_action( 'wp_footer', array( $this, 'print_snippet' ), 999 );
	}

	/**
	 * Whether the General-tab toggle is on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		return is_array( $settings ) && ! empty( $settings['google_analytics_integration'] );
	}

	/**
	 * Print the inline snippet.
	 *
	 * @return void
	 */
	public function print_snippet() {
		?>
<script id="kdna-rc-ga4-bridge">
(function () {
	var lastSignature = '';

	function fire() {
		if ( typeof window.gtag !== 'function' ) { return; }
		if ( ! window.kdnaRCResolved ) { return; }

		var region   = window.kdnaRCResolved.region   || '(none)';
		var language = window.kdnaRCResolved.language || '(none)';
		var signature = region + '|' + language;

		// Skip duplicate fires when nothing changed (the resolution
		// chain dispatches multiple times in rare cases).
		if ( signature === lastSignature ) { return; }
		lastSignature = signature;

		// User properties propagate to every event in this session.
		window.gtag( 'set', 'user_properties', {
			kdna_region:   region,
			kdna_language: language
		} );

		// One-shot custom event for explicit logging.
		window.gtag( 'event', 'kdna_resolution', {
			kdna_region:   region,
			kdna_language: language
		} );
	}

	// Re-fire whenever the language selector swaps the language.
	document.addEventListener( 'kdna-rc-language-changed', function () {
		// Small delay so window.kdnaRCResolved has updated.
		window.setTimeout( fire, 50 );
	} );

	// Initial fire once the resolution pipeline has had a chance to run.
	if ( document.readyState === 'complete' ) {
		window.setTimeout( fire, 800 );
	} else {
		window.addEventListener( 'load', function () {
			window.setTimeout( fire, 400 );
		} );
	}
})();
</script>
		<?php
	}
}
