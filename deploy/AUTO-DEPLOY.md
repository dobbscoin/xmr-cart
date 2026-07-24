# Auto-deploy pipeline (xmr-cart.info on guru)

Every push to `origin/master` (NAS bare repo at `claude-memory-nas:git/xmr-cart.git`)
lands on the public demo at https://xmr-cart.info/ within ~60 seconds. No manual
step; no CI/GitHub Actions required.

## How it works

1. On any workstation, `git push origin master` → NAS bare repo updates.
2. Guru cron (`/etc/cron.d/xmr-cart-autodeploy`) runs every minute as `btcbob`:
   `/home/btcbob/xmr-cart-deploy/pull-and-deploy.sh`.
3. The script:
   - `flock`s so overlapping cron ticks don't race
   - `git fetch --quiet origin master`
   - if local HEAD matches remote master, exits (0 op)
   - otherwise: `git reset --hard`, rsync into `/var/www/xmr-cart.info/`,
     `sudo systemctl reload php8.2-fpm`
4. The rsync **excludes** `config.php`, `data/*`, `public/assets/products/*`,
   `public/assets/banner/*` — so the operator's wallet identity, order DB,
   admin passphrase, and uploaded images survive every deploy.

## Runtime files preserved across deploys

| Path | Why |
|---|---|
| `config.php` | wallet identity + cookie secret; operator-managed |
| `data/store.sqlite` | orders, subaddress counter, kv (passphrase hash, wallet override) |
| `public/assets/products/*` | product images uploaded via admin |
| `public/assets/banner/*` | masthead banner image |

## Deploy log

`~btcbob/xmr-cart-deploy/deploy.log` on guru. One line per deploy:

    2026-07-24T15:12:03+00:00 deploy start: e3edbcb... -> e3b1075...
    2026-07-24T15:12:04+00:00 deploy done: e3b1075

## Rollback

    ssh guru
    cd /home/btcbob/xmr-cart-deploy
    git reset --hard <prior sha>
    ./pull-and-deploy.sh   # local-only reset skips the fetch guard the next tick

Or just revert the offending commit and push — the pipeline redeploys.

## Turning it off

    sudo rm /etc/cron.d/xmr-cart-autodeploy

Deployed state on guru is preserved; only auto-updates stop.
