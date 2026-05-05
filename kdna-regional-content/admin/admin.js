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

		return {
			name:      $editor.find( '.kdna-rc-input-name' ).val().trim(),
			slug:      $editor.find( '.kdna-rc-input-slug' ).val().trim(),
			type:      $editor.find( '.kdna-rc-input-type:checked' ).val() || 'single',
			countries: selected,
			language:  $editor.find( '.kdna-rc-input-language' ).val().trim(),
			direction: $editor.find( '.kdna-rc-input-direction:checked' ).val() || 'ltr'
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
		// edited manually. Once the editor touches the slug we leave it alone.
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

	$( function () {
		bindUpdateDatabaseButton();
		bindRegionsTab();
		bindTestDetection();
	} );
} )( jQuery );
