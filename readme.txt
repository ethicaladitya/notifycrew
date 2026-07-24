=== NotifyCrew - Team reminders for Slack ===
Contributors: ethicaladitya
Tags: reminders, slack, team, notifications, scheduler
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schedule team reminders and deliver them to Slack channels with automatic retry, team management, and a Google-authenticated frontend portal.

== Description ==

**NotifyCrew** is a production-ready WordPress plugin that lets organizations schedule, manage, and deliver team reminders directly to Slack channels. It combines a full-featured admin interface with an optional Google-authenticated frontend portal so that both admins and team members can create reminders.

= Key Features =

* **Slack Integration** — Supports both Incoming Webhooks and Bot Tokens (`chat.postMessage`). Credentials are encrypted at rest using libsodium.
* **Team Management** — Create teams, assign WordPress users or email-only members, and configure per-team Slack channels, mention tags (`@channel`, `@here`), and quick-schedule presets.
* **Reminder Lifecycle** — Create, edit, delete, and filter reminders by team and status (Pending, Sent, Completed). Each reminder tracks its delivery history.
* **Automatic Retry with Exponential Backoff** — Failed Slack deliveries are automatically retried up to 5 times with increasing delays (2, 4, 8, 16, 32 minutes). Manual retry is also available from the admin list.
* **Admin Email Notifications** — After 2+ consecutive delivery failures, the site admin receives an email alert with a direct link to check Slack settings.
* **Admin Dashboard Notices** — Recent Slack delivery failures are surfaced as dismissible notices in the WordPress admin area.
* **Quick Schedule** — Per-team configurable hour presets (e.g., "In 4 hours", "In 12 hours", "In 48 hours") for fast reminder creation without picking exact dates.
* **Frontend Reminder Portal** — A beautiful, Google-authenticated shortcode-based portal (`[ncrw_frontend_reminder_form]`) lets team members create and manage reminders from any page.
* **View-Only Reminder Portal** — A read-only shortcode (`[ncrw_upcoming_reminders]`) lets team members browse upcoming reminders without editing.
* **Google OAuth Authentication** — Frontend portals verify identity via Google Sign-In tokens. Only email domains you whitelist can access the portal.
* **Headless REST API** — External frontend apps can use the `ncrw/v1/portal/*` REST endpoints with API key authentication and Google token verification for fully decoupled deployments.
* **Per-Team Slack Channels** — Each team can have its own Slack channel. A global fallback channel is used when no team-specific channel is configured.
* **Per-Team Mention Tags** — Append `@channel` or `@here` mentions to every Slack reminder sent for a specific team.
* **Rich Slack Messages** — Reminders are delivered as Block Kit messages with headers, scheduled time, task/ticket links, submitter info, and optional notes.
* **Complete Audit Log** — Every reminder event (created, sent, retry scheduled, completed) is logged with timestamps.
* **Safe Uninstall** — Plugin data (tables, options, transients) is only deleted on uninstall if you explicitly opt in via Settings → Data Cleanup.
* **WP Cron Self-Healing** — The 5-minute cron schedule auto-recovers if it is ever cleared or fails to register during activation.
* **Fully Translatable** — All user-facing strings use WordPress i18n functions with the `notifycrew` text domain. A `.pot` file is included.

= Admin Menu Structure =

After activation, a **Reminders** menu (with a bell icon) appears in the WordPress admin sidebar with the following sub-pages:

* **All Reminders** — Filterable list of all reminders with team/status filters, pagination, retry, edit, and delete actions.
* **Add Reminder** — Create or edit a reminder with team selection, quick-schedule, date/time picker, task link, and comments.
* **Teams** — Create teams, manage members (WordPress users or email-only), configure Slack channel, mention tag, and quick-schedule hours.
* **Settings** — Configure Slack credentials (webhook or bot token), visibility, frontend portal, Google OAuth, allowed domains, API key, and uninstall cleanup.

= Shortcodes =

* `[ncrw_frontend_reminder_form]` — Renders the full-featured reminder creation + management portal with Google Sign-In authentication.
* `[ncrw_upcoming_reminders]` — Renders a read-only view of upcoming team reminders with Google Sign-In authentication, status filters, and sorting.

= REST API Endpoints =

All endpoints are under the `ncrw/v1` namespace:

* `POST /ncrw/v1/process` — Trigger immediate processing of due reminders (admin only).
* `GET /ncrw/v1/reminders` — List reminders with team/status/pagination filters (logged-in users).
* `POST /ncrw/v1/reminders/{id}/retry` — Queue a manual retry for a failed reminder (team admin).
* `POST /ncrw/v1/portal/bootstrap` — Bootstrap a portal session: verify Google token, return teams and reminders (public with API key + Google token).
* `POST /ncrw/v1/portal/reminder` — Create or update a reminder from an external frontend (public with API key + Google token).

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* PHP sodium extension (included by default in PHP 7.2+)
* A Slack workspace with an Incoming Webhook URL or a Bot Token with `chat:write` scope

== Installation ==

1. Upload the `notifycrew` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Reminders → Settings** in the admin sidebar.
4. Under **Slack Integration**, choose your auth mode (Incoming Webhook or Bot Token) and enter your credentials. They will be encrypted before storage.
5. Go to **Reminders → Teams** and create your first team.
6. Add team members by selecting WordPress users or entering email addresses.
7. (Optional) Configure a per-team Slack channel, mention tag, and quick-schedule hour presets.
8. Go to **Reminders → Add Reminder** to create your first reminder.

= Setting Up the Frontend Portal =

1. Go to **Reminders → Settings** and enable **Frontend Reminder Intake**.
2. Enter your **Google OAuth Client ID** (create one at [Google Cloud Console](https://console.cloud.google.com/apis/credentials)).
3. Set the **Allowed Email Domains** (e.g., `yourcompany.com`).
4. Create or edit a WordPress page and add the shortcode `[ncrw_frontend_reminder_form]`.
5. Team members can now sign in with their Google account and manage reminders.

= Setting Up the View-Only Portal =

1. Follow the same Google OAuth setup as above.
2. Add the `[ncrw_upcoming_reminders]` shortcode to any page.

= Setting Up the Headless API =

1. Enable Frontend Reminder Intake and configure Google OAuth as above.
2. Generate a **Headless API Key** in Settings and store it securely on your server.
3. Your external frontend sends the API key in the `X-NCRW-API-Key` header and a Google ID token in the request body.
4. Use the `POST /ncrw/v1/portal/bootstrap` and `POST /ncrw/v1/portal/reminder` endpoints.

== Frequently Asked Questions ==

= What Slack authentication modes are supported? =

Both **Incoming Webhooks** and **Bot Tokens** (via Slack's `chat.postMessage` API) are supported. You can switch between them at any time in the Settings page. Your credentials are encrypted at rest using libsodium.

= How does retry logic work? =

If a Slack delivery fails, the plugin automatically schedules retries using exponential backoff: 2 minutes after the 1st failure, 4 minutes after the 2nd, 8, 16, and finally 32 minutes. After 5 total attempts, the reminder is marked as "completed" to prevent infinite loops. You can also trigger a manual retry from the admin Reminders list.

= What happens when retries keep failing? =

After 2+ consecutive failures, the site admin receives an email notification with the reminder title, error details, and direct links to the Reminders and Settings pages. Additionally, a warning notice appears at the top of every admin page.

= Can non-admin users submit reminders? =

Yes. Enable the **Frontend Portal** in Settings, configure Google OAuth, and embed the `[ncrw_frontend_reminder_form]` shortcode on any page. Users sign in with their Google account and can only create/edit reminders in teams they belong to.

= What is the "Quick Schedule" feature? =

Instead of picking an exact date and time, users can select a preset delay like "In 4 hours" or "In 48 hours". Each team can have its own set of hour options, configured in the Teams management page.

= How do I configure per-team Slack channels? =

Go to **Reminders → Teams**, select a team, and enter a Slack channel name (e.g., `#engineering-reminders`). If no team channel is set, the global fallback channel is used.

= What are mention tags? =

Each team can be configured to append `@channel` (notify all members) or `@here` (notify active members) to every Slack reminder. This is set in the Teams management page.

= Is my data deleted when I deactivate the plugin? =

No. Deactivation only removes the cron schedule and flushes rewrite rules. Your data (reminders, teams, logs, settings) is preserved. Data is only deleted on full uninstall (plugin deletion) **and** only if you enable "Data Cleanup" in Settings.

= Can I use this with an external frontend (headless)? =

Yes. The plugin provides REST API endpoints under `ncrw/v1/portal/*` that accept an API key header and Google ID token for authentication. This allows you to build a completely custom frontend on any stack while using WordPress as the backend.

= What database tables does the plugin create? =

Four custom tables are created with the `ncrw_` prefix:
* `ncrw_teams` — Team definitions
* `ncrw_team_users` — Team memberships (by user ID and/or email)
* `ncrw_reminders` — Reminder records with status, attempts, and scheduling data
* `ncrw_logs` — Audit log for all reminder events

== External services ==

This plugin connects to the following third-party services:

**Slack API (chat.postMessage)**

This service delivers team reminders to Slack channels. It is activated every time a scheduled reminder is due or manually retried.

Data sent: team name, reminder title, task/ticket link, submitter email address, reminder notes, and timestamp.
This service is provided by Slack Technologies: [terms of service](https://slack.com/terms-of-service), [privacy policy](https://slack.com/trust/privacy/privacy-policy).

**Google OAuth / Sign-In**

This service authenticates users on the optional frontend reminder portal and view-only portal. It is activated when a user loads a portal page or submits a reminder form.

Data sent: Google ID token (for identity verification only).
This service is provided by Google LLC: [terms of service](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).

== Screenshots ==

1. **Reminders List** — Filterable list with status badges, team names, retry actions, and failure details.
2. **Add/Edit Reminder** — Form with team selection, quick-schedule presets, date/time picker, task link, and comments.
3. **Settings Page** — Slack credentials (encrypted), frontend portal toggle, Google OAuth configuration, and data cleanup.
4. **Teams Management** — Create teams, manage members, configure Slack channel, mention tag, and quick-schedule hours.
5. **Frontend Portal** — Google-authenticated reminder creation form with team selector and reminder list.
6. **View-Only Portal** — Read-only view of upcoming reminders with status filters and sorting.

== Changelog ==

= 1.0.0 =
* Initial release of NotifyCrew.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
