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

	// Lightweight diagnostic logger. Quiet by default; enable with either:
	//   window.kdnaRCDebug = true (set before this script runs)  OR
	//   the URL parameter ?kdna_rc_debug=1
	// Output is prefixed with [KDNA RC] so it is easy to filter in DevTools.
	var DEBUG = !! window.kdnaRCDebug;
	try {
		if ( ! DEBUG && /[?&]kdna_rc_debug=1\b/.test( window.location.search ) ) {
			DEBUG = true;
		}
	} catch ( err ) {
		// Non-browser context; ignore.
	}
	function debug( msg, extra ) {
		if ( ! DEBUG || ! window.console || ! window.console.log ) {
			return;
		}
		if ( typeof extra === 'undefined' ) {
			window.console.log( '[KDNA RC] ' + msg );
		} else {
			window.console.log( '[KDNA RC] ' + msg, extra );
		}
	}

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

	// Hydrate data-kdna-show-in on listing items from the post-regions map
	// printed by KDNA_RC_Assets::print_restricted_posts_map().
	//
	// To survive the variety of listing widgets and custom templates in the
	// wild, look for each post via three independent strategies:
	//   1. .post-{id}                  - the standard post_class() class.
	//   2. .kdna-rc-post-{id}          - our own marker, added by both
	//                                    KDNA_RC_Post_Visibility::add_post_class
	//                                    and the JetEngine item-classes filter.
	//   3. [data-post-id="{id}"]       - some JetEngine and theme builders
	//                                    write this attribute on item wrappers.
	//
	// When a match is found inside a known grid wrapper, the attribute is
	// moved up to that wrapper so hiding it collapses the grid cell cleanly.
	// Otherwise the attribute is set on the matched element itself.
	function hydratePostVisibility() {
		var map = window.kdnaRCPostRegions || {};
		var keys = Object.keys( map );
		if ( keys.length === 0 ) {
			debug( 'no post-regions map (no posts have _kdna_rc_regions meta).' );
			return 0;
		}

		var WRAPPER_SELECTOR = '.jet-listing-grid__item, .e-loop-item, .elementor-post, .jet-listing-dynamic-post';
		var tagged = 0;

		for ( var i = 0; i < keys.length; i++ ) {
			var postId = keys[ i ];
			var slugs  = map[ postId ];
			if ( ! slugs || ! slugs.length ) {
				continue;
			}
			var value  = slugs.join( ',' );

			// Combine all three strategies into one query for fewer DOM passes.
			var nodes = document.querySelectorAll(
				'.post-' + postId +
				', .kdna-rc-post-' + postId +
				', [data-post-id="' + postId + '"]'
			);

			if ( nodes.length === 0 ) {
				debug( 'post ' + postId + ' has restrictions [' + value + '] but no matching DOM element on this page.' );
				continue;
			}

			for ( var j = 0; j < nodes.length; j++ ) {
				var node = nodes[ j ];
				var target = node.closest ? node.closest( WRAPPER_SELECTOR ) : null;
				var elem = target || node;
				if ( elem.getAttribute( ATTR ) === value ) {
					continue; // Already tagged with the same value.
				}
				elem.setAttribute( ATTR, value );
				tagged++;
				debug( 'tag post ' + postId + ' on ' + describeNode( elem ) + ' (matched ' + describeNode( node ) + ')', elem );
			}
		}

		debug( 'hydrated ' + tagged + ' element(s) from post-regions map.' );
		return tagged;
	}

	// Walk every element marked with data-kdna-show-in and hide those whose
	// allowed list does not include the visitor's region. We use display:none
	// so the surrounding layout reflows naturally (CSS Grid handles this
	// cleanly; flex layouts may need a small CSS tweak documented in the
	// brief). The element stays in the DOM so a subsequent region change
	// could in theory bring it back, but Stage 5 does not change the region
	// after first resolution.
	function describeNode( node ) {
		var tag = node.tagName ? node.tagName.toLowerCase() : '?';
		var cls = node.className && node.className.toString ? node.className.toString().split( /\s+/ ).slice( 0, 4 ).join( '.' ) : '';
		return tag + ( cls ? '.' + cls : '' );
	}

	function applyVisibilityFilter( region ) {
		var nodes = document.querySelectorAll( '[' + ATTR + ']' );
		var hidden = 0;
		var shown  = 0;
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var allowed = splitList( node.getAttribute( ATTR ) );
			if ( ! region || allowed.indexOf( region ) === -1 ) {
				node.style.display = 'none';
				node.setAttribute( 'data-kdna-rc-hidden', '1' );
				hidden++;
				debug( 'hide: ' + describeNode( node ) + ' allowed=[' + allowed.join( ',' ) + ']', node );
			} else {
				if ( node.getAttribute( 'data-kdna-rc-hidden' ) === '1' ) {
					node.style.display = '';
					node.removeAttribute( 'data-kdna-rc-hidden' );
				}
				shown++;
				debug( 'show: ' + describeNode( node ) + ' allowed=[' + allowed.join( ',' ) + ']', node );
			}
		}
		debug( 'visibility filter: region="' + ( region || '(none)' ) + '" matched=' + nodes.length + ' hidden=' + hidden + ' shown=' + shown + '.' );
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

	// Swap content variants based on the visitor's region.
	//
	// Each kdna-rc-variant-wrapper produced by the Stage 6 extensions holds:
	//   1. one .kdna-rc-default child (visible, data-kdna-region="default"),
	//   2. one or more sibling .kdna-rc-variant children with
	//      data-kdna-region="<slug>" and an inline display:none.
	//
	// When the visitor's region matches a sibling we hide the default and
	// reveal the matching variant. If nothing matches the default stays
	// visible. Cleared inline display so any variant style="display:none"
	// is reset to its computed value.
	function applyVariantSwap( region ) {
		var wrappers = document.querySelectorAll( '.kdna-rc-variant-wrapper' );
		for ( var i = 0; i < wrappers.length; i++ ) {
			var wrapper = wrappers[ i ];
			var defaultNode = wrapper.querySelector( '.kdna-rc-variant.kdna-rc-default' );
			var match = null;
			if ( region ) {
				match = wrapper.querySelector(
					'.kdna-rc-variant[data-kdna-region="' + region.replace( /"/g, '\\"' ) + '"]:not(.kdna-rc-default)'
				);
			}
			if ( match ) {
				if ( defaultNode ) {
					defaultNode.style.display = 'none';
				}
				match.style.display = '';
			} else if ( defaultNode ) {
				// Make sure the default is visible (it is by default, but a
				// previous swap on the same page could have hidden it).
				defaultNode.style.display = '';
			}
		}
	}

	// Run the full pipeline once the visitor's region is known.
	function applyForRegion( region ) {
		debug( 'resolved region: "' + ( region || '(empty)' ) + '". Default region: "' + ( cfg.defaultRegion || '(empty)' ) + '".' );
		debug( 'window.kdnaRCPostRegions =', window.kdnaRCPostRegions || {} );
		hydratePostVisibility();
		applyVisibilityFilter( region );
		applyVariantSwap( region );
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
