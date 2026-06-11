/*
 * KDNA Regional Content : Region-switch banner.
 *
 * On first page load only, asks the visitor's IP-derived region from the
 * Stage 4 detector via the AJAX endpoint with `peek=1` (so the cookie is
 * not overwritten — important when the URL prefix already forced a
 * different region). Compares against the region implied by the current
 * URL. If they differ, the banner is shown with a polite "switch?"
 * prompt. Either button sets a 30-day cookie so the banner is shown at
 * most once per visitor per cookie lifetime.
 *
 * The "Yes" button rebuilds the URL by replacing the region prefix
 * segment (or inserting one when the URL has no prefix) and navigates
 * to it. Language prefixes are preserved when present.
 *
 * Never auto-redirects. Search engines and shared links land on the
 * URL they were sent to.
 */
( function () {
	'use strict';

	if ( typeof window.kdnaRC === 'undefined' || typeof window.kdnaRCRegionBanner === 'undefined' ) {
		return;
	}

	var cfg   = window.kdnaRC;
	var local = window.kdnaRCRegionBanner;

	function readCookie( name ) {
		var pattern = new RegExp( '(?:^|; )' + name.replace( /[.$?*|{}()[\]\\\/+^]/g, '\\$&' ) + '=([^;]*)' );
		var match   = document.cookie.match( pattern );
		return match ? decodeURIComponent( match[1] ) : '';
	}

	function writeCookie( name, value, days ) {
		var attrs = '; path=/; samesite=lax; max-age=' + ( 60 * 60 * 24 * ( days || 30 ) );
		if ( window.location.protocol === 'https:' ) { attrs += '; secure'; }
		document.cookie = name + '=' + encodeURIComponent( value ) + attrs;
	}

	function regionSlugs() {
		var out = [];
		if ( cfg.regions && cfg.regions.length ) {
			for ( var i = 0; i < cfg.regions.length; i++ ) {
				if ( cfg.regions[ i ].slug ) {
					out.push( String( cfg.regions[ i ].slug ).toLowerCase() );
				}
			}
		}
		return out;
	}

	function languageSlugs() {
		var out = [];
		if ( cfg.languages && cfg.languages.length ) {
			for ( var i = 0; i < cfg.languages.length; i++ ) {
				if ( cfg.languages[ i ].slug ) {
					out.push( String( cfg.languages[ i ].slug ).toLowerCase() );
				}
			}
		}
		return out;
	}

	function findRegion( slug ) {
		if ( ! slug || ! cfg.regions ) { return null; }
		slug = String( slug ).toLowerCase();
		for ( var i = 0; i < cfg.regions.length; i++ ) {
			if ( String( cfg.regions[ i ].slug ).toLowerCase() === slug ) {
				return cfg.regions[ i ];
			}
		}
		return null;
	}

	// Normalised WordPress install path (always begins and ends with /,
	// e.g. '/demo2/' for a subfolder install or '/' for a root install).
	// Used to strip the install prefix before splitting on slashes and to
	// re-prefix it when rebuilding switched URLs.
	function homePath() {
		var hp = ( cfg && cfg.homePath ) ? String( cfg.homePath ) : '/';
		if ( hp.charAt( 0 ) !== '/' ) { hp = '/' + hp; }
		if ( hp.charAt( hp.length - 1 ) !== '/' ) { hp += '/'; }
		return hp;
	}

	// Strip the WordPress install path from a pathname so the remaining
	// segments are relative to the home URL.
	function relativeToHome( pathname ) {
		var hp = homePath();
		if ( '/' === hp ) { return pathname; }
		if ( pathname.indexOf( hp ) === 0 ) {
			return '/' + pathname.slice( hp.length );
		}
		return pathname;
	}

	// Split the current URL path into its prefix and remainder. Returns
	// { region: 'au', language: 'fr', rest: 'about-us/' } where any
	// missing piece is an empty string. Strips the WordPress install
	// path (e.g. '/demo2/') first so subfolder installs work.
	function parseCurrentPath() {
		var path = relativeToHome( window.location.pathname || '/' );
		var trimmed = path.replace( /^\/+|\/+$/g, '' );
		var parts = trimmed.length ? trimmed.split( '/' ) : [];

		var regions = regionSlugs();
		var langs   = languageSlugs();

		var detectedRegion   = '';
		var detectedLanguage = '';

		if ( parts.length > 0 && regions.indexOf( parts[0].toLowerCase() ) !== -1 ) {
			detectedRegion = parts.shift().toLowerCase();
		}
		if ( parts.length > 0 && langs.indexOf( parts[0].toLowerCase() ) !== -1 ) {
			detectedLanguage = parts.shift().toLowerCase();
		}

		return {
			region:   detectedRegion,
			language: detectedLanguage,
			rest:     parts.join( '/' )
		};
	}

	// Build a URL on the same site but with the supplied region prefix.
	// Preserves any existing language prefix and the path's trailing
	// slash. Always preserves query string and hash. Re-prefixes the
	// WordPress install path so the URL stays inside the install.
	function buildSwitchedUrl( newRegionSlug ) {
		var parsed = parseCurrentPath();
		var segments = [];
		if ( newRegionSlug ) { segments.push( newRegionSlug ); }
		if ( parsed.language ) { segments.push( parsed.language ); }
		if ( parsed.rest ) { segments.push( parsed.rest ); }

		var newPath = homePath() + segments.join( '/' );
		// Mirror the trailing-slash behaviour of the current URL.
		if ( window.location.pathname.length > 1 && window.location.pathname.slice( -1 ) === '/' && newPath.slice( -1 ) !== '/' ) {
			newPath += '/';
		}
		return newPath + ( window.location.search || '' ) + ( window.location.hash || '' );
	}

	// Format the message template with the detected region name.
	function format( template, name ) {
		return String( template || '' ).replace( /\{region\}/g, name );
	}

	// Apply admin-configured styling via CSS custom properties on the
	// modal root, and inject the optional logo image. Each blank value
	// falls through to the CSS default so the form can leave most
	// fields empty.
	function applyStyles( el ) {
		var s = ( local && local.modalStyles ) ? local.modalStyles : {};
		var setVar = function ( name, value ) {
			if ( value !== undefined && value !== null && String( value ).length > 0 ) {
				el.style.setProperty( name, String( value ) );
			}
		};
		setVar( '--kdna-rc-banner-bg', s.modalBg );
		setVar( '--kdna-rc-banner-text', s.modalText );
		setVar( '--kdna-rc-banner-yes-bg', s.yesBg );
		setVar( '--kdna-rc-banner-yes-text', s.yesText );
		setVar( '--kdna-rc-banner-no-bg', s.noBg );
		setVar( '--kdna-rc-banner-no-text', s.noText );
		setVar( '--kdna-rc-banner-no-border', s.noBorder );
		if ( s.radius && /^\d+$/.test( String( s.radius ) ) ) {
			el.style.setProperty( '--kdna-rc-banner-radius', s.radius + 'px' );
		}
		if ( s.maxWidth && /^\d+$/.test( String( s.maxWidth ) ) ) {
			el.style.setProperty( '--kdna-rc-banner-max-width', s.maxWidth + 'px' );
		}

		if ( s.logoUrl ) {
			var inner = el.querySelector( '.kdna-rc-region-banner__inner' );
			if ( inner && ! inner.querySelector( '.kdna-rc-region-banner__logo' ) ) {
				var img = document.createElement( 'img' );
				img.className = 'kdna-rc-region-banner__logo';
				img.alt = '';
				img.src = String( s.logoUrl );
				var firstChild = inner.firstChild;
				inner.insertBefore( img, firstChild );
			}
		}
	}

	// Dismiss helper: set the seen cookie, hide the modal, and unbind
	// the document-level ESC listener so we are not leaking it.
	function dismiss( el, escHandler ) {
		writeCookie( local.seenCookie, '1', local.cookieDays || 30 );
		el.hidden = true;
		el.classList.remove( 'is-shown' );
		if ( escHandler ) {
			document.removeEventListener( 'keydown', escHandler );
		}
	}

	function showBanner( detected ) {
		var el = document.getElementById( 'kdna-rc-region-banner' );
		if ( ! el ) { return; }

		applyStyles( el );

		var message = el.querySelector( '.kdna-rc-region-banner__message' );
		var flag    = el.querySelector( '.kdna-rc-region-banner__flag' );
		var yesBtn  = el.querySelector( '.kdna-rc-region-banner__yes' );
		var noBtn   = el.querySelector( '.kdna-rc-region-banner__no' );
		var close   = el.querySelector( '.kdna-rc-region-banner__close' );

		if ( message ) { message.textContent = format( local.message, detected.name ); }

		// Flag rendering via flag-icons. The plugin does not store flag
		// codes on regions (regions are country GROUPS as well as single
		// countries), so we use the region's slug as a best-effort hint
		// — when the slug happens to match an ISO country code (au, nz,
		// us, uk → gb) the flag renders. Fallback: no flag.
		if ( flag ) {
			flag.className = 'kdna-rc-region-banner__flag';
			var hint = String( detected.slug || '' ).toLowerCase();
			if ( 'uk' === hint ) { hint = 'gb'; }
			if ( /^[a-z]{2}$/.test( hint ) ) {
				flag.classList.add( 'fi', 'fi-' + hint );
			}
		}

		// ESC key dismisses the modal. Bound at document level so it
		// works regardless of which child has focus. Removed by dismiss().
		var escHandler = function ( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				dismiss( el, escHandler );
			}
		};
		document.addEventListener( 'keydown', escHandler );

		if ( yesBtn ) {
			yesBtn.textContent = local.yesLabel;
			yesBtn.setAttribute( 'href', buildSwitchedUrl( detected.slug ) );
			yesBtn.addEventListener( 'click', function () {
				// Default navigation handles the actual switch; just set
				// the cookie so the modal does not re-appear after the
				// page reloads on the new region URL.
				writeCookie( local.seenCookie, '1', local.cookieDays || 30 );
				document.removeEventListener( 'keydown', escHandler );
			}, { once: true } );
		}

		if ( noBtn ) {
			noBtn.textContent = local.noLabel;
			noBtn.addEventListener( 'click', function () {
				dismiss( el, escHandler );
			}, { once: true } );
		}

		if ( close ) {
			close.addEventListener( 'click', function () {
				dismiss( el, escHandler );
			}, { once: true } );
		}

		// Backdrop click dismisses. e.target is the root backdrop only
		// when the visitor clicked outside the card; clicks inside the
		// card bubble up with a different target and are ignored.
		el.addEventListener( 'click', function ( e ) {
			if ( e.target === el ) {
				dismiss( el, escHandler );
			}
		} );

		el.hidden = false;
		el.classList.add( 'is-shown' );

		// Set the seen cookie immediately, not just on button click, so a
		// visitor who scrolls past the modal without interacting does
		// not see it again on the next page.
		writeCookie( local.seenCookie, '1', local.cookieDays || 30 );
	}

	function peekRegion( done ) {
		if ( ! cfg.ajaxUrl || ! local.peekAction ) { done( '' ); return; }
		try {
			var xhr = new XMLHttpRequest();
			xhr.open( 'GET', cfg.ajaxUrl + '?action=' + encodeURIComponent( local.peekAction ) + '&peek=1', true );
			xhr.timeout = 4000;
			xhr.onreadystatechange = function () {
				if ( xhr.readyState !== 4 ) { return; }
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					try {
						var json = JSON.parse( xhr.responseText );
						if ( json && json.success && json.data ) {
							done( String( json.data.slug || '' ).toLowerCase() );
							return;
						}
					} catch ( e ) {}
				}
				done( '' );
			};
			xhr.ontimeout = function () { done( '' ); };
			xhr.onerror = function () { done( '' ); };
			xhr.send();
		} catch ( err ) {
			done( '' );
		}
	}

	function start() {
		// Bail when no regions are configured: nothing to suggest.
		if ( regionSlugs().length === 0 ) { return; }

		// Already seen or dismissed.
		if ( readCookie( local.seenCookie ) ) { return; }

		peekRegion( function ( detectedSlug ) {
			if ( ! detectedSlug ) { return; }

			var parsed = parseCurrentPath();
			// Prefer the region the visitor is actually being shown:
			// URL prefix wins, then the kdna_region cookie (sticky from
			// a previous override or detection), then the configured
			// default. Keeps the banner in sync with the content-swap
			// layer so a traveler with a stale cookie still gets the
			// "switch?" prompt.
			var currentRegion = parsed.region || readCookie( 'kdna_region' ) || cfg.defaultRegion || '';

			// Same region: nothing to suggest.
			if ( detectedSlug === currentRegion.toLowerCase() ) { return; }

			// Detected region must be configured to be switchable.
			var region = findRegion( detectedSlug );
			if ( ! region ) { return; }

			showBanner( region );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start, { once: true } );
	} else {
		start();
	}
} )();
