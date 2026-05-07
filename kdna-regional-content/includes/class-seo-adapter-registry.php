<?php
/**
 * Registry that picks the active SEO-plugin adapter.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_SEO_Adapter_Registry
 *
 * Walks the list of supported SEO plugins, returns the first one whose
 * adapter::is_active() returns true. The active adapter drives:
 *   - the SEO meta box's field key map,
 *   - the filter-chain override registration,
 *   - the SEO Health Check's "detected SEO plugin" finding.
 *
 * Order of preference follows market share roughly, with Yoast first to
 * match what the plugin shipped with prior to multi-adapter support.
 * If two SEO plugins are simultaneously active (a misconfiguration that
 * usually breaks things on its own) the first match wins.
 */
class KDNA_RC_SEO_Adapter_Registry {

	/**
	 * Cached active adapter instance (per request).
	 *
	 * @var KDNA_RC_SEO_Adapter|null
	 */
	private static $cached_active = null;

	/**
	 * Cached "have we tried" flag. Set even when no adapter is active.
	 *
	 * @var bool
	 */
	private static $cached_resolved = false;

	/**
	 * Build the canonical adapter list.
	 *
	 * Each entry is a fresh instance; instantiation is cheap because no
	 * constructor side-effects fire until init() is called.
	 *
	 * @return array<int,KDNA_RC_SEO_Adapter>
	 */
	public static function all_adapters() {
		return array(
			new KDNA_RC_SEO_Adapter_Yoast(),
			new KDNA_RC_SEO_Adapter_Rank_Math(),
			new KDNA_RC_SEO_Adapter_AIOSEO(),
			new KDNA_RC_SEO_Adapter_SEOPress(),
			new KDNA_RC_SEO_Adapter_The_SEO_Framework(),
			new KDNA_RC_SEO_Adapter_Slim_SEO(),
			new KDNA_RC_SEO_Adapter_SmartCrawl(),
			new KDNA_RC_SEO_Adapter_Squirrly(),
		);
	}

	/**
	 * Return the active adapter, or null when no supported SEO plugin is loaded.
	 *
	 * @return KDNA_RC_SEO_Adapter|null
	 */
	public static function active_adapter() {
		if ( self::$cached_resolved ) {
			return self::$cached_active;
		}

		foreach ( self::all_adapters() as $adapter ) {
			if ( $adapter instanceof KDNA_RC_SEO_Adapter && $adapter->is_active() ) {
				self::$cached_active   = $adapter;
				self::$cached_resolved = true;
				return $adapter;
			}
		}

		self::$cached_active   = null;
		self::$cached_resolved = true;
		return null;
	}

	/**
	 * Reset the cache. Useful for unit tests or after activating /
	 * deactivating a plugin within the same request.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cached_active   = null;
		self::$cached_resolved = false;
	}

	/**
	 * Whether any supported SEO plugin is currently active.
	 *
	 * @return bool
	 */
	public static function any_active() {
		return null !== self::active_adapter();
	}
}
