/*
 * KDNA Regional Content : Language Selector widget runtime.
 *
 * Implements an accessible combobox (ARIA listbox pattern) for every
 * .kdna-rc-ls element on the page. Drives the Stage 10 kdna_rc_set_language
 * AJAX endpoint when the user picks a language, then either reloads the
 * page or triggers the in-page variant swap based on the widget's
 * data-on-select attribute.
 *
 * Keyboard shortcuts when the panel is closed:
 *   Enter / Space / ArrowDown / ArrowUp : open the panel.
 * When the panel is open:
 *   ArrowDown / ArrowUp : move active option.
 *   Home / End          : first / last option.
 *   Enter / Space       : commit active option.
 *   Escape              : close the panel and return focus to the trigger.
 *   Tab                 : close the panel (focus follows the natural tab order).
 *
 * Pointer interactions outside the open panel close it. A live region
 * announces the current language for screen readers.
 */
( function () {
	'use strict';

	var cfg = window.kdnaRC || {};
	var instances = [];

	function each( list, fn ) {
		if ( ! list ) { return; }
		for ( var i = 0; i < list.length; i++ ) { fn( list[ i ], i ); }
	}

	function findIndex( arr, predicate ) {
		for ( var i = 0; i < arr.length; i++ ) {
			if ( predicate( arr[ i ], i ) ) { return i; }
		}
		return -1;
	}

	// Update the trigger label after a live swap so the displayed flag
	// and text match the freshly chosen language without a server round
	// trip. Mirrors the option markup.
	function updateTriggerLabel( root, slug, name, flag ) {
		var trigger = root.querySelector( '.kdna-rc-ls-trigger' );
		if ( ! trigger ) { return; }

		var flagSpan = trigger.querySelector( '.kdna-rc-ls-flag' );
		if ( flagSpan ) {
			flagSpan.className = 'kdna-rc-ls-flag' + ( flag ? ' fi fi-' + flag : '' );
		}
		var labelSpan = trigger.querySelector( '.kdna-rc-ls-label' );
		if ( labelSpan && name ) {
			labelSpan.textContent = name;
		}
		root.setAttribute( 'data-current', slug );

		// Mark the matching option as selected.
		each( root.querySelectorAll( '.kdna-rc-ls-option' ), function ( opt ) {
			var matches = opt.getAttribute( 'data-slug' ) === slug;
			opt.setAttribute( 'aria-selected', matches ? 'true' : 'false' );
			opt.classList.toggle( 'is-active', matches );
		} );
	}

	// Send the language choice to the kdna_rc_set_language endpoint built
	// in Stage 10. Resolves once the cookie is set so callers can safely
	// trigger a live swap or a reload after the response.
	function commitLanguage( slug, done ) {
		if ( ! cfg.ajaxUrl || ! cfg.setLanguageAction || ! cfg.setLanguageNonce ) {
			done( false );
			return;
		}

		try {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', cfg.ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
			xhr.timeout = 4000;
			xhr.onreadystatechange = function () {
				if ( xhr.readyState !== 4 ) { return; }
				done( xhr.status >= 200 && xhr.status < 300 );
			};
			xhr.ontimeout = function () { done( false ); };
			xhr.onerror = function () { done( false ); };
			xhr.send(
				'action=' + encodeURIComponent( cfg.setLanguageAction ) +
				'&nonce=' + encodeURIComponent( cfg.setLanguageNonce ) +
				'&slug=' + encodeURIComponent( slug )
			);
		} catch ( err ) {
			done( false );
		}
	}

	function selectLanguage( instance, optionEl ) {
		var slug = optionEl.getAttribute( 'data-slug' );
		var name = optionEl.getAttribute( 'data-name' ) || optionEl.querySelector( '.kdna-rc-ls-label' ) && optionEl.querySelector( '.kdna-rc-ls-label' ).textContent;
		var flag = optionEl.getAttribute( 'data-flag' ) || '';
		if ( ! slug ) { return; }

		var mode = instance.root.getAttribute( 'data-on-select' ) || 'reload';

		closePanel( instance );

		commitLanguage( slug, function () {
			updateTriggerLabel( instance.root, slug, name, flag );

			if ( mode === 'reload' ) {
				window.location.reload();
				return;
			}

			// Live swap path: re-run the variant pass on every wrapper using
			// the new language. The function lives in frontend.js; bail soft
			// if it is not available (e.g. asset stripped by some optimiser).
			if ( typeof window.kdnaRCRefreshLanguage === 'function' ) {
				window.kdnaRCRefreshLanguage( slug );
			}

			// Custom DOM event so third-party scripts can react.
			try {
				var ev = new CustomEvent( 'kdna-rc-language-changed', { detail: { slug: slug, name: name, flag: flag } } );
				document.dispatchEvent( ev );
			} catch ( err ) {
				// IE / very old browsers: skip silently.
			}
		} );
	}

	function openPanel( instance ) {
		if ( instance.open ) { return; }
		instance.open = true;
		instance.trigger.setAttribute( 'aria-expanded', 'true' );
		instance.panel.hidden = false;
		instance.root.classList.add( 'is-open' );

		// Default focus on the currently selected option, or the first.
		var selectedIdx = findIndex( instance.options, function ( o ) {
			return o.getAttribute( 'aria-selected' ) === 'true';
		} );
		setActive( instance, selectedIdx >= 0 ? selectedIdx : 0 );
	}

	function closePanel( instance ) {
		if ( ! instance.open ) { return; }
		instance.open = false;
		instance.trigger.setAttribute( 'aria-expanded', 'false' );
		instance.panel.hidden = true;
		instance.root.classList.remove( 'is-open' );
		// Drop highlight; selection state is tracked separately via aria-selected.
		each( instance.options, function ( o ) { o.classList.remove( 'is-active' ); } );
	}

	function setActive( instance, idx ) {
		if ( idx < 0 || idx >= instance.options.length ) { return; }
		each( instance.options, function ( o ) { o.classList.remove( 'is-active' ); } );
		var opt = instance.options[ idx ];
		opt.classList.add( 'is-active' );
		opt.focus();
	}

	function activeIndex( instance ) {
		return findIndex( instance.options, function ( o ) {
			return o.classList.contains( 'is-active' ) || document.activeElement === o;
		} );
	}

	function bindInstance( root ) {
		var trigger = root.querySelector( '.kdna-rc-ls-trigger' );
		var panel   = root.querySelector( '.kdna-rc-ls-panel' );
		if ( ! trigger || ! panel ) { return; }

		var instance = {
			root: root,
			trigger: trigger,
			panel: panel,
			options: panel.querySelectorAll( '.kdna-rc-ls-option' ),
			open: false
		};
		instances.push( instance );

		trigger.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			if ( instance.open ) { closePanel( instance ); } else { openPanel( instance ); }
		} );

		trigger.addEventListener( 'keydown', function ( ev ) {
			switch ( ev.key ) {
				case 'ArrowDown':
				case 'ArrowUp':
				case 'Enter':
				case ' ':
					ev.preventDefault();
					openPanel( instance );
					break;
				case 'Escape':
					closePanel( instance );
					break;
			}
		} );

		each( instance.options, function ( option, idx ) {
			option.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				selectLanguage( instance, option );
			} );

			option.addEventListener( 'keydown', function ( ev ) {
				switch ( ev.key ) {
					case 'ArrowDown':
						ev.preventDefault();
						setActive( instance, Math.min( idx + 1, instance.options.length - 1 ) );
						break;
					case 'ArrowUp':
						ev.preventDefault();
						setActive( instance, Math.max( idx - 1, 0 ) );
						break;
					case 'Home':
						ev.preventDefault();
						setActive( instance, 0 );
						break;
					case 'End':
						ev.preventDefault();
						setActive( instance, instance.options.length - 1 );
						break;
					case 'Enter':
					case ' ':
						ev.preventDefault();
						selectLanguage( instance, option );
						break;
					case 'Escape':
						ev.preventDefault();
						closePanel( instance );
						trigger.focus();
						break;
					case 'Tab':
						closePanel( instance );
						break;
				}
			} );
		} );
	}

	// Single document-level listeners so multiple instances on the same
	// page do not stack overlapping handlers.
	document.addEventListener( 'click', function ( ev ) {
		each( instances, function ( instance ) {
			if ( ! instance.open ) { return; }
			if ( ! instance.root.contains( ev.target ) ) {
				closePanel( instance );
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( ev ) {
		if ( ev.key !== 'Escape' ) { return; }
		each( instances, function ( instance ) {
			if ( instance.open ) {
				closePanel( instance );
				instance.trigger.focus();
			}
		} );
	} );

	function bootstrap() {
		each( document.querySelectorAll( '.kdna-rc-ls' ), bindInstance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootstrap, { once: true } );
	} else {
		bootstrap();
	}

	// Re-bind dynamically inserted instances (used by Elementor preview when
	// the editor re-renders the widget without a full page reload).
	document.addEventListener( 'kdna-rc-rebind-language-selector', bootstrap );
} )();
