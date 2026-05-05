/*
 * KDNA Regional Content admin scripts.
 *
 * Stage 2: drives the Update Database Now button on the Tools tab. Posts to
 * the kdna_rc_update_database AJAX action, displays progress and result
 * messages, and refreshes the status table cells in place so admins can see
 * the new metadata without reloading.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.kdnaRCAdmin === 'undefined' ) {
		return;
	}

	var config = window.kdnaRCAdmin;

	// Map of status field keys to a function that turns the response value
	// into the text we want to render. Centralised so it is easy to extend.
	function setCell( field, value ) {
		var cell = document.querySelector( '#kdna-rc-db-status [data-field="' + field + '"]' );
		if ( ! cell ) {
			return;
		}
		cell.textContent = value;
	}

	function renderStatus( status ) {
		if ( ! status ) {
			return;
		}

		setCell( 'last_updated_human', status.last_updated_human || '' );
		setCell( 'file_size_human', status.file_size_human || '' );
		setCell( 'database_type', ( status.metadata && status.metadata.database_type ) || '' );
		setCell( 'ip_version', status.metadata && status.metadata.ip_version
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

	$( function () {
		var $button  = $( '#kdna-rc-update-db' );
		var $spinner = $( '.kdna-rc-spinner' );
		var $message = $( '.kdna-rc-update-message' );

		if ( ! $button.length ) {
			return;
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
						renderStatus( response.data && response.data.status );
					} else {
						var err = ( response && response.data && response.data.message ) || config.i18n.failure;
						$message.addClass( 'kdna-rc-msg-error' ).text( err );
						if ( response && response.data && response.data.status ) {
							renderStatus( response.data.status );
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
	} );
} )( jQuery );
