# KDNA Regional Content – Project Brief

**Plugin Name:** KDNA Regional Content
**Slug:** `kdna-regional-content`
**Text Domain:** `kdna-regional-content`
**Author:** Krull Design & Advertising (KDNA)
**Target:** WordPress 6.x, Elementor 3.x / 4.x, PHP 8.0+

---

## 1. Purpose

A WordPress + Elementor plugin that serves region-specific content to visitors based on their detected country. Editors define regions (single countries or groups of countries) in the admin, then either:

1. **Inject regional content variants** into existing Elementor widgets (Heading, Text Editor, Button, Image, Icon, Icon List), or
2. **Restrict widget visibility** to specific regions on any Elementor widget, section, or container.

When a visitor lands on the site, geo-detection determines their country, maps it to a configured region, and the matching variant is shown. If no region matches, a configurable default region is shown.

The plugin must work with full-page caching (WP Rocket), support same-language regional differences for the current site, and be expandable to multilingual scenarios (with `lang` and `dir` attributes on variant output).

---

## 2. Scope

| Item | Decision |
|---|---|
| Front end? | Yes |
| Admin? | Yes (settings page) |
| Geo-IP service | MaxMind GeoLite2 (local `.mmdb` database, free with account) |
| Granularity | Country level only (ISO 3166-1 alpha-2 codes) |
| Cache compatibility | WP Rocket (full-page cache must work) |
| Multilingual support | Same-language by default, but architecture must support different languages with `lang` / `dir` attributes per region |
| Default fallback | Admin-selectable default region |
| Testing override | `?region=XX` URL parameter |
| Widgets with content variants | Heading, Text Editor, Button, Image, Icon, Icon List |
| Widgets with visibility control | ALL Elementor widgets, sections, and containers |
| Database storage | `wp_options` for settings and regions; `.mmdb` file in `wp-content/uploads/kdna-regional-content/` |
| External JS libs | None (vanilla JS only) |
| External PHP libs | MaxMind `maxmind-db/reader` library (bundled in `/lib/`) |

---

## 3. Architecture Overview

### 3.1 Caching strategy (critical)

WP Rocket caches the full HTML of pages. We cannot vary cached output by visitor. Therefore:

1. **Server-side rendering outputs ALL variants** for every page that uses the plugin. Each variant is wrapped with `data-kdna-region="..."` attributes.
2. **The default variant is visible** by default (no inline `display: none`) so cached visitors who match the default see the right content with zero flicker.
3. **Non-default variants are hidden** with inline `style="display: none;"`.
4. **A cookie (`kdna_region`) holds the visitor's matched region slug.** Set on first visit via a non-cached AJAX endpoint, valid for 30 days.
5. **Front-end JavaScript runs early** (in `<head>`, deferred), reads the cookie, and if the visitor's region differs from the default, swaps visibility (hides default, reveals matching variant).
6. **Anti-flicker behaviour** (see section 3.5): regional widgets are briefly hidden with `visibility: hidden` for visitors who require a swap, revealed once detection completes. Returning visitors who match the default region see zero delay; first-time visitors typically wait 100-300ms; an 800ms safety timeout prevents content ever being permanently hidden.

### 3.2 Geo-IP detection flow

1. Visitor hits the site.
2. JS checks for `kdna_region` cookie.
3. **If cookie exists:** read region slug, swap variants, done.
4. **If cookie missing:** fire AJAX to `wp-admin/admin-ajax.php?action=kdna_detect_region` (this URL must be excluded from WP Rocket cache, see Stage 8).
5. Server reads visitor IP (handling Cloudflare and proxy headers), queries MaxMind `.mmdb`, matches country to a configured region, returns slug, sets cookie.
6. JS receives slug, swaps variants.

### 3.3 IP detection priority order

1. `HTTP_CF_CONNECTING_IP` (Cloudflare)
2. `HTTP_X_FORWARDED_FOR` (first IP in chain)
3. `HTTP_X_REAL_IP`
4. `REMOTE_ADDR`

Admin setting to allow disabling the proxy headers if the site is not behind a CDN (security: prevents IP spoofing via headers on direct-access sites).

### 3.4 Test override

`?region=XX` URL parameter (where `XX` is a region slug, not a country code) overrides detection and sets the cookie for the session. By default available to admins only; admin toggle to enable for all visitors (useful during staging).

### 3.5 Anti-flicker pattern (no FOUC)

The goal: visitors should never see content briefly appear and then change. Implementation:

**Behavioural rules:**

- **Returning visitor whose cookie matches the default region:** zero hiding, zero delay. Page renders normally, JS does nothing.
- **Returning visitor whose cookie matches a non-default region:** regional widgets briefly hidden, JS swaps variants and reveals. Happens in microseconds (next tick).
- **First-time visitor (no cookie):** regional widgets hidden, AJAX detection fires, JS swaps and reveals once response arrives (typically 100-300ms).
- **Safety timeout 800ms:** if AJAX hangs or fails, content is revealed regardless. Visitors are never stuck looking at blank space.

**Mechanism:**

1. **An inline `<style>` block** is printed in `<head>` at `wp_head` priority 1, defining a `kdna-rc-pending` class on `<html>` that hides regional widgets with `visibility: hidden` (preserves layout, no jump on reveal).

2. **An inline `<script>` block** is printed immediately after, before any other scripts. It:
   - Reads the `kdna_region` cookie
   - Reads the configured default region slug (injected from PHP)
   - If cookie missing OR cookie does not match default: adds `kdna-rc-pending` class to `documentElement`
   - Sets an 800ms timeout to remove the class as a failsafe

3. **The main `frontend.js`** runs the swap logic (read cookie or AJAX-detect, swap variants, apply visibility filter) then removes the `kdna-rc-pending` class on `documentElement`, which clears the safety timeout and reveals everything in its final state.

**Why `visibility: hidden` instead of `display: none`:** layout space is preserved, so when content appears there is no scroll jump or content shift. Above-the-fold widgets keep their position in the layout while detection completes.

**Why `documentElement` (i.e. `<html>`) instead of `<body>`:** the class is available before `<body>` parses, so the CSS rules apply to widgets the moment they enter the DOM.

**Selectors hidden during pending state:**

- `[data-kdna-show-in]` – widgets, sections, containers with visibility restrictions
- `.kdna-rc-variant-wrapper` – containers holding default + variant elements

The default variant inside a wrapper is normally visible. The wrapper itself is what gets hidden during pending, so neither the default nor the variant flashes before JS decides which to show.

---

## 4. File Structure

```
kdna-regional-content/
├── kdna-regional-content.php          (main plugin file – metadata, autoloader, bootstrap)
├── README.md
├── uninstall.php                       (clean up options on uninstall)
├── includes/
│   ├── class-plugin.php                (main plugin class, singleton)
│   ├── class-geoip.php                 (MaxMind .mmdb reader wrapper)
│   ├── class-regions.php               (regions/groups CRUD on wp_options)
│   ├── class-detector.php              (visitor IP detection, cookie, AJAX endpoint, ?region= override)
│   ├── class-database-updater.php      (MaxMind DB download/update via license key + WP-Cron)
│   ├── class-assets.php                (script/style enqueue, with cache-aware ordering)
│   ├── class-elementor-visibility.php  (visibility controls on all widgets/sections/containers)
│   ├── class-elementor-variants.php    (base class for content variant injection)
│   └── widget-extensions/
│       ├── class-heading-extension.php
│       ├── class-text-editor-extension.php
│       ├── class-button-extension.php
│       ├── class-image-extension.php
│       ├── class-icon-extension.php
│       └── class-icon-list-extension.php
├── admin/
│   ├── class-settings.php              (settings page registration + AJAX handlers)
│   ├── views/
│   │   ├── settings-page.php           (tab wrapper)
│   │   ├── tab-general.php             (license key, default region, override settings)
│   │   ├── tab-regions.php             (regions/groups CRUD UI)
│   │   └── tab-tools.php               (DB update button, last updated, status)
│   ├── admin-style.css
│   └── admin.js                        (regions UI: country multi-select, slug auto-gen, save via AJAX)
├── assets/
│   ├── js/
│   │   └── frontend.js                 (cookie read, AJAX detection, variant swap, visibility filter)
│   └── css/
│       └── frontend.css                (default visibility rules for variants)
├── data/
│   └── .gitkeep                        (GeoLite2-Country.mmdb downloaded here at runtime, not committed)
└── lib/
    └── maxmind-db-reader/              (bundled MaxMind PHP library, MIT licensed)
```

**Note on the `.mmdb` file:** Stored in `wp-content/uploads/kdna-regional-content/GeoLite2-Country.mmdb`, NOT in the plugin folder. This survives plugin updates and respects WP filesystem conventions.

---

## 5. Settings Page Layout

Top-level admin menu item: **Regional Content** (icon: `dashicons-admin-site-alt3`)

Three tabs:

### Tab 1: General

- **MaxMind License Key** (text input, password masked)
- **Default Region** (dropdown of configured regions, used when no match)
- **Test Override Mode** (radio: Admins only | All visitors | Disabled)
- **Trust Proxy Headers** (checkbox: enable if behind Cloudflare or other CDN/reverse proxy)
- **Cookie Lifetime** (number input, days, default 30)

### Tab 2: Regions

CRUD interface for regions. Each region:

- **Slug** (auto-generated from name, editable, used in `?region=` and as identifier)
- **Display Name** (admin-facing label, e.g. "Australia & New Zealand")
- **Type** (radio: Single Country | Group of Countries)
- **Countries** (multi-select with search; country list bundled as JSON)
- **Language Code** (optional text input, e.g. `en-AU`, `fr-FR`, applied as `lang` attribute on variant output)
- **Direction** (radio: LTR | RTL, applied as `dir` attribute when not default LTR)

Drag-to-reorder. Inline edit. Delete with confirmation.

### Tab 3: Tools

- **MaxMind Database Status** (last updated timestamp, file size, country count)
- **Update Database Now** (button, fires AJAX to download fresh `.mmdb`)
- **Auto-Update Schedule** (dropdown: Weekly | Monthly | Never – default Monthly)
- **Test Detection** (input field for IP address + "Test" button, shows what region this IP would match – useful for debugging)
- **Clear All Variant Caches** (button, flushes WP Rocket cache if available)

---

## 6. Elementor Integration

### 6.1 Visibility control (all widgets)

Hook into Elementor controls registration to add a "Regional Visibility" tab on every widget, section, and container:

- `elementor/element/common/_section_style/after_section_end` – widgets
- `elementor/element/section/section_advanced/after_section_end` – sections
- `elementor/element/container/section_layout/after_section_end` – containers

Controls added:
- **Restrict by Region** (switcher, default off)
- **Show in Regions** (multi-select, list of configured regions, only visible when switcher is on)

Render: hook `elementor/frontend/widget/before_render` (and section/container equivalents) to add `data-kdna-show-in="region1,region2"` to the wrapper element when restrictions are set.

Front-end JS removes elements where the visitor's region is not in the `data-kdna-show-in` list.

### 6.2 Content variants (Heading, Text Editor, Button, Image, Icon, Icon List)

Each extended widget gets a new "Regional Content" controls section.

**Pattern for Heading, Text Editor, Button, Image, Icon:**

A repeater control where each row defines a variant for a specific region:
- Region selector (dropdown of configured regions)
- Field overrides for that widget type (e.g. for Heading: title text + link override; for Image: image picker + alt + link)

**Pattern for Icon List (different):**

Each existing Icon List item gets a "Restrict to Regions" multi-select control. List items either show or hide per region, no full content variants. This matches editor mental model better than swapping the entire list.

**Render strategy for variants:**

Output the original widget render as the default variant (visible). After it, output one extra wrapper per variant containing the swapped content, hidden by default. Front-end JS swaps visibility based on cookie.

Implementation per widget will use the cleanest method available:
- **Heading**: filter `elementor/widget/render_content`, use DOMDocument to replace heading text and link, append variant copies
- **Text Editor**: same pattern, replace innerHTML of the rendered content
- **Button**: same pattern, replace button text, link, icon
- **Image**: same pattern, replace `src`, `alt`, link
- **Icon**: same pattern, replace icon class
- **Icon List**: per-item `data-kdna-show-in` attribute, no duplication

### 6.3 Cache-friendly markup pattern

```html
<!-- Default variant (visible) -->
<div class="kdna-rc-variant kdna-rc-default" data-kdna-region="default">
  <h2>G'day from Australia</h2>
</div>

<!-- Region variant (hidden by default) -->
<div class="kdna-rc-variant" data-kdna-region="europe" style="display:none" lang="en-GB">
  <h2>Hello from Europe</h2>
</div>

<div class="kdna-rc-variant" data-kdna-region="usa" style="display:none" lang="en-US">
  <h2>Howdy from the US</h2>
</div>
```

JS swap logic: when cookie region matches a non-default variant, hide the default, remove inline `display:none` from matching variant.

---

## 7. MaxMind Integration

### 7.1 Library

Bundle the official MaxMind PHP library (`maxmind-db/reader` v1.x) in `/lib/maxmind-db-reader/`. License: Apache 2.0. No Composer dependency on the host site.

### 7.2 Database download

When admin clicks "Update Database Now":

1. Validate license key is present.
2. Build request to `https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key={KEY}&suffix=tar.gz`
3. Download with `wp_remote_get()` to a temp file in `wp-content/uploads/kdna-regional-content/`.
4. Extract `.tar.gz`, locate the `.mmdb` file inside, move to `wp-content/uploads/kdna-regional-content/GeoLite2-Country.mmdb`.
5. Clean up temp files.
6. Store last-updated timestamp in options.
7. Schedule next auto-update via WP-Cron based on admin setting.

### 7.3 Lookup

`KDNA_RC_GeoIP::get_country_code($ip)` returns 2-letter ISO code or `null` if not found. Wrapped in try/catch to fail silently if database is missing or corrupted. Includes a 5-minute object cache per IP to avoid redundant lookups within a single request.

---

## 8. Build Stages

Each stage is a self-contained Claude Code session. Open a new chat per stage if context gets long. At the start of each session, paste the relevant prompt below. Before each session after the first, ask Claude Code to read this brief and the current state of the repo.

**At the end of every stage, Claude Code MUST:**

1. Update the Current Build Status table in Section 9 to mark the just-completed stage as `Complete`, with a brief one-line note in the Notes column describing what was delivered (e.g. "Settings page live, license key field saving correctly").
2. Commit changes with a clear commit message referencing the stage number (e.g. "Stage 3: Regions & Groups manager").
3. **Stop and wait for the next prompt from the user.** Do NOT proceed to the next stage automatically. The user reviews and tests each stage before approving the next one.

This applies to every stage including the final zip stage.

### Stage 1: Plugin scaffold + settings page foundation

**What this delivers:** Activatable plugin with main menu item, three empty tabs, MaxMind license key field saving to options.

**Prompt:**
> Read PROJECT-BRIEF.md in this repo. Build Stage 1 of the KDNA Regional Content plugin: the main plugin file with proper headers and an autoloader, a `KDNA_RC_Plugin` singleton bootstrap class, and the admin settings page registered as a top-level menu with three empty tabs (General, Regions, Tools). On the General tab, render only the MaxMind License Key field for now, saving to a single option key `kdna_rc_settings`. Use WordPress Settings API. Activate cleanly with no errors. Follow KDNA conventions: `kdna-` prefix on all slugs, classes, hooks, options. UK English. No em dashes.

### Stage 2: MaxMind library integration + database download

**What this delivers:** Bundled MaxMind library, working "Update Database Now" button on the Tools tab that downloads, extracts, and stores the `.mmdb` file. Last-updated timestamp displayed.

**Prompt:**
> Read PROJECT-BRIEF.md. Build Stage 2: download the official `maxmind-db/reader` PHP library and bundle it in `/lib/maxmind-db-reader/` (no Composer needed). Build the Tools tab UI showing database status (last updated, file size, country count if available). Add an "Update Database Now" button that fires an admin AJAX request, validates the license key, downloads `GeoLite2-Country.tar.gz` from MaxMind, extracts it, and stores `GeoLite2-Country.mmdb` in `wp-content/uploads/kdna-regional-content/`. Use `wp_remote_get`, `WP_Filesystem`, and PHP's `PharData` for tar.gz extraction. Show progress and success/error messages via AJAX response. Schedule a WP-Cron event for monthly auto-update (admin can change in settings).

### Stage 3: Regions & Groups manager

**What this delivers:** Full CRUD on the Regions tab. Admin can add, edit, delete, reorder regions. Each region has slug, name, type, countries, lang, dir.

**Prompt:**
> Read PROJECT-BRIEF.md. Build Stage 3: the Regions admin tab. Use a single option key `kdna_rc_regions` storing a serialised array. Build a `KDNA_RC_Regions` class with methods `get_all()`, `get($slug)`, `save($region)`, `delete($slug)`, `reorder($slugs)`. UI: list of regions with drag-to-reorder, inline edit (modal or expandable row), and "Add Region" button. Form fields per region: Slug (auto-generated from name, editable), Display Name, Type (Single / Group), Countries multi-select with search (load full ISO 3166-1 alpha-2 country list as JSON from `data/countries.json`), Language Code text input, Direction radio. Save via admin AJAX with nonce. Update the General tab to add the "Default Region" dropdown populated from configured regions.

### Stage 4: Visitor detection + cookie + AJAX endpoint + override

**What this delivers:** Server-side detection working. Visitor's region resolved on first visit, cookie set, `?region=XX` override functional.

**Prompt:**
> Read PROJECT-BRIEF.md. Build Stage 4: the visitor detection layer. Create `KDNA_RC_GeoIP` (wraps MaxMind reader, returns ISO country for an IP) and `KDNA_RC_Detector` (gets visitor IP with proxy header support per the brief, matches country to a region, manages the `kdna_region` cookie, handles `?region=XX` override based on test override mode). Register a public AJAX endpoint `kdna_rc_detect_region` (both `wp_ajax_` and `wp_ajax_nopriv_`) that returns JSON: `{slug, lang, dir}`. The endpoint sets the cookie server-side. Expose the configured default region slug to the front end via a small inline script printed in `wp_head` so it is available to the anti-flicker script in Stage 5 (use `window.kdnaRC = { defaultRegion: 'slug', ajaxUrl: '...', nonce: '...' }`). Add a Test Detection field on the Tools tab where admin enters an IP and sees the resolved region. Add the General tab settings: Default Region (already added in Stage 3), Test Override Mode, Trust Proxy Headers, Cookie Lifetime. Make sure the AJAX endpoint URL is documented in the README so users can exclude it from WP Rocket cache.

### Stage 5: Visibility control on all Elementor widgets, sections, containers

**What this delivers:** Every Elementor widget, section, and container has a "Regional Visibility" controls section. Selected restrictions render as data attributes. Front-end JS hides non-matching elements.

**Prompt:**
> Read PROJECT-BRIEF.md, paying close attention to section 3.5 (Anti-flicker pattern). Build Stage 5: Elementor visibility control. Create `KDNA_RC_Elementor_Visibility` that hooks `elementor/element/common/_section_style/after_section_end`, `elementor/element/section/section_advanced/after_section_end`, and `elementor/element/container/section_layout/after_section_end` to inject a "Regional Visibility" controls section with a switcher and a multi-select of configured regions. On render, hook `elementor/frontend/widget/before_render`, `elementor/frontend/section/before_render`, `elementor/frontend/container/before_render` to add `data-kdna-show-in="slug1,slug2"` to the wrapper element when restrictions are active. Build `assets/js/frontend.js`: read cookie, if missing fire AJAX to `kdna_rc_detect_region`, then walk the DOM and remove (or hide via display:none) elements with `data-kdna-show-in` that don't include the visitor's region. Implement the anti-flicker pattern from section 3.5: print an inline `<style>` and inline `<script>` in `wp_head` at priority 1 that adds `kdna-rc-pending` class to `documentElement` when cookie is missing or non-default, sets an 800ms safety timeout, and the CSS rule `.kdna-rc-pending [data-kdna-show-in] { visibility: hidden; }`. The main `frontend.js` removes the class once visibility filtering completes. Use `has_widget_inner_wrapper()` correctly per Elementor Atomic conventions.

### Stage 6: Heading + Text Editor variants

**What this delivers:** Heading and Text Editor widgets gain a "Regional Content" repeater. All variants render to HTML, JS swaps visibility based on cookie.

**Prompt:**
> Read PROJECT-BRIEF.md, including section 3.5 (Anti-flicker). Build Stage 6: content variants for Heading and Text Editor widgets. Create `KDNA_RC_Variants_Base` abstract class and concrete `KDNA_RC_Heading_Extension`, `KDNA_RC_Text_Editor_Extension`. Each adds a "Regional Content" controls section to its widget via `elementor/element/{widget}/{section}/after_section_end` containing a repeater: Region (dropdown), Title (Heading) or Content (Text Editor), and for Heading also Link override. Render via `elementor/widget/render_content` filter: when variants exist, wrap the entire output in a `<div class="kdna-rc-variant-wrapper">` containing the original output as the default variant (visible, with `data-kdna-region="default"`), then append each variant as a sibling with the original HTML modified using DOMDocument (replace heading text and href, or replace inner content for text editor). Variants get `style="display:none"`, plus `lang` and `dir` attributes if set on the region. Extend the inline anti-flicker CSS from Stage 5 to also hide `.kdna-rc-variant-wrapper` during the pending state, so visitors who require a variant swap do not see the default flash before the swap. Update `frontend.js` to handle variant swap: when visitor's region matches a variant, hide the default and unhide the matching variant, then remove the `kdna-rc-pending` class. Test that the default variant is shown for visitors matching the default region with zero delay, returning visitors on non-default regions get an instant swap, and first-time non-default visitors get a brief hide-then-reveal capped at 800ms.

### Stage 7: Button + Image + Icon + Icon List variants

**What this delivers:** Remaining four widgets extended. Icon List uses the per-item visibility model (different from the others).

**Prompt:**
> Read PROJECT-BRIEF.md. Build Stage 7: extend Button, Image, Icon, and Icon List. For Button: variant repeater with Text, Link, Icon overrides. For Image: variant repeater with Image (media picker), Alt, Link overrides. For Icon: variant repeater with Icon picker and Link override. All three follow the Stage 6 pattern: original = default variant, variants appended as hidden siblings, JS swaps. For Icon List: do NOT use a variant repeater. Instead, add a "Restrict to Regions" multi-select control to each existing list item via the item-level repeater controls, and on render add `data-kdna-show-in` to each `<li>` so existing visibility JS handles it. Test all four widgets end-to-end with multiple regions configured.

### Stage 8: WP Rocket compatibility, polish, README

**What this delivers:** Cache-aware AJAX endpoint, admin notices, minor UX polish, full README with setup instructions.

**Prompt:**
> Read PROJECT-BRIEF.md. Build Stage 8: final polish. Auto-detect WP Rocket and, if active, programmatically add `kdna_rc_detect_region` to its excluded URLs (using `rocket_exclude_urls` filter or the appropriate WP Rocket API). Add admin notices for: missing license key, missing/outdated `.mmdb` (older than 60 days), no regions configured, no default region set. Add a "Clear All Caches" button on the Tools tab that clears WP Rocket cache + transients. Write a comprehensive README.md covering: installation, MaxMind account signup steps, license key setup, region configuration walkthrough, how to use variants in Elementor, how the `?region=XX` override works, WP Rocket integration notes, troubleshooting. Final pass: confirm all output is escaped, all input is sanitised, all AJAX uses nonces, no PHP warnings on activation/deactivation/uninstall, clean uninstall via `uninstall.php`.

### Stage 9: Final zip and deliver

**What this delivers:** Plugin packaged as a `.zip` ready for upload.

**Prompt:**
> Read PROJECT-BRIEF.md. Final stage: zip the plugin folder as `kdna-regional-content.zip`, excluding any `.git` folder, `node_modules`, dev files, and the `data/` folder contents (only keep `.gitkeep`). Verify the zip extracts cleanly into `wp-content/plugins/`. Update Current Build Status table in the brief to mark all stages complete. Output a final summary of what's been built, known limitations, and recommended next steps.

---

## 9. Current Build Status

Update this table between Claude Code sessions to maintain continuity.

| Stage | Status | Notes |
|---|---|---|
| 1. Scaffold + settings foundation | Complete | Activatable plugin scaffold, KDNA_RC_Plugin singleton, top-level menu with three tabs, MaxMind License Key field saving to `kdna_rc_settings` via Settings API. |
| 2. MaxMind library + DB download | Complete | Bundled MaxMind reader v1.11.1 (Apache 2.0), Tools tab status panel, Update Database Now AJAX button, monthly WP-Cron schedule with weekly/never options. |
| 3. Regions & Groups manager | Not started | |
| 4. Visitor detection + cookie | Not started | |
| 5. Visibility control on all widgets | Not started | |
| 6. Heading + Text Editor variants | Not started | |
| 7. Button + Image + Icon + Icon List | Not started | |
| 8. WP Rocket + polish + README | Not started | |
| 9. Final zip | Not started | |

---

## 10. Testing Checklist (final)

- [ ] Plugin activates and deactivates cleanly with no PHP warnings
- [ ] MaxMind license key saves correctly
- [ ] Database download succeeds and shows correct timestamp
- [ ] Auto-update WP-Cron event scheduled and runs
- [ ] Test Detection on Tools tab returns correct region for known IPs (e.g. `8.8.8.8` = US, `1.1.1.1` = AU)
- [ ] At least 3 regions configurable: Single country (e.g. AU), group (e.g. Europe = DE/FR/IT/ES/NL), default (e.g. Rest of World)
- [ ] Default Region selectable, used when visitor IP doesn't match any region
- [ ] `?region=AU` override works for admins (and all users when enabled)
- [ ] Cookie set correctly with configured lifetime
- [ ] Visibility control: widget restricted to "Australia" is hidden for visitors from US
- [ ] Visibility control works on widgets, sections, and containers
- [ ] Heading variant: editor sets two variants, correct one shows per region
- [ ] Text Editor, Button, Image, Icon variants all swap correctly
- [ ] Icon List per-item visibility filters items correctly
- [ ] Default region visitor sees zero hiding, zero delay (page renders normally on first and subsequent visits)
- [ ] Returning non-default-region visitor (cookie set) sees instant swap, no perceptible delay
- [ ] First-time non-default-region visitor sees brief hide-then-reveal (typically 100-300ms)
- [ ] If AJAX detection is artificially slowed or blocked, content reveals after 800ms safety timeout
- [ ] No layout jump or content shift when variants reveal (visibility: hidden preserves layout)
- [ ] WP Rocket active: cached pages still serve correct variants per visitor
- [ ] AJAX endpoint excluded from WP Rocket cache automatically
- [ ] `lang` and `dir` attributes applied on variant output when configured
- [ ] No JavaScript console errors
- [ ] No PHP warnings or notices anywhere
- [ ] Uninstall removes all options and cleans up upload folder

---

## 11. Key Decisions & Constraints (locked)

- **Geo service:** MaxMind GeoLite2 local database (free, no rate limits, monthly updates)
- **Cache strategy:** Render all variants, JS swap based on cookie
- **Anti-flicker:** Inline head script + `visibility: hidden` for pending state, 800ms safety timeout, default-region returning visitors see zero delay
- **Cookie lifetime:** 30 days default, admin-configurable
- **Granularity:** Country only (no state/city)
- **Override syntax:** `?region=XX` where XX is region slug
- **Default fallback:** Admin selectable
- **Test override audience:** Admins by default, optionally all visitors
- **Icon List model:** Per-item visibility (not full list variants)
- **Multilingual:** Same-language by default, expandable via `lang`/`dir` per region
- **No external runtime APIs** (everything local after initial MaxMind DB download)

---

## 12. KDNA Conventions

- All slugs, classes, hooks, option keys, data attributes prefixed `kdna-` or `KDNA_RC_`
- Text domain: `kdna-regional-content`
- UK English throughout (admin UI strings, code comments, README)
- No em dashes anywhere, use commas
- Elementor Atomic markup conventions: `has_widget_inner_wrapper()` returning false when `e_optimized_markup` is active, no reliance on `.elementor-widget-container`
- Sanitise all input, escape all output, nonces on all admin AJAX
- Comment every function with a plain-English description of what it does
- Plugin folder must be self-contained, no Composer required on host site
