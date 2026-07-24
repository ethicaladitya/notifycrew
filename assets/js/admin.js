/* global ncrwAdmin */
( function ( $, cfg ) {
	'use strict';

	/**
	 * Confirm delete before submitting delete forms.
	 */
	$( document ).on( 'submit', '.ncrw-delete-form', function ( e ) {
		if ( ! window.confirm( cfg.i18n.confirmDelete ) ) {
			e.preventDefault();
		}
	} );

	/**
	 * Manual "Process Now" button via REST API.
	 * Usage: <button class="js-ncrw-process-now">…</button>
	 */
	$( document ).on( 'click', '.js-ncrw-process-now', function ( e ) {
		e.preventDefault();

		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( cfg.i18n.processing );

		$.ajax( {
			url: cfg.restUrl + '/process',
			method: 'POST',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', cfg.restNonce );
			},
			success: function () {
				$btn.text( cfg.i18n.success );
				setTimeout( function () {
					window.location.reload();
				}, 1500 );
			},
			error: function () {
				$btn.prop( 'disabled', false ).text( cfg.i18n.error );
			}
		} );
	} );

	/**
	 * Auto-dismiss success notices after 4 seconds.
	 */
	$( function () {
		function syncAdminScheduleMode() {
			var $quick = $( '#ncrw_quick_hours' );
			var $datetime = $( '#ncrw_datetime' );
			var $datetimeRow = $datetime.closest( 'tr' );
			if ( ! $quick.length || ! $datetime.length ) {
				return;
			}

			var selected = parseInt( $quick.val() || '0', 10 );
			if ( selected > 0 ) {
				$datetime.prop( 'required', false ).prop( 'disabled', true );
				$datetimeRow.hide();
			} else {
				$datetime.prop( 'required', true ).prop( 'disabled', false );
				$datetimeRow.show();
			}
		}

		function rebuildQuickHoursOptions() {
			var $team = $( '#ncrw_team_id' );
			var $quick = $( '#ncrw_quick_hours' );
			if ( ! $team.length || ! $quick.length ) {
				return;
			}

			var raw = String( $team.find( ':selected' ).data( 'quick-hours' ) || '' );
			var existing = parseInt( $quick.val() || '0', 10 );
			var parts = raw.split( /[,\s]+/ ).filter( function ( v ) { return !!v; } );

			$quick.empty().append( '<option value="0">Custom date & time</option>' );
			parts.forEach( function ( h ) {
				var value = parseInt( h, 10 );
				if ( value > 0 ) {
					$quick.append( '<option value="' + value + '">In ' + value + ' hours</option>' );
				}
			} );

			if ( existing > 0 && parts.indexOf( String( existing ) ) !== -1 ) {
				$quick.val( String( existing ) );
			}

			syncAdminScheduleMode();
		}

		$( document ).on( 'change', '#ncrw_team_id', rebuildQuickHoursOptions );
		$( document ).on( 'change', '#ncrw_quick_hours', syncAdminScheduleMode );

		rebuildQuickHoursOptions();
		syncAdminScheduleMode();

		// Prevent password managers from injecting values into the webhook field.
		var $webhookInput = $( '#ncrw_slack_webhook' );
		if ( $webhookInput.length ) {
			$webhookInput.val( '' );
			window.setTimeout( function () {
				$webhookInput.val( '' );
			}, 150 );
		}

		setTimeout( function () {
			$( '.notice-success.is-dismissible' ).fadeOut( 500 );
		}, 4000 );
	} );

} )( jQuery, ncrwAdmin );
