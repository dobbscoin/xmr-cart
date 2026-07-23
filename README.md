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

Point a vhost at `public/`. Load the storefront, then load `/admin/` — the
first visit sends you to a one-time setup page that asks for a passphrase
and stores its hash in the SQLite kv table. From then on it's a normal login.

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
what you want if the shop earns money. If you're just kicking the tyres —
the passphrase alone is fine.

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
