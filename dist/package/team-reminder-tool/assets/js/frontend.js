/* global trtFrontend */

// ---- Global Google callback (must be outside IIFE so Google GSI can call it) ----
window.trtOnGoogleCallback = function ( response ) {
	'use strict';
	if ( ! window._trtPortal ) {
		return;
	}
	window._trtPortal.onGoogleSignIn( response );
};

( function () {
	'use strict';
	var AUTH_STORAGE_KEY = 'trtFrontendAuth';

	var state = {
		token: '',
		email: '',
		teams: [],
		selectedTeamId: 0,
		items: []
	};

	function decodeJwtPayload( token ) {
		try {
			var payload = token.split( '.' )[1];
			var json = window.atob( payload.replace( /-/g, '+' ).replace( /_/g, '/' ) );
			return JSON.parse( json );
		} catch ( e ) {
			return null;
		}
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	function showNotice( message, type ) {
		var el = byId( 'trt-portal-notice' );
		if ( ! el ) {
			return;
		}
		el.hidden = false;
		el.className = 'trt-frontend-notice trt-frontend-notice--' + type;
		el.textContent = message;
	}

	function clearNotice() {
		var el = byId( 'trt-portal-notice' );
		if ( ! el ) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
	}

	function setSignedInUI( isSignedIn ) {
		var gate = byId( 'trt-auth-gate' );
		var app = byId( 'trt-portal-app' );
		var emailEl = byId( 'trt-user-email' );
		var tokenInput = byId( 'trt_google_id_token' );

		// Use explicit display style so theme CSS cannot override [hidden]
		if ( gate ) {
			gate.style.display = isSignedIn ? 'none' : '';
		}
		if ( app ) {
			app.hidden = ! isSignedIn;
			app.style.display = isSignedIn ? '' : 'none';
		}
		if ( emailEl ) {
			emailEl.textContent = state.email;
		}
		if ( tokenInput ) {
			tokenInput.value = state.token;
		}
	}

	function persistAuthState() {
		if ( ! window.localStorage ) {
			return;
		}

		try {
			if ( ! state.token ) {
				window.localStorage.removeItem( AUTH_STORAGE_KEY );
				return;
			}

			window.localStorage.setItem(
				AUTH_STORAGE_KEY,
				JSON.stringify( {
					token: state.token,
					email: state.email
				} )
			);
		} catch ( e ) {
			// Ignore storage failures (private mode, disabled storage, quota).
		}
	}

	function clearPersistedAuthState() {
		if ( ! window.localStorage ) {
			return;
		}

		try {
			window.localStorage.removeItem( AUTH_STORAGE_KEY );
		} catch ( e ) {
			// Ignore storage failures.
		}
	}

	function restorePersistedAuthState() {
		if ( ! window.localStorage ) {
			return false;
		}

		try {
			var raw = window.localStorage.getItem( AUTH_STORAGE_KEY );
			if ( ! raw ) {
				return false;
			}

			var parsed = JSON.parse( raw );
			if ( ! parsed || ! parsed.token ) {
				return false;
			}

			state.token = String( parsed.token || '' );
			state.email = String( parsed.email || '' );
			return !!state.token;
		} catch ( e ) {
			return false;
		}
	}

	function apiPost( action, data ) {
		var params = new URLSearchParams();
		params.append( 'action', action );
		params.append( 'trt_frontend_nonce', trtFrontend.nonce );
		params.append( 'trt_google_id_token', state.token );

		Object.keys( data || {} ).forEach( function ( key ) {
			params.append( key, data[key] );
		} );

		return window.fetch( trtFrontend.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: params.toString()
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function renderTeamOptions() {
		var select = byId( 'trt_front_team_id' );
		var quick = byId( 'trt_front_quick_hours' );
		if ( ! select ) {
			return;
		}

		select.innerHTML = '';
		state.teams.forEach( function ( team ) {
			var option = document.createElement( 'option' );
			option.value = String( team.id );
			option.textContent = team.name;
			if ( parseInt( team.id, 10 ) === parseInt( state.selectedTeamId, 10 ) ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );

		if ( quick ) {
			quick.innerHTML = '<option value="0">Custom date & time</option>';
			var selectedTeam = state.teams.find( function ( team ) {
				return parseInt( team.id, 10 ) === parseInt( state.selectedTeamId, 10 );
			} );

			( ( selectedTeam && selectedTeam.quick_hours ) || [] ).forEach( function ( hour ) {
				var option = document.createElement( 'option' );
				option.value = String( hour );
				option.textContent = 'In ' + hour + ' hours';
				quick.appendChild( option );
			} );
		}

		syncScheduleInputMode();
	}

	function syncScheduleInputMode() {
		var quick = byId( 'trt_front_quick_hours' );
		var datetime = byId( 'trt_front_datetime' );
		var datetimeLabel = document.querySelector( 'label[for="trt_front_datetime"]' );
		if ( ! quick || ! datetime ) {
			return;
		}

		if ( parseInt( quick.value || '0', 10 ) > 0 ) {
			datetime.disabled = true;
			datetime.required = false;
			datetime.hidden = true;
			datetime.style.display = 'none';
			if ( datetimeLabel ) {
				datetimeLabel.hidden = true;
				datetimeLabel.style.display = 'none';
			}
		} else {
			datetime.disabled = false;
			datetime.required = true;
			datetime.hidden = false;
			datetime.style.display = '';
			if ( datetimeLabel ) {
				datetimeLabel.hidden = false;
				datetimeLabel.style.display = '';
			}
		}
	}

	function renderList() {
		var root = byId( 'trt-reminders-list' );
		if ( ! root ) {
			return;
		}
		root.innerHTML = '';

		if ( ! state.items.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'trt-empty';
			empty.textContent = trtFrontend.i18n.emptyState;
			root.appendChild( empty );
			return;
		}

		state.items.forEach( function ( item ) {
			var card = document.createElement( 'article' );
			card.className = 'trt-card trt-card--' + item.status;
			card.innerHTML = [
				'<div class="trt-card-head">',
				'<h4>' + escapeHtml( item.title ) + '</h4>',
				'<span class="trt-chip trt-chip--' + escapeHtml( item.status ) + '">' + escapeHtml( item.status ) + '</span>',
				'</div>',
				'<p class="trt-meta">' + escapeHtml( item.display_time ) + '</p>',
				item.comments ? '<p class="trt-comments">' + escapeHtml( item.comments ) + '</p>' : '',
				item.task_link ? '<p><a href="' + escapeAttr( item.task_link ) + '" target="_blank" rel="noopener noreferrer">Open link</a></p>' : '',
				'<div class="trt-card-actions">',
				'<button type="button" class="button" data-edit-id="' + item.id + '">' + trtFrontend.i18n.edit + '</button>',
				'</div>'
			].join( '' );
			root.appendChild( card );
		} );

		root.querySelectorAll( '[data-edit-id]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = parseInt( btn.getAttribute( 'data-edit-id' ), 10 );
				startEdit( id );
			} );
		} );
	}

	function startEdit( id ) {
		var item = state.items.find( function ( r ) {
			return r.id === id;
		} );
		if ( ! item ) {
			return;
		}

		byId( 'trt_front_id' ).value = String( item.id );
		byId( 'trt_front_team_id' ).value = String( item.team_id || state.selectedTeamId || 0 );
		byId( 'trt_front_title' ).value = item.title || '';
		byId( 'trt_front_quick_hours' ).value = '0';
		byId( 'trt_front_datetime' ).value = item.datetime_local || '';
		byId( 'trt_front_link' ).value = item.task_link || '';
		byId( 'trt_front_comments' ).value = item.comments || '';
		byId( 'trt-form-title' ).textContent = trtFrontend.i18n.editTitle;
		byId( 'trt-front-cancel' ).hidden = false;
		window.scrollTo( { top: byId( 'trt-reminder-form' ).offsetTop - 80, behavior: 'smooth' } );
	}

	function resetForm() {
		byId( 'trt_front_id' ).value = '0';
		byId( 'trt-reminder-form' ).reset();
		if ( byId( 'trt_front_team_id' ) && state.selectedTeamId ) {
			byId( 'trt_front_team_id' ).value = String( state.selectedTeamId );
		}
		if ( byId( 'trt_front_quick_hours' ) ) {
			byId( 'trt_front_quick_hours' ).value = '0';
		}
		syncScheduleInputMode();
		byId( 'trt_google_id_token' ).value = state.token;
		byId( 'trt-form-title' ).textContent = trtFrontend.i18n.createTitle;
		byId( 'trt-front-cancel' ).hidden = true;
	}

	function loadReminders() {
		return apiPost( 'trt_frontend_list_reminders', { trt_team_id: state.selectedTeamId } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				throw new Error( ( res && res.data && res.data.message ) || 'Failed to load reminders.' );
			}
			state.email = res.data.email || state.email;
			state.teams = res.data.teams || state.teams;
			state.selectedTeamId = parseInt( res.data.selected_team_id || state.selectedTeamId || 0, 10 );
			renderTeamOptions();
			state.items = res.data.items || [];
			renderList();
		} );
	}

	function bindEvents() {
		var form = byId( 'trt-reminder-form' );
		var cancel = byId( 'trt-front-cancel' );
		var refresh = byId( 'trt-refresh-list' );
		var signOut = byId( 'trt-sign-out' );
		var teamSelect = byId( 'trt_front_team_id' );
		var quickSelect = byId( 'trt_front_quick_hours' );

		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				clearNotice();

				var payload = {
					id: byId( 'trt_front_id' ).value,
					trt_team_id: byId( 'trt_front_team_id' ).value,
					trt_quick_hours: byId( 'trt_front_quick_hours' ).value,
					trt_title: byId( 'trt_front_title' ).value,
					trt_reminder_datetime: byId( 'trt_front_datetime' ).value,
					trt_link: byId( 'trt_front_link' ).value,
					trt_comments: byId( 'trt_front_comments' ).value
				};

				apiPost( 'trt_frontend_save_reminder', payload )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							throw new Error( ( res && res.data && res.data.message ) || 'Save failed.' );
						}
						showNotice( res.data.mode === 'updated' ? trtFrontend.i18n.updatedMessage : trtFrontend.i18n.createdMessage, 'success' );
						resetForm();
						return loadReminders();
					} )
					.catch( function ( err ) {
						showNotice( err.message || 'Save failed.', 'error' );
					} );
			} );
		}

		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				resetForm();
			} );
		}

		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				loadReminders().catch( function ( err ) {
					showNotice( err.message || 'Refresh failed.', 'error' );
				} );
			} );
		}

		if ( teamSelect ) {
			teamSelect.addEventListener( 'change', function () {
				state.selectedTeamId = parseInt( teamSelect.value || '0', 10 );
				loadReminders().catch( function ( err ) {
					showNotice( err.message || 'Refresh failed.', 'error' );
				} );
			} );
		}

		if ( quickSelect ) {
			quickSelect.addEventListener( 'change', function () {
				syncScheduleInputMode();
			} );
		}

		if ( signOut ) {
			signOut.addEventListener( 'click', function () {
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				clearPersistedAuthState();
				resetForm();
				renderTeamOptions();
				renderList();
				setSignedInUI( false );
				clearNotice();
			} );
		}
	}

	function escapeHtml( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escapeAttr( str ) {
		return escapeHtml( str );
	}

	// Expose sign-in handler so window.trtOnGoogleCallback can call it.
	window._trtPortal = {
		onGoogleSignIn: function ( response ) {
			var payload = decodeJwtPayload( response.credential || '' );
			if ( ! payload || ! payload.email ) {
				showNotice( trtFrontend.i18n.signInError, 'error' );
				return;
			}
			state.token = response.credential || '';
			state.email = payload.email || '';
			persistAuthState();
			setSignedInUI( true );
			resetForm();
			clearNotice();
			loadReminders().catch( function ( err ) {
				clearPersistedAuthState();
				state.token = '';
				setSignedInUI( false );
				showNotice( err.message || trtFrontend.i18n.signInError, 'error' );
			} );
		}
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.querySelector( '[data-trt-portal="1"]' ) ) {
			return;
		}
		bindEvents();

		if ( restorePersistedAuthState() ) {
			setSignedInUI( true );
			resetForm();
			loadReminders().catch( function () {
				clearPersistedAuthState();
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				setSignedInUI( false );
				showNotice( trtFrontend.i18n.sessionExpired || trtFrontend.i18n.signInError, 'error' );
			} );
			return;
		}

		setSignedInUI( false );
	} );

} )();
