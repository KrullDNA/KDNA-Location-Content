# KDNA Regional Content

A WordPress and Elementor plugin that serves region-specific content to visitors based on their detected country. Editors define regions (single countries or groups of countries) in the admin, then either inject regional content variants into Elementor widgets or restrict widget, section, container, or post visibility per region. Designed to work behind WP Rocket and other full-page caches.

- **Plugin name:** KDNA Regional Content
- **Slug:** `kdna-regional-content`
- **Author:** Krull Design and Advertising (KDNA)
- **License:** GPL-2.0-or-later
- **Required:** WordPress 6.0+, PHP 8.0+, Elementor 3.x or 4.x

## Table of contents

1. Installation
2. MaxMind account signup
3. License key setup
4. Region configuration walkthrough
5. Using variants in Elementor
6. Element, post, and JetEngine visibility
7. The `?region=` URL override
8. WP Rocket integration
9. Troubleshooting

---

## 1. Installation

1. Upload the `kdna-regional-content` folder to `wp-content/plugins/` (or upload the zip via Plugins, Add New, Upload Plugin).
2. Activate the plugin under **Plugins** in the WordPress admin.
3. Visit **Regional Content** in the admin sidebar (icon: globe).

The plugin self-checks on activation and surfaces admin notices when something is missing: license key, MaxMind database, regions, or default region. Each notice carries a deep link to the right tab.

## 2. MaxMind account signup

The plugin uses the free MaxMind GeoLite2-Country database. Create an account once:

1. Go to [maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup) and complete the form (free, no credit card needed).
2. Confirm the email and sign in to the MaxMind portal.
3. Open **Account, Manage License Keys** and click **Generate new license key**.
4. Choose a description (e.g. `KDNA Regional Content on example.com`) and select **No, I will use the legacy URL** when prompted (the plugin downloads `.tar.gz`, not GeoIP Update).
5. Copy the generated license key. Treat it like a password.

## 3. License key setup

1. Go to **Regional Content, General**.
2. Paste the MaxMind license key into the **MaxMind License Key** field.
3. Save changes.
4. Switch to the **Tools** tab and click **Update Database Now**.

The button downloads the latest GeoLite2-Country archive, extracts the `.mmdb`, and stores it under `wp-content/uploads/kdna-regional-content/`. The status table refreshes in place with the build date, file size, and IP version.

A WP-Cron event keeps the database fresh. The default schedule is **Monthly (recommended)**; switch to Weekly or Never under the same Tools tab if you prefer.

## 4. Region configuration walkthrough

Regions are the core unit. A region is one country or a group of countries treated together. Visitors detected in any of a region's countries see that region's content.

To add a region:

1. Go to **Regional Content, Regions**.
2. Click **Add Region**. The inline editor expands.
3. Fill in:
   - **Display Name** (e.g. `Australia and New Zealand`). The slug auto-fills from the name; edit it if needed (lowercase letters, numbers, hyphens).
   - **Type:** Single Country or Group of Countries.
   - **Countries:** search the country picker, tick the ones the region covers.
   - **Language Code:** optional BCP 47 tag (e.g. `en-AU`, `pt-BR`). Applied as the `lang` attribute on variant output.
   - **Direction:** Left to right or Right to left. Applied as the `dir` attribute on variant output when set to RTL.
4. Click **Save Region**.

Drag rows by the handle on the left to reorder. Region order controls match priority: when a country appears in more than one region, the first region wins. Delete with confirmation; deleting a region also clears the Default Region setting if it pointed at the removed slug.

**Default Region:** under **Regional Content, General**, pick a Default Region from the dropdown. Visitors whose country is not in any configured region (and any visitor whose IP cannot be resolved) fall back to this region. We recommend creating a "Rest of World" group for the default.

## 5. Using variants in Elementor

Six widgets gain a **Regional Content** controls section: Heading, Text Editor, Button, Image, Icon, and Icon List. The first five use a variant repeater; Icon List uses per-item visibility (described in section 6).

### Heading and Text Editor

1. Edit a Heading widget, scroll to **Regional Content** in the panel.
2. Click **Add Item** to add a row.
3. Pick a **Region** from the dropdown.
4. **Heading widget:** enter the new **Title** and an optional **Link** override.
   **Text Editor widget:** enter the variant **Content** in the WYSIWYG editor.
5. Repeat for each region you want to override. Regions you do not list keep the widget's default content.

### Button

Repeater fields per region: **Text** (button label), **Link** (URL with target/rel flags), and **Icon** (Font Awesome or whatever icon library Elementor has loaded).

### Image

Repeater fields per region: **Image** (media picker), **Alt** text, and **Link**. The plugin clears `srcset` and `sizes` from the original markup when you swap the image so the browser does not pick a stale URL.

### Icon

Repeater fields per region: **Icon** (Icons control) and **Link**. When the original Icon widget has no link and the variant supplies one, the wrapper is converted to an `<a>` element automatically.

### How the swap works

Server-side, every variant is rendered into the page. The default variant is visible (`data-kdna-region="default"`); other variants are appended as siblings with `style="display:none"`, plus `lang` and `dir` attributes when the region defines them. Front-end JavaScript reads the `kdna_region` cookie and, on first visit, calls the public AJAX endpoint to detect the visitor. When the visitor's region matches a non-default variant, the JS hides the default and reveals the matching variant.

The anti-flicker pattern (inline style and inline script in `<head>`, priority 1) keeps regional widgets `visibility: hidden` only for visitors whose cookie is missing or does not match the default. Visitors who already match the default see zero hiding and zero delay. A safety timeout reveals everything after 800ms even if the AJAX detector hangs.

## 6. Element, post, and JetEngine visibility

Three other ways to restrict content, all of which share the same underlying mechanism.

### Element visibility (Elementor)

Open any widget, section, or container in Elementor and find **Regional Visibility** under the Advanced panel. Toggle **Restrict by Region** on, pick one or more regions in **Show in Regions**, and the element appears only for visitors in those regions. Server output is unchanged; the client-side filter walks `[data-kdna-show-in]` and removes non-matching elements.

### Post visibility (any post type)

Tick the post types you want to support under **Regional Content, General, Post Types with Region Visibility**. A **Regional Visibility** meta box then appears in the editor sidebar for those post types. Tick the regions allowed to see the post and save. The list is stored in the `_kdna_rc_regions` post meta. Posts with no regions ticked show everywhere.

For single-post pages, configure **Single Post Behaviour for Restricted Posts**:

- **Show anyway** (default): the page renders even when the visitor is not in an allowed region.
- **Redirect to URL**: the page redirects client-side to the configured URL. Because the redirect runs in the browser from a tiny `<meta name="kdna-rc-post-regions">` tag, it works on cached pages.

### Icon List per-item

In the Icon List widget, each list item has a new **Show in Regions** multi-select. Tick the regions allowed to see that item. Items with nothing ticked show everywhere; items with one or more regions ticked are tagged `data-kdna-show-in` on their `<li>` and the same filter handles them.

### JetEngine Listing Grid

When JetEngine is active, every listing item's outer wrapper carries `data-kdna-show-in` populated from each post's `_kdna_rc_regions` meta. **Query args are not modified**, so the cached HTML still contains every post and the visitor only sees the items their region is allowed to. CSS Grid listings reflow cleanly; flex listings include a small CSS rule (`assets/css/frontend.css`) that drops the margin contribution of hidden items so there are no empty gaps.

## 7. The `?region=` URL override

Append `?region=slug` to any URL to force a specific region for the current session. The chosen region is stored in the `kdna_region` cookie so subsequent pages stick.

Override mode lives under **Regional Content, General, Test Override Mode**:

- **Admins only** (default): only logged-in users with `manage_options` can use the override. Recommended for production.
- **All visitors**: anyone can use the override. Useful on staging.
- **Disabled**: the parameter is ignored.

Examples:

```
https://example.com/about?region=australia
https://example.com/?region=usa
```

Once set, the cookie persists for the configured Cookie Lifetime (1 to 365 days, default 30). To clear, delete the `kdna_region` cookie or visit any page after the cookie expires.

## 8. WP Rocket integration

The plugin auto-detects WP Rocket and excludes the public detection endpoint:

```
/wp-admin/admin-ajax.php?action=kdna_rc_detect_region
```

This is added to both `rocket_cache_reject_uri` and `rocket_exclude_urls` filters so it survives WP Rocket version differences. The endpoint also sends `nocache_headers()` on every response.

The rest of the front-end output is fully cacheable. Variants for every region are rendered server-side and the cookie-driven JavaScript chooses the correct one in the visitor's browser.

The **Tools, Clear All Caches** button flushes the WP Rocket page cache, the WordPress object cache, and any plugin transients. When WP Rocket is not active the button still flushes the object cache and transients.

## 9. Troubleshooting

**Detection always returns the default region.**
- Check that the database is downloaded (Tools tab status panel) and not stale (older than 60 days).
- Try **Tools, Test Detection** with a known IP (`8.8.8.8` for the US, `1.1.1.1` for AU). If detection fails for these, the database is missing or corrupt.
- If the site is behind Cloudflare, make sure **Trust Proxy Headers** is on so the plugin reads `CF-Connecting-IP`. If it sits on a direct-connect host, leave the toggle off.

**Variants flash before swapping.**
- Make sure the inline anti-flicker block is present in `<head>`. Search the page source for `id="kdna-rc-pending-style"` and `id="kdna-rc-pending-script"`; they should appear near the top of `<head>`, before any other plugin scripts.
- Check that frontend.js is loading and not blocked by an asset optimiser. If a build step strips the `<script id="kdna-rc-pending-script">` block, the anti-flicker no longer protects regional widgets.
- Returning visitors who already match the default region see zero hiding by design.

**Hidden Icon List items leave grid gaps.**
- Modern flex grids should collapse cleanly thanks to the rule in `assets/css/frontend.css`. If your theme uses a custom grid system, add a similar rule for its hidden-item selector.

**`?region=` does not work.**
- Check **Test Override Mode**. On production it defaults to admins-only; switch to All visitors temporarily on staging.
- Confirm the slug exists under the Regions tab. Unknown slugs are silently ignored.

**Database update fails.**
- Verify the license key under General. The button is disabled when no key is saved.
- Check that `wp-content/uploads/kdna-regional-content/` exists and is writable by the web server.
- The plugin streams the MaxMind archive to disk and extracts via `PharData`. If your host disables Phar, the update fails with a clear error message; either enable Phar or upload the `.mmdb` file manually to the same folder.

**Uninstall does not clean everything.**
- Deactivating the plugin only stops it running; it keeps options and the database file in place so reactivation is clean.
- Uninstalling (Plugins, Delete) runs `uninstall.php`, which deletes the `kdna_rc_settings`, `kdna_rc_regions`, and `kdna_rc_db_status` options, removes the `_kdna_rc_regions` post meta, deletes plugin transients, clears the cron event, and removes the `wp-content/uploads/kdna-regional-content/` folder.

## Build status

See `../PROJECT-BRIEF.md` for the full build status table and stage descriptions.
