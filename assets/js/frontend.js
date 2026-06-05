/* global trtFrontend */

// ---- Global Google callback (must be outside IIFE so Google GSI can call it) ----
window.trtOnGoogleCallback = function (response) {
	'use strict';
	if (!window._trtPortal) {
		return;
	}
	window._trtPortal.onGoogleSignIn(response);
};

// ---- Global Google callback for the view portal ----
window.trtOnViewCallback = function (response) {
	'use strict';
	if (!window._trtView) {
		return;
	}
	window._trtView.onGoogleSignIn(response);
};

(function () {
	'use strict';
	var AUTH_STORAGE_KEY = 'trtFrontendAuth';

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
		var el = byId('trt-portal-notice');
		if (!el) {
			return;
		}
		el.hidden = false;
		el.className = 'trt-frontend-notice trt-frontend-notice--' + type;
		el.textContent = message;
	}

	function clearNotice() {
		var el = byId('trt-portal-notice');
		if (!el) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
	}

	function setSignedInUI(isSignedIn) {
		var gate = byId('trt-auth-gate');
		var app = byId('trt-portal-app');
		var emailEl = byId('trt-user-email');
		var tokenInput = byId('trt_google_id_token');

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
		params.append('trt_frontend_nonce', trtFrontend.nonce);
		params.append('trt_google_id_token', state.token);

		Object.keys(data || {}).forEach(function (key) {
			params.append(key, data[key]);
		});

		return window.fetch(trtFrontend.ajaxUrl, {
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
		var select = byId('trt_front_team_id');
		var quick = byId('trt_front_quick_hours');
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
		var quick = byId('trt_front_quick_hours');
		var datetime = byId('trt_front_datetime');
		var datetimeLabel = document.querySelector('label[for="trt_front_datetime"]');
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
		var root = byId('trt-reminders-list');
		if (!root) {
			return;
		}
		root.innerHTML = '';

		if (!state.items.length) {
			var empty = document.createElement('p');
			empty.className = 'trt-empty';
			empty.textContent = trtFrontend.i18n.emptyState;
			root.appendChild(empty);
			return;
		}

		state.items.forEach(function (item) {
			var card = document.createElement('article');
			card.className = 'trt-card trt-card--' + item.status;
			card.innerHTML = [
				'<div class="trt-card-head">',
				'<h4>' + escapeHtml(item.title) + '</h4>',
				'<span class="trt-chip trt-chip--' + escapeHtml(item.status) + '">' + escapeHtml(item.status) + '</span>',
				'</div>',
				'<p class="trt-meta">' + escapeHtml(item.display_time) + '</p>',
				item.comments ? '<p class="trt-comments">' + escapeHtml(item.comments) + '</p>' : '',
				item.task_link ? '<p class="trt-card-link"><a href="' + escapeAttr(item.task_link) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.task_link) + '</a></p>' : '',
				item.added_by ? '<p class="trt-added-by">Added by ' + escapeHtml(item.added_by) + '</p>' : '',
				'<div class="trt-card-actions">',
				'<button type="button" class="button" data-edit-id="' + item.id + '">' + trtFrontend.i18n.edit + '</button>',
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

		byId('trt_front_id').value = String(item.id);
		byId('trt_front_team_id').value = String(item.team_id || state.selectedTeamId || 0);
		byId('trt_front_title').value = item.title || '';
		byId('trt_front_quick_hours').value = '0';
		byId('trt_front_datetime').value = item.datetime_local || '';
		byId('trt_front_link').value = item.task_link || '';
		byId('trt_front_comments').value = item.comments || '';
		byId('trt-form-title').textContent = trtFrontend.i18n.editTitle;
		byId('trt-front-cancel').hidden = false;
		window.scrollTo({ top: byId('trt-reminder-form').offsetTop - 80, behavior: 'smooth' });
	}

	function resetForm() {
		byId('trt_front_id').value = '0';
		byId('trt-reminder-form').reset();
		if (byId('trt_front_team_id') && state.selectedTeamId) {
			byId('trt_front_team_id').value = String(state.selectedTeamId);
		}
		if (byId('trt_front_quick_hours')) {
			byId('trt_front_quick_hours').value = '0';
		}
		syncScheduleInputMode();
		byId('trt_google_id_token').value = state.token;
		byId('trt-form-title').textContent = trtFrontend.i18n.createTitle;
		byId('trt-front-cancel').hidden = true;
	}

	function setMainActiveTab(status) {
		state.listStatus = status;
		var tabs = document.querySelectorAll('#trt-main-status-tabs .trt-tab');
		tabs.forEach(function (tab) {
			tab.classList.toggle('trt-tab--active', tab.getAttribute('data-status') === status);
		});
	}

	function syncMainOrderUI() {
		var btn = byId('trt-main-order-toggle');
		if (!btn) {
			return;
		}
		btn.setAttribute('data-order', state.listOrder);
		var icon = btn.querySelector('.trt-order-icon');
		var label = btn.querySelector('.trt-order-label');
		if (icon) { icon.textContent = 'ASC' === state.listOrder ? '↑' : '↓'; }
		if (label) { label.textContent = state.listOrder; }
	}

	function loadReminders() {
		var payload = {
			trt_team_id: state.selectedTeamId,
			trt_orderby: state.listOrderby,
			trt_order: state.listOrder,
			trt_status: state.listStatus
		};
		return apiPost('trt_frontend_list_reminders', payload).then(function (res) {
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
		var form = byId('trt-reminder-form');
		var cancel = byId('trt-front-cancel');
		var refresh = byId('trt-refresh-list');
		var signOut = byId('trt-sign-out');
		var teamSelect = byId('trt_front_team_id');
		var quickSelect = byId('trt_front_quick_hours');

		// --- filter tabs ---
		var statusTabs = document.querySelectorAll('#trt-main-status-tabs .trt-tab');
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
		var orderbySelect = byId('trt-main-orderby');
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
		var orderBtn = byId('trt-main-order-toggle');
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
					id: byId('trt_front_id').value,
					trt_team_id: byId('trt_front_team_id').value,
					trt_quick_hours: byId('trt_front_quick_hours').value,
					trt_title: byId('trt_front_title').value,
					trt_reminder_datetime: byId('trt_front_datetime').value,
					trt_link: byId('trt_front_link').value,
					trt_comments: byId('trt_front_comments').value
				};

				apiPost('trt_frontend_save_reminder', payload)
					.then(function (res) {
						if (!res || !res.success) {
							throw new Error((res && res.data && res.data.message) || 'Save failed.');
						}
						var createdId = 0;
						if (res && res.data && res.data.mode === 'created') {
							createdId = parseInt((res.data.reminder_id || (res.data.reminder && res.data.reminder.id) || 0), 10) || 0;
						}

						if (res.data.mode === 'updated') {
							showNotice(trtFrontend.i18n.updatedMessage, 'success');
						} else if (createdId > 0) {
							showNotice(trtFrontend.i18n.createdMessage + ' #' + createdId, 'success');
						} else {
							showNotice(trtFrontend.i18n.createdMessage, 'success');
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

	// Expose sign-in handler so window.trtOnGoogleCallback can call it.
	window._trtPortal = {
		onGoogleSignIn: function (response) {
			var payload = decodeJwtPayload(response.credential || '');
			if (!payload || !payload.email) {
				showNotice(trtFrontend.i18n.signInError, 'error');
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
				showNotice(err.message || trtFrontend.i18n.signInError, 'error');
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('[data-trt-portal="1"]')) {
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
				showNotice(trtFrontend.i18n.sessionExpired || trtFrontend.i18n.signInError, 'error');
			});
			return;
		}

		setSignedInUI(false);
	});

})();

// =====================================================================
// View Portal — Upcoming Reminders (read-only, sorted list)
// =====================================================================

/* global trtView */

(function () {
	'use strict';

	var VIEW_AUTH_KEY = 'trtViewAuth';

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
		var el = vById('trt-view-notice');
		if (!el) {
			return;
		}
		el.hidden = false;
		el.className = 'trt-frontend-notice trt-frontend-notice--' + type;
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
		var el = vById('trt-view-notice');
		if (!el) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
	}

	function setViewSignedInUI(isSignedIn) {
		var gate = vById('trt-view-auth-gate');
		var app = vById('trt-view-app');
		var emailEl = vById('trt-view-user-email');
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
		params.append('action', 'trt_frontend_view_reminders');
		params.append('trt_view_nonce', trtView.nonce);
		params.append('trt_google_id_token', state.token);
		Object.keys(data || {}).forEach(function (key) {
			params.append(key, data[key]);
		});
		return window.fetch(trtView.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: params.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function renderViewTeamOptions() {
		var select = vById('trt-view-team');
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
		var root = vById('trt-view-list');
		var countEl = vById('trt-view-count');
		if (!root) {
			return;
		}
		root.innerHTML = '';

		var count = state.items.length;
		if (countEl) {
			countEl.hidden = false;
			var label = 1 === count ? trtView.i18n.countSingular : trtView.i18n.countPlural;
			countEl.textContent = count + ' ' + label;
		}

		if (!count) {
			var empty = document.createElement('p');
			empty.className = 'trt-empty';
			empty.textContent = trtView.i18n.emptyState;
			root.appendChild(empty);
			return;
		}

		var nowTs = Math.floor(Date.now() / 1000);

		state.items.forEach(function (item) {
			var isUpcoming = item.remind_at_ts && item.remind_at_ts > nowTs && 'pending' === item.status;
			var card = document.createElement('article');
			card.className = 'trt-card trt-card--' + escapeHtml(item.status) + (isUpcoming ? ' trt-card--upcoming' : '');

			var linkHtml = item.task_link
				? '<p class="trt-card-link" style="margin:8px 0 0">' +
				'<a href="' + escapeAttr(item.task_link) + '" target="_blank" rel="noopener noreferrer">' +
				'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
				escapeHtml(item.task_link) + '</a></p>'
				: '';;

			card.innerHTML =
				'<div class="trt-card-head">' +
				'<h4>' + escapeHtml(item.title) + '</h4>' +
				'<span class="trt-chip trt-chip--' + escapeHtml(item.status) + '">' + escapeHtml(statusLabel(item.status)) + '</span>' +
				'</div>' +
				'<div class="trt-meta-row">' +
				'<span class="trt-meta-item">' +
				'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
				'<span><strong>Trigger:</strong> ' + escapeHtml(item.display_time) + '</span>' +
				'</span>' +
				'<span class="trt-meta-item">' +
				'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
				'<span><strong>Added:</strong> ' + escapeHtml(item.created_display) + '</span>' +
				'</span>' +
				'</div>' +
				(item.comments ? '<p class="trt-comments">' + escapeHtml(item.comments) + '</p>' : '') +
				linkHtml;

			root.appendChild(card);
		});
	}

	function loadViewReminders() {
		return viewApiPost({
			trt_team_id: state.selectedTeamId,
			trt_orderby: state.orderby,
			trt_order: state.order,
			trt_status: state.status
		}).then(function (res) {
			if (!res || !res.success) {
				throw new Error((res && res.data && res.data.message) || trtView.i18n.loadError);
			}
			state.email = res.data.email || state.email;
			state.teams = res.data.teams || state.teams;
			state.selectedTeamId = parseInt(res.data.selected_team_id || state.selectedTeamId || 0, 10);
			renderViewTeamOptions();
			var emailEl = vById('trt-view-user-email');
			if (emailEl) {
				emailEl.textContent = state.email;
			}
			state.items = res.data.items || [];
			renderViewList();
		});
	}

	function setActiveTab(status) {
		state.status = status;
		var tabs = document.querySelectorAll('#trt-view-status-tabs .trt-tab');
		tabs.forEach(function (tab) {
			tab.classList.toggle('trt-tab--active', tab.getAttribute('data-status') === status);
		});
	}

	function syncOrderUI() {
		var btn = vById('trt-view-order-toggle');
		if (!btn) {
			return;
		}
		btn.setAttribute('data-order', state.order);
		var icon = btn.querySelector('.trt-order-icon');
		var label = btn.querySelector('.trt-order-label');
		if (icon) { icon.textContent = 'ASC' === state.order ? '↑' : '↓'; }
		if (label) { label.textContent = state.order; }
	}

	function bindViewEvents() {
		// Status tabs
		var tabs = document.querySelectorAll('#trt-view-status-tabs .trt-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				setActiveTab(tab.getAttribute('data-status') || '');
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || trtView.i18n.loadError, 'error');
				});
			});
		});

		// Sort-by select
		var orderbySelect = vById('trt-view-orderby');
		if (orderbySelect) {
			orderbySelect.addEventListener('change', function () {
				state.orderby = orderbySelect.value || 'remind_at';
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || trtView.i18n.loadError, 'error');
				});
			});
		}

		// Sort-order toggle
		var orderBtn = vById('trt-view-order-toggle');
		if (orderBtn) {
			orderBtn.addEventListener('click', function () {
				state.order = 'ASC' === state.order ? 'DESC' : 'ASC';
				syncOrderUI();
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || trtView.i18n.loadError, 'error');
				});
			});
		}

		// Team select
		var teamSelect = vById('trt-view-team');
		if (teamSelect) {
			teamSelect.addEventListener('change', function () {
				state.selectedTeamId = parseInt(teamSelect.value || '0', 10);
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || trtView.i18n.loadError, 'error');
				});
			});
		}

		// Refresh
		var refreshBtn = vById('trt-view-refresh');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function () {
				clearViewNotice();
				loadViewReminders().catch(function (err) {
					showViewNotice(err.message || trtView.i18n.loadError, 'error');
				});
			});
		}

		// Sign out
		var signOut = vById('trt-view-sign-out');
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

	window._trtView = {
		onGoogleSignIn: function (response) {
			var payload = decodeJwtPayload(response.credential || '');
			if (!payload || !payload.email) {
				showViewNotice(trtView.i18n.signInError, 'error');
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
				showViewNotice(err.message || trtView.i18n.signInError, 'error');
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('[data-trt-view="1"]')) {
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
				showViewNotice(trtView.i18n.sessionExpired || trtView.i18n.signInError, 'error');
			});
			return;
		}

		setViewSignedInUI(false);
	});

})();
