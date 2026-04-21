#!/usr/bin/env bash
set -euo pipefail

WP_ROOT="/var/www/html/reminders-wp"
SITE="/etc/nginx/sites-available/reminders.incsubsupport.work"
DOMAIN="reminders.incsubsupport.work"

if [ -S /run/php/php8.3-fpm.sock ]; then
  PHP_FPM_SOCK="/run/php/php8.3-fpm.sock"
elif [ -S /run/php/php8.2-fpm.sock ]; then
  PHP_FPM_SOCK="/run/php/php8.2-fpm.sock"
else
  echo "php-fpm socket not found" >&2
  exit 1
fi

RELAY_SECRET="$(openssl rand -hex 32)"
RELAY_API_KEY="$(printf '%s' "$RELAY_SECRET" | sha256sum | awk '{print substr($1,1,32)}')"

cat > /etc/reminders-slack-relay.ini <<EOF
relay_secret=${RELAY_SECRET}
relay_api_key=${RELAY_API_KEY}
slack_webhook=
EOF
chown root:www-data /etc/reminders-slack-relay.ini
chmod 640 /etc/reminders-slack-relay.ini

mkdir -p "$WP_ROOT/_relay"
cat > "$WP_ROOT/_relay/slack.php" <<'PHP'
<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Method not allowed'));
    exit;
}

$config = parse_ini_file('/etc/reminders-slack-relay.ini');
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(array('error' => 'Relay config missing'));
    exit;
}

$apiKey = isset($config['relay_api_key']) ? (string) $config['relay_api_key'] : '';
$secret = isset($config['relay_secret']) ? (string) $config['relay_secret'] : '';
$webhook = isset($config['slack_webhook']) ? (string) $config['slack_webhook'] : '';

$sentApiKey = isset($_SERVER['HTTP_X_TRT_API_KEY']) ? (string) $_SERVER['HTTP_X_TRT_API_KEY'] : '';
$sentTs = isset($_SERVER['HTTP_X_TRT_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_TRT_TIMESTAMP'] : '';
$sentSig = isset($_SERVER['HTTP_X_TRT_SIGNATURE']) ? (string) $_SERVER['HTTP_X_TRT_SIGNATURE'] : '';

if ($apiKey === '' || $secret === '' || $webhook === '') {
    http_response_code(500);
    echo json_encode(array('error' => 'Relay is not configured'));
    exit;
}

if (!hash_equals($apiKey, $sentApiKey)) {
    http_response_code(403);
    echo json_encode(array('error' => 'Invalid API key'));
    exit;
}

if (!ctype_digit($sentTs)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid timestamp'));
    exit;
}

$ts = (int) $sentTs;
if (abs(time() - $ts) > 300) {
    http_response_code(400);
    echo json_encode(array('error' => 'Timestamp out of range'));
    exit;
}

$body = file_get_contents('php://input');
if ($body === false || $body === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'Missing payload'));
    exit;
}

$expected = hash_hmac('sha256', $sentTs . '.' . $body, $secret);
if (!hash_equals($expected, $sentSig)) {
    http_response_code(403);
    echo json_encode(array('error' => 'Invalid signature'));
    exit;
}

$ch = curl_init($webhook);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
));
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || strtolower(trim((string) $response)) !== 'ok') {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Slack relay failed', 'code' => $httpCode, 'detail' => $curlErr !== '' ? $curlErr : (string) $response));
    exit;
}

http_response_code(204);
exit;
PHP

chown -R www-data:www-data "$WP_ROOT/_relay"
chmod 750 "$WP_ROOT/_relay"
chmod 640 "$WP_ROOT/_relay/slack.php"

cp "$SITE" "${SITE}.bak.$(date +%Y%m%d%H%M%S)"
cat > "$SITE" <<EOF
server {
    server_name ${DOMAIN};

    root ${WP_ROOT};
    index index.php index.html index.htm;

    access_log /var/log/nginx/reminders.incsubsupport.work.access.log;
    error_log /var/log/nginx/reminders.incsubsupport.work.error.log;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    location = /_relay/slack {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${WP_ROOT}/_relay/slack.php;
        fastcgi_param DOCUMENT_ROOT ${WP_ROOT};
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location ~* \.(?:css|js|mjs|map|ico|gif|jpe?g|png|svg|webp|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public, max-age=604800, immutable";
        access_log off;
        try_files \$uri =404;
    }

    location ~ /\.ht {
        deny all;
    }

    listen [::]:443 ssl ipv6only=on; # managed by Certbot
    listen 443 ssl; # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/reminders.incsubsupport.work/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/reminders.incsubsupport.work/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; # managed by Certbot
}

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}
EOF

nginx -t
systemctl reload nginx

echo "RELAY_SECRET=${RELAY_SECRET}" > /root/reminders-relay-secrets.txt
echo "RELAY_API_KEY=${RELAY_API_KEY}" >> /root/reminders-relay-secrets.txt
echo "RELAY_URL=https://${DOMAIN}/_relay/slack" >> /root/reminders-relay-secrets.txt
chmod 600 /root/reminders-relay-secrets.txt

echo "setup_nginx_wp_and_relay_ok"
