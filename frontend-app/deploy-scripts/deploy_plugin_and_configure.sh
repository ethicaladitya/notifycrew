#!/usr/bin/env bash
set -euo pipefail

WP_ROOT="/var/www/html/reminders-wp"
PLUGIN_DIR="$WP_ROOT/wp-content/plugins/reminder-manager"
REPO_TMP="/tmp/reminder-manager"

mkdir -p "$PLUGIN_DIR"
rsync -a --delete \
  --exclude '.git' \
  --exclude 'frontend-app/node_modules' \
  --exclude 'frontend-app/dist' \
  --exclude '.DS_Store' \
  "$REPO_TMP"/ "$PLUGIN_DIR"/

chown -R www-data:www-data "$PLUGIN_DIR"

RELAY_SECRET="$(awk -F= '/^RELAY_SECRET=/{print $2}' /root/reminders-relay-secrets.txt)"
RELAY_URL="$(awk -F= '/^RELAY_URL=/{print $2}' /root/reminders-relay-secrets.txt)"

sudo -u www-data wp plugin activate reminder-manager --path="$WP_ROOT" || true

sudo -u www-data env TRT_RELAY_URL="$RELAY_URL" TRT_RELAY_SECRET="$RELAY_SECRET" wp eval --path="$WP_ROOT" '
$service = \Aditya\ReminderTool\Services\Slack_Service::get_instance();
$service->save_mode("relay");
$service->save_relay_url(getenv("TRT_RELAY_URL"));
$service->save_relay_key(getenv("TRT_RELAY_SECRET"));
$service->save_username("reminder_bot");
'

echo "deploy_plugin_and_configure_ok"
