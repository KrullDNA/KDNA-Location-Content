/*
 * KDNA Regional Content : Multilingual JetEngine field admin runtime.
 *
 * Lightweight, pure JS (no AJAX, no page reload). For each
 * .kdna-rc-mlf-editor it:
 *   - wires tablist keyboard nav (arrow keys, Home/End),
 *   - toggles which panel is visible when a tab is clicked,
 *   - keeps WYSIWYG editor state intact across switches by hiding /
 *     showing wrappers rather than calling .destroy(),
 *   - drives the WordPress media library for Image-field tabs.
 */
( function ( $ ) {
	'use strict';

	function each( list, fn ) {
		if ( ! list ) { return; }
		for ( var i = 0; i < list.length; i++ ) { fn( list[ i ], i ); }
	}

	function activateTab( editorRoot, tabKey ) {
		each( editorRoot.querySelectorAll( '.kdna-rc-mlf-tab' ), function ( tab ) {
			var match = tab.getAttribute( 'data-tab' ) === tabKey;
			tab.classList.toggle( 'is-active', match );
			tab.setAttribute( 'aria-selected', match ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', match ? '0' : '-1' );
		} );
		each( editorRoot.querySelectorAll( '.kdna-rc-mlf-panel' ), function ( panel ) {
			var match = panel.getAttribute( 'data-tab' ) === tabKey;
			panel.hidden = ! match;
		} );
	}

	function refreshStatusDot( editorRoot, tabKey, isFilled ) {
		var tab = editorRoot.querySelector( '.kdna-rc-mlf-tab[data-tab="' + tabKey + '"]' );
		if ( ! tab ) { return; }
		var dot = tab.querySelector( '.kdna-rc-mlf-tab-status' );
		if ( ! dot ) { return; }
		dot.classList.toggle( 'is-filled', isFilled );
		dot.classList.toggle( 'is-empty', ! isFilled );
	}

	// Bind keyboard navigation across the tablist for one editor.
	function bindTablist( editorRoot ) {
		var tabs = editorRoot.querySelectorAll( '.kdna-rc-mlf-tab' );

		each( tabs, function ( tab, idx ) {
			tab.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				activateTab( editorRoot, tab.getAttribute( 'data-tab' ) );
				tab.focus();
			} );

			tab.addEventListener( 'keydown', function ( ev ) {
				var nextIdx = -1;
				switch ( ev.key ) {
					case 'ArrowRight':
					case 'ArrowDown':
						nextIdx = ( idx + 1 ) % tabs.length;
						break;
					case 'ArrowLeft':
					case 'ArrowUp':
						nextIdx = ( idx - 1 + tabs.length ) % tabs.length;
						break;
					case 'Home':
						nextIdx = 0;
						break;
					case 'End':
						nextIdx = tabs.length - 1;
						break;
				}
				if ( nextIdx === -1 ) { return; }
				ev.preventDefault();
				var nextTab = tabs[ nextIdx ];
				activateTab( editorRoot, nextTab.getAttribute( 'data-tab' ) );
				nextTab.focus();
			} );
		} );
	}

	// Wire WordPress media library for one Image-field picker.
	function bindImagePicker( picker ) {
		if ( typeof wp === 'undefined' || ! wp.media ) { return; }

		var chooseBtn = picker.querySelector( '.kdna-rc-mlf-image-choose' );
		var removeBtn = picker.querySelector( '.kdna-rc-mlf-image-remove' );
		var hiddenId  = picker.querySelector( '.kdna-rc-mlf-image-id' );
		var preview   = picker.querySelector( '.kdna-rc-mlf-image-preview' );
		if ( ! chooseBtn || ! hiddenId || ! preview ) { return; }

		var frame = null;

		chooseBtn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: 'Choose Image',
					button: { text: 'Use this image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var thumbUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					hiddenId.value = String( attachment.id );
					preview.innerHTML = '<img src="' + thumbUrl + '" alt="" />';
					picker.classList.add( 'has-image' );
					if ( removeBtn ) { removeBtn.style.display = ''; }

					var editorRoot = picker.closest( '.kdna-rc-mlf-editor' );
					var panel      = picker.closest( '.kdna-rc-mlf-panel' );
					if ( editorRoot && panel ) {
						refreshStatusDot( editorRoot, panel.getAttribute( 'data-tab' ), true );
					}
				} );
			}
			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				hiddenId.value = '';
				preview.innerHTML = '<span class="kdna-rc-mlf-image-empty">No image selected</span>';
				picker.classList.remove( 'has-image' );
				removeBtn.style.display = 'none';

				var editorRoot = picker.closest( '.kdna-rc-mlf-editor' );
				var panel      = picker.closest( '.kdna-rc-mlf-panel' );
				if ( editorRoot && panel ) {
					refreshStatusDot( editorRoot, panel.getAttribute( 'data-tab' ), false );
				}
			} );
		}
	}

	// For text fields: keep the completion dot in sync as the editor types.
	function bindTextStatus( editorRoot ) {
		each( editorRoot.querySelectorAll( '.kdna-rc-mlf-input' ), function ( input ) {
			input.addEventListener( 'input', function () {
				var panel = input.closest( '.kdna-rc-mlf-panel' );
				if ( ! panel ) { return; }
				refreshStatusDot( editorRoot, panel.getAttribute( 'data-tab' ), input.value.trim() !== '' );
			} );
		} );
	}

	function bootstrap() {
		each( document.querySelectorAll( '.kdna-rc-mlf-editor' ), function ( editorRoot ) {
			if ( editorRoot.dataset.kdnaBound === '1' ) { return; }
			editorRoot.dataset.kdnaBound = '1';
			bindTablist( editorRoot );
			bindTextStatus( editorRoot );
			each( editorRoot.querySelectorAll( '.kdna-rc-mlf-image-picker' ), bindImagePicker );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootstrap, { once: true } );
	} else {
		bootstrap();
	}

	// Re-bind when JetEngine re-renders meta-box rows dynamically.
	if ( typeof $ === 'function' ) {
		$( document ).on( 'kdna-rc-rebind-mlf', bootstrap );
		$( document ).on( 'jet-engine/meta-fields/rendered', bootstrap );
	}
} )( typeof jQuery !== 'undefined' ? jQuery : null );
