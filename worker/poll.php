<?php
/**
 * Poll worker — run from cron every minute:
 *   * * * * *  php /path/to/xmr-cart/worker/poll.php >> /var/log/xmr-cart.log 2>&1
 *
 * Reads open orders, scans for payments, and moves them to confirming / paid /
 * expired. Buyers' status pages just read the row this updates, so the node is
 * only ever hit by this one process — not by every browser refresh.
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit( "CLI only.\n" ); }

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/settle.php';

// Single-instance lock so a slow tick never overlaps the next.
// Named per install, so two stores on one box don't skip each other's ticks.
$lock = fopen( sys_get_temp_dir() . '/xmr-shop-poll-' . md5( __DIR__ ) . '.lock', 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
	fwrite( STDERR, "[" . date( 'c' ) . "] previous poll still running; skipping.\n" );
	exit( 0 );
}

$store = store();
$xmr   = xmr();

$tip = $xmr->tipHeight();
$mode = $xmr->isReal() ? 'live' : 'DEMO';
notify_health( 'node', null !== $tip,
	'PAYMENT CHECKS DOWN — the store cannot reach its Monero node',
	"The payment checker has not been able to reach the Monero node.\nNew payments are NOT being confirmed until it's back (nothing is lost; they'll settle when it returns).\n\nCheck the node, and the 'nodes' setting in config.php.",
	'Payment checks are back' );
notify_health( 'price', price()->xmrRate() > 0,
	'CHECKOUT PAUSED — no usable XMR price',
	"The store has had no usable XMR exchange rate, so checkout is refusing new orders (on purpose: it won't misprice them).\nIt usually comes back on its own when the price feed recovers.",
	'Checkout is taking orders again (XMR price is back)' );
if ( null === $tip ) {
	fwrite( STDERR, "[" . date( 'c' ) . "] [$mode] node unreachable; will retry next tick.\n" );
	exit( 0 );
}

$open = $store->all( "SELECT * FROM orders WHERE status IN ('pending','confirming') ORDER BY id ASC" );
$changed = 0;
foreach ( $open as $order ) {
	$before = $order['status'];
	$after  = settle_order( $store, $xmr, $order, $tip );
	if ( $after !== $before ) {
		$changed++;
		echo "[" . date( 'c' ) . "] order #{$order['id']} ({$order['token']}): {$before} -> {$after}\n";
	}
}

echo "[" . date( 'c' ) . "] [$mode] tip={$tip} open=" . count( $open ) . " changed={$changed}\n";
flock( $lock, LOCK_UN );
