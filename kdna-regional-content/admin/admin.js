/*
 * KDNA Regional Content admin scripts.
 *
 * Drives:
 *   Tools tab : Update Database Now AJAX button.
 *   Regions tab : drag-to-reorder list, slug auto-generation, country picker
 *                 with search, and AJAX save / delete / reorder against the
 *                 KDNA_RC_Regions handler.
 *
 * All AJAX actions share the kdna_rc_admin nonce localised through
 * window.kdnaRCAdmin and check_ajax_referer() on the server side.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.kdnaRCAdmin === 'undefined' ) {
		return;
	}

	var config = window.kdnaRCAdmin;

	// =====================================================================
	// Tools tab : Update Database Now
	// =====================================================================

	function bindUpdateDatabaseButton() {
		var $button = $( '#kdna-rc-update-db' );
		if ( ! $button.length ) {
			return;
		}

		var $actions = $button.closest( '.kdna-rc-actions' );
		var $spinner = $actions.find( '.kdna-rc-spinner' );
		var $message = $actions.find( '.kdna-rc-update-message' );

		function setStatusCell( field, value ) {
			var cell = document.querySelector( '#kdna-rc-db-status [data-field="' + field + '"]' );
			if ( ! cell ) {
				return;
			}
			cell.textContent = value;
		}

		function renderDbStatus( status ) {
			if ( ! status ) {
				return;
			}
			setStatusCell( 'last_updated_human', status.last_updated_human || '' );
			setStatusCell( 'file_size_human', status.file_size_human || '' );
			setStatusCell( 'database_type', ( status.metadata && status.metadata.database_type ) || '' );
			setStatusCell( 'ip_version', status.metadata && status.metadata.ip_version
				? 'IPv' + status.metadata.ip_version
				: '' );

			var existsCell = document.querySelector( '#kdna-rc-db-status [data-field="exists"]' );
			if ( existsCell ) {
				existsCell.innerHTML = '';
				var pill = document.createElement( 'span' );
				pill.className = 'kdna-rc-status-pill ' + ( status.exists ? 'is-ok' : 'is-warn' );
				pill.textContent = status.exists ? 'Yes' : 'No';
				existsCell.appendChild( pill );
			}
		}

		$button.on( 'click', function ( event ) {
			event.preventDefault();

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$message
				.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' )
				.text( config.i18n.updating );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 180000,
				data: {
					action: config.actions.updateDatabase,
					nonce: config.nonce
				}
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$message
							.addClass( 'kdna-rc-msg-ok' )
							.text( ( response.data && response.data.message ) || config.i18n.success );
						renderDbStatus( response.data && response.data.status );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						$message.addClass( 'kdna-rc-msg-error' ).text( err );
						if ( response && response.data && response.data.status ) {
							renderDbStatus( response.data.status );
						}
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					$message.addClass( 'kdna-rc-msg-error' ).text( msg );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	}

	// =====================================================================
	// Regions tab : list, drag-reorder, inline edit, country picker, AJAX
	// =====================================================================

	function slugify( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	function format( template, value ) {
		return String( template || '' ).replace( '%d', value );
	}

	// Build the list of country checkboxes inside an editor's picker. Called
	// on first open and again any time the country selection or type changes.
	function renderCountryPicker( $picker, search ) {
		var $list    = $picker.find( '.kdna-rc-country-list' );
		var selected = ( $picker.attr( 'data-selected' ) || '' )
			.split( ',' )
			.map( function ( c ) { return c.trim().toUpperCase(); } )
			.filter( Boolean );
		var selectedSet = {};
		selected.forEach( function ( c ) { selectedSet[ c ] = true; } );

		var typeRadio = $picker.closest( '.kdna-rc-region-editor' ).find( '.kdna-rc-input-type:checked' );
		var isSingle  = typeRadio.length ? typeRadio.val() === 'single' : false;

		var query = ( search || '' ).toLowerCase().trim();
		var html  = '';
		var any   = false;

		( config.countries || [] ).forEach( function ( country ) {
			if ( query && country.name.toLowerCase().indexOf( query ) === -1 && country.code.toLowerCase().indexOf( query ) === -1 ) {
				return;
			}
			any = true;
			var checked = selectedSet[ country.code ] ? ' checked' : '';
			var inputType = isSingle ? 'radio' : 'checkbox';
			html += '<label class="kdna-rc-country-option' + ( checked ? ' is-selected' : '' ) + '">' +
				'<input type="' + inputType + '" name="kdna-rc-country" value="' + country.code + '"' + checked + ' />' +
				'<span class="kdna-rc-country-name">' + country.name + '</span>' +
				'<span class="kdna-rc-country-code">' + country.code + '</span>' +
				'</label>';
		} );

		if ( ! any ) {
			html = '<p class="kdna-rc-country-empty">' + config.i18n.noResults + '</p>';
		}

		$list.html( html );
	}

	function getEditorPayload( $editor ) {
		var $picker   = $editor.find( '.kdna-rc-country-picker' );
		var selected  = ( $picker.attr( 'data-selected' ) || '' )
			.split( ',' )
			.map( function ( c ) { return c.trim().toUpperCase(); } )
			.filter( Boolean );

		// Stage 10: per-region default language. The select is only present
		// when at least one language is configured; default to '' so the
		// payload shape stays predictable on language-less sites.
		var $defaultLang = $editor.find( '.kdna-rc-input-default-language' );

		return {
			name:             $editor.find( '.kdna-rc-input-name' ).val().trim(),
			slug:             $editor.find( '.kdna-rc-input-slug' ).val().trim(),
			type:             $editor.find( '.kdna-rc-input-type:checked' ).val() || 'single',
			countries:        selected,
			language:         $editor.find( '.kdna-rc-input-language' ).val().trim(),
			direction:        $editor.find( '.kdna-rc-input-direction:checked' ).val() || 'ltr',
			default_language: $defaultLang.length ? ( $defaultLang.val() || '' ) : ''
		};
	}

	function updateRowSummary( $row, payload ) {
		var $heading = $row.find( '.kdna-rc-region-name' );
		var $slug    = $row.find( '.kdna-rc-region-slug' );
		var $meta    = $row.find( '.kdna-rc-region-meta' );

		$heading.text( payload.name || config.i18n.untitledRegion );
		$slug.text( payload.slug || '' );

		if ( payload.type === 'group' ) {
			var template = payload.countries.length === 1
				? config.i18n.groupSummaryOne
				: config.i18n.groupSummaryMany;
			$meta.text( format( template, payload.countries.length ) );
		} else {
			$meta.text( config.i18n.singleSummary );
		}
	}

	function showFormMessage( $editor, message, isError ) {
		var $msg = $editor.find( '.kdna-rc-form-message' );
		$msg
			.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' )
			.addClass( isError ? 'kdna-rc-msg-error' : 'kdna-rc-msg-ok' )
			.text( message );
	}

	function clearFormMessage( $editor ) {
		$editor.find( '.kdna-rc-form-message' )
			.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' )
			.text( '' );
	}

	function setEditorBusy( $editor, busy ) {
		$editor.find( '.kdna-rc-spinner' ).toggleClass( 'is-active', !! busy );
		$editor.find( '.kdna-rc-save, .kdna-rc-cancel' ).prop( 'disabled', !! busy );
	}

	function openEditor( $row ) {
		$( '.kdna-rc-region.is-editing' ).not( $row ).each( function () {
			closeEditor( $( this ), false );
		} );
		$row.addClass( 'is-editing' );
		var $editor = $row.find( '.kdna-rc-region-editor' );
		$editor.prop( 'hidden', false );
		renderCountryPicker( $editor.find( '.kdna-rc-country-picker' ), '' );
	}

	function closeEditor( $row, removeIfNew ) {
		$row.removeClass( 'is-editing' );
		$row.find( '.kdna-rc-region-editor' ).prop( 'hidden', true );
		if ( removeIfNew && $row.hasClass( 'is-new' ) ) {
			$row.remove();
		}
	}

	function bindRegionsTab() {
		var $list = $( '#kdna-rc-region-list' );
		if ( ! $list.length ) {
			return;
		}

		// Drag-to-reorder using jQuery UI sortable bundled with WordPress.
		$list.sortable( {
			handle: '.kdna-rc-handle',
			items: '> li.kdna-rc-region',
			axis: 'y',
			placeholder: 'kdna-rc-region-placeholder',
			forcePlaceholderSize: true,
			update: function () {
				var slugs = [];
				$list.children( 'li.kdna-rc-region' ).each( function () {
					var slug = $( this ).attr( 'data-slug' );
					if ( slug ) {
						slugs.push( slug );
					}
				} );
				if ( ! slugs.length ) {
					return;
				}
				$.ajax( {
					url: config.ajaxUrl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: config.actions.reorderRegions,
						nonce: config.nonce,
						slugs: slugs
					}
				} );
			}
		} );

		// Add Region : clone the empty template into the list and open it.
		$( '.kdna-rc-add-region' ).on( 'click', function ( event ) {
			event.preventDefault();
			var template = document.getElementById( 'kdna-rc-region-template' );
			if ( ! template || ! template.content ) {
				return;
			}
			var $clone = $( template.content.firstElementChild.cloneNode( true ) );
			$clone.addClass( 'is-new' );
			$clone.find( '.kdna-rc-region-name' ).text( config.i18n.newRegion );
			$clone.find( '.kdna-rc-region-slug' ).text( '' );
			$list.append( $clone );
			$( '.kdna-rc-empty' ).hide();
			openEditor( $clone );
			$clone.find( '.kdna-rc-input-name' ).trigger( 'focus' );
		} );

		// Delegated handlers on the list so freshly added rows work too.
		$list.on( 'click', '.kdna-rc-edit', function ( event ) {
			event.preventDefault();
			openEditor( $( this ).closest( '.kdna-rc-region' ) );
		} );

		$list.on( 'click', '.kdna-rc-cancel', function ( event ) {
			event.preventDefault();
			closeEditor( $( this ).closest( '.kdna-rc-region' ), true );
		} );

		$list.on( 'click', '.kdna-rc-delete', function ( event ) {
			event.preventDefault();
			var $row = $( this ).closest( '.kdna-rc-region' );
			var slug = $row.attr( 'data-slug' );
			var msg  = $( this ).attr( 'data-confirm' ) || 'Delete?';

			if ( ! slug ) {
				$row.remove();
				return;
			}
			if ( ! window.confirm( msg ) ) { // eslint-disable-line no-alert
				return;
			}
			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.deleteRegion,
					nonce: config.nonce,
					slug: slug
				}
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$row.fadeOut( 150, function () { $row.remove(); } );
				}
			} );
		} );

		// Auto-generate slug from name while the slug field has not been
		// edited manually. Once the editor touches the slug we leave it
		// alone. For single-country regions with a country already chosen,
		// the country-picker handler owns the slug (using the ISO code),
		// so we don't overwrite it from the name here.
		$list.on( 'input', '.kdna-rc-input-name', function () {
			var $editor = $( this ).closest( '.kdna-rc-region-editor' );
			var $slug   = $editor.find( '.kdna-rc-input-slug' );
			if ( $slug.data( 'manual' ) ) {
				return;
			}
			var mode     = $editor.find( '.kdna-rc-input-type' ).val();
			var selected = ( $editor.find( '.kdna-rc-country-picker' ).attr( 'data-selected' ) || '' )
				.split( ',' ).filter( Boolean );
			if ( mode === 'single' && selected.length === 1 ) {
				return;
			}
			$slug.val( slugify( $( this ).val() ) );
		} );
		$list.on( 'input', '.kdna-rc-input-slug', function () {
			$( this ).data( 'manual', true );
		} );

		// Country picker behaviour.
		$list.on( 'input', '.kdna-rc-country-search', function () {
			var $picker = $( this ).closest( '.kdna-rc-country-picker' );
			renderCountryPicker( $picker, $( this ).val() );
		} );

		$list.on( 'change', '.kdna-rc-country-option input', function () {
			var $picker  = $( this ).closest( '.kdna-rc-country-picker' );
			var inputType = $( this ).attr( 'type' );
			var selected = ( $picker.attr( 'data-selected' ) || '' )
				.split( ',' )
				.map( function ( c ) { return c.trim().toUpperCase(); } )
				.filter( Boolean );

			var code = $( this ).val();
			if ( inputType === 'radio' ) {
				selected = [ code ];
			} else if ( $( this ).is( ':checked' ) ) {
				if ( selected.indexOf( code ) === -1 ) {
					selected.push( code );
				}
			} else {
				selected = selected.filter( function ( c ) { return c !== code; } );
			}
			$picker.attr( 'data-selected', selected.join( ',' ) );
			$picker.find( '.kdna-rc-country-option' ).removeClass( 'is-selected' );
			$picker.find( '.kdna-rc-country-option input:checked' )
				.closest( '.kdna-rc-country-option' )
				.addClass( 'is-selected' );

			// For single-country regions, prefer the ISO country code as the
			// slug (au, nz, us) rather than a slugified name. Only fills the
			// slug if the editor has not manually touched it.
			var $editor = $picker.closest( '.kdna-rc-region-editor' );
			var $slug   = $editor.find( '.kdna-rc-input-slug' );
			var mode    = $editor.find( '.kdna-rc-input-type' ).val();
			if ( mode === 'single' && selected.length === 1 && ! $slug.data( 'manual' ) ) {
				$slug.val( selected[ 0 ].toLowerCase() );
			}
		} );

		// Switching between Single and Group rebuilds the picker so radios
		// and checkboxes line up with the new mode.
		$list.on( 'change', '.kdna-rc-input-type', function () {
			var $editor = $( this ).closest( '.kdna-rc-region-editor' );
			var $picker = $editor.find( '.kdna-rc-country-picker' );

			if ( $( this ).val() === 'single' ) {
				var selected = ( $picker.attr( 'data-selected' ) || '' )
					.split( ',' )
					.filter( Boolean );
				if ( selected.length > 1 ) {
					$picker.attr( 'data-selected', selected[ 0 ] );
				}
			}
			renderCountryPicker( $picker, $editor.find( '.kdna-rc-country-search' ).val() || '' );
		} );

		// Save : POST to the kdna_rc_save_region action and refresh the row.
		$list.on( 'click', '.kdna-rc-save', function ( event ) {
			event.preventDefault();

			var $row     = $( this ).closest( '.kdna-rc-region' );
			var $editor  = $row.find( '.kdna-rc-region-editor' );
			var payload  = getEditorPayload( $editor );
			var original = $row.attr( 'data-slug' ) || '';

			clearFormMessage( $editor );
			setEditorBusy( $editor, true );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.saveRegion,
					nonce: config.nonce,
					region: payload,
					original_slug: original
				}
			} )
				.done( function ( response ) {
					if ( response && response.success && response.data && response.data.region ) {
						var saved = response.data.region;
						$row.removeClass( 'is-new' ).attr( 'data-slug', saved.slug );
						$row.find( '.kdna-rc-input-slug' ).val( saved.slug ).data( 'manual', false );
						updateRowSummary( $row, saved );
						showFormMessage( $editor, response.data.message || config.i18n.regionSaved, false );
						setTimeout( function () {
							closeEditor( $row, false );
							clearFormMessage( $editor );
						}, 600 );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						showFormMessage( $editor, err, true );
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					showFormMessage( $editor, msg, true );
				} )
				.always( function () {
					setEditorBusy( $editor, false );
				} );
		} );
	}

	// =====================================================================
	// Tools tab : Test Detection
	// =====================================================================

	function bindTestDetection() {
		var $input   = $( '#kdna-rc-test-ip' );
		var $button  = $( '#kdna-rc-test-detect' );
		var $result  = $( '.kdna-rc-test-result' );
		if ( ! $input.length || ! $button.length ) {
			return;
		}
		var $spinner = $button.parent().find( '.kdna-rc-spinner' );

		function escapeHtml( str ) {
			return String( str )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		}

		function renderResult( data ) {
			if ( ! data ) {
				$result.empty();
				return;
			}

			var country = data.country_code
				? escapeHtml( data.country_name || data.country_code ) + ' (' + escapeHtml( data.country_code ) + ')'
				: '<em>' + escapeHtml( config.i18n.testNoCountry ) + '</em>';

			var region;
			if ( data.region ) {
				region = escapeHtml( data.region.name ) + ' (<code>' + escapeHtml( data.region.slug ) + '</code>)';
				if ( data.used_default ) {
					region += ' &nbsp;<em>' + escapeHtml( '(default region)' ) + '</em>';
				}
			} else {
				region = '<em>' + escapeHtml( config.i18n.testNoMatch ) + '</em>';
			}

			$result.html(
				'<table class="widefat striped" style="max-width:540px;">' +
					'<tbody>' +
						'<tr><th>IP</th><td><code>' + escapeHtml( data.ip ) + '</code></td></tr>' +
						'<tr><th>Country</th><td>' + country + '</td></tr>' +
						'<tr><th>Region</th><td>' + region + '</td></tr>' +
					'</tbody>' +
				'</table>'
			);
		}

		function runTest() {
			var ip = ( $input.val() || '' ).trim();
			if ( ! ip ) {
				$input.trigger( 'focus' );
				return;
			}

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.html( '<p><em>' + escapeHtml( config.i18n.testDetecting ) + '</em></p>' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.testDetection,
					nonce: config.nonce,
					ip: ip
				}
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						renderResult( response.data );
					} else {
						var msg = ( response && response.data && response.data.message ) || config.i18n.failure;
						$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( msg ) + '</p>' );
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( msg ) + '</p>' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		}

		$button.on( 'click', function ( event ) {
			event.preventDefault();
			runTest();
		} );

		$input.on( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				runTest();
			}
		} );
	}

	// =====================================================================
	// Tools tab : Clear All Caches
	// =====================================================================

	function bindClearCaches() {
		var $button = $( '#kdna-rc-clear-caches' );
		if ( ! $button.length ) {
			return;
		}

		var $actions = $button.closest( '.kdna-rc-actions' );
		var $spinner = $actions.find( '.kdna-rc-spinner' );
		var $message = $actions.find( '.kdna-rc-clear-message' );

		$button.on( 'click', function ( event ) {
			event.preventDefault();

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$message
				.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' )
				.text( config.i18n.clearing );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.clearCaches,
					nonce: config.nonce
				}
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						var msg = ( response.data && response.data.message ) || config.i18n.cleared;
						if ( response.data && response.data.cleared && response.data.cleared.length ) {
							msg += ' (' + response.data.cleared.join( ', ' ) + ')';
						}
						$message.addClass( 'kdna-rc-msg-ok' ).text( msg );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						$message.addClass( 'kdna-rc-msg-error' ).text( err );
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					$message.addClass( 'kdna-rc-msg-error' ).text( msg );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	}

	// =====================================================================
	// Languages tab : list, drag-reorder, inline edit, import library
	// =====================================================================

	function bindLanguagesTab() {
		var $list = $( '#kdna-rc-language-list' );
		if ( ! $list.length ) {
			return;
		}

		// Drag to reorder. Wrapped in try/catch because if jQuery UI Sortable
		// fails to initialise (e.g. another plugin's broken script) the
		// remaining click bindings below must still work.
		try {
			if ( typeof $list.sortable === 'function' ) {
				$list.sortable( {
					handle: '.kdna-rc-handle',
					items: '> li.kdna-rc-language',
					axis: 'y',
					placeholder: 'kdna-rc-region-placeholder',
					forcePlaceholderSize: true,
					update: function () {
						var slugs = [];
						$list.children( 'li.kdna-rc-language' ).each( function () {
							var slug = $( this ).attr( 'data-slug' );
							if ( slug ) {
								slugs.push( slug );
							}
						} );
						if ( ! slugs.length ) {
							return;
						}
						$.ajax( {
							url: config.ajaxUrl,
							method: 'POST',
							dataType: 'json',
							data: {
								action: config.actions.reorderLanguages,
								nonce: config.nonce,
								slugs: slugs
							}
						} );
					}
				} );
			}
		} catch ( err ) {
			if ( window.console && window.console.warn ) {
				window.console.warn( '[KDNA RC] Languages: sortable init failed, drag-reorder disabled.', err );
			}
		}

		function updateRowSummary( $row, payload ) {
			var $name = $row.find( '.kdna-rc-region-name' );
			var $slug = $row.find( '.kdna-rc-region-slug' );
			$name.text( payload.name || config.i18n.untitledLanguage );
			$slug.text( payload.slug || '' );

			var $cell = $row.find( '.kdna-rc-flag-cell' );
			$cell.empty();
			if ( payload.flag ) {
				var span = document.createElement( 'span' );
				span.className = 'fi fi-' + payload.flag + ' kdna-rc-flag-display';
				span.setAttribute( 'aria-hidden', 'true' );
				$cell.append( span );
			}
		}

		function getEditorPayload( $editor ) {
			return {
				name: $editor.find( '.kdna-rc-input-name' ).val().trim(),
				slug: $editor.find( '.kdna-rc-input-slug' ).val().trim(),
				flag: ( $editor.find( '.kdna-rc-input-flag' ).val() || '' ).trim().toLowerCase()
			};
		}

		function showFormMessage( $editor, message, isError ) {
			$editor.find( '.kdna-rc-form-message' )
				.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' )
				.addClass( isError ? 'kdna-rc-msg-error' : 'kdna-rc-msg-ok' )
				.text( message );
		}

		function setEditorBusy( $editor, busy ) {
			$editor.find( '.kdna-rc-spinner' ).toggleClass( 'is-active', !! busy );
			$editor.find( '.kdna-rc-save, .kdna-rc-cancel' ).prop( 'disabled', !! busy );
		}

		function openEditor( $row ) {
			$( '.kdna-rc-language.is-editing' ).not( $row ).each( function () {
				closeEditor( $( this ), false );
			} );
			$row.addClass( 'is-editing' );
			$row.find( '.kdna-rc-region-editor' ).prop( 'hidden', false );
		}

		function closeEditor( $row, removeIfNew ) {
			$row.removeClass( 'is-editing' );
			$row.find( '.kdna-rc-region-editor' ).prop( 'hidden', true );
			if ( removeIfNew && $row.hasClass( 'is-new' ) ) {
				$row.remove();
			}
		}

		// Add Language: clone the empty template. Delegated to document so
		// the binding cannot miss the button on first paint and survives
		// re-renders by other admin scripts.
		$( document ).on( 'click', '.kdna-rc-add-language', function ( event ) {
			event.preventDefault();
			var template = document.getElementById( 'kdna-rc-language-template' );
			if ( ! template || ! template.content ) {
				if ( window.console && window.console.warn ) {
					window.console.warn( '[KDNA RC] Languages template missing; cannot Add Language.' );
				}
				return;
			}
			var first = template.content.firstElementChild;
			if ( ! first ) {
				return;
			}
			var $clone = $( first.cloneNode( true ) );
			$clone.addClass( 'is-new' );
			$clone.find( '.kdna-rc-region-name' ).text( config.i18n.newLanguage || 'New language' );
			$list.append( $clone );
			$( '.kdna-rc-languages .kdna-rc-empty' ).hide();
			openEditor( $clone );
			$clone.find( '.kdna-rc-input-name' ).trigger( 'focus' );
		} );

		// Delegated handlers.
		$list.on( 'click', '.kdna-rc-edit', function ( event ) {
			event.preventDefault();
			openEditor( $( this ).closest( '.kdna-rc-language' ) );
		} );

		$list.on( 'click', '.kdna-rc-cancel', function ( event ) {
			event.preventDefault();
			closeEditor( $( this ).closest( '.kdna-rc-language' ), true );
		} );

		$list.on( 'click', '.kdna-rc-delete', function ( event ) {
			event.preventDefault();
			var $row = $( this ).closest( '.kdna-rc-language' );
			var slug = $row.attr( 'data-slug' );
			var msg  = $( this ).attr( 'data-confirm' ) || 'Delete?';

			if ( ! slug ) {
				$row.remove();
				return;
			}
			if ( ! window.confirm( msg ) ) { // eslint-disable-line no-alert
				return;
			}
			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.deleteLanguage,
					nonce: config.nonce,
					slug: slug
				}
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$row.fadeOut( 150, function () { $row.remove(); } );
				}
			} );
		} );

		// Auto-generate slug from name until manually touched.
		$list.on( 'input', '.kdna-rc-input-name', function () {
			var $editor = $( this ).closest( '.kdna-rc-region-editor' );
			var $slug   = $editor.find( '.kdna-rc-input-slug' );
			if ( $slug.data( 'manual' ) ) {
				return;
			}
			$slug.val( slugify( $( this ).val() ) );
		} );
		$list.on( 'input', '.kdna-rc-input-slug', function () {
			$( this ).data( 'manual', true );
		} );

		// Live flag preview as the country code is typed.
		$list.on( 'input', '.kdna-rc-input-flag', function () {
			var $preview = $( this ).closest( '.kdna-rc-flag-input-row' ).find( '.kdna-rc-flag-preview' );
			var code = ( $( this ).val() || '' ).trim().toLowerCase();
			$preview.attr( 'class', 'kdna-rc-flag-preview' );
			if ( /^[a-z]{2}$/.test( code ) ) {
				$preview.addClass( 'fi fi-' + code );
			}
		} );

		// Save.
		$list.on( 'click', '.kdna-rc-save', function ( event ) {
			event.preventDefault();
			var $row     = $( this ).closest( '.kdna-rc-language' );
			var $editor  = $row.find( '.kdna-rc-region-editor' );
			var payload  = getEditorPayload( $editor );
			var original = $row.attr( 'data-slug' ) || '';

			showFormMessage( $editor, '', false );
			setEditorBusy( $editor, true );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.saveLanguage,
					nonce: config.nonce,
					language: payload,
					original_slug: original
				}
			} )
				.done( function ( response ) {
					if ( response && response.success && response.data && response.data.language ) {
						var saved = response.data.language;
						$row.removeClass( 'is-new' ).attr( 'data-slug', saved.slug );
						$row.find( '.kdna-rc-input-slug' ).val( saved.slug ).data( 'manual', false );
						updateRowSummary( $row, saved );
						showFormMessage( $editor, response.data.message || config.i18n.languageSaved, false );
						setTimeout( function () {
							closeEditor( $row, false );
						}, 600 );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						showFormMessage( $editor, err, true );
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					showFormMessage( $editor, msg, true );
				} )
				.always( function () {
					setEditorBusy( $editor, false );
				} );
		} );

		// Library import modal.
		var $modal = $( '#kdna-rc-language-library-modal' );
		var $libList = $modal.find( '.kdna-rc-library-list' );

		function closeModal() {
			$modal.prop( 'hidden', true );
		}
		function openModal() {
			$modal.prop( 'hidden', false );
			$modal.find( '.kdna-rc-library-search' ).val( '' ).trigger( 'input' ).trigger( 'focus' );
		}

		$( '.kdna-rc-import-language' ).on( 'click', function ( event ) {
			event.preventDefault();
			openModal();
		} );

		$modal.on( 'click', '.kdna-rc-modal-close', function ( event ) {
			event.preventDefault();
			closeModal();
		} );
		$modal.on( 'click', function ( event ) {
			if ( event.target === $modal[ 0 ] ) {
				closeModal();
			}
		} );

		$libList.on( 'input', '.kdna-rc-library-search', function () {
			var q = ( $( this ).val() || '' ).toLowerCase();
			$libList.find( '.kdna-rc-library-item' ).each( function () {
				var name = ( $( this ).attr( 'data-name' ) || '' ).toLowerCase();
				var slug = ( $( this ).attr( 'data-slug' ) || '' ).toLowerCase();
				$( this ).toggle( q === '' || name.indexOf( q ) !== -1 || slug.indexOf( q ) !== -1 );
			} );
		} );
		// Bind search on the input itself (it lives outside the list).
		$modal.find( '.kdna-rc-library-search' ).on( 'input', function () {
			var q = ( $( this ).val() || '' ).toLowerCase();
			$libList.find( '.kdna-rc-library-item' ).each( function () {
				var name = ( $( this ).attr( 'data-name' ) || '' ).toLowerCase();
				var slug = ( $( this ).attr( 'data-slug' ) || '' ).toLowerCase();
				$( this ).toggle( q === '' || name.indexOf( q ) !== -1 || slug.indexOf( q ) !== -1 );
			} );
		} );

		// One-click add from library: posts a save with the library values.
		$libList.on( 'click', '.kdna-rc-library-add', function ( event ) {
			event.preventDefault();
			var $item = $( this ).closest( '.kdna-rc-library-item' );
			var payload = {
				slug: $item.attr( 'data-slug' ) || '',
				name: $item.attr( 'data-name' ) || '',
				flag: $item.attr( 'data-flag' ) || ''
			};

			var $btn = $( this ).prop( 'disabled', true );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.saveLanguage,
					nonce: config.nonce,
					language: payload,
					original_slug: ''
				}
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.language ) {
					var template = document.getElementById( 'kdna-rc-language-template' );
					if ( template && template.content ) {
						var $clone = $( template.content.firstElementChild.cloneNode( true ) );
						$clone.attr( 'data-slug', response.data.language.slug );
						updateRowSummary( $clone, response.data.language );
						$list.append( $clone );
						$( '.kdna-rc-languages .kdna-rc-empty' ).hide();
					}
					closeModal();
				} else {
					var err = ( response && response.data && response.data.message ) || config.i18n.failure;
					window.alert( err ); // eslint-disable-line no-alert
				}
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// Esc to close.
		$( document ).on( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && $modal.is( ':visible' ) ) {
				closeModal();
			}
		} );
	}

	// =====================================================================
	// Tools tab : Test Language Detection
	// =====================================================================

	function bindTestLanguageDetection() {
		var $button = $( '#kdna-rc-test-language-detect' );
		if ( ! $button.length ) {
			return;
		}
		var $accept    = $( '#kdna-rc-test-accept-language' );
		var $override  = $( '#kdna-rc-test-lang-override' );
		var $region    = $( '#kdna-rc-test-region' );
		var $firstVisit = $( '#kdna-rc-test-first-visit' );
		var $result    = $( '.kdna-rc-test-language-result' );
		var $spinner   = $button.parent().find( '.kdna-rc-spinner' );

		function escapeHtml( str ) {
			return String( str ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
			} );
		}

		$button.on( 'click', function ( event ) {
			event.preventDefault();

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.html( '<p><em>' + escapeHtml( config.i18n.testDetecting || 'Looking up...' ) + '</em></p>' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.testLangDetection,
					nonce: config.nonce,
					accept_language: $accept.val(),
					override: $override.val(),
					region: $region.val(),
					first_visit: $firstVisit.is( ':checked' ) ? 1 : 0
				}
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						var data = response.data || {};
						var resolved = data.slug
							? '<strong>' + escapeHtml( data.name || data.slug ) + '</strong> (<code>' + escapeHtml( data.slug ) + '</code>)'
							: '<em>None</em>';
						var html = '<table class="widefat striped" style="max-width:540px;"><tbody>' +
							'<tr><th>Resolved language</th><td>' + resolved + '</td></tr>' +
							'<tr><th>Source</th><td><code>' + escapeHtml( data.source || 'unknown' ) + '</code></td></tr>';
						if ( data.steps ) {
							html += '<tr><th colspan="2">Step results</th></tr>';
							for ( var key in data.steps ) {
								if ( Object.prototype.hasOwnProperty.call( data.steps, key ) ) {
									html += '<tr><th>' + escapeHtml( key ) + '</th><td>' + escapeHtml( data.steps[ key ] ) + '</td></tr>';
								}
							}
						}
						html += '</tbody></table>';
						$result.html( html );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( err ) + '</p>' );
					}
				} )
				.fail( function ( jqXHR ) {
					var msg = config.i18n.network;
					if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
						msg = jqXHR.responseJSON.data.message;
					}
					$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( msg ) + '</p>' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	}

	$( function () {
		bindUpdateDatabaseButton();
		bindRegionsTab();
		bindTestDetection();
		bindClearCaches();
		bindLanguagesTab();
		bindTestLanguageDetection();
		bindMigrationTool();
		bindAuditTool();
		bindFlushRewriteRules();
		bindUrlPreviewMetaBox();
		bindSeoHealthCheck();
	} );

	// =====================================================================
	// Tools tab : SEO health check
	// =====================================================================

	function bindSeoHealthCheck() {
		var $button = $( '#kdna-rc-seo-health-check' );
		if ( ! $button.length ) { return; }
		var $spinner = $button.parent().find( '.kdna-rc-spinner' );
		var $result  = $( '.kdna-rc-seo-health-result' );

		function escapeHtml( str ) {
			return String( str ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
			} );
		}

		function levelBadge( level ) {
			var cls = level === 'pass' ? 'is-ok' : ( level === 'fail' ? 'is-error' : 'is-warn' );
			var lbl = level.charAt( 0 ).toUpperCase() + level.slice( 1 );
			return '<span class="kdna-rc-health-badge ' + cls + '">' + lbl + '</span>';
		}

		$button.on( 'click', function ( ev ) {
			ev.preventDefault();
			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.html( '<p><em>Running checks...</em></p>' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.seoHealthCheck,
					nonce: config.nonce
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( ( response && response.data && response.data.message ) || 'Health check failed.' ) + '</p>' );
						return;
					}
					var findings = ( response.data && response.data.findings ) || [];
					if ( findings.length === 0 ) {
						$result.html( '<p>No findings.</p>' );
						return;
					}
					var html = '<ul class="kdna-rc-health-list">';
					findings.forEach( function ( f ) {
						html += '<li>' + levelBadge( f.level ) + ' <strong>' + escapeHtml( f.title ) + '</strong>: ' + escapeHtml( f.message ) + '</li>';
					} );
					html += '</ul>';
					$result.html( html );
				} )
				.fail( function () {
					$result.html( '<p class="kdna-rc-msg-error">Network error.</p>' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	}

	// =====================================================================
	// Tools tab : Flush rewrite rules
	// =====================================================================

	function bindFlushRewriteRules() {
		var $button = $( '#kdna-rc-flush-rules' );
		if ( ! $button.length ) { return; }

		var $actions = $button.parent();
		var $spinner = $actions.find( '.kdna-rc-spinner' );
		var $message = $actions.find( '.kdna-rc-flush-message' );

		$button.on( 'click', function ( ev ) {
			ev.preventDefault();
			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$message.removeClass( 'kdna-rc-msg-ok kdna-rc-msg-error' ).text( '' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.flushRules,
					nonce: config.nonce
				}
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$message.addClass( 'kdna-rc-msg-ok' ).text( ( response.data && response.data.message ) || 'Done.' );
					} else {
						$message.addClass( 'kdna-rc-msg-error' ).text( ( response && response.data && response.data.message ) || 'Flush failed.' );
					}
				} )
				.fail( function () {
					$message.addClass( 'kdna-rc-msg-error' ).text( 'Network error.' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	}

	// =====================================================================
	// Post edit : URL preview meta box (copy + Test as visitor)
	// =====================================================================

	function bindUrlPreviewMetaBox() {
		var box = document.getElementById( 'kdna_rc_url_preview' );
		if ( ! box ) { return; }

		// Copy buttons.
		$( box ).on( 'click', '.kdna-rc-url-copy', function ( ev ) {
			ev.preventDefault();
			var url = $( this ).attr( 'data-url' ) || '';
			if ( ! url ) { return; }
			var $btn = $( this );
			var prev = $btn.text();

			function flash( ok ) {
				$btn.text( ok ? 'Copied!' : 'Copy failed' );
				setTimeout( function () { $btn.text( prev ); }, 1500 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( function () { flash( true ); }, function () { flash( false ); } );
				return;
			}

			// Fallback: temporary textarea + execCommand.
			var ta = document.createElement( 'textarea' );
			ta.value = url;
			document.body.appendChild( ta );
			ta.select();
			try {
				flash( document.execCommand( 'copy' ) );
			} catch ( err ) {
				flash( false );
			}
			document.body.removeChild( ta );
		} );

		// Test as visitor : open the selected URL in a new tab.
		$( box ).on( 'click', '.kdna-rc-test-as-visitor-go', function ( ev ) {
			ev.preventDefault();
			var url = $( box ).find( '.kdna-rc-test-as-visitor' ).val();
			if ( url ) {
				window.open( url, '_blank', 'noopener' );
			}
		} );
	}

	// =====================================================================
	// Tools tab : Field Translation Audit
	// =====================================================================

	function bindAuditTool() {
		var $cpt    = $( '#kdna-rc-audit-cpt' );
		var $run    = $( '#kdna-rc-audit-run' );
		var $result = $( '.kdna-rc-audit-result' );
		if ( ! $run.length ) { return; }
		var $spinner = $run.parent().find( '.kdna-rc-spinner' );

		function escapeHtml( str ) {
			return String( str ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
			} );
		}

		// Render the results table.
		function renderTable( payload ) {
			var fields = payload.fields || {};
			var rows   = payload.rows || [];
			var langs  = payload.lang_map || {};

			var fieldKeys = Object.keys( fields );
			if ( fieldKeys.length === 0 ) {
				$result.html( '<p><em>' + escapeHtml( 'No multilingual fields found on the selected post type.' ) + '</em></p>' );
				return;
			}

			// Per-field summary at the top: completeness percentage per language.
			var langKeys = Object.keys( langs );
			var summary  = '<h3>Completeness</h3><table class="widefat striped" style="max-width:760px;"><thead><tr><th>Field</th><th>Default</th>';
			langKeys.forEach( function ( s ) {
				summary += '<th>' + escapeHtml( langs[ s ].name ) + '</th>';
			} );
			summary += '</tr></thead><tbody>';

			fieldKeys.forEach( function ( field ) {
				var counts = { default: 0 };
				langKeys.forEach( function ( s ) { counts[ s ] = 0; } );
				rows.forEach( function ( row ) {
					var entry = ( row.fields && row.fields[ field ] ) || {};
					if ( entry.default ) { counts.default++; }
					langKeys.forEach( function ( s ) { if ( entry[ s ] ) { counts[ s ]++; } } );
				} );
				var total = rows.length || 1;
				summary += '<tr><th>' + escapeHtml( fields[ field ] ) + '<br><code>' + escapeHtml( field ) + '</code></th>';
				summary += '<td>' + counts.default + '/' + rows.length + ' (' + Math.round( ( counts.default / total ) * 100 ) + '%)</td>';
				langKeys.forEach( function ( s ) {
					var pct = Math.round( ( counts[ s ] / total ) * 100 );
					summary += '<td>' + counts[ s ] + '/' + rows.length + ' (' + pct + '%) ';
					if ( counts[ s ] < rows.length ) {
						summary += ' <button type="button" class="button-link kdna-rc-audit-bulk" data-field="' + escapeHtml( field ) + '" data-lang="' + escapeHtml( s ) + '">Add empty slots</button>';
					}
					summary += '</td>';
				} );
				summary += '</tr>';
			} );
			summary += '</tbody></table>';

			// Per-post table.
			var detail = '<h3>Per-post completeness</h3><table class="widefat striped" style="margin-top:1em;"><thead><tr><th>Post</th>';
			fieldKeys.forEach( function ( field ) {
				detail += '<th colspan="' + ( langKeys.length + 1 ) + '">' + escapeHtml( fields[ field ] ) + '</th>';
			} );
			detail += '</tr><tr><th></th>';
			fieldKeys.forEach( function () {
				detail += '<th>Default</th>';
				langKeys.forEach( function ( s ) {
					detail += '<th>' + escapeHtml( langs[ s ].flag || s ) + '</th>';
				} );
			} );
			detail += '</tr></thead><tbody>';

			rows.forEach( function ( row ) {
				detail += '<tr><td><a href="' + escapeHtml( row.edit_link ) + '" target="_blank">' + escapeHtml( row.title || '#' + row.id ) + '</a></td>';
				fieldKeys.forEach( function ( field ) {
					var entry = ( row.fields && row.fields[ field ] ) || {};
					var dotDefault = entry.default ? '●' : '○';
					detail += '<td title="default">' + dotDefault + '</td>';
					langKeys.forEach( function ( s ) {
						var dot = entry[ s ] ? '●' : '○';
						var link = entry[ s ]
							? dot
							: '<a href="' + escapeHtml( row.edit_link ) + '#kdna-rc-mlf-' + escapeHtml( field ) + '-' + escapeHtml( s ) + '" target="_blank" title="Translate ' + escapeHtml( s ) + '">' + dot + '</a>';
						detail += '<td>' + link + '</td>';
					} );
				} );
				detail += '</tr>';
			} );
			detail += '</tbody></table>';

			$result.html( summary + detail );
		}

		$run.on( 'click', function ( ev ) {
			ev.preventDefault();
			$run.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.html( '<p><em>Scanning...</em></p>' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.auditScan,
					nonce: config.nonce,
					cpt: $cpt.val()
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$result.html( '<p class="kdna-rc-msg-error">' + escapeHtml( ( response && response.data && response.data.message ) || 'Audit failed.' ) + '</p>' );
						return;
					}
					renderTable( response.data || {} );
				} )
				.always( function () {
					$run.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				} );
		} );

		// Bulk-add empty language slots for one field/language.
		$result.on( 'click', '.kdna-rc-audit-bulk', function ( ev ) {
			ev.preventDefault();
			var $btn = $( this );
			var field = $btn.attr( 'data-field' );
			var lang  = $btn.attr( 'data-lang' );
			if ( ! window.confirm( 'Seed an empty "' + lang + '" slot on every post missing it? This will mark them on edit screens.' ) ) { return; } // eslint-disable-line no-alert
			$btn.prop( 'disabled', true );
			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.auditBulkAdd,
					nonce: config.nonce,
					field: field,
					language: lang,
					cpt: $cpt.val()
				}
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$btn.replaceWith( '<em>' + ( response.data && response.data.message || 'Seeded.' ) + '</em>' );
				} else {
					$btn.prop( 'disabled', false );
					window.alert( ( response && response.data && response.data.message ) || 'Bulk add failed.' ); // eslint-disable-line no-alert
				}
			} );
		} );
	}

	// =====================================================================
	// Tools tab : Migrate Field to Multilingual
	// =====================================================================

	function bindMigrationTool() {
		var $cpt    = $( '#kdna-rc-mig-cpt' );
		var $field  = $( '#kdna-rc-mig-field' );
		var $target = $( '#kdna-rc-mig-target' );
		var $run    = $( '#kdna-rc-mig-run' );
		if ( ! $cpt.length || ! $run.length ) { return; }

		var $progress = $( '.kdna-rc-migrate-progress' );
		var $barFill  = $( '.kdna-rc-migrate-bar-fill' );
		var $status   = $( '.kdna-rc-migrate-status' );
		var $result   = $( '.kdna-rc-migrate-result' );
		var $spinner  = $run.parent().find( '.kdna-rc-spinner' );

		// Repopulate the source field dropdown when the CPT changes.
		$cpt.on( 'change', function () {
			var post_type = $cpt.val();
			$field.prop( 'disabled', true ).empty().append( '<option value="">Loading...</option>' );
			$run.prop( 'disabled', true );

			if ( ! post_type ) {
				$field.empty().append( '<option value="">Pick a CPT first</option>' );
				return;
			}

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.migrationFields,
					nonce: config.nonce,
					post_type: post_type
				}
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$field.empty().append( '<option value="">No fields available</option>' );
					return;
				}
				var fields = response.data && response.data.fields ? response.data.fields : {};
				$field.empty().append( '<option value="">Select a field...</option>' ).prop( 'disabled', false );
				Object.keys( fields ).forEach( function ( key ) {
					$field.append( '<option value="' + key + '">' + fields[ key ] + '</option>' );
				} );
			} );
		} );

		$field.on( 'change', function () {
			$run.prop( 'disabled', ! $field.val() );
		} );

		$run.on( 'click', function ( ev ) {
			ev.preventDefault();
			var post_type   = $cpt.val();
			var field       = $field.val();
			var target_type = $target.val();
			if ( ! post_type || ! field || ! target_type ) { return; }

			var prompt = 'This will convert all instances of "' + field + '" on ' + post_type + ' to ' + target_type + '. Existing values become the Default tab content. This cannot be undone except by manual database edit. Continue?';
			if ( ! window.confirm( prompt ) ) { return; } // eslint-disable-line no-alert

			$run.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$progress.prop( 'hidden', false );
			$status.text( 'Starting migration...' );
			$barFill.css( 'width', '0%' );
			$result.empty();

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.migrationStart,
					nonce: config.nonce,
					post_type: post_type,
					field: field,
					target_type: target_type
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$result.html( '<p class="kdna-rc-msg-error">' + ( response && response.data && response.data.message || 'Could not start migration.' ) + '</p>' );
						$run.prop( 'disabled', false );
						$spinner.removeClass( 'is-active' );
						return;
					}
					var meta = response.data;
					if ( meta.batches === 0 ) {
						$status.text( 'No posts to migrate.' );
						$run.prop( 'disabled', false );
						$spinner.removeClass( 'is-active' );
						return;
					}
					runBatch( 0, meta.batches, meta.total, post_type, field, target_type, 0 );
				} );
		} );

		function runBatch( batch, total_batches, total_posts, post_type, field, target_type, processed ) {
			$status.text( 'Migrating batch ' + ( batch + 1 ) + ' of ' + total_batches + '...' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.actions.migrationBatch,
					nonce: config.nonce,
					post_type: post_type,
					field: field,
					target_type: target_type,
					batch: batch
				}
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$result.html( '<p class="kdna-rc-msg-error">' + ( response && response.data && response.data.message || 'Migration failed.' ) + '</p>' );
					$run.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
					return;
				}
				var data = response.data;
				processed += ( data.processed || 0 );
				var pct = total_posts > 0 ? Math.min( 100, Math.round( ( processed / total_posts ) * 100 ) ) : 100;
				$barFill.css( 'width', pct + '%' );

				if ( data.is_last ) {
					var typeMsg = data.type_changed ? ' Field type updated to multilingual.' : ' (Field-type update could not be applied automatically; update manually in JetEngine.)';
					$result.html( '<p class="kdna-rc-msg-ok">Migrated ' + processed + ' posts. Default values preserved. Languages tabs now available on edit screens.' + typeMsg + '</p>' );
					$status.text( 'Done.' );
					$run.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
					return;
				}

				runBatch( batch + 1, total_batches, total_posts, post_type, field, target_type, processed );
			} );
		}
	}

	// =====================================================================
	// Settings tab : Region Banner styling helpers
	// =====================================================================
	// Paired colour picker + text input stay in sync so an admin can use
	// the native picker for quick edits and the text input for keyword /
	// rgba() values the picker cannot represent. Logo URL field opens
	// wp.media() so the admin can pick an attachment instead of pasting.
	$( function () {
		$( document ).on( 'input change', '[data-kdna-rc-colour-sync]', function () {
			var $self = $( this );
			var targetSelector = $self.attr( 'data-kdna-rc-colour-sync' );
			if ( ! targetSelector ) { return; }
			var $target = $( targetSelector );
			if ( ! $target.length ) { return; }
			var value = $self.val();
			// Only push hex values into the picker; arbitrary strings
			// (rgba, keywords) typed into the text input would break the
			// picker's internal state.
			if ( $target.attr( 'type' ) === 'color' ) {
				if ( /^#[0-9a-fA-F]{6}$/.test( value ) ) {
					$target.val( value );
				}
			} else {
				$target.val( value );
			}
		} );

		$( document ).on( 'click', '.kdna-rc-media-pick', function ( e ) {
			e.preventDefault();
			var targetSelector = $( this ).attr( 'data-target' );
			if ( ! targetSelector || typeof window.wp === 'undefined' || typeof window.wp.media !== 'function' ) {
				return;
			}
			var frame = window.wp.media( {
				title: 'Choose Logo Image',
				button: { text: 'Use this image' },
				multiple: false,
				library: { type: 'image' }
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( attachment && attachment.url ) {
					$( targetSelector ).val( attachment.url ).trigger( 'change' );
				}
			} );
			frame.open();
		} );
	} );
} )( jQuery );
