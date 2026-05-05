/*
 * KDNA Regional Content frontend script.
 *
 * Stage 5 responsibilities:
 *   - read the kdna_region cookie; if missing, fire AJAX to the public
 *     detector endpoint exposed via window.kdnaRC,
 *   - walk the DOM and hide every element with data-kdna-show-in whose
 *     value does not include the visitor's resolved region,
 *   - on single-post pages, read <meta name="kdna-rc-post-regions"> and
 *     redirect when the visitor is not allowed and the admin has chosen
 *     the redirect single-post mode,
 *   - clear the .kdna-rc-pending class on <html> once filtering is done
 *     so the anti-flicker style stops hiding the regional bits.
 *
 * The script is a single mechanism for Elementor widgets, sections,
 * containers, and JetEngine listing items because they all share the
 * same data-kdna-show-in attribute.
 */
( function () {
	'use strict';

	var cfg      = window.kdnaRC || {};
	var local    = window.kdnaRCFrontend || {};
	var COOKIE   = cfg.cookieName || 'kdna_region';
	var ATTR     = 'data-kdna-show-in';
	var META     = 'kdna-rc-post-regions';
	var PENDING  = 'kdna-rc-pending';
	var safetyTimer = null;

	// Read a cookie value by name, decoded. Returns '' when not set.
	function readCookie( name ) {
		var pattern = new RegExp( '(?:^|; )' + name.replace( /[.$?*|{}()[\]\\\/+^]/g, '\\$&' ) + '=([^;]*)' );
		var match   = document.cookie.match( pattern );
		return match ? decodeURIComponent( match[1] ) : '';
	}

	// Convert a comma-separated value list into an array of trimmed slugs.
	function splitList( value ) {
		if ( ! value ) {
			return [];
		}
		return String( value )
			.split( ',' )
			.map( function ( slug ) { return slug.trim(); } )
			.filter( Boolean );
	}

	// Walk every element marked with data-kdna-show-in and hide those whose
	// allowed list does not include the visitor's region. We use display:none
	// so the surrounding layout reflows naturally (CSS Grid handles this
	// cleanly; flex layouts may need a small CSS tweak documented in the
	// brief). The element stays in the DOM so a subsequent region change
	// could in theory bring it back, but Stage 5 does not change the region
	// after first resolution.
	function applyVisibilityFilter( region ) {
		var nodes = document.querySelectorAll( '[' + ATTR + ']' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var allowed = splitList( node.getAttribute( ATTR ) );
			if ( ! region || allowed.indexOf( region ) === -1 ) {
				node.style.display = 'none';
				node.setAttribute( 'data-kdna-rc-hidden', '1' );
			} else {
				if ( node.getAttribute( 'data-kdna-rc-hidden' ) === '1' ) {
					node.style.display = '';
					node.removeAttribute( 'data-kdna-rc-hidden' );
				}
			}
		}
	}

	// Single-post redirect: read <meta name="kdna-rc-post-regions"> and
	// redirect when the visitor's region is not in the list and the admin
	// has chosen the redirect mode.
	function applySinglePostPolicy( region ) {
		var meta = document.querySelector( 'meta[name="' + META + '"]' );
		if ( ! meta ) {
			return;
		}
		var allowed = splitList( meta.getAttribute( 'content' ) || '' );
		if ( allowed.length === 0 ) {
			return;
		}
		if ( region && allowed.indexOf( region ) !== -1 ) {
			return; // Allowed; nothing to do.
		}
		if ( local.singleMode === 'redirect' && local.redirectUrl ) {
			// Avoid redirect loops: only redirect when we are not already on the target URL.
			try {
				var target = new URL( local.redirectUrl, window.location.origin );
				if ( target.href !== window.location.href ) {
					window.location.replace( target.href );
					return;
				}
			} catch ( err ) {
				if ( local.redirectUrl !== window.location.href ) {
					window.location.replace( local.redirectUrl );
					return;
				}
			}
		}
		// Otherwise show the page as-is (default Show anyway behaviour).
	}

	function clearPending() {
		document.documentElement.classList.remove( PENDING );
		if ( safetyTimer ) {
			window.clearTimeout( safetyTimer );
			safetyTimer = null;
		}
	}

	// Run the full pipeline once the visitor's region is known.
	function applyForRegion( region ) {
		applyVisibilityFilter( region );
		applySinglePostPolicy( region );
		clearPending();
	}

	// Detect the visitor's region by hitting the public AJAX endpoint. The
	// endpoint sets the cookie server-side so subsequent visits skip this
	// round trip entirely.
	function fetchRegion( done ) {
		if ( ! cfg.ajaxUrl || ! cfg.detectAction ) {
			done( '' );
			return;
		}

		var url = cfg.ajaxUrl + '?action=' + encodeURIComponent( cfg.detectAction );
		var xhr = new XMLHttpRequest();
		xhr.open( 'GET', url, true );
		xhr.timeout = 4000;
		xhr.onreadystatechange = function () {
			if ( xhr.readyState !== 4 ) {
				return;
			}
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				try {
					var json = JSON.parse( xhr.responseText );
					if ( json && json.success && json.data ) {
						done( json.data.slug || '' );
						return;
					}
				} catch ( e ) {
					// Fall through.
				}
			}
			done( '' );
		};
		xhr.ontimeout = function () { done( '' ); };
		xhr.onerror = function () { done( '' ); };
		try {
			xhr.send();
		} catch ( e ) {
			done( '' );
		}
	}

	// Re-arm a local safety timer in addition to the inline-script one so
	// long-running fetches still surface content rather than wait forever.
	safetyTimer = window.setTimeout( clearPending, 1500 );

	function start() {
		var cookieValue = readCookie( COOKIE );
		if ( cookieValue ) {
			applyForRegion( cookieValue );
			return;
		}

		fetchRegion( function ( slug ) {
			applyForRegion( slug || cfg.defaultRegion || '' );
		} );
	}

	// Run as soon as the DOM has parsed. The script is enqueued with
	// strategy:defer so this fires before DOMContentLoaded on most browsers,
	// but we listen anyway in case strategy:defer is not honoured.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start, { once: true } );
	} else {
		start();
	}
} )();
