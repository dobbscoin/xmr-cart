<?php
/**
 * Email notifications.
 *
 * To the shop owner (notify_email):
 *   refund due · payment checks down / back · checkout paused / back ·
 *   low stock / sold out · new order placed · order paid (helpers.php)
 * To the buyer (the email they gave at checkout, if notify_buyers):
 *   payment received · shipped (with carrier + tracking)
 *
 * Owner mail never carries buyer PII (name/address/phone/return address): the console
 * is the one place those live. Buyer mail goes only to the buyer, about their own order.
 * Every alert is sent once per event (kv 'notify:*' latches), so nothing repeats.
 * A mail failure is logged and never interrupts checkout or settlement.
 */

/** Send one plain-text mail. Returns true if handed to the MTA (or the test log). */
function notify_send( $to, $subject, $body, $replyTo = '' ) {
	$clean = function ( $s ) { return trim( str_replace( array( "\r", "\n" ), ' ', (string) $s ) ); };
	$to = $clean( $to );
	if ( '' === $to || ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) { return false; }
	$from = $clean( Config::get( 'notify_from', 'orders@localhost' ) );
	$name = $clean( store_name() );
	$replyTo = $clean( '' !== $replyTo ? $replyTo : $from );
	$subj = function_exists( 'mb_encode_mimeheader' ) ? mb_encode_mimeheader( $clean( $subject ), 'UTF-8', 'B', "\r\n" ) : $clean( $subject );
	$headers = 'From: ' . ( function_exists( 'mb_encode_mimeheader' ) ? mb_encode_mimeheader( $name, 'UTF-8', 'B' ) : $name ) . ' <' . $from . ">\r\n"
		. 'Reply-To: ' . $replyTo . "\r\n"
		. "X-Mailer: xmr-cart\r\n"
		. "MIME-Version: 1.0\r\n"
		. 'Content-Type: text/plain; charset=UTF-8';
	// Test hook: write mail to a file instead of sending it.
	$log = (string) Config::get( 'notify_mail_log', '' );
	if ( '' !== $log ) {
		@file_put_contents( $log, "=== TO: $to\nSUBJECT: $subject\n$body\n", FILE_APPEND );
		return true;
	}
	try {
		return (bool) @mail( $to, $subj, $body, $headers, '-f' . $from );
	} catch ( \Throwable $e ) {
		error_log( 'notify_send failed: ' . $subject );
		return false;
	}
}

function notify_owner_address() { return trim( (string) Config::get( 'notify_email', '' ) ); }

/** Owner alert, sent at most once per $key (null = always). */
function notify_owner( $subject, $body, $key = null ) {
	$to = notify_owner_address();
	if ( '' === $to ) { return false; }
	if ( null !== $key ) {
		if ( store()->kvGet( 'notify:' . $key ) ) { return false; }
		store()->kvSet( 'notify:' . $key, (string) now() );
	}
	return notify_send( $to, $subject, $body . "\n\n-- \n" . store_name() . " (xmr-cart)\n" );
}

/** Base URL of the storefront (for links in buyer mail). Config site_url wins; else learned from checkout. */
function notify_site_url() {
	$u = rtrim( trim( (string) Config::get( 'site_url', '' ) ), '/' );
	if ( '' !== $u ) { return $u; }
	$row = store()->kvGet( 'site:base_url' );
	return $row ? rtrim( (string) $row['v'], '/' ) : '';
}
/** Called on a web checkout: remember where the storefront lives, for links the worker sends later. */
function notify_learn_site_url() {
	if ( PHP_SAPI === 'cli' || empty( $_SERVER['HTTP_HOST'] ) ) { return; }
	$host = preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST'] );
	$dir  = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/' ) ) ), '/' );
	$url  = ( is_https() ? 'https' : 'http' ) . '://' . $host . $dir;
	$cur  = store()->kvGet( 'site:base_url' );
	if ( ! $cur || $cur['v'] !== $url ) { store()->kvSet( 'site:base_url', $url ); }
}
function notify_order_link( $order ) {
	$base = notify_site_url();
	return '' !== $base ? $base . '/pay.php?t=' . $order['token'] : '';
}

function notify_items_text( $orderId ) {
	$t = '';
	foreach ( order_items( $orderId ) as $it ) {
		$t .= '  ' . $it['product_name'] . ( (int) $it['qty'] > 1 ? ' x' . (int) $it['qty'] : '' ) . "\n";
	}
	return $t;
}

// ---- 1. refund due -------------------------------------------------------------
/** $why: 'overpaid' | 'underpaid' | 'cancelled' | 'late'. Once per order. */
function notify_refund_due( $order, $why ) {
	$due = refund_due_pico( $order );
	if ( '0' === $due ) { return false; }
	$what = array(
		'overpaid'  => 'was OVERPAID. The order is paid; the extra should go back.',
		'underpaid' => 'was UNDERPAID and has now expired. What was received should go back.',
		'cancelled' => 'was CANCELLED after payment arrived. What was received should go back.',
		'late'      => 'received payment after it had closed. What was received should go back.',
	);
	$body = 'Order #' . (int) $order['id'] . ' ' . ( $what[ $why ] ?? 'has money to return.' ) . "\n\n"
		. 'Refund due : ' . pico_to_xmr( $due ) . " XMR\n"
		. 'Order total: ' . $order['xmr_amount'] . ' XMR (' . strtoupper( (string) $order['currency'] ) . ' ' . number_format( (float) $order['price_fiat'], 2 ) . ")\n"
		. 'Received   : ' . pico_to_xmr( $order['received_pico'] ) . " XMR\n\n"
		. "Items:\n" . notify_items_text( $order['id'] ) . "\n"
		. "The buyer's XMR return address is on the order in the console (Orders tab). Send the refund\n"
		. "from your own wallet; the store never sends coin.";
	return notify_owner( 'REFUND DUE — order #' . (int) $order['id'] . ' (' . pico_to_xmr( $due ) . ' XMR)', $body, 'refund:' . (int) $order['id'] );
}

// ---- 2 + 3. health: payment checks / price feed -----------------------------------
/**
 * Called by the poll worker every tick. $ok = this check worked this tick.
 * After 'notify_health_minutes' (15) of failure: one alert. On recovery: one all-clear.
 */
function notify_health( $name, $ok, $downSubject, $downBody, $upSubject ) {
	$since = store()->kvGet( 'health:' . $name . ':down_since' );
	$sent  = store()->kvGet( 'notify:health:' . $name );
	if ( $ok ) {
		if ( $since ) { store()->q( 'DELETE FROM kv WHERE k=?', array( 'health:' . $name . ':down_since' ) ); }
		if ( $sent ) {
			store()->q( 'DELETE FROM kv WHERE k=?', array( 'notify:health:' . $name ) );
			$mins = (int) round( ( now() - (int) ( $since['v'] ?? now() ) ) / 60 );
			notify_owner( $upSubject, "All clear: back to normal after about $mins minutes." );
		}
		return;
	}
	if ( ! $since ) { store()->kvSet( 'health:' . $name . ':down_since', (string) now() ); return; }
	$limit = 60 * max( 1, (int) Config::get( 'notify_health_minutes', 15 ) );
	if ( ! $sent && now() - (int) $since['v'] >= $limit ) {
		notify_owner( $downSubject, $downBody . "\n\nDown since: " . date( 'Y-m-d H:i', (int) $since['v'] ) . ' UTC.', 'health:' . $name );
	}
}

// ---- 4. low stock / sold out -----------------------------------------------------
/** Check one product after its stock changed. Latch per product; resets when restocked above the line. */
function notify_stock( $productId ) {
	$p = store()->one( 'SELECT id, name, sku, stock FROM products WHERE id=?', array( (int) $productId ) );
	if ( ! $p ) { return; }
	$thr   = max( 0, (int) Config::get( 'notify_low_stock', 2 ) );
	$key   = 'notify:stock:' . (int) $p['id'];
	$latch = store()->kvGet( $key );
	$level = $latch ? (string) $latch['v'] : '';
	$stock = (int) $p['stock'];
	$label = $p['name'] . ( '' !== (string) $p['sku'] ? ' [' . $p['sku'] . ']' : '' );
	if ( $stock > $thr ) {
		if ( $latch ) { store()->q( 'DELETE FROM kv WHERE k=?', array( $key ) ); }
		return;
	}
	if ( 0 === $stock && 'out' !== $level ) {
		store()->kvSet( $key, 'out' );
		notify_owner( 'SOLD OUT — ' . $p['name'], "Sold out: $label\n\nStock is 0 (some may come back if an unpaid order expires).\nRestock it in the console's Catalog tab." );
	} elseif ( $stock > 0 && '' === $level ) {
		store()->kvSet( $key, 'low' );
		notify_owner( 'Low stock — ' . $p['name'] . " ($stock left)", "Low stock: $label\n\nOnly $stock left (alert level: $thr)." );
	}
}

// ---- 5. new order placed ---------------------------------------------------------
function notify_new_order( $orderId ) {
	if ( ! Config::get( 'notify_new_orders', true ) ) { return; }
	$o = store()->one( 'SELECT * FROM orders WHERE id=?', array( (int) $orderId ) );
	if ( ! $o ) { return; }
	$mins = (int) round( ( (int) $o['expires_at'] - (int) $o['created_at'] ) / 60 );
	$ship = '' !== trim( (string) $o['ship_addr'] ) ? 'to be shipped' : 'digital (no shipping)';
	notify_owner(
		'New order #' . (int) $o['id'] . ' — awaiting payment',
		'Order #' . (int) $o['id'] . " was just placed and is waiting for payment ($ship).\n\n"
		. "Items:\n" . notify_items_text( $o['id'] ) . "\n"
		. 'Amount : ' . $o['xmr_amount'] . ' XMR (' . strtoupper( (string) $o['currency'] ) . ' ' . number_format( (float) $o['price_fiat'], 2 ) . ")\n"
		. "Window : $mins minutes to send.\n\n"
		. "Nothing to do yet. You'll get a PAID email when it confirms; if it isn't paid it expires on its own."
	);
}

// ---- 6. buyer: payment received ------------------------------------------------
function notify_buyer_paid( $order ) {
	if ( ! Config::get( 'notify_buyers', true ) ) { return false; }
	$to = trim( (string) $order['ship_email'] );
	if ( '' === $to ) { return false; }
	$digital = '' === trim( (string) $order['ship_addr'] );
	$link = notify_order_link( $order );
	$body = "Thank you. Your payment for order #" . (int) $order['id'] . " has been received and confirmed on the Monero network.\n\n"
		. "Items:\n" . notify_items_text( $order['id'] ) . "\n"
		. 'Paid   : ' . pico_to_xmr( $order['received_pico'] ) . " XMR\n"
		. 'Total  : ' . strtoupper( (string) $order['currency'] ) . ' ' . number_format( (float) $order['price_fiat'], 2 ) . "\n"
		. ( '' !== $link ? "Order  : $link\n" : '' )
		. "\n" . ( $digital
			? "This order is digital: it will be delivered to this email address."
			: "We'll email you again when it ships, with tracking if the carrier provides it." )
		. ( '0' !== refund_due_pico( $order ) ? "\n\nYou sent " . pico_to_xmr( refund_due_pico( $order ) ) . " XMR more than the order total. The difference will be returned to the XMR return address you gave at checkout." : '' )
		. "\n\nQuestions? Just reply to this email.\n\n-- \n" . store_name() . "\n";
	return notify_send( $to, store_name() . ' — payment received for order #' . (int) $order['id'], $body );
}

// ---- 7. buyer: shipped, with tracking ------------------------------------------
function notify_carriers() {
	return array(
		''      => array( 'Carrier (optional)', '' ),
		'usps'  => array( 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' ),
		'ups'   => array( 'UPS', 'https://www.ups.com/track?tracknum=' ),
		'fedex' => array( 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr=' ),
		'dhl'   => array( 'DHL', 'https://www.dhl.com/en/express/tracking.html?AWB=' ),
		'other' => array( 'Other', '' ),
	);
}
function notify_tracking_url( $carrier, $number ) {
	$c = notify_carriers();
	$number = preg_replace( '/[^A-Za-z0-9]/', '', (string) $number );
	return ( '' !== $number && isset( $c[ $carrier ] ) && '' !== $c[ $carrier ][1] ) ? $c[ $carrier ][1] . rawurlencode( $number ) : '';
}
function notify_buyer_shipped( $order ) {
	if ( ! Config::get( 'notify_buyers', true ) ) { return false; }
	$to = trim( (string) $order['ship_email'] );
	if ( '' === $to ) { return false; }
	$c       = notify_carriers();
	$carrier = (string) ( $order['tracking_carrier'] ?? '' );
	$number  = trim( (string) ( $order['tracking_number'] ?? '' ) );
	$url     = notify_tracking_url( $carrier, $number );
	$link    = notify_order_link( $order );
	$digital = '' === trim( (string) $order['ship_addr'] );
	$body = ( $digital
			? "Your order #" . (int) $order['id'] . " has been delivered."
			: "Good news: your order #" . (int) $order['id'] . " has shipped." ) . "\n\n"
		. "Items:\n" . notify_items_text( $order['id'] ) . "\n"
		. ( '' !== $number ? 'Carrier : ' . ( $c[ $carrier ][0] ?? 'Other' ) . "\nTracking: $number\n" . ( '' !== $url ? "Track it: $url\n" : '' ) : '' )
		. ( '' !== $link ? "Order   : $link\n" : '' )
		. "\nQuestions? Just reply to this email.\n\n-- \n" . store_name() . "\n";
	return notify_send( $to, store_name() . ' — order #' . (int) $order['id'] . ( $digital ? ' delivered' : ' has shipped' ), $body );
}
