#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "Usage: $0 <slack_webhook_url>" >&2
  exit 1
fi

WEBHOOK="$1"
CFG="/etc/reminders-slack-relay.ini"

python3 - <<PY
from pathlib import Path
import configparser

cfg_path = Path("$CFG")
cp = configparser.ConfigParser()
cp.read_string("[relay]\n" + cfg_path.read_text())
cp['relay']['slack_webhook'] = "$WEBHOOK"
out = "\n".join([f"{k}={v}" for k,v in cp['relay'].items()]) + "\n"
cfg_path.write_text(out)
PY

chown root:www-data "$CFG"
chmod 640 "$CFG"

echo "relay_webhook_set_ok"
