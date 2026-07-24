#!/bin/bash
set -e
echo "=== NOPASSWD check ==="
sudo -n whoami
echo "=== sudoers.d ==="
sudo ls -la /etc/sudoers.d/

echo
echo "=== apt cache freshness ==="
sudo apt-get update -qq
echo "=== install baseline (nginx + PHP 8.2 + tools) ==="
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  nginx \
  php8.2-fpm php8.2-cli php8.2-sqlite3 php8.2-curl php8.2-gd php8.2-mbstring php8.2-xml \
  php8.2-bcmath php8.2-gmp \
  sqlite3 \
  git curl wget \
  certbot python3-certbot-nginx \
  bzip2 pv jq \
  ca-certificates gnupg
echo "=== nginx version ==="
nginx -v 2>&1
echo "=== php version ==="
php -v | head -1
echo "=== nginx service ==="
sudo systemctl enable --now nginx
sudo systemctl is-active nginx
echo "=== php-fpm socket ==="
ls /run/php/
