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
$EDITOR config.php                  # fill in view key, node URL, admin hash, etc.

# Generate the admin passphrase hash:
php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT).PHP_EOL;"
# Paste the output into admin_pass_hash in config.php.

# Runtime dirs must be writable by the web-server user:
sudo chown -R www-data:www-data data/ public/assets/products/ public/assets/banner/
sudo chmod 750 data/ public/assets/products/ public/assets/banner/
```

Point a vhost at `public/` and put `admin/` behind a VPN, Tailscale, or
localhost — the admin passphrase is defense-in-depth, not the primary gate.

Wire the cron:

```cron
* * * * * php /var/www/xmr-cart/worker/poll.php >> /var/log/xmr-cart.log 2>&1
17 3 * * * php /var/www/xmr-cart/worker/purge_pii.php >> /var/log/xmr-cart-pii.log 2>&1
```

Load the storefront, then log into `admin/` with your passphrase. Create a
batch, add a product, mark the batch live. That's it.

## Why full node

The scanner verifies the Pedersen commitment on every incoming output. A
pruned node cannot serve commitments, so the check **fails closed**: the
payment is correctly identified as yours but the order never settles, and the
buyer's coin is stuck in a subaddress with no order to redeem against.

This is a deliberate safety property. Do not "fix" it by disabling the
commitment check — that removes the guarantee that the scanner correctly
matches an incoming output's amount to what the buyer owes.

## Security posture

- **Admin console must be behind a VPN, Tailscale, or bound to localhost.**
  The passphrase is a second line, not a first.
- Buyer PII is scrubbed by `worker/purge_pii.php` after `SHIP_KEEP_DAYS` /
  `DEAD_KEEP_DAYS`. Financial columns are preserved.
- The store only holds the private VIEW key. Keep the SPEND key on cold
  hardware.
- `config.php` is git-ignored; do not check it in.

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
