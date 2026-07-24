/* global ncrwFrontend */

// ---- Global Google callback (must be outside IIFE so Google GSI can call it) ----
window.ncrwOnGoogleCallback = function (response) {
	'use strict';
	if (!window._ncrwPortal) {
		return;
	}
	window._ncrwPortal.onGoogleSignIn(response);
};

// ---- Global Google callback for the view portal ----
window.ncrwOnViewCallback = function (response) {
	'use strict';
	if (!window._ncrwView) {
		return;
	}
	window._ncrwView.onGoogleSignIn(response);
};

(function () {
	'use strict';
	var AUTH_STORAGE_KEY = 'ncrwFrontendAuth';

	var state = {
		token: '',
		email: '',
		teams: [],
		selectedTeamId: 0,
		items: [],
		listStatus: '',
		listOrderby: 'remind_at',
		listOrder: 'DESC' // Default to DESC for Trigger Date
	};

	function decodeJwtPayload(token) {
		try {
			var payload = token.split('.')[1];
			var json = window.atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
			return JSON.parse(json);
		} catch (e) {
			return null;
		}
	}

	function byId(id) {
		return document.getElementById(id);
	}

	function showNotice(message, type) {
		var el = byId('ncrw-portal-notice');
		if (!el) {
			return;
		}
		el.hidden = false;
		el.className = 'ncrw-frontend-notice ncrw-frontend-notice--' + type;
		el.textContent = message;
	}

	function clearNotice() {
		var el = byId('ncrw-portal-notice');
		if (!el) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
	}

	function setSignedInUI(isSignedIn) {
		var gate = byId('ncrw-auth-gate');
		var app = byId('ncrw-portal-app');
		var emailEl = byId('ncrw-user-email');
		var tokenInput = byId('ncrw_google_id_token');

		// Use explicit display style so theme CSS cannot override [hidden]
		if (gate) {
			gate.style.display = isSignedIn ? 'none' : '';
		}
		if (app) {
			app.hidden = !isSignedIn;
			app.style.display = isSignedIn ? '' : 'none';
		}
		if (emailEl) {
			emailEl.textContent = state.email;
		}
		if (tokenInput) {
			tokenInput.value = state.token;
		}
	}

	function persistAuthState() {
		if (!window.localStorage) {
			return;
		}

		try {
			if (!state.token) {
				window.localStorage.removeItem(AUTH_STORAGE_KEY);
				return;
			}

			window.localStorage.setItem(
				AUTH_STORAGE_KEY,
				JSON.stringify({
					token: state.token,
					email: state.email
				})
			);
		} catch (e) {
			// Ignore storage failures (private mode, disabled storage, quota).
		}
	}

	function clearPersistedAuthState() {
		if (!window.localStorage) {
			return;
		}

		try {
			window.localStorage.removeItem(AUTH_STORAGE_KEY);
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function restorePersistedAuthState() {
		if (!window.localStorage) {
			return false;
		}

		try {
			var raw = window.localStorage.getItem(AUTH_STORAGE_KEY);
			if (!raw) {
				return false;
			}

			var parsed = JSON.parse(raw);
			if (!parsed || !parsed.token) {
				return false;
			}

			state.token = String(parsed.token || '');
			state.email = String(parsed.email || '');
			return !!state.token;
		} catch (e) {
			return false;
		}
	}

	function apiPost(action, data) {
		var params = new URLSearchParams();
		params.append('action', action);
		params.append('ncrw_frontend_nonce', ncrwFrontend.nonce);
		params.append('ncrw_google_id_token', state.token);

		Object.keys(data || {}).forEach(function (key) {
			params.append(key, data[key]);
		});

		return window.fetch(ncrwFrontend.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: params.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function renderTeamOptions() {
		var select = byId('ncrw_front_team_id');
		var quick = byId('ncrw_front_quick_hours');
		if (!select) {
			return;
		}

		select.innerHTML = '';
		state.teams.forEach(function (team) {
			var option = document.createElement('option');
			option.value = String(team.id);
			option.textContent = team.name;
			if (parseInt(team.id, 10) === parseInt(state.selectedTeamId, 10)) {
				option.selected = true;
			}
			select.appendChild(option);
		});

		if (quick) {
			quick.innerHTML = '<option value="0">Custom date & time</option>';
			var selectedTeam = state.teams.find(function (team) {
				return parseInt(team.id, 10) === parseInt(state.selectedTeamId, 10);
			});

			((selectedTeam && selectedTeam.quick_hours) || []).forEach(function (hour) {
				var option = document.createElement('option');
				option.value = String(hour);
				option.textContent = 'In ' + hour + ' hours';
				quick.appendChild(option);
			});
		}

		syncScheduleInputMode();
	}

	function syncScheduleInputMode() {
		var quick = byId('ncrw_front_quick_hours');
		var datetime = byId('ncrw_front_datetime');
		var datetimeLabel = document.querySelector('label[for="ncrw_front_datetime"]');
		if (!quick || !datetime) {
			return;
		}

		if (parseInt(quick.value || '0', 10) > 0) {
			datetime.disabled = true;
			datetime.required = false;
			datetime.hidden = true;
			datetime.style.display = 'none';
			if (datetimeLabel) {
				datetimeLabel.hidden = true;
				datetimeLabel.style.display = 'none';
			}
		} else {
			datetime.disabled = false;
			datetime.required = true;
			datetime.hidden = false;
			datetime.style.display = '';
			if (datetimeLabel) {
				datetimeLabel.hidden = false;
				datetimeLabel.style.display = '';
			}
		}
	}

	function renderList() {
		var root = byId('ncrw-reminders-list');
		if (!root) {
			return;
		}
		root.innerHTML = '';

		if (!state.items.length) {
			var empty = document.createElement('p');
			empty.className = 'ncrw-empty';
			empty.textContent = ncrwFrontend.i18n.emptyState;
			root.appendChild(empty);
			return;
		}

		state.items.forEach(function (item) {
			var card = document.createElement('article');
			card.className = 'ncrw-card ncrw-card--' + item.status;
			card.innerHTML = [
				'<div class="ncrw-card-head">',
				'<h4>' + escapeHtml(item.title) + '</h4>',
				'<span class="ncrw-chip ncrw-chip--' + escapeHtml(item.status) + '">' + escapeHtml(item.status) + '</span>',
				'</div>',
				'<p class="ncrw-meta">' + escapeHtml(item.display_time) + '</p>',
				item.comments ? '<p class="ncrw-comments">' + escapeHtml(item.comments) + '</p>' : '',
				item.task_link ? '<p class="ncrw-card-link"><a href="' + escapeAttr(item.task_link) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.task_link) + '</a></p>' : '',
				item.added_by ? '<p class="ncrw-added-by">Added by ' + escapeHtml(item.added_by) + '</p>' : '',
				'<div class="ncrw-card-actions">',
				'<button type="button" class="button" data-edit-id="' + item.id + '">' + ncrwFrontend.i18n.edit + '</button>',
				'</div>'
			].join('');
			root.appendChild(card);
		});

		root.querySelectorAll('[data-edit-id]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = parseInt(btn.getAttribute('data-edit-id'), 10);
				startEdit(id);
			});
		});
	}

	function startEdit(id) {
		var item = state.items.find(function (r) {
			return r.id === id;
		});
		if (!item) {
			return;
		}

		byId('ncrw_front_id').value = String(item.id);
		byId('ncrw_front_team_id').value = String(item.team_id || state.selectedTeamId || 0);
		byId('ncrw_front_title').value = item.title || '';
		byId('ncrw_front_quick_hours').value = '0';
		byId('ncrw_front_datetime').value = item.datetime_local || '';
		byId('ncrw_front_link').value = item.task_link || '';
		byId('ncrw_front_comments').value = item.comments || '';
		byId('ncrw-form-title').textContent = ncrwFrontend.i18n.editTitle;
		byId('ncrw-front-cancel').hidden = false;
		window.scrollTo({ top: byId('ncrw-reminder-form').offsetTop - 80, behavior: 'smooth' });
	}

	function resetForm() {
		byId('ncrw_front_id').value = '0';
		byId('ncrw-reminder-form').reset();
		if (byId('ncrw_front_team_id') && state.selectedTeamId) {
			byId('ncrw_front_team_id').value = String(state.selectedTeamId);
		}
		if (byId('ncrw_front_quick_hours')) {
			byId('ncrw_front_quick_hours').value = '0';
		}
		syncScheduleInputMode();
		byId('ncrw_google_id_token').value = state.token;
		byId('ncrw-form-title').textContent = ncrwFrontend.i18n.createTitle;
		byId('ncrw-front-cancel').hidden = true;
	}

	function setMainActiveTab(status) {
		state.listStatus = status;
		var tabs = document.querySelectorAll('#ncrw-main-status-tabs .ncrw-tab');
		tabs.forEach(function (tab) {
			tab.classList.toggle('ncrw-tab--active', tab.getAttribute('data-status') === status);
		});
	}

	function syncMainOrderUI() {
		var btn = byId('ncrw-main-order-toggle');
		if (!btn) {
			return;
		}
		btn.setAttribute('data-order', state.listOrder);
		var icon = btn.querySelector('.ncrw-order-icon');
		var label = btn.querySelector('.ncrw-order-label');
		if (icon) { icon.textContent = 'ASC' === state.listOrder ? '↑' : '↓'; }
		if (label) { label.textContent = state.listOrder; }
	}

	function loadReminders() {
		var payload = {
			ncrw_team_id: state.selectedTeamId,
			ncrw_orderby: state.listOrderby,
			ncrw_order: state.listOrder,
			ncrw_status: state.listStatus
		};
		return apiPost('ncrw_frontend_list_reminders', payload).then(function (res) {
			if (!res || !res.success) {
				throw new Error((res && res.data && res.data.message) || 'Failed to load reminders.');
			}
			state.email = res.data.email || state.email;
			state.teams = res.data.teams || state.teams;
			state.selectedTeamId = parseInt(res.data.selected_team_id || state.selectedTeamId || 0, 10);
			renderTeamOptions();
			state.items = res.data.items || [];
			renderList();
		});
	}

	function bindEvents() {
		var form = byId('ncrw-reminder-form');
		var cancel = byId('ncrw-front-cancel');
		var refresh = byId('ncrw-refresh-list');
		var signOut = byId('ncrw-sign-out');
		var teamSelect = byId('ncrw_front_team_id');
		var quickSelect = byId('ncrw_front_quick_hours');

		// --- filter tabs ---
		var statusTabs = document.querySelectorAll('#ncrw-main-status-tabs .ncrw-tab');
		statusTabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				setMainActiveTab(tab.getAttribute('data-status') || '');
				clearNotice();
				loadReminders().catch(function (err) {
					showNotice(err.message || 'Refresh failed.', 'error');
				});
			});
		});

		// --- sort-by select ---
		var orderbySelect = byId('ncrw-main-orderby');
		if (orderbySelect) {
			orderbySelect.addEventListener('change', function () {
				state.listOrderby = orderbySelect.value || 'remind_at';
				clearNotice();
				loadReminders().catch(function (err) {
					showNotice(err.message || 'Refresh failed.', 'error');
				});
			});
		}

		// --- sort-order toggle ---
		var orderBtn = byId('ncrw-main-order-toggle');
		if (orderBtn) {
			orderBtn.addEventListener('click', function () {
				state.listOrder = 'ASC' === state.listOrder ? 'DESC' : 'ASC';
				syncMainOrderUI();
				clearNotice();
				loadReminders().catch(function (err) {
					showNotice(err.message || 'Refresh failed.', 'error');
				});
			});
		}

		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				clearNotice();

				var payload = {
					id: byId('ncrw_front_id').value,
					ncrw_team_id: byId('ncrw_front_team_id').value,
					ncrw_quick_hours: byId('ncrw_front_quick_hours').value,
					ncrw_title: byId('ncrw_front_title').value,
					ncrw_reminder_datetime: byId('ncrw_front_datetime').value,
					ncrw_link: byId('ncrw_front_link').value,
					ncrw_comments: byId('ncrw_front_comments').value
				};

				apiPost('ncrw_frontend_save_reminder', payload)
					.then(function (res) {
						if (!res || !res.success) {
							throw new Error((res && res.data && res.data.message) || 'Save failed.');
						}
						var createdId = 0;
						if (res && res.data && res.data.mode === 'created') {
							createdId = parseInt((res.data.reminder_id || (res.data.reminder && res.data.reminder.id) || 0), 10) || 0;
						}

						if (res.data.mode === 'updated') {
							showNotice(ncrwFrontend.i18n.updatedMessage, 'success');
						} else if (createdId > 0) {
							showNotice(ncrwFrontend.i18n.createdMessage + ' #' + createdId, 'success');
						} else {
							showNotice(ncrwFrontend.i18n.createdMessage, 'success');
						}
						resetForm();
						return loadReminders();
					})
					.catch(function (err) {
						showNotice(err.message || 'Save failed.', 'error');
					});
			});
		}

		if (cancel) {
			cancel.addEventListener('click', function () {
				resetForm();
			});
		}

		if (refresh) {
			refresh.addEventListener('click', function () {
				loadReminders().catch(function (err) {
					showNotice(err.message || 'Refresh failed.', 'error');
				});
			});
		}

		if (teamSelect) {
			teamSelect.addEventListener('change', function () {
				state.selectedTeamId = parseInt(teamSelect.value || '0', 10);
				loadReminders().catch(function (err) {
					showNotice(err.message || 'Refresh failed.', 'error');
				});
			});
		}

		if (quickSelect) {
			quickSelect.addEventListener('change', function () {
				syncScheduleInputMode();
			});
		}

		if (signOut) {
			signOut.addEventListener('click', function () {
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				clearPersistedAuthState();
				resetForm();
				renderTeamOptions();
				renderList();
				setSignedInUI(false);
				clearNotice();
			});
		}
	}

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function escapeAttr(str) {
		return escapeHtml(str);
	}

	// Expose sign-in handler so window.ncrwOnGoogleCallback can call it.
	window._ncrwPortal = {
		onGoogleSignIn: function (response) {
			var payload = decodeJwtPayload(response.credential || '');
			if (!payload || !payload.email) {
				showNotice(ncrwFrontend.i18n.signInError, 'error');
				return;
			}
			state.token = response.credential || '';
			state.email = payload.email || '';
			persistAuthState();
			setSignedInUI(true);
			resetForm();
			clearNotice();
			loadReminders().catch(function (err) {
				clearPersistedAuthState();
				state.token = '';
				setSignedInUI(false);
				showNotice(err.message || ncrwFrontend.i18n.signInError, 'error');
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('[data-ncrw-portal="1"]')) {
			return;
		}
		bindEvents();

		if (restorePersistedAuthState()) {
			setSignedInUI(true);
			resetForm();
			loadReminders().catch(function () {
				clearPersistedAuthState();
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				setSignedInUI(false);
				showNotice(ncrwFrontend.i18n.sessionExpired || ncrwFrontend.i18n.signInError, 'error');
			});
			return;
		}

		setSignedInUI(false);
	});

})();

// =====================================================================
// View Portal — Upcoming Reminders (read-only, sorted list)
// =====================================================================

/* global ncrwView */

(function () {
	'use strict';

	var VIEW_AUTH_KEY = 'ncrwViewAuth';

	var state = {
		token: '',
		email: '',
		teams: [],
		selectedTeamId: 0,
		items: [],
		orderby: 'remind_at',
		order: 'ASC',
		status: ''
	};

	function decodeJwtPayload(token) {
		try {
			var payload = token.split('.')[1];
			var json = window.atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
			return JSON.parse(json);
		} catch (e) {
			return null;
		}
	}

	function vById(id) {
		return document.getElementById(id);
	}

	function showViewNotice(message, type) {
		var el = vById('ncrw-view-notice');
		if (!el) {
			return;
		}
		el.hidden = false;
		el.className = 'ncrw-frontend-notice ncrw-frontend-notice--' + type;
		el.textContent = message;
		if ('success' === type) {
			setTimeout(function () {
				if (el) {
					el.hidden = true;
					el.textContent = '';
				}
			}, 4000);
		}
	}

	function clearViewNotice() {
		var el = vById('ncrw-view-notice');
		if (!el) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
	}

	function setViewSignedInUI(isSignedIn) {
		var gate = vById('ncrw-view-auth-gate');
		var app = vById('ncrw-view-app');
		var emailEl = vById('ncrw-view-user-email');
		if (gate) {
			gate.style.display = isSignedIn ? 'none' : '';
		}
		if (app) {
			app.hidden = !isSignedIn;
			app.style.display = isSignedIn ? '' : 'none';
		}
		if (emailEl) {
			emailEl.textContent = state.email;
		}
	}

	function persistViewAuth() {
		if (!window.localStorage) {
			return;
		}
		try {
			if (!state.token) {
				window.localStorage.removeItem(VIEW_AUTH_KEY);
				return;
			}
			window.localStorage.setItem(
				VIEW_AUTH_KEY,
				JSON.stringify({ token: state.token, email: state.email })
			);
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function clearPersistedViewAuth() {
		if (!window.localStorage) {
			return;
		}
		try {
			window.localStorage.removeItem(VIEW_AUTH_KEY);
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function restorePersistedViewAuth() {
		if (!window.localStorage) {
			return false;
		}
		try {
			var raw = window.localStorage.getItem(VIEW_AUTH_KEY);
			if (!raw) {
				return false;
			}
			var parsed = JSON.parse(raw);
			if (!parsed || !parsed.token) {
				return false;
			}
			state.token = String(parsed.token || '');
			state.email = String(parsed.email || '');
			return !!state.token;
		} catch (e) {
			return false;
		}
	}

	function viewApiPost(data) {
		var params = new URLSearchParams();
		params.append('action', 'ncrw_frontend_view_reminders');
		params.append('ncrw_view_nonce', ncrwView.nonce);
		params.append('ncrw_google_id_token', state.token);
		Object.keys(data || {}).forEach(function (key) {
			params.append(key, data[key]);
		});
		return window.fetch(ncrwView.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: params.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function renderViewTeamOptions() {
		var select = vById('ncrw-view-team');
		if (!select) {
			return;
		}
		select.innerHTML = '';
		state.teams.forEach(function (team) {
			var option = document.createElement('option');
			option.value = String(team.id);
			option.textContent = team.name;
			if (parseInt(team.id, 10) === parseInt(state.selectedTeamId, 10)) {
				option.selected = true;
			}
			select.appendChild(option);
		});
	}

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function escapeAttr(str) {
		return escapeHtml(str);
	}

	function statusLabel(status) {
		var map = { pending: 'Pending', sent: 'Sent', failed: 'Failed', completed: 'Completed' };
		return map[status] || status;
	}

	function renderViewList() {
		var root = vById('ncrw-view-list');
		var countEl = vById('ncrw-view-count');
		if (!root) {
			return;
		}
		root.innerHTML = '';

		var count = state.items.length;
		if (countEl) {
			countEl.hidden = false;
			var label = 1 === count ? ncrwView.i18n.countSingular : ncrwView.i18n.countPlural;
			countEl.textContent = count + ' ' + label;
		}

		if (!count) {
			var empty = document.createElement('p');
			empty.className = 'ncrw-empty';
			empty.textContent = ncrwView.i18n.emptyState;
			root.appendChild(empty);
			return;
		}

		var nowTs = Math.floor(Date.now() / 1000);

		state.items.forEach(function (item) {
			var isUpcoming = item.remind_at_ts && item.remind_at_ts > nowTs && 'pending' === item.status;
			var card = document.createElement('article');
			card.className = 'ncrw-card ncrw-card--' + escapeHtml(item.status) + (isUpcoming ? ' ncrw-card--upcoming' : '');

			var linkHtml = item.task_link
				? '<p class="ncrw-card-link" style="margin:8px 0 0">' +
				'<a href="' + escapeAttr(item.task_link) + '" target="_blank" rel="noopener noreferrer">' +
				'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
				escapeHtml(item.task_link) + '</a></p>'
				: '';;

			card.innerHTML =
				'<div class="ncrw-card-head">' +
				'<h4>' + escapeHtml(item.title) + '</h4>' +
				'<span class="ncrw-chip ncrw-chip--' + escapeHtml(item.status) + '">' + escapeHtml(statusLabel(item.status)) + '</span>' +
				'</div>' +
				'<div class="ncrw-meta-row">' +
				'<span class="ncrw-meta-item">' +
				'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
				'<span><strong>Trigger:</strong> ' + escapeHtml(item.display_time) + '</span>' +
				'</span>' +
				'<span class="ncrw-meta-item">' +
				'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
				'<span><strong>Added:</strong> ' + escapeHtml(item.created_display) + '</span>' +
				'</span>' +
				'</div>' +
				(item.comments ? '<p class="ncrw-comments">' + escapeHtml(item.comments) + '</p>' : '') +
				linkHtml;

			root.appendChild(card);
		});
	}

	function loadViewReminders() {
		return viewApiPost({
			ncrw_team_id: state.selectedTeamId,
			ncrw_orderby: state.orderby,
			ncrw_order: state.order,
			ncrw_status: state.status
		}).then(function (res) {
			if (!res || !res.success) {
				throw new Error((res && res.data && res.data.message) || ncrwView.i18n.loadError);
			}
			state.email = res.data.email || state.email;
			state.teams = res.data.teams || state.teams;
			state.selectedTeamId = parseInt(res.data.selected_team_id || state.selectedTeamId || 0, 10);
			renderViewTeamOptions();
			var emailEl = vById('ncrw-view-user-email');
			if (emailEl) {
				emailEl.textContent = state.email;
			}
			state.items = res.data.items || [];
			renderViewList();
		});
	}

	function setActiveTab(status) {
		state.status = status;
		var tabs = document.querySelectorAll('#ncrw-view-status-tabs .ncrw-tab');
		tabs.forEach(function (tab) {
			tab.classList.toggle('ncrw-tab--active', tab.getAttribute('data-status') === status);
		});
	}

	function syncOrderUI() {
		var btn = vById('ncrw-view-order-toggle');
		if (!btn) {
			return;
		}
		btn.setAttribute('data-order', state.order);
		var icon = btn.querySelector('.ncrw-order-icon');
		var label = btn.querySelector('.ncrw-order-label');
		if (icon) { icon.textContent = 'ASC' === state.order ? '↑' : '↓'; }
		if (label) { label.textContent = state.order; }
	}

	function bindViewEvents() {
		// Status tabs
		var tabs = document.querySelectorAll('#ncrw-view-status-tabs .ncrw-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				setActiveTab(tab.getAttribute('data-status') || '');
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || ncrwView.i18n.loadError, 'error');
				});
			});
		});

		// Sort-by select
		var orderbySelect = vById('ncrw-view-orderby');
		if (orderbySelect) {
			orderbySelect.addEventListener('change', function () {
				state.orderby = orderbySelect.value || 'remind_at';
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || ncrwView.i18n.loadError, 'error');
				});
			});
		}

		// Sort-order toggle
		var orderBtn = vById('ncrw-view-order-toggle');
		if (orderBtn) {
			orderBtn.addEventListener('click', function () {
				state.order = 'ASC' === state.order ? 'DESC' : 'ASC';
				syncOrderUI();
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || ncrwView.i18n.loadError, 'error');
				});
			});
		}

		// Team select
		var teamSelect = vById('ncrw-view-team');
		if (teamSelect) {
			teamSelect.addEventListener('change', function () {
				state.selectedTeamId = parseInt(teamSelect.value || '0', 10);
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || ncrwView.i18n.loadError, 'error');
				});
			});
		}

		// Refresh
		var refreshBtn = vById('ncrw-view-refresh');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function () {
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || ncrwView.i18n.loadError, 'error');
				});
			});
		}

		// Sign out
		var signOut = vById('ncrw-view-sign-out');
		if (signOut) {
			signOut.addEventListener('click', function () {
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				clearPersistedViewAuth();
				renderViewTeamOptions();
				renderViewList();
				setViewSignedInUI(false);
				clearViewNotice();
			});
		}
	}

	window._ncrwView = {
		onGoogleSignIn: function (response) {
			var payload = decodeJwtPayload(response.credential || '');
			if (!payload || !payload.email) {
				showViewNotice(ncrwView.i18n.signInError, 'error');
				return;
			}
			state.token = response.credential || '';
			state.email = payload.email || '';
			persistViewAuth();
			setViewSignedInUI(true);
			clearViewNotice();
			loadViewReminders().catch(function (err) {
				clearPersistedViewAuth();
				state.token = '';
				setViewSignedInUI(false);
				showViewNotice(err.message || ncrwView.i18n.signInError, 'error');
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('[data-ncrw-view="1"]')) {
			return;
		}

		bindViewEvents();

		if (restorePersistedViewAuth()) {
			setViewSignedInUI(true);
			loadViewReminders().catch(function () {
				clearPersistedViewAuth();
				state.token = '';
				state.email = '';
				state.teams = [];
				state.selectedTeamId = 0;
				state.items = [];
				setViewSignedInUI(false);
				showViewNotice(ncrwView.i18n.sessionExpired || ncrwView.i18n.signInError, 'error');
			});
			return;
		}

		setViewSignedInUI(false);
	});

})();
