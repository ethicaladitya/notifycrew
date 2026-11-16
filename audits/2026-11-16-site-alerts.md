# Plan: site alerts in Slack (1.2.0) and the first step of event triggers

Task ids: `notifycrew-plan-event-triggers` (milestone 1), `notifycrew-timezone-setting` (milestone 1, Chair
2026-11-16: ship in the next release). This file is not shipped (`.distinclude`).

## Goal
Make NotifyCrew useful to every WordPress site that has a Slack workspace, not only teams that schedule reminders.
The first trigger is the one people are losing elsewhere right now: "tell me in Slack when my site needs updates,
when an update fails, and when a plugin crashes the site".

## Why this first (evidence: ops repo `state/audits/2026-11-16-notifycrew-competitors.md`)
- wp-updates-notifier (20k installs) removed Slack in its 2.0 rewrite (2026-10-26). A user already wrote a companion
  plugin to bring it back.
- Notification (10k) moved webhooks into a paid add-on in v9.
- The Slack-only notifiers each have fewer than 50 installs. The big plugins are paid automators or email/Telegram only.
- Search terms it opens: "update notifications", "plugin update notification", "slack notifications",
  "slack integration", "site down alert". Today we rank nowhere for the first two and #93 for "slack integration".

## Design (1.2.0)
- **Settings > Site alerts** (Reminders > Settings), all off by default:
  - Updates available: WordPress, plugin and theme updates, after WordPress's own twice-daily check. Each new
    version is announced once (`ncrw_alerts_notified` holds `type:slug:version` keys; pruned when the update is gone).
    The check runs in a single cron event a minute after the update transient is saved, so the HTTP call never
    slows down an admin page.
  - Automatic update results: off / failures only / everything (`automatic_updates_complete`).
  - Plugin or theme crash: on `recovery_mode_email`, the moment WordPress emails the admin about a fatal error,
    so the same once-a-day rate limit as core. The message has the error and the plugins screen link, never the
    recovery-mode login link (a one-time admin login must not go to a shared channel).
  - Alert channel: used in bot-token mode. In webhook mode the webhook's own channel is used.
  - "Send test alert" button, showing Slack's actual error on failure.
- Slack sending: `Slack_Service::send_message( $text, $blocks, $channel )` shares the webhook/bot transport with
  reminders. No new external services: same Slack endpoints the readme already lists.
- **Timezone setting** (General section): "Same as site" (default) or any zone from `wp_timezone_choice()`.
  `Time::timezone()` resolves it and every conversion and display goes through it. Changing it never moves stored
  reminders (UTC instants).

## Risks
- Alert noise: off by default, one message per new version, crash alerts rate-limited by core.
- Fatal alerts run during a fatal error's shutdown: keep the code tiny, no class loading beyond what is loaded,
  10-second timeout, and never throw.
- Sites with WP_DISABLE_FATAL_ERROR_HANDLER or recovery mode off get no crash alert: say so in the FAQ.

## Milestones (each its own release)
1. 1.2.0: site alerts (updates, automatic update results, crashes) to Slack, timezone setting.
2. Channel abstraction: Discord, Microsoft Teams and Google Chat webhooks for both reminders and alerts.
3. Content events: new user, new comment, post pending review, failed logins (with thresholds).
4. WooCommerce: new order, refund, low stock. Form plugins: CF7, WPForms, Gravity Forms, Fluent Forms.
5. Message templates with merge tags and per-trigger routing.

## Tests
- `tests/smoke/site-alerts.php`: update transient with a fake plugin update -> exactly one Slack payload, repeat ->
  none; automatic-update results with one failure in failures-only mode -> one payload naming the failure; crash
  filter -> payload without the recovery URL and the email returned unchanged; all off -> nothing sent.
- `tests/smoke/timezone.php`: plugin timezone Europe/London on an Asia/Kolkata site.
- Release checks: Plugin Check at every severity, no PHP notices, browser check of Settings in WordPress Studio.
