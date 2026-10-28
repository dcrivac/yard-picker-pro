#!/usr/bin/env bash
# One-time server setup for Yard Picker Pro on a fresh Ubuntu 24.04 VPS.
#
# Installs Caddy (HTTPS), PHP 8.3-FPM and MariaDB, clones the app into
# /var/www/yardpicker, loads schema.sql, writes .env.php with generated DB
# credentials, and creates a `deploy` user for .github/workflows/deploy.yml.
#
# Run as root, either as DigitalOcean "user data" or over SSH:
#   DEPLOY_PUBKEY="ssh-ed25519 AAAA..." bash setup.sh
#
# Settings (environment variables):
#   DEPLOY_PUBKEY  required. Public half of the GitHub Actions SSH_KEY secret.
#   APP_DOMAIN     default yardpicker.crivac.com
#   BRANCH         default main. Branch to check out first; deploys reset to main.
#
# Safe to re-run: existing DB credentials and .env.php are kept.
# Log: /var/log/yardpicker-setup.log

set -euo pipefail
exec > >(tee -a /var/log/yardpicker-setup.log) 2>&1

: "${DEPLOY_PUBKEY:?Set DEPLOY_PUBKEY to the deploy SSH public key}"
APP_DOMAIN="${APP_DOMAIN:-yardpicker.crivac.com}"
BRANCH="${BRANCH:-main}"
REPO_URL="https://github.com/dcrivac/yard-picker-pro.git"
DOCROOT="/var/www/yardpicker"
DB_NAME="yardpicker"
DB_USER="yardpicker"
CREDS="/root/.yardpicker-db"
export DEBIAN_FRONTEND=noninteractive

echo "== Yard Picker Pro setup for $APP_DOMAIN ($(date -u))"

# Swap: MariaDB + PHP is tight on a 1 GB droplet.
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Packages. Caddy comes from its official apt repository.
apt-get update -q
apt-get install -y -q debian-keyring debian-archive-keyring apt-transport-https curl gnupg git ufw unattended-upgrades
if [ ! -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg ]; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
fi
apt-get install -y -q caddy php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring mariadb-server
dpkg-reconfigure -f noninteractive unattended-upgrades

# Firewall: SSH and web only.
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# Deploy user for GitHub Actions (key-only SSH, owns the docroot).
id deploy >/dev/null 2>&1 || adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy
usermod -aG www-data caddy   # Caddy serves static files from the www-data group docroot
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
grep -qxF "$DEPLOY_PUBKEY" /home/deploy/.ssh/authorized_keys 2>/dev/null || echo "$DEPLOY_PUBKEY" >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys

# App code. Full clone (not single-branch) so deploy.yml can fetch main.
if [ ! -d "$DOCROOT/.git" ]; then
  install -d -o deploy -g www-data -m 750 "$DOCROOT"
  sudo -u deploy git clone --branch "$BRANCH" "$REPO_URL" "$DOCROOT"
fi

# Database: generate credentials once, keep them on re-runs.
if [ ! -f "$CREDS" ]; then
  umask 077
  echo "DB_PASS=$(openssl rand -hex 24)" > "$CREDS"
fi
# shellcheck source=/dev/null
. "$CREDS"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
mariadb "$DB_NAME" < "$DOCROOT/schema.sql"

# .env.php: DB settings filled in, API keys left for you to add.
ENV_FILE="$DOCROOT/.env.php"
if [ ! -f "$ENV_FILE" ]; then
  cat > "$ENV_FILE" <<PHP
<?php
putenv('APP_ORIGIN=https://$APP_DOMAIN');
putenv('DB_HOST=localhost');
putenv('DB_NAME=$DB_NAME');
putenv('DB_USER=$DB_USER');
putenv('DB_PASS=$DB_PASS');
// Fill these in (see .env.example), then nothing needs restarting:
// putenv('ANTHROPIC_API_KEY=');
// putenv('EBAY_VERIFICATION_TOKEN=');
// putenv('EBAY_CLIENT_ID=');
// putenv('EBAY_CLIENT_SECRET=');
// putenv('STRIPE_SECRET_KEY=');
// putenv('STRIPE_WEBHOOK_SECRET=');
// putenv('RESEND_API_KEY=');
// putenv('RESEND_FROM=');
PHP
fi
chown deploy:www-data "$ENV_FILE" && chmod 640 "$ENV_FILE"

# PHP: keep server sessions as long as the 30-day login cookie in db.php.
# Ubuntu's session cleanup cron otherwise deletes them after 24 idle minutes.
cat > /etc/php/8.3/fpm/conf.d/99-yardpicker.ini <<'INI'
session.gc_maxlifetime = 2592000
expose_php = Off
INI
systemctl restart php8.3-fpm

# Caddy. The site sits behind Cloudflare's proxy, so trust Cloudflare's
# ranges and pass the visitor IP to PHP; api.php rate-limits on REMOTE_ADDR
# and would otherwise see a handful of Cloudflare IPs for every user.
CF_RANGES="$(curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6)"
CF_RANGES="$(echo "$CF_RANGES" | tr -s '[:space:]' ' ' | sed 's/ *$//')"
cat > /etc/caddy/Caddyfile <<CADDY
{
	servers {
		trusted_proxies static $CF_RANGES
		client_ip_headers CF-Connecting-IP
	}
}

$APP_DOMAIN {
	root * $DOCROOT
	encode zstd gzip

	# Never serve repo internals or secrets.
	@private path /.git /.git/* /.github/* /.env* /.htaccess /schema.sql /*.md /deploy/* /probe/*
	respond @private 404

	# Same no-cache policy .htaccess applied on Apache.
	header /*.html Cache-Control "no-cache, no-store, must-revalidate"

	php_fastcgi unix//run/php/php8.3-fpm.sock {
		env REMOTE_ADDR {client_ip}
	}
	file_server
}
CADDY
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
systemctl reload caddy || systemctl restart caddy

# The app scrapes pyp.com, which blocks some datacenter IP ranges. Check now.
PYP_CODE="$(curl -s -o /tmp/pyp-check.html -w '%{http_code}' -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15' https://www.pyp.com/inventory/chula-vista-1264/ || true)"
if [ "$PYP_CODE" = "200" ] && ! grep -q 'Just a moment' /tmp/pyp-check.html; then
  echo "== PYP CHECK: PASS (pyp.com inventory reachable from this server)"
else
  echo "== PYP CHECK: FAIL (HTTP $PYP_CODE, likely a bot challenge). The scraper will not work from this IP."
fi

echo "== Done. Next steps:"
echo "  1. Add the API keys to $ENV_FILE"
echo "  2. GitHub repo variable DEPLOY_PATH=$DOCROOT; secrets SSH_HOST=<this IP>, SSH_USER=deploy, SSH_PORT=22"
echo "  3. Cloudflare DNS: A record for ${APP_DOMAIN%%.*} -> this IP"
