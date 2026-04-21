# Copilot Quick Context

## Plugin Identity
- Name: Team Reminder Tool
- Type: WordPress plugin
- Entry: team-reminder-tool.php
- Namespace: Aditya\\ReminderTool\\
- Text domain: team-reminder-tool

## Module Map
- includes/class-plugin.php: Bootstraps DB, cron, REST, and admin components.
- includes/database/class-database.php: dbDelta schema creation and upgrades.
- includes/services/class-reminder-service.php: reminder lifecycle + retry logic + logs.
- includes/services/class-slack-service.php: webhook send and encrypted webhook storage.
- includes/services/class-cron-service.php: periodic processor registration/scheduling.
- includes/services/class-rest-service.php: admin-only operational REST endpoints.
- includes/services/class-tag-service.php: tag CRUD.
- includes/admin/pages/*: admin UI forms and handlers.

## Data Storage
- {prefix}trt_reminders
- {prefix}trt_tags
- {prefix}trt_logs

## Guardrails
- Keep capability checks + nonces for all mutating operations.
- Keep sanitize/escape behavior everywhere.
- Preserve WP i18n wrappers with text domain team-reminder-tool.
- Keep webhook secrets masked and encrypted.
- Preserve existing hooks, option keys, and route namespace unless change is intentional.
