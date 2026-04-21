=== Team Reminder Tool ===
Contributors: aditya
Tags: reminders, slack, team, notifications, scheduler
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A production-grade team reminder tool with Slack integration, team management, and retry logic.

== Description ==

Team Reminder Tool lets you schedule and send team reminders to Slack channels. It supports:

* Slack Incoming Webhooks and Bot Tokens (chat.postMessage)
* Team management with member assignments and quick-schedule hour presets
* Frontend reminder portal with Google OAuth authentication
* Headless portal REST endpoints for external frontend apps via server-side proxy
* Automatic retry with exponential backoff on Slack delivery failures
* Full delivery log per reminder

== Installation ==

1. Upload the `reminder-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Team Reminders** in the admin menu to configure Slack credentials, teams, and reminders.

== Frequently Asked Questions ==

= What Slack authentication modes are supported? =

Both Incoming Webhooks and Bot Tokens (via `chat.postMessage`) are supported. You can switch between them at any time in the Settings page.

= How does retry logic work? =

If a Slack delivery fails, the plugin automatically schedules retries using exponential backoff (up to the configured maximum retry count). You can also trigger a manual retry from the Reminders list.

= Can non-admin users submit reminders? =

Yes. Enable the Frontend Portal in Settings and embed the `[trt_frontend_reminder_form]` shortcode on any page. Users authenticate via Google Sign-In and can only create/edit reminders in their assigned teams.

== Screenshots ==

1. Reminders list with status badges and retry actions.
2. Add/Edit reminder form with team selection and quick-schedule hours.
3. Settings page — Slack credentials and frontend portal configuration.
4. Teams management page with member and quick-schedule configuration.

== Changelog ==

= 1.0.1 =
* Removed Slack relay mode and relay settings.
* Restored Slack channel fallback behavior for legacy/global channel setups.
* Added one-time cleanup of deprecated relay options from WordPress settings.

= 1.0.0 =
* Initial release.
