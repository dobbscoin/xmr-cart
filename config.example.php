<?php
/**
 * Copy this file to config.php and fill it in. config.php is git-ignored.
 *
 * Nothing here can spend your coins: the store only ever holds your PRIVATE VIEW
 * KEY (read-only) and your PRIMARY ADDRESS. Keep your SPEND key off this machine.
 */

return array(

	// ---- Store identity -----------------------------------------------------
	'store_name'  => 'XMR Shop',                    // shown in the wordmark, page titles, email From:
	'store_url'   => 'https://example.com',         // canonical public URL; used by the admin "view store" link

	// ---- Wallet (read-only) -------------------------------------------------
	// Your primary address and the matching PRIVATE VIEW key. The view key lets
	// the store watch for incoming payments; it can never move funds.
	'primary_address' => 'YOUR_PRIMARY_MONERO_ADDRESS',
	'view_key'        => 'YOUR_PRIVATE_VIEW_KEY',
	'network'         => 'mainnet',                 // mainnet | stagenet | testnet

	// ---- Monero node(s) -----------------------------------------------------
	// Comma-separated list. First is primary; scanner tries each in order until
	// one answers. tip_height cross-checks as the MIN across responders (a
	// laggard can only delay settlement, never bring it forward).
	//
	// EVERY NODE MUST BE A FULL (NON-PRUNED) NODE. The scanner verifies the
	// Pedersen commitment on each incoming output — a pruned node cannot serve
	// commitments, so the check fails CLOSED: the payment is correctly
	// identified as yours but never settles, and the buyer's coin is stuck.
	// Run your own node, and for real money list ONLY nodes you run. Settlement trusts
	// whichever node answers: a malicious public node could serve a fabricated
	// transaction to your subaddress and mark an unpaid order paid. With only your
	// own node, an outage just makes payments wait (fail closed).
	'nodes' => 'http://YOUR_OWN_NODE:18081',

	// ---- Settlement rules ---------------------------------------------------
	'min_confirmations' => 10,                      // 10 ~= the standard spendable depth
	'tolerance_atomic'  => 0,                       // accepted shortfall, piconero (0 = exact)
	'order_ttl_minutes' => 30,                      // quote/price lock window before expiry.
	'expiry_grace_minutes' => 20,                   // after the window, keep scanning this long before expiring (late-mined payments).
	                                                // TTL is the window to SEND, not to confirm. The scanner
	                                                // reads mined blocks only (not the mempool); once an output
	                                                // is mined the order goes 'confirming' and TTL is irrelevant.
	'underpaid_hold_hours' => 24,                   // an underpaid order expires (stock released) this long after its window.

	// ---- Pricing ------------------------------------------------------------
	// Store prices are set in fiat; the XMR amount is locked at checkout from a
	// live rate. If the rate fetch fails (or you prefer manual), manual_xmr_rate
	// is used as the price of 1 XMR in your store currency.
	'store_currency'     => 'usd',
	'coingecko_api_key'  => '',                     // optional; blank uses the free endpoint
	'manual_xmr_rate'    => 0,                      // >0 forces a fixed rate, skips the fetch
	'price_cache_seconds'=> 120,
	'price_max_stale_minutes' => 15,                // older than this and checkout stops until the feed is back

	// ---- Admin console ------------------------------------------------------
	// Leave admin_pass_hash empty. The first visit to /admin/ will send you to
	// a one-time setup page that asks for a passphrase and stores its hash
	// inside the store's SQLite database (kv table) — config.php stays yours.
	//
	// If you'd rather pin the hash here (deterministic config, no DB writes),
	// paste a bcrypt hash and it will take precedence over the DB value:
	//   php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT).PHP_EOL;"
	//
	// For defense in depth, additionally bind admin/ to a VPN, Tailscale, or
	// localhost in your web-server config. See README § "Hardening the admin".
	'admin_pass_hash'    => '',                     // blank = first-run wizard fires
	// REQUIRED. Signs the admin login cookie; the store refuses to run without a real one.
	//   php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'
	'cookie_secret'      => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
	// REQUIRED for first-run setup: the setup page asks for it, so a stranger who
	// reaches a fresh install first cannot claim it. Any long random string.
	'setup_key'          => '',

	// ---- Order-paid email notifications (optional) --------------------------
	// Sends a short "order N is paid, ship it" mail to the operator. Buyer PII
	// is DELIBERATELY not included — the console is the one place ship-to data
	// is exposed. Leave notify_email blank to disable.
	'notify_email'       => '',                     // where to send operator alerts (install.php asks for it)
	// Optional: send some kinds of alert somewhere else. Blank = notify_email.
	'notify_email_orders'  => '',                   // new + paid orders
	'notify_email_refunds' => '',                   // REFUND DUE
	'notify_email_health'  => '',                   // payment checks down / checkout paused (+ all clear)
	'notify_email_stock'   => '',                   // low stock / sold out
	'notify_from'        => 'orders@example.com',   // From: address (must be one your MTA can send)
	// With notify_email set, the owner also gets: REFUND DUE, payment checks down/back,
	// checkout paused/back, low stock / sold out, and new orders. Buyers get a payment
	// receipt and a shipped email (with tracking) unless notify_buyers is false.
	'notify_new_orders'  => true,                   // email the owner when an order is placed (before payment)
	'notify_low_stock'   => 2,                      // alert when an item drops to this many left (and at 0)
	'notify_health_minutes' => 15,                  // node / price-feed outage length before alerting
	'notify_buyers'      => true,                   // payment-received + shipped emails to the buyer
	'site_url'           => '',                     // e.g. https://shop.example.com — for order links in buyer mail (blank = learned at checkout)

	// ---- Paths --------------------------------------------------------------
	'db_path'      => __DIR__ . '/data/store.sqlite',
	'uploads_dir'  => __DIR__ . '/public/assets/products',
	'uploads_url'  => 'assets/products',
);
