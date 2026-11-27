# Plan: notifycrew-onboarding (first Slack message in under 2 minutes)

Task: `notifycrew-onboarding` (P1). Written 2026-11-27 during "users first" (prompts/notifycrew.md: onboarding is
allowed build work in this phase).

## Goal
An admin who activates NotifyCrew sees what to do next and gets a real message into Slack within two minutes, then
sets up either a reminder or site alerts. Activation-to-first-message decides whether an install stays active, and
active installs are what wordpress.org counts and ranks.

Measure (no tracking, by design): we can't see other sites. Proxies: the listing's active-install bucket and the
baseline (median downloads a day) vs active installs over 28 days; our own Studio walk-through timed by stopwatch
(target: activation to first Slack message under 2 minutes with a webhook URL in hand); support threads about setup.

## What a new admin sees today (1.2.1)
- Activation creates tables and schedules cron (`includes/class-plugin.php:95`). No notice, no redirect, no hint.
- The menu is "Reminders" > All Reminders. The empty list says "No reminders found." (listing screenshot 1) with no
  next step. Slack setup is on Reminders > Settings, under "Slack Integration", below General and Site alerts
  (`class-settings-page.php:467`).
- The only test button is "Send test alert" inside Site alerts (`class-settings-page.php:274`), which reads as an
  alerts feature, not "check my Slack connection".
- Nothing explains how to get a Slack incoming webhook URL.

## Search terms and users it wins
Not a search play: it protects every install the users-first channels bring (Product Hunt 8 Dec, tutorial, list
authors, community posts). A visitor who installs and bounces is an install we paid for and lost, and a likely
1-2 star "doesn't work" review at a total of 1 rating.

## Design (milestone 1, release 1.3.0)
1. **Setup checklist** on NotifyCrew screens (All Reminders, Settings, Teams) for `manage_options` users until done
   or dismissed. Three steps, each with a status computed from saved state (no new tracking):
   1. Connect Slack: done when `Slack_Service::has_webhook() || has_bot_token()`. Link to the Slack section anchor.
      Inline help: the 4 clicks to create an incoming webhook in Slack (api.slack.com/apps > Create app > Incoming
      Webhooks > Add to workspace > copy URL), with a link to Slack's own guide.
   2. Send a test message: done after a successful test send (new option `ncrw_onboarding` `test_ok` timestamp,
      autoload no). Button right in the checklist (admin-post, nonce, capability), reusing
      `Slack_Service::send_message()` with a short "NotifyCrew is connected to <site>" text.
   3. Put it to work: done when any reminder exists or any site alert is on. Two buttons: "Add a reminder" and
      "Turn on site alerts".
   Dismiss: "Hide checklist" (per user meta, nonce). All done: one success line, then it hides itself.
2. **Activation notice**: one dismissible admin notice on the Plugins screen only, after activation (transient set in
   `activate()`, shown once): "NotifyCrew is active. Connect Slack in two minutes" > Settings. Not shown on other
   screens, not repeated. No redirect on activation (bulk activations and guidelines).
3. **Slack section first** on Settings when Slack isn't connected (move "Slack Integration" above General), and a
   "Send test message" button there, next to the saved credentials. "Send test alert" stays in Site alerts.
4. **Empty states**: All Reminders with zero reminders shows the checklist instead of a bare "No reminders found";
   Teams with zero teams says what a team is (a Slack channel + mention rule) with "Add team".
5. **Plugins row link**: "Settings" action link on the Plugins screen.

Out of scope for M1: the review prompt (its own task, `notifycrew-review-prompt`, M2 below), a setup wizard,
any new channel, any remote call other than the existing Slack send.

## Milestone 2 (release 1.3.x, task notifycrew-review-prompt)
After real success only: at least 5 successful deliveries (reminders or alerts, from the existing logs table) and
14 days since the first one, show a dismissible notice on NotifyCrew screens only: "Is NotifyCrew saving you time?
A short review helps other teams find it" > the wordpress.org review page. "Maybe later" (30 days), "Don't ask
again". Never on other screens, never before success, never tied to features (guideline 11).

## Risks
- Notice nags: wordpress.org guideline 11 (no hijacking the admin). Mitigation: Plugins-screen-only one-shot notice,
  checklist only on our own screens, dismissible, per user.
- Test button abuse: capability `manage_options`, nonce, one send per click, existing timeout.
- PHP 7.4 compatibility, escaping, i18n of every new string, Plugin Check clean at every severity.
- Moving the Slack section must not change form names or saved options (settings stay as they are on update).

## Tests
- `tests/smoke/onboarding.php`: checklist states (none, connected, test ok, reminder exists, alerts on), dismiss
  per user, activation transient shown once, test-send handler rejects missing nonce and non-admins, and records
  `test_ok` only on success (Slack call mocked with `pre_http_request`).
- Studio walk-through with screenshots: fresh activation > notice > settings > webhook > test message > first
  reminder; stopwatch time in the run report.

## Release
1.3.0 when M1 passes the gate (5-day rule: earliest 2026-11-28). Changelog in user words: "A setup checklist gets you
from activation to your first Slack message in a couple of minutes." Before Product Hunt (8 Dec).

## Changes after the Opus review (2026-11-27), binding for M1 and M2
The first review returned 8 findings. Each was checked in the code, and each changes the design above as follows:
1. **A team comes first.** A reminder needs a team (`class-reminders-page.php:385-387`, save fails at `:573`), and
   a fresh install has none. Step 3 becomes "Create your first team" (Teams has only "No teams found.",
   `class-teams-page.php:230`), then "Add a reminder", or "Turn on site alerts" instead. Teams' empty state stays in M1.
2. **Bot-token mode needs a channel.** `send_message()` in bot mode falls back to the `ncrw_slack_channel` option
   (`class-slack-service.php:106-109`), which no screen saves. Add a "Default channel" field next to the bot token
   in the Slack form (saves `ncrw_slack_channel`). The test message uses it, and its error text talks about the
   connection, not site alerts.
3. **Step 1 is done only for the selected mode**: webhook mode with a saved webhook, or bot mode with a token
   and a default channel (`get_mode()`).
4. **The webhook help follows Slack's current UI**: api.slack.com/apps > Create New App > From scratch > name and
   workspace > Incoming Webhooks > On > Add New Webhook to Workspace > pick a channel > Allow > copy the URL. It
   notes that some workspaces need an admin to approve the app, and links to Slack's guide.
5. Correction: "Slack Integration" is its own form below the `options.php` form (`class-settings-page.php:467`),
   not a settings section. Moving it up changes no option names.
6. **The existing failure notice breaks guideline 11 (fixed in M1).** `maybe_show_slack_failure_notice`
   (`class-admin.php:47-87`) appears on every admin screen and can't be dismissed. Show it only on NotifyCrew screens
   and the Dashboard, and make it dismissible until the next new failure. Its `completed` event is the max-attempts
   failure (`class-reminder-service.php:682`), so the query itself is right.
7. **M2 counts reminder `sent` events only** (`class-reminder-service.php:555`). Site alerts don't log successes.
   The trigger is at least 5 sent events, the first at least 14 days old. "Maybe later" works once (30 days), then
   the notice never shows again. "Don't ask again" stops it for good.
8. **Handler rules.** Test send: `manage_options`, then `check_admin_referer` with its own action. `test_ok` is saved
   only when the result is `true`. The error is stored in an option, escaped on output, and never put in the URL.
   Then `wp_safe_redirect` + `exit`. Dismiss: a POST with a nonce, a capability check, and only the current user's
   meta. **Upgrade:** a site already on 1.2.x with Slack connected and a team or reminder in place is marked done
   on upgrade (a `db_version`/option check) and never sees the checklist.
If time runs short before 2026-12-08: the Slack-section move becomes a plain reorder. Never cut 1, 2, 3, 6 or 8.
