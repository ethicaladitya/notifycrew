# Agent Context: NotifyCrew

Use this file as a concise orientation before making code changes.

## What This Plugin Does
- Manages scheduled team reminders in WordPress admin.
- Sends reminders to Slack using incoming webhooks.
- Supports tags with optional Slack mentions.
- Retries failed sends with exponential backoff.

## Core Conventions
- Namespace: Aditya\\NotifyCrew\\
- WordPress coding style and APIs throughout.
- Security baseline:
  - direct-access guards
  - capability checks (manage_options)
  - nonce validation on state-changing requests
  - sanitize input / escape output

## Important Integration Points
- Cron hook: ncrw_process_reminders
- REST namespace: ncrw/v1
- Tables: ncrw_reminders, ncrw_tags, ncrw_logs
- Slack webhook is encrypted at rest; never reveal in UI.

## Safe Change Strategy
1. Find affected layer (admin page, service, DB, or REST).
2. Keep data contracts aligned across model/service/admin rendering.
3. Preserve translation wrappers and text domain notifycrew.
4. Validate that retries, statuses, and logs remain consistent.
5. Avoid broad refactors unless explicitly requested.
