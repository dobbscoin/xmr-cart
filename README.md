<!-- home-banner -->
> ### 🏠 Home is [git.subgenius.finance](https://git.subgenius.finance/SubGeniusFinance/xmr-cart).
>
> The GitHub copy is a **mirror, pushed from here**. Open issues and pull requests at home.
>
> **Have a fork or a clone?** Point it home with `git remote set-url origin https://git.subgenius.finance/SubGeniusFinance/xmr-cart.git`

---
# xmr-cart

A small XMR-only checkout for shops that don't want WordPress underneath them.
Vanilla PHP, SQLite, no framework. Verifies payments on your own Monero node,
fails **CLOSED** when it can't, and never touches your spend key.

**Status:** carved from a live shop that has verified real mainnet payments end
to end. Payment scanner is `SlowBearDigger/xmr-pay-woocommerce` v1.1.4 (MIT),
lifted intact and wrapped in a lightweight WordPress shim so it runs without WP.

---

## What it does

- Storefront + admin console + cron worker in one small PHP tree
- Fresh Monero subaddress derived per order (no address reuse)
- Payment scanner checks Pedersen commitments before crediting an order
- Fails **CLOSED** on a pruned node — a payment is identified but never settles
  (so you can't accidentally ship on a bad verify)
- Buyer sees the pool tx immediately; the order only ships after
  `min_confirmations` matures
- Fiat-pegged prices, XMR amount locked at checkout
- Data minimisation: buyer PII purged from paid orders on a rolling schedule

## Requirements

- PHP 8.0+ with `sqlite3`, `curl`, `gd` extensions
- **A full (non-pruned) Monero node** you control. Public nodes work as
  fallback but the primary must be yours — see [Why full node](#why-full-node).
- A web server (nginx or Apache) that can serve PHP
- Cron (or systemd timer) for the payment poll and PII purge

## Install

```bash
git clone https://github.com/YOUR/xmr-cart.git /var/www/xmr-cart
cd /var/www/xmr-cart
cp config.example.php config.php
$EDITOR config.php                  # fill in view key, node URL, currency, etc.

# Runtime dirs must be writable by the web-server user:
sudo chown -R www-data:www-data data/ public/assets/products/ public/assets/banner/
sudo chmod 750 data/ public/assets/products/ public/assets/banner/
```

In `config.php`, set `cookie_secret` and `setup_key` to long random strings
(`php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'`). The store refuses to run
with the shipped placeholder secret.

Point a vhost at `public/`. Load the storefront, then load `/admin/` — the
first visit sends you to a one-time setup page that asks for your `setup_key`
and a passphrase, and stores the passphrase hash in the SQLite kv table.
From then on it's a normal login.

Admin works over the public internet out of the box — no VPN required to get
started. For a shop taking real orders, additionally lock admin/ down at the
web-server layer: see [Hardening the admin](#hardening-the-admin) below.

Wire the cron:

```cron
* * * * * php /var/www/xmr-cart/worker/poll.php >> /var/log/xmr-cart.log 2>&1
17 3 * * * php /var/www/xmr-cart/worker/purge_pii.php >> /var/log/xmr-cart-pii.log 2>&1
```

Once the passphrase is set and you can sign in: create a batch, add a
product, mark the batch live. That's it.

### Resetting the admin passphrase

If you forget it, from the shell:

```bash
sqlite3 data/store.sqlite "DELETE FROM kv WHERE k='admin_pass_hash';"
```

The next `/admin/` visit will bring the first-run wizard back so you can set
a new one. If you'd rather pin the hash in `config.php` (config-first,
deterministic), that value takes precedence over the DB — see the
`admin_pass_hash` comment in `config.example.php`.

## Why full node

The scanner verifies the Pedersen commitment on every incoming output. A
pruned node cannot serve commitments, so the check **fails closed**: the
payment is correctly identified as yours but the order never settles, and the
buyer's coin is stuck in a subaddress with no order to redeem against.

This is a deliberate safety property. Do not "fix" it by disabling the
commitment check — that removes the guarantee that the scanner correctly
matches an incoming output's amount to what the buyer owes.

## Configuring settlement nodes

The scanner talks to a list of Monero daemons — the "settlement node fleet"
in the storefront's chain-status pill. Ideally your own full node is
primary, with a couple of community nodes as fallbacks.

### Where the list lives

Two places, with a defined precedence:

1. **Admin console → Nodes tab.** Add / edit / remove / reorder nodes from the
   UI. Changes land in the SQLite `kv` table (`nodes_override`), take effect
   immediately, and re-probe the fleet. Best fit for iterating on the fleet
   or rotating a failing node.
2. **`config.php`** — comma-separated URLs, single line. Ops-first, config-as-
   code, deterministic:

   ```php
   'nodes' => 'http://127.0.0.1:18081,https://xmr-node.cakewallet.com:18081,https://node.monerodevs.org:18089',
   ```

**Lookup order: kv `nodes_override` > `config.php['nodes']`.** If the console
list is empty (or explicitly reverted with the "Revert to config.php" button
on the Nodes tab), the scanner falls back to `config.php`. **PHP-FPM reads
`config.php` on every request** — no restart needed either way.

### How the scanner uses the list

- **First responder wins** for per-tick queries — nodes are tried in listed
  order.
- **`tip_height` cross-checks as the MIN across responders.** A laggard node
  can only *delay* settlement, never bring it forward — the scanner refuses
  to credit an order at a height that isn't confirmed by every reachable
  node.
- **Every node must be non-pruned.** See [Why full node](#why-full-node). A
  pruned node in the list will cause fail-closed on any output it happens to
  serve — orders identified but never settled.

### Watching node health

The **Nodes** tab in the admin console shows per-node status: `OK` / `FAIL`,
`PRUNED`, `SYNCING`, tip height, response time. The **storefront's
chain-status pill** shows the same signal at a glance for buyers.

Live data comes from `lib/NodeProbe.php`, cached briefly so page loads
aren't gated on 4 nodes replying. The "Test all now" button re-probes on
demand, and every add / edit / remove / reorder from the Nodes tab
automatically re-probes against the new list.

## Running your own Monero node

Settlement trusts whichever node answers, so for real money list only nodes
you run. A public node can't steal funds, but a malicious one could report a
payment that never happened. Running your own node is also a big privacy upgrade. What the operator sees when queries hit
their node is which subaddresses your buyers are paying. That's a leak worth
closing.

### Install `monerod`

```bash
# Ubuntu / Debian — the packaged version is fine for a small shop
sudo apt install monero

# Or grab the current release for the latest features / fixes:
#   https://www.getmonero.org/downloads/
```

### Minimum startup

For a same-box node (store and node on the same machine):

```bash
monerod \
  --data-dir /var/lib/monero \
  --rpc-bind-ip 127.0.0.1 \
  --rpc-bind-port 18081 \
  --non-interactive \
  --log-file /var/log/monero/monerod.log \
  --log-level 0
```

For a separate node (store hits it over LAN / VPN):

```bash
monerod \
  --data-dir /var/lib/monero \
  --rpc-bind-ip 10.0.0.5 \                    # your private LAN or VPN IP
  --rpc-bind-port 18081 \
  --confirm-external-bind \                    # required whenever bind isn't 127.0.0.1
  --non-interactive
```

Then point `config.php`'s `nodes` at `http://127.0.0.1:18081` (same-box) or
`http://10.0.0.5:18081` (separate node).

### Flags NOT to pass

| flag | why not |
|---|---|
| `--prune-blockchain` | pruned nodes can't serve commitments → fail-closed → nothing settles. This is the whole point. |
| `--restricted-rpc` on the store's RPC port | may block `get_transactions` with `rct=true` and `get_outs`, which the scanner needs. If you're also serving public queries, put restricted on a *separately* bound port (`--rpc-restricted-bind-port`). |
| `--limit-rate-*` set aggressively low | the scanner does bursty per-order queries at settlement time; strangling the RPC just delays orders. |

### systemd unit

Drop at `/etc/systemd/system/monerod.service`:

```ini
[Unit]
Description=Monero full node
After=network-online.target
Wants=network-online.target

[Service]
User=monero
Group=monero
ExecStart=/usr/bin/monerod \
  --data-dir /var/lib/monero \
  --rpc-bind-ip 127.0.0.1 \
  --rpc-bind-port 18081 \
  --non-interactive \
  --log-file /var/log/monero/monerod.log
Restart=on-failure
RestartSec=30
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo useradd -r -s /usr/sbin/nologin monero || true
sudo mkdir -p /var/lib/monero /var/log/monero
sudo chown monero:monero /var/lib/monero /var/log/monero
sudo systemctl daemon-reload
sudo systemctl enable --now monerod
```

### What to expect

- **Disk:** ~200 GB for mainnet as of mid-2026, growing ~50 GB/year. Stagenet
  is ~20 GB. Give it a dedicated SSD if you can.
- **Sync time:** first sync is 8–48h on a decent VPS, longer on a home
  connection. After that it just keeps up.
- **RAM:** 4 GB is enough. 8 GB is comfortable.
- **The store keeps working during first sync.** The chain-status pill will
  say "syncing" and orders won't settle against blocks the node hasn't seen
  yet, but the storefront serves fine. If you need to take orders immediately,
  keep community nodes at the top of your `nodes` list until your own is
  caught up, then rotate to primary.

## Security posture

- **Admin console is passphrase-gated.** The bcrypt hash in `config.php` is
  the gate; the console works over the public internet out of the box. Pick a
  strong passphrase and you're covered for a small shop.
- Buyer PII is scrubbed by `worker/purge_pii.php` after `SHIP_KEEP_DAYS` /
  `DEAD_KEEP_DAYS`. Financial columns are preserved.
- The store only holds the private VIEW key. Keep the SPEND key on cold
  hardware.
- `config.php` is git-ignored; do not check it in.

## Hardening the admin

For a shop taking real orders, add a second layer at the web-server level so
the passphrase form is never even offered from the public internet. Pick one:

- **Bind admin/ to a VPN or Tailscale interface.** In nginx, put the
  `location /admin/` block in a separate `server` block that listens only on
  your VPN/tailnet IP (e.g. `listen 100.64.0.1:8089;`). Public 443 stops
  answering for `/admin/*` entirely.
- **Bind admin/ to localhost + SSH port-forward.** `listen 127.0.0.1:8089;`
  and access via `ssh -L 8089:127.0.0.1:8089 your-host`.
- **HTTP Basic auth in front of the passphrase.** `auth_basic` +
  `auth_basic_user_file` in the `/admin/` location. Cheapest option, no
  network reconfig.

Any of these turns the passphrase into a second line of defence, which is
what you want if the shop earns money. The app itself does not throttle
login attempts, so if admin/ stays public, at least rate-limit
`/admin/login.php` in the web server (e.g. nginx `limit_req`, a few per minute).

## Brand assets

The default mark (`public/assets/brand/mark.svg`) is a Monero-orange square
with a bold M — deliberately generic, not the Monero project's official logo.
If you want the real logo, download brand assets from
[getmonero.org/press-kit](https://www.getmonero.org/press-kit/) and drop
replacements into `public/assets/brand/`. Check the Monero brand-usage
guidelines before shipping.

## Upstream credit

The payment scanner in `lib/scanner/` is
[`SlowBearDigger/xmr-pay-woocommerce`](https://github.com/SlowBearDigger/xmr-pay-woocommerce)
v1.1.4, MIT-licensed. See `LICENSE-THIRD-PARTY.md`. That project handles the
tricky parts: subaddress derivation, output-key computation, Pedersen
commitment verification, node HTTP + digest auth. xmr-cart wraps it in a
WordPress-free shim and adds the storefront, admin console, and settlement
logic on top.

## License

MIT (see `LICENSE`). The bundled scanner is MIT under its own copyright
(see `LICENSE-THIRD-PARTY.md`).
