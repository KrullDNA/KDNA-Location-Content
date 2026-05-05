# KDNA Regional Content

A WordPress and Elementor plugin that serves region-specific content to visitors based on their detected country. Editors define regions (single countries or groups) in the admin, then either inject regional content variants into Elementor widgets or restrict widget visibility per region. Designed to work behind WP Rocket and other full-page caches.

This is a build-in-progress README; the full version (installation walkthrough, MaxMind signup steps, Elementor walk-through, troubleshooting) lands in Stage 8.

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- Elementor 3.x or 4.x (front-end variants are added in Stage 5 onwards)
- A free MaxMind account for the GeoLite2-Country database

## Quick start

1. Install and activate the plugin.
2. Go to **Regional Content > General** in the WordPress admin and paste your MaxMind license key.
3. Go to **Regional Content > Tools** and click **Update Database Now**.
4. Go to **Regional Content > Regions** and add at least one region (single country or group).
5. Back on the **General** tab, choose a Default Region.

## Detection layer (Stage 4)

Visitor detection runs on every front-end page load:

- The visitor IP is read from CF-Connecting-IP, X-Forwarded-For, X-Real-IP, or REMOTE_ADDR (in that order). Set **Trust Proxy Headers** off when the server is reachable directly, so an attacker cannot spoof their country with a forged header.
- The first matching region wins. Match priority: `?region=` URL override (subject to the override mode), existing `kdna_region` cookie, GeoIP lookup, then the configured Default Region.
- The `kdna_region` cookie persists the resolved slug for subsequent page loads. Lifetime is configurable from 1 to 365 days; default 30.
- The `?region=slug` URL parameter forces a specific region for testing. The override mode controls who can use it: admins only (default), all visitors, or disabled.

The plugin prints a tiny inline script in `<head>` exposing:

```js
window.kdnaRC = {
	defaultRegion: 'rest-of-world',
	ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php',
	cookieName: 'kdna_region',
	detectAction: 'kdna_rc_detect_region',
	nonce: '...'
};
```

The Stage 5+ front-end script reads the cookie and, when missing, calls the public AJAX endpoint described below.

## WP Rocket and full-page caching

The detection AJAX endpoint **must not be cached**. Stage 8 adds the WP Rocket integration that excludes it automatically; until then, exclude it manually under **Settings > WP Rocket > Cache > Never Cache (URLs)**:

```
/wp-admin/admin-ajax.php?action=kdna_rc_detect_region
```

If your cache plugin only accepts paths, exclude `/wp-admin/admin-ajax.php` (which is the WordPress norm). The plugin sends `nocache_headers()` on the AJAX response so any well-behaved cache will already skip it.

The rest of the front-end output is fully cacheable. Variants for every region are rendered server-side and the cookie-driven JS swap chooses the right one in the visitor's browser.

## Build status

See `../PROJECT-BRIEF.md` in the repository root for the canonical build status table and stage descriptions.
