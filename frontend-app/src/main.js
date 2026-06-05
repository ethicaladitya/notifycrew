import './style.css';

const API_BASE = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '');
const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || '';
const AUTH_KEY = 'trtPortalAuth';

const state = {
  token: '',
  email: '',
  teams: [],
  selectedTeamId: 0,
  items: [],
  portalOpen: false,
};

function html() {
  return `
    <main>
      <section class="hero">
        <span class="badge">team portal</span>
        <h1>Reminders Workspace</h1>
        <p>Private workspace for team reminder operations.</p>
      </section>

      <section id="public-home" class="auth card">
        <h3>Private Access</h3>
        <p>This workspace is restricted to authorized team members.</p>
        <div class="actions">
          <button class="primary" id="open-portal-btn" type="button">Open member portal</button>
        </div>
      </section>

      <section id="auth" class="auth card hidden">
        <h3>Member Sign-In</h3>
        <div id="google-signin"></div>
      </section>

      <section id="app-shell" class="hidden">
        <div class="toolbar">
          <div>
            <div class="meta">Signed in as</div>
            <strong id="user-email"></strong>
          </div>
          <div class="actions">
            <button class="ghost" id="refresh-btn" type="button">Refresh</button>
            <button class="ghost" id="signout-btn" type="button">Sign out</button>
          </div>
        </div>

        <div id="notice" class="notice hidden"></div>

        <div class="app-grid">
          <article class="panel card">
            <h3 id="form-title">Create Reminder</h3>
            <form id="reminder-form">
              <input type="hidden" id="id" value="0" />

              <div class="field">
                <label for="team-id">Team</label>
                <select id="team-id" required></select>
              </div>

              <div class="field">
                <label for="title">Title</label>
                <input id="title" type="text" required />
              </div>

              <div class="field">
                <label for="quick-hours">Quick Schedule</label>
                <select id="quick-hours">
                  <option value="0">Custom date & time</option>
                </select>
              </div>

              <div class="field" id="datetime-wrap">
                <label for="reminder-datetime">Date & time (UTC)</label>
                <input id="reminder-datetime" type="datetime-local" />
              </div>

              <div class="field">
                <label for="link">Task/Ticket/Slack Link</label>
                <input id="link" type="url" />
              </div>

              <div class="field">
                <label for="comments">Comments</label>
                <textarea id="comments"></textarea>
              </div>

              <div class="actions">
                <button class="primary" type="submit">Save reminder</button>
                <button class="ghost" id="cancel-btn" type="button">Cancel edit</button>
              </div>
            </form>
          </article>

          <article class="panel card">
            <h3>Your Reminders</h3>
            <div id="list" class="list"></div>
          </article>
        </div>
      </section>
    </main>
  `;
}

function byId(id) {
  return document.getElementById(id);
}

function showNotice(message, kind) {
  const el = byId('notice');
  el.classList.remove('hidden', 'success', 'error');
  el.classList.add(kind);
  el.textContent = message;
}

function clearNotice() {
  const el = byId('notice');
  el.className = 'notice hidden';
  el.textContent = '';
}

function setSignedInUi(isSignedIn) {
  const publicHome = byId('public-home');
  const auth = byId('auth');

  publicHome.classList.toggle('hidden', isSignedIn || state.portalOpen);
  auth.classList.toggle('hidden', isSignedIn || !state.portalOpen);
  byId('app-shell').classList.toggle('hidden', !isSignedIn);
  byId('user-email').textContent = state.email;
}

function openPortal() {
  state.portalOpen = true;
  setSignedInUi(false);
  clearNotice();
  initGoogleSignIn();
}

async function ensureGoogleScript() {
  if (window.google?.accounts?.id) {
    return;
  }

  await new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    script.onload = resolve;
    script.onerror = () => reject(new Error('Google Sign-In failed to initialize.'));
    document.head.appendChild(script);
  });
}

function persistAuth() {
  if (!state.token) {
    localStorage.removeItem(AUTH_KEY);
    return;
  }

  localStorage.setItem(
    AUTH_KEY,
    JSON.stringify({
      token: state.token,
      email: state.email,
    }),
  );
}

function restoreAuth() {
  const raw = localStorage.getItem(AUTH_KEY);
  if (!raw) {
    return false;
  }

  try {
    const parsed = JSON.parse(raw);
    if (!parsed.token) {
      return false;
    }

    state.token = String(parsed.token || '');
    state.email = String(parsed.email || '');
    return true;
  } catch {
    return false;
  }
}

async function apiPost(path, payload) {
  const response = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json();
  if (!response.ok || !data.success) {
    throw new Error(data.message || data.error || 'Request failed');
  }

  return data.data;
}

async function bootstrap(teamId = 0) {
  const data = await apiPost('/portal/bootstrap', {
    id_token: state.token,
    team_id: teamId,
  });

  state.email = data.email || state.email;
  state.teams = Array.isArray(data.teams) ? data.teams : [];
  state.selectedTeamId = Number(data.selected_team_id || teamId || 0);
  state.items = Array.isArray(data.items) ? data.items : [];

  renderTeams();
  renderList();
}

function renderTeams() {
  const select = byId('team-id');
  const quick = byId('quick-hours');

  select.innerHTML = '';
  state.teams.forEach((team) => {
    const option = document.createElement('option');
    option.value = String(team.id);
    option.textContent = team.name;
    option.selected = Number(team.id) === Number(state.selectedTeamId);
    select.appendChild(option);
  });

  const selectedTeam = state.teams.find((team) => Number(team.id) === Number(state.selectedTeamId));
  quick.innerHTML = '<option value="0">Custom date & time</option>';
  (selectedTeam?.quick_hours || []).forEach((hour) => {
    const option = document.createElement('option');
    option.value = String(hour);
    option.textContent = `In ${hour} hours`;
    quick.appendChild(option);
  });

  syncDateVisibility();
}

function renderList() {
  const list = byId('list');
  list.innerHTML = '';

  if (!state.items.length) {
    list.innerHTML = '<p>No reminders yet.</p>';
    return;
  }

  state.items.forEach((item) => {
    const article = document.createElement('article');
    article.className = 'item';
    const chipClass = `chip chip-${escapeHtml(item.status)}`;
    article.innerHTML = `
      <div class="item-header">
        <h4>${escapeHtml(item.title)}</h4>
        <span class="${chipClass}">${escapeHtml(item.status)}</span>
      </div>
      <p class="time">${escapeHtml(item.display_time)}</p>
      ${item.comments ? `<p class="item-comments">${escapeHtml(item.comments)}</p>` : ''}
      ${item.task_link ? `<p class="item-link"><a href="${escapeAttr(item.task_link)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.task_link)}</a></p>` : ''}
      ${item.added_by ? `<p class="item-meta">Added by ${escapeHtml(item.added_by)}</p>` : ''}
      <div class="item-footer">
        <button class="ghost" type="button" data-id="${Number(item.id)}">Edit</button>
      </div>
    `;
    list.appendChild(article);
  });

  list.querySelectorAll('button[data-id]').forEach((button) => {
    button.addEventListener('click', () => {
      startEdit(Number(button.getAttribute('data-id')));
    });
  });
}

function startEdit(id) {
  const item = state.items.find((candidate) => Number(candidate.id) === id);
  if (!item) {
    return;
  }

  byId('id').value = String(item.id);
  byId('team-id').value = String(item.team_id || state.selectedTeamId || 0);
  byId('title').value = item.title || '';
  byId('quick-hours').value = '0';
  byId('reminder-datetime').value = item.datetime_local || '';
  byId('link').value = item.task_link || '';
  byId('comments').value = item.comments || '';
  byId('form-title').textContent = 'Edit Reminder';
  syncDateVisibility();
}

function resetForm() {
  byId('id').value = '0';
  byId('reminder-form').reset();
  byId('form-title').textContent = 'Create Reminder';
  byId('team-id').value = String(state.selectedTeamId || 0);
  byId('quick-hours').value = '0';
  syncDateVisibility();
}

function syncDateVisibility() {
  const quick = Number(byId('quick-hours').value || 0);
  byId('datetime-wrap').classList.toggle('hidden', quick > 0);
  byId('reminder-datetime').required = quick === 0;
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttr(value) {
  return escapeHtml(value);
}

function decodePayload(token) {
  try {
    const payload = token.split('.')[1];
    return JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/')));
  } catch {
    return null;
  }
}

async function saveReminder(event) {
  event.preventDefault();
  clearNotice();

  const payload = {
    id_token: state.token,
    id: Number(byId('id').value || 0),
    team_id: Number(byId('team-id').value || 0),
    quick_hours: Number(byId('quick-hours').value || 0),
    title: byId('title').value,
    reminder_datetime: byId('reminder-datetime').value,
    link: byId('link').value,
    comments: byId('comments').value,
  };

  try {
    const data = await apiPost('/portal/reminder', payload);
    const createdId = data && data.mode === 'created'
      ? Number(data.reminder_id || (data.reminder && data.reminder.id) || 0)
      : 0;

    if (data.mode === 'updated') {
      showNotice('Reminder updated.', 'success');
    } else if (createdId > 0) {
      showNotice('Reminder created. Reminder ID: #' + createdId, 'success');
    } else {
      showNotice('Reminder created.', 'success');
    }
    resetForm();
    await bootstrap(state.selectedTeamId);
  } catch (error) {
    showNotice(error.message, 'error');
  }
}

function bindEvents() {
  byId('open-portal-btn').addEventListener('click', openPortal);

  byId('reminder-form').addEventListener('submit', saveReminder);
  byId('cancel-btn').addEventListener('click', resetForm);

  byId('quick-hours').addEventListener('change', syncDateVisibility);

  byId('team-id').addEventListener('change', async (event) => {
    state.selectedTeamId = Number(event.target.value || 0);
    try {
      await bootstrap(state.selectedTeamId);
    } catch (error) {
      showNotice(error.message, 'error');
    }
  });

  byId('refresh-btn').addEventListener('click', async () => {
    try {
      await bootstrap(state.selectedTeamId);
      showNotice('Reminders refreshed.', 'success');
    } catch (error) {
      showNotice(error.message, 'error');
    }
  });

  byId('signout-btn').addEventListener('click', () => {
    localStorage.removeItem(AUTH_KEY);
    state.token = '';
    state.email = '';
    state.teams = [];
    state.selectedTeamId = 0;
    state.items = [];
    state.portalOpen = false;
    clearNotice();
    setSignedInUi(false);
  });
}

async function initGoogleSignIn() {
  if (!GOOGLE_CLIENT_ID) {
    showNotice('Missing VITE_GOOGLE_CLIENT_ID configuration.', 'error');
    return;
  }

  try {
    await ensureGoogleScript();
  } catch (error) {
    showNotice(error.message, 'error');
    return;
  }

  if (!window.google?.accounts?.id) {
    showNotice('Google Sign-In failed to initialize.', 'error');
    return;
  }

  window.google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback: async (response) => {
      const payload = decodePayload(response.credential || '');
      if (!payload?.email) {
        showNotice('Google Sign-In failed.', 'error');
        return;
      }

      state.token = response.credential;
      state.email = payload.email;
      state.portalOpen = true;
      persistAuth();
      setSignedInUi(true);

      try {
        await bootstrap();
      } catch (error) {
        localStorage.removeItem(AUTH_KEY);
        state.token = '';
        setSignedInUi(false);
        showNotice(error.message, 'error');
      }
    },
  });

  window.google.accounts.id.renderButton(byId('google-signin'), {
    theme: 'filled_blue',
    size: 'large',
    shape: 'pill',
    text: 'signin_with',
  });
}

async function init() {
  document.querySelector('#app').innerHTML = html();
  bindEvents();

  if (restoreAuth()) {
    state.portalOpen = true;
    await initGoogleSignIn();
    setSignedInUi(true);
    try {
      await bootstrap();
      return;
    } catch {
      localStorage.removeItem(AUTH_KEY);
      state.token = '';
      state.email = '';
      state.teams = [];
      state.selectedTeamId = 0;
      state.items = [];
      state.portalOpen = false;
      setSignedInUi(false);
      showNotice('Session expired. Please sign in again.', 'error');
    }
  }

  setSignedInUi(false);
}

init();
