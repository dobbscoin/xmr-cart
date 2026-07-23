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
	// Run your own node. Public nodes are for fallback only.
	'nodes' => 'http://YOUR_OWN_NODE:18081,https://xmr-node.cakewallet.com:18081',

	// ---- Settlement rules ---------------------------------------------------
	'min_confirmations' => 10,                      // 10 ~= the standard spendable depth
	'tolerance_atomic'  => 0,                       // accepted shortfall, piconero (0 = exact)
	'order_ttl_minutes' => 30,                      // quote/price lock window before expiry.
	                                                // TTL is the window to SEND, not to confirm — the
	                                                // moment any output is seen (including in the mempool),
	                                                // the order goes 'confirming' and TTL is irrelevant.

	// ---- Pricing ------------------------------------------------------------
	// Store prices are set in fiat; the XMR amount is locked at checkout from a
	// live rate. If the rate fetch fails (or you prefer manual), manual_xmr_rate
	// is used as the price of 1 XMR in your store currency.
	'store_currency'     => 'usd',
	'coingecko_api_key'  => '',                     // optional; blank uses the free endpoint
	'manual_xmr_rate'    => 0,                      // >0 forces a fixed rate, skips the fetch
	'price_cache_seconds'=> 120,

	// ---- Admin console ------------------------------------------------------
	// The passphrase below is the gate. Pick a strong one.
	// Generate a hash:  php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT).PHP_EOL;"
	//
	// If you want defense in depth, additionally bind admin/ to a VPN, Tailscale
	// interface, or localhost in your web-server config so the passphrase is
	// never even offered a login prompt from the public internet. Recommended
	// for any shop taking real orders. See README.md § "Hardening the admin".
	'admin_pass_hash'    => '',                     // password_hash() output; empty = warn loudly
	'cookie_secret'      => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

	// ---- Order-paid email notifications (optional) --------------------------
	// Sends a short "order N is paid, ship it" mail to the operator. Buyer PII
	// is DELIBERATELY not included — the console is the one place ship-to data
	// is exposed. Leave notify_email blank to disable.
	'notify_email'       => '',                     // where to send operator alerts
	'notify_from'        => 'orders@example.com',   // From: address (must be one your MTA can send)

	// ---- Paths --------------------------------------------------------------
	'db_path'      => __DIR__ . '/data/store.sqlite',
	'uploads_dir'  => __DIR__ . '/public/assets/products',
	'uploads_url'  => 'assets/products',
);
