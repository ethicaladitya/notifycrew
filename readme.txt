=== NotifyCrew - Team reminders and site alerts for Slack ===
Contributors: ethicaladitya
Tags: slack, reminders, notifications, slack integration, scheduler
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Team reminders and site alerts for Slack: scheduled reminders per team, and a message when updates are due, fail, or a plugin crashes.

== Description ==

Your team already lives in Slack. Your deadlines live somewhere else: a spreadsheet, a calendar nobody opens, or someone's memory.

NotifyCrew closes that gap. Schedule a reminder in WordPress, pick the team it belongs to, and NotifyCrew posts it to that team's Slack channel at the right moment. No Zapier account, no monthly per-seat fee, no third-party service holding your data. Your WordPress site talks to Slack directly.

= Why teams choose NotifyCrew =

* **No per-seat pricing.** It is a WordPress plugin. Add as many teams and members as you want.
* **No middleman service.** Reminders go straight from your site to Slack. Nothing is routed through anyone else's server.
* **Your Slack credentials stay encrypted.** Webhook URLs and bot tokens are encrypted at rest with libsodium, not stored as plain text in the options table.
* **Delivery you can trust.** If Slack is down or your token expires, NotifyCrew retries automatically and tells you when something is wrong instead of failing silently.
* **Your team does not need WordPress accounts.** Members can be added by email address alone and still manage their own reminders through the optional portal.

= What you can do =

* **Send reminders to Slack on a schedule.** Pick a date and time, or use a one-click preset like "In 4 hours" or "In 48 hours".
* **Give every team its own channel.** The design team posts to `#design`, engineering posts to `#eng-standup`. A global fallback channel covers teams without one.
* **Ping the right people.** Add `@channel` or `@here` per team so urgent reminders actually get seen.
* **Recover from failures automatically.** Failed deliveries retry with exponential backoff, and you get an email plus an admin notice when a reminder keeps failing.
* **Send rich, readable messages.** Reminders arrive as Slack Block Kit messages with a header, the scheduled time, a task or ticket link, who submitted it, and any notes.
* **Let your team file their own reminders.** Drop a shortcode on any page for a Google-authenticated portal, restricted to the email domains you allow.
* **Set it and forget it.** Make any reminder repeat daily, weekly, or monthly. NotifyCrew auto-creates the next occurrence so standups, deadlines, and check-ins keep running.
* **Keep a full audit trail.** Every reminder event is logged with a timestamp, so you can always answer "was this actually sent?"
* **Uninstall cleanly, or not at all.** Your data is never deleted unless you explicitly opt in under Settings.

= Site alerts: know when your site needs you =

Turn on **Site alerts** under Reminders > Settings and NotifyCrew posts to Slack when:

* **Updates are available.** WordPress, plugin and theme updates, checked after WordPress looks for updates. New versions are listed in one message, and each version is announced only once, so the channel doesn't fill up.
* **Automatic updates run.** Get every result, or only the failures, with the reason WordPress gave.
* **A plugin or theme crashes the site.** Sent when WordPress catches the fatal error and emails the admin, at most once a day. The message says which file broke. It never contains WordPress's recovery-mode login link, because a shared channel is no place for a one-time admin login.

Use the "Send test alert" button to check the connection. Alerts use the same Slack webhook or bot token as your reminders, and each alert type is off until you turn it on.

= Common uses =

* Daily stand-up and scrum reminders for engineering teams
* Deploy windows, release freezes, and on-call handoffs
* Invoice, payroll, and compliance deadlines
* Content and social publishing schedules
* Client check-ins and recurring agency deliverables
* Nudges for anything your team keeps forgetting
* Update and crash alerts for agencies and site owners who live in Slack

= Let your team schedule their own reminders =

Most reminder plugins stop at the WordPress admin, which means everything routes through whoever has an admin login.

NotifyCrew ships an optional frontend portal instead. Add `[ncrw_frontend_reminder_form]` to any page and your team signs in with Google, sees only the teams they belong to, and creates or edits their own reminders. No WordPress account required. Restrict access to your company's email domains so nothing else gets through.

Prefer read-only? `[ncrw_upcoming_reminders]` shows what is coming up, with filters and sorting, and nothing editable.

Building your own frontend? NotifyCrew exposes REST endpoints under `ncrw/v1` so you can run the portal on any stack and keep WordPress as the backend. See the FAQ.

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher, with the sodium extension (bundled with PHP 7.2 and later)
* A Slack workspace, plus either an Incoming Webhook URL or a Bot Token with the `chat:write` scope

== Installation ==

1. Install and activate the plugin. A **Reminders** menu appears in your admin sidebar.
2. Go to **Reminders > Settings**, choose Incoming Webhook or Bot Token, and paste your Slack credentials. They are encrypted before they are saved.
3. Go to **Reminders > Teams** and create a team. Add members by picking WordPress users or by typing email addresses.
4. Optionally give the team its own Slack channel, a mention tag, and quick-schedule presets.
5. Go to **Reminders > Add Reminder** and schedule your first one.

= Setting up the team portal (optional) =

1. In **Reminders > Settings**, enable **Frontend Reminder Intake**.
2. Add your **Google OAuth Client ID**, created at [Google Cloud Console](https://console.cloud.google.com/apis/credentials).
3. Set **Allowed Email Domains**, for example `yourcompany.com`.
4. Add `[ncrw_frontend_reminder_form]` to a page. For a read-only view, use `[ncrw_upcoming_reminders]` instead.

== Frequently Asked Questions ==

= Is NotifyCrew free? =

Yes. It is fully functional, GPL licensed, with no paid tier, no locked features, and no upsells.

= Do I need a paid Slack plan? =

No. Incoming Webhooks and Bot Tokens both work on Slack's free plan.

= Do my team members need WordPress accounts? =

No. Members can be added by email address alone. If you enable the frontend portal, they sign in with Google and manage their own reminders without ever touching wp-admin.

= Can reminders repeat automatically? =

Yes. Set any reminder to repeat daily, weekly, monthly, or on a custom interval. Choose how long to repeat — forever, after a set number of times, or until a specific date. When a recurring reminder fires, NotifyCrew auto-creates the next occurrence so you never have to set it up again.

= How does the retry logic work? =

If a Slack delivery fails, NotifyCrew reschedules it with exponential backoff: 2 minutes after the first failure, then 4, then 8, then 16. After the fifth attempt it stops retrying and marks the reminder Completed so it cannot loop forever. You can also trigger a manual retry from the Reminders list at any time.

= What happens when deliveries keep failing? =

From the second failure onward, the site admin gets an email with the reminder title, the error Slack returned, and links back to the Reminders and Settings pages. A warning notice also appears in the admin area while failures are recent, and the latest error is shown inline next to the reminder's attempt count.

= My reminders are not sending on time. What should I check? =

NotifyCrew runs on WP-Cron, which only fires when someone visits your site. On low-traffic sites, reminders can be late. Disable WP-Cron and use a real server cron job hitting `wp-cron.php` every five minutes for reliable delivery. Also confirm your Slack credentials are still valid under **Reminders > Settings**.

= Which Slack authentication mode should I use? =

Use an **Incoming Webhook** for the fastest setup when all reminders go to one channel. Use a **Bot Token** with the `chat:write` scope if you want per-team channels. You can switch modes at any time.

= Is my data deleted when I deactivate the plugin? =

No. Deactivation only clears the cron schedule. Your reminders, teams, logs, and settings are preserved. Data is removed only when you delete the plugin, and only if you enabled **Data Cleanup** in Settings first.

= What does the plugin store in my database? =

Four tables prefixed `ncrw_`: teams, team memberships, reminders, and an audit log of reminder events.

= Can I build my own frontend? =

Yes. The `ncrw/v1/portal/bootstrap` and `ncrw/v1/portal/reminder` endpoints accept an API key in the `X-NCRW-API-Key` header along with a Google ID token, so you can run a decoupled frontend on any stack. Additional endpoints under `ncrw/v1` cover listing reminders, triggering processing, and queuing retries for logged-in users.

= Can NotifyCrew tell me in Slack when plugins need updating? =

Yes. Turn on "Updates available" under Reminders > Settings > Site alerts. After WordPress checks for updates (twice a day), NotifyCrew posts one message listing any WordPress, plugin or theme versions it hasn't announced before. You can also get a message for every automatic update, or only when one fails.

= Will I get a Slack message when my site crashes? =

When a plugin or theme causes a fatal error on an admin or login page, WordPress turns on recovery mode and emails the site admin. With "Crashes" turned on, NotifyCrew posts the same news to Slack at that moment, with the error and the file that caused it. Like WordPress's email, it is sent at most once a day. There is no crash alert when your site disables WordPress's fatal error handler (`WP_DISABLE_FATAL_ERROR_HANDLER`), on multisite (WordPress has no recovery mode there), or when the crash comes from a plugin that loads before NotifyCrew. For full uptime monitoring, pair NotifyCrew with an external uptime checker.

= Does it work on multisite? =

Reminders and update alerts work on each site of a network, with their own settings. Crash alerts are not available on multisite, because WordPress turns off recovery mode there.

= Which timezone does NotifyCrew use? =

Your site's timezone (Settings > General) by default. To use a different one for reminders, pick it under Reminders > Settings > Timezone. Changing it never moves existing reminders: they still go out at the same moment. In Slack, everyone sees the reminder time in their own timezone.

= Is NotifyCrew translation ready? =

Yes. Every user-facing string uses WordPress i18n functions with the `notifycrew` text domain, and a `.pot` file is included.

== Screenshots ==

1. Before NotifyCrew: reminders scattered across DMs, channels, and forgotten spreadsheets. After: every reminder lives in one place, sorted by team and status, with delivery history at a glance.
2. Creating a reminder takes seconds. Pick a team, write what needs to happen, and choose a preset like "In 4 hours" — no date picker required.
3. Every team gets its own Slack channel and mention rules. Engineering posts to #eng-standup with @channel. Design posts to #design. No crossover, no noise.
4. Slack credentials encrypted with libsodium. Google OAuth for the optional team portal. Every setting in one screen.

== External services ==

This plugin connects to the following third-party services:

**Slack API (chat.postMessage)**

This service delivers team reminders and site alerts to Slack, through an incoming webhook (hooks.slack.com) or the chat.postMessage API (slack.com/api), whichever you configure. It is used every time a scheduled reminder is due or manually retried, when a site alert you turned on fires, and when you press "Send test alert".

Data sent for reminders: team name, reminder title, task/ticket link, submitter email address, reminder notes, and timestamp.
Data sent for site alerts: site name, names and versions of WordPress, plugins and themes with updates or automatic update results, the error message and file path of a crash, and links to your site's admin screens.
This service is provided by Slack Technologies: [terms of service](https://slack.com/terms-of-service), [privacy policy](https://slack.com/trust/privacy/privacy-policy).

**Google OAuth / Sign-In**

This service authenticates users on the optional frontend reminder portal and view-only portal. It is activated when a user loads a portal page or submits a reminder form.

Data sent: Google ID token (for identity verification only).
This service is provided by Google LLC: [terms of service](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).

== Changelog ==

= 1.2.1 =
* Fix: a reminder could be sent to Slack twice when WordPress's scheduled run, "Retry now" and the REST API overlapped. Now only one sending run happens at a time and each reminder is claimed before it is sent.
* Fix: a reminder with a very long title, long notes or a very long link was rejected by Slack and never arrived. Long text is now shortened to fit Slack's limits (the notification preview keeps the full title).
* Fix: a title longer than 255 characters could not be saved. It is now shortened, and the title fields stop at 255 characters.
* Large backlogs are sent in batches of 25, with the next batch scheduled a minute later, so one run can't time out halfway.

= 1.2.0 =
* New: Site alerts. Get a Slack message when WordPress, plugin or theme updates are available, when automatic updates run or fail, and when a plugin or theme crashes the site. Each alert is off until you turn it on under Reminders > Settings, and there is a "Send test alert" button.
* New: choose the timezone NotifyCrew uses for reminders under Reminders > Settings. It follows your site's timezone by default.
* Times shown with a timezone name no longer show it twice when your time format already includes one.

= 1.1.3 =
* Fix: reminder times now use your site's timezone (Settings > General). Before, every time you typed was read as UTC, so a 10:00 reminder in India went out at 15:30.
* Times in the admin, the team portal and the REST API are shown in the site timezone, with its name next to them.
* REST API: `reminder_datetime` and `recurrence_end_date` are now read in the site timezone. Add an offset (2026-11-16T10:00:00+05:30, or Z for UTC) to send another timezone. Other formats, such as a bare date or a time ending in "UTC", are now refused with a 400 error. Responses include `recurrence_end_date_local`.
* Slack shows each reader the reminder time in their own timezone.
* Daily, weekly and monthly reminders keep the same local time when daylight saving time starts or ends.
* Reminders you created before this update keep the moment you set them for; they are now shown in your site timezone.
* The failure notice and the failure email now say NotifyCrew instead of the old plugin name.

= 1.1.2 =
* Fix: NotifyCrew no longer crashes on PHP 7.4. Every earlier version used a PHP 8-only feature, so sites on PHP 7.4 hit a fatal error as soon as a reminder or team was loaded.
* Fix: a recurring reminder now keeps repeating when one occurrence fails to reach Slack. Before, a short Slack outage or an expired token ended the whole series.
* Release builds now package only the plugin's own files.

= 1.1.1 =
* Tested with WordPress 7.1.

= 1.1.0 =
* New: Recurring reminders — daily, weekly, monthly, and custom intervals
* New: End conditions — stop after N occurrences or on a specific date
* Improved: Auto-reschedule creates the next occurrence when a recurring reminder fires

= 1.0.0 =
* Initial release of NotifyCrew.

== Upgrade Notice ==

= 1.2.1 =
Reliability fixes: no more duplicate Slack messages when runs overlap, and reminders with long titles or notes now arrive instead of failing.

= 1.2.0 =
New site alerts for Slack: updates available, automatic update results and plugin crashes. Plus a timezone setting for reminders. Nothing changes until you turn them on.

= 1.1.3 =
Reminder times now follow your site's timezone instead of UTC. Check the time on your existing reminders after updating.

= 1.1.2 =
Fixes a fatal error on PHP 7.4 and recurring reminders that stopped after one failed delivery. Recommended for everyone.

= 1.1.1 =
Maintenance release — tested with WordPress 7.1.

= 1.1.0 =
Adds recurring reminders. Existing one-time reminders are unaffected.

= 1.0.0 =
Initial release.
