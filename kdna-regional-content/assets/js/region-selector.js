/*
 * KDNA Regional Content : Region Selector widget runtime.
 *
 * Two jobs:
 *   1. Accessible dropdown behaviour (ARIA listbox pattern) — open/close,
 *      keyboard navigation, click-outside-to-close, focus management.
 *      Trigger toggles the panel; Enter/Space/Arrow keys open + move;
 *      Escape closes and returns focus to the trigger.
 *   2. Href rewrite on load. The widget renders hrefs server-side from
 *      REQUEST_URI, which is correct for the page being served. On sites
 *      that fragment-cache the header separately, the cached header can
 *      end up carrying hrefs for whatever page was in the cache when it
 *      was captured. Re-computing hrefs from window.location.pathname
 *      on load guarantees "switch to NZ" always lands on the NZ version
 *      of the page the visitor is actually looking at.
 *
 * Navigation itself is just a link click — no AJAX, no cookie writes.
 * The server picks up the region from the URL prefix and syncs the
 * kdna_region cookie via KDNA_RC_URL_Routing::sync_prefix_cookies().
 */
( function () {
	'use strict';

	var cfg = window.kdnaRC || {};

	function each( list, fn ) {
		if ( ! list ) { return; }
		for ( var i = 0; i < list.length; i++ ) { fn( list[ i ], i ); }
	}

	function slugList ( source ) {
		var out = [];
		if ( source && source.length ) {
			for ( var i = 0; i < source.length; i++ ) {
				if ( source[ i ] && source[ i ].slug ) {
					out.push( String( source[ i ].slug ).toLowerCase() );
				}
			}
		}
		return out;
	}

	var regionSlugs   = slugList( cfg.regions );
	var languageSlugs = slugList( cfg.languages );

	var homePath = ( cfg && cfg.homePath ) ? String( cfg.homePath ) : '/';
	if ( homePath.charAt( 0 ) !== '/' ) { homePath = '/' + homePath; }
	if ( homePath.charAt( homePath.length - 1 ) !== '/' ) { homePath += '/'; }

	function stripHome ( pathname ) {
		if ( '/' === homePath ) { return pathname; }
		if ( pathname.indexOf( homePath ) === 0 ) {
			return '/' + pathname.slice( homePath.length );
		}
		return pathname;
	}

	function buildTargetUrl ( targetSlug ) {
		var pathname = window.location.pathname;
		var relative = stripHome( pathname );
		var trimmed  = relative.replace( /^\/+|\/+$/g, '' );
		var parts    = trimmed.length ? trimmed.split( '/' ) : [];

		if ( parts.length > 0 && regionSlugs.indexOf( parts[0].toLowerCase() ) !== -1 ) {
			parts.shift();
		}
		var lang = '';
		if ( parts.length > 0 && languageSlugs.indexOf( parts[0].toLowerCase() ) !== -1 ) {
			lang = parts.shift().toLowerCase();
		}

		var segments = [ targetSlug ];
		if ( lang ) { segments.push( lang ); }
		for ( var i = 0; i < parts.length; i++ ) {
			if ( parts[ i ] ) { segments.push( parts[ i ] ); }
		}

		var rebuilt = homePath + segments.join( '/' );
		if ( pathname.length > 1 && pathname.slice( -1 ) === '/' && rebuilt.slice( -1 ) !== '/' ) {
			rebuilt += '/';
		}
		return rebuilt + window.location.search + window.location.hash;
	}

	function rewriteHrefs ( root ) {
		each( root.querySelectorAll( '.kdna-rc-rs-option' ), function ( opt ) {
			var slug = opt.getAttribute( 'data-slug' );
			var link = opt.querySelector( '.kdna-rc-rs-link' );
			if ( slug && link && regionSlugs.length ) {
				link.setAttribute( 'href', buildTargetUrl( slug ) );
			}
		} );
	}

	// Flip the panel to right-align if opening at its natural
	// left-aligned position would overflow the viewport. Runs after
	// unhiding so the panel actually has layout measurements. Also
	// handles the reverse case: if right-alignment was left over from
	// a previous open and there's now room to left-align, remove the
	// class. Called on open and on resize while a panel is open.
	function adjustAlignment ( root ) {
		var panel = root.querySelector( '.kdna-rc-rs-panel' );
		if ( ! panel ) { return; }
		// Reset first so we measure the natural left-aligned position.
		root.classList.remove( 'kdna-rc-rs--align-end' );
		var rect     = panel.getBoundingClientRect();
		var viewport = window.innerWidth || document.documentElement.clientWidth;
		if ( rect.right > viewport - 4 ) {
			root.classList.add( 'kdna-rc-rs--align-end' );
		}
	}

	function openPanel ( root ) {
		var trigger = root.querySelector( '.kdna-rc-rs-trigger' );
		var panel   = root.querySelector( '.kdna-rc-rs-panel' );
		if ( ! trigger || ! panel ) { return; }
		root.classList.add( 'is-open' );
		trigger.setAttribute( 'aria-expanded', 'true' );
		panel.removeAttribute( 'hidden' );
		adjustAlignment( root );
	}

	function closePanel ( root, focusTrigger ) {
		var trigger = root.querySelector( '.kdna-rc-rs-trigger' );
		var panel   = root.querySelector( '.kdna-rc-rs-panel' );
		if ( ! trigger || ! panel ) { return; }
		root.classList.remove( 'is-open' );
		trigger.setAttribute( 'aria-expanded', 'false' );
		panel.setAttribute( 'hidden', '' );
		if ( focusTrigger ) { trigger.focus(); }
	}

	function togglePanel ( root ) {
		if ( root.classList.contains( 'is-open' ) ) {
			closePanel( root, true );
		} else {
			openPanel( root );
		}
	}

	function initInstance ( root ) {
		rewriteHrefs( root );

		var trigger = root.querySelector( '.kdna-rc-rs-trigger' );
		if ( ! trigger ) { return; }

		trigger.addEventListener( 'click', function () { togglePanel( root ); } );

		trigger.addEventListener( 'keydown', function ( event ) {
			var key = event.key;
			if ( 'Enter' === key || ' ' === key || 'ArrowDown' === key || 'ArrowUp' === key ) {
				event.preventDefault();
				openPanel( root );
				var firstLink = root.querySelector( '.kdna-rc-rs-option .kdna-rc-rs-link' );
				if ( firstLink ) { firstLink.focus(); }
			}
		} );

		each( root.querySelectorAll( '.kdna-rc-rs-option .kdna-rc-rs-link' ), function ( link ) {
			link.addEventListener( 'keydown', function ( event ) {
				var key = event.key;
				var links = root.querySelectorAll( '.kdna-rc-rs-option .kdna-rc-rs-link' );
				var idx   = -1;
				for ( var i = 0; i < links.length; i++ ) {
					if ( links[ i ] === document.activeElement ) { idx = i; break; }
				}
				if ( 'Escape' === key ) {
					event.preventDefault();
					closePanel( root, true );
				} else if ( 'ArrowDown' === key && idx > -1 && idx + 1 < links.length ) {
					event.preventDefault();
					links[ idx + 1 ].focus();
				} else if ( 'ArrowUp' === key && idx > 0 ) {
					event.preventDefault();
					links[ idx - 1 ].focus();
				} else if ( 'Home' === key ) {
					event.preventDefault();
					if ( links.length ) { links[ 0 ].focus(); }
				} else if ( 'End' === key ) {
					event.preventDefault();
					if ( links.length ) { links[ links.length - 1 ].focus(); }
				}
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! root.contains( event.target ) ) {
				closePanel( root, false );
			}
		} );

		// Re-check alignment while open on viewport size changes so a
		// visitor rotating a phone or resizing a window doesn't end up
		// with a panel that clips off the new edge.
		window.addEventListener( 'resize', function () {
			if ( root.classList.contains( 'is-open' ) ) {
				adjustAlignment( root );
			}
		} );
	}

	function start () {
		each( document.querySelectorAll( '.kdna-rc-rs' ), initInstance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start, { once: true } );
	} else {
		start();
	}
} )();
