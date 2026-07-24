#!/bin/bash
# Auto-deploy hook for xmr-cart on guru.
# Cron runs this every minute as btcbob. If NAS master has moved past our
# local HEAD, git-reset the local checkout, rsync into /var/www/xmr-cart.info/
# (preserving config.php + runtime data/), and reload php-fpm.

set -euo pipefail
DEPLOY_SRC=/home/btcbob/xmr-cart-deploy
DEPLOY_DST=/var/www/xmr-cart.info
LOG=$DEPLOY_SRC/deploy.log
LOCK=$DEPLOY_SRC/.deploying.lock

# Serialize — cron can overlap if a deploy takes longer than a minute.
exec 9>"$LOCK"
flock -n 9 || exit 0

cd "$DEPLOY_SRC"

# Cheap fetch; bail if nothing to do.
git fetch --quiet origin master
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/master)
[ "$LOCAL" = "$REMOTE" ] && exit 0

echo "$(date -Iseconds) deploy start: $LOCAL -> $REMOTE" >>"$LOG"

git reset --hard --quiet origin/master

rsync -a --delete \
  --exclude=.git \
  --exclude=config.php \
  --exclude=data \
  --exclude=public/assets/products \
  --exclude=public/assets/banner \
  "$DEPLOY_SRC/" "$DEPLOY_DST/"

# Perms: any newly-arrived files land as btcbob:btcbob after rsync-from-btcbob.
# Reset group to www-data so php-fpm can still read them.
sudo -n chgrp -R www-data "$DEPLOY_DST" 2>>"$LOG" || true
sudo -n systemctl reload php8.2-fpm >>"$LOG" 2>&1

NEW=$(git rev-parse --short HEAD)
echo "$(date -Iseconds) deploy done: $NEW" >>"$LOG"
