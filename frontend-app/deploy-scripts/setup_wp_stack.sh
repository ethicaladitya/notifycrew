#!/usr/bin/env bash
set -euo pipefail

WP_ROOT="/var/www/html/reminders-wp"
DB_NAME="reminders_wp"
DB_USER="reminders_wp_user"
WP_DOMAIN="reminders.incsubsupport.work"
WP_ADMIN_EMAIL="aditya.shah@incsub.com"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php-fpm php-mysql php-curl php-xml php-mbstring php-zip php-gd php-intl unzip curl

systemctl enable --now mariadb
if systemctl enable --now php8.3-fpm 2>/dev/null; then
  PHP_FPM_SOCK="/run/php/php8.3-fpm.sock"
elif systemctl enable --now php8.2-fpm 2>/dev/null; then
  PHP_FPM_SOCK="/run/php/php8.2-fpm.sock"
else
  echo "No supported php-fpm service found" >&2
  exit 1
fi

mkdir -p "$WP_ROOT"
cd /tmp
curl -fsSLo latest.tar.gz https://wordpress.org/latest.tar.gz
rm -rf /tmp/wordpress
tar -xzf latest.tar.gz
rsync -a --delete /tmp/wordpress/ "$WP_ROOT"/

DB_PASS="$(openssl rand -base64 24 | tr -d '=+/' | cut -c1-24)"
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ ! -f "$WP_ROOT/wp-config.php" ]; then
  cp "$WP_ROOT/wp-config-sample.php" "$WP_ROOT/wp-config.php"
fi

sed -i "s/database_name_here/${DB_NAME}/" "$WP_ROOT/wp-config.php"
sed -i "s/username_here/${DB_USER}/" "$WP_ROOT/wp-config.php"
sed -i "s/password_here/${DB_PASS}/" "$WP_ROOT/wp-config.php"
sed -i "s/localhost/127.0.0.1/" "$WP_ROOT/wp-config.php"

SALTS="$(curl -fsSL https://api.wordpress.org/secret-key/1.1/salt/)"
python3 - <<PY
from pathlib import Path
import re

p = Path("$WP_ROOT/wp-config.php")
s = p.read_text()
pattern = re.compile(r"define\(\s*'AUTH_KEY'.*?define\(\s*'NONCE_SALT'.*?;\n", re.S)
replacement = '''$SALTS\n'''
if pattern.search(s):
    s = pattern.sub(replacement, s)
p.write_text(s)
PY

curl -fsSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /usr/local/bin/wp

if ! sudo -u www-data wp core is-installed --path="$WP_ROOT" >/dev/null 2>&1; then
  WP_ADMIN_PASS="$(openssl rand -base64 24 | tr -d '=+/' | cut -c1-20)"
  sudo -u www-data wp core install \
    --path="$WP_ROOT" \
    --url="https://${WP_DOMAIN}" \
    --title="Reminders Portal" \
    --admin_user="aditya" \
    --admin_password="$WP_ADMIN_PASS" \
    --admin_email="$WP_ADMIN_EMAIL"
else
  WP_ADMIN_PASS="(unchanged-existing-install)"
fi

chown -R www-data:www-data "$WP_ROOT"
find "$WP_ROOT" -type d -exec chmod 755 {} \;
find "$WP_ROOT" -type f -exec chmod 644 {} \;
chmod 640 "$WP_ROOT/wp-config.php"

cat > /root/reminders-wp-secrets.txt <<EOF
WP_URL=https://${WP_DOMAIN}
WP_DB_NAME=${DB_NAME}
WP_DB_USER=${DB_USER}
WP_DB_PASS=${DB_PASS}
WP_ADMIN_USER=aditya
WP_ADMIN_PASS=${WP_ADMIN_PASS}
WP_ADMIN_EMAIL=${WP_ADMIN_EMAIL}
PHP_FPM_SOCK=${PHP_FPM_SOCK}
EOF
chmod 600 /root/reminders-wp-secrets.txt

echo "setup_wp_stack_ok"
