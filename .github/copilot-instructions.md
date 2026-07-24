how# NotifyCrew Copilot Instructions

This repository is a production-style WordPress plugin for scheduled Slack reminders with retry logic.

## Project Snapshot
- Plugin root: notifycrew.php
- Namespace: Aditya\\NotifyCrew\\
- Entry: notifycrew.php
- Minimums: PHP 7.4+, WordPress 5.8+
- Bootstrap constants: NCRW_VERSION, NCRW_DB_VERSION, NCRW_FILE, NCRW_DIR, NCRW_URL, NCRW_BASENAME

## Architecture Map
- notifycrew.php
  - Defines constants, loads autoloader, registers activation/deactivation hooks, boots Plugin singleton.
- includes/class-plugin.php
  - Plugin orchestrator: i18n, DB migration check, cron, REST, admin registration.
- includes/class-autoloader.php
  - Custom PSR-4 style loader for Aditya\\NotifyCrew\\*.

### Admin Layer
- includes/admin/class-admin.php
  - Adds admin menu pages and enqueues admin assets.
- includes/admin/pages/class-reminders-page.php
  - Reminder list + add/edit form + admin_post handlers for create/update/delete/retry.
- includes/admin/pages/class-tags-page.php
  - Tag list/form + admin_post handlers for create/update/delete.
- includes/admin/pages/class-settings-page.php
  - Settings API fields and Slack credential/settings form handling.

### Domain/Data Layer
- includes/database/class-database.php
  - Creates/updates tables with dbDelta.
  - Tables: ncrw_reminders, ncrw_tags, ncrw_logs.
- includes/models/class-reminder.php
  - Typed reminder DTO from DB row.
- includes/models/class-tag.php
  - Typed tag DTO from DB row.

### Service Layer
- includes/services/class-reminder-service.php
  - Reminder CRUD, list/count, due/retryable queries, retry scheduling, logging.
- includes/services/class-tag-service.php
  - Tag CRUD.
- includes/services/class-slack-service.php
  - Webhook send + encrypted webhook storage + channel/username overrides.
- includes/services/class-cron-service.php
  - Registers/schedules periodic processing hook.
- includes/services/class-rest-service.php
  - Admin-only REST routes for manual process, list reminders, and retry.

### Assets and I18n
- assets/css/admin.css: Admin UI styling.
- assets/js/admin.js: Confirm delete, trigger manual processing via REST, auto-dismiss notices.
- languages/notifycrew.pot: Translation template.

## Data and Options
- DB version option: ncrw_db_version
- General settings option: ncrw_options
- Slack options:
  - ncrw_slack_webhook (encrypted)
  - ncrw_slack_channel
  - ncrw_slack_username

## Runtime Flows
1. Reminder created in admin page -> stored as pending.
2. Cron (or manual REST process) fetches due + retryable reminders.
3. Slack send success -> status sent, log event.
4. Slack send failure -> status failed, retry_count incremented, next_retry set using exponential backoff until MAX_RETRIES.

## Editing Rules For This Repo
- Preserve direct access guards in PHP files.
- Preserve singleton patterns used across services/pages.
- Keep sanitization on input and escaping on output.
- Keep nonce + capability checks on all mutating admin/REST actions.
- Do not expose stored webhook values in admin UI or logs.
- Keep SQL prepared except where identifiers are safely whitelisted.
- Use text domain notifycrew for all user-facing strings.
- Keep compatibility with PHP 7.4 (avoid 8.x-only syntax/features).

## Quick Checkpoints After Changes
- Activation/deactivation hooks still wired.
- Cron hook names unchanged unless migration is intentional.
- Table names/columns align with services and models.
- REST namespace and route permissions remain consistent.
- Admin pages still enqueue assets only on plugin screens.
