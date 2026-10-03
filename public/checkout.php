<?php
require_once __DIR__ . '/inc.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { redirect( 'index.php' ); }
csrf_check();

function fail( $msg ) {
	$back = isset( $_POST['items'] ) ? '<a class="btn ghost" href="cart.php">Back to your cart</a>' : '<a class="btn ghost" href="index.php">Back to catalog</a>';
	store_head( 'Checkout' ); echo '<div class="panel"><h1 style="font-family:var(--serif)">Hold on</h1><p>' . h( $msg ) . '</p><p>' . $back . '</p></div>'; store_foot(); exit;
}

// Either a cart (items[<product_id>]=<qty>) or the single-product Buy form
// (product_id + qty), which is just a one-line cart.
$fromCart = isset( $_POST['items'] );
$want     = $fromCart
	? cart_parse( $_POST['items'] )
	: cart_parse( array( (int) req( 'product_id', 0 ) => (int) req( 'qty', 1 ) ) );
if ( ! $want ) { fail( 'Your cart is empty.' ); }

// Look every line up server-side; the browser only ever supplies ids and quantities.
$lines = array();
foreach ( $want as $pid => $q ) {
	$p = find_buyable_product( $pid );
	if ( ! $p ) {
		fail( $fromCart
			? 'Something in your cart is no longer available. Go back to your cart to review it.'
			: 'That item is no longer available.' );
	}
	if ( ! $fromCart ) { $q = min( $q, (int) $p['stock'] ); } // the Buy form has always clamped
	if ( (int) $p['stock'] < $q || $q < 1 ) {
		fail( (int) $p['stock'] > 0
			? 'Only ' . (int) $p['stock'] . ' of “' . $p['name'] . '” left. Please lower the quantity.'
			: '“' . $p['name'] . '” just sold out.' );
	}
	$lines[] = array( 'p' => $p, 'qty' => $q );
}
// Never trust the browser's `required` attribute — validate server-side.
$name  = trim( (string) req( 'ship_name', '' ) );
$email = trim( (string) req( 'ship_email', '' ) );
$addr  = trim( (string) req( 'ship_addr', '' ) );
$phone = trim( (string) req( 'ship_phone', '' ) );

// collapse \r\n so stored addresses are consistent
$addr = str_replace( array( "\r\n", "\r" ), "\n", $addr );

if ( '' === $name )  { fail( 'Please give us a name for the parcel.' ); }
if ( '' === $email ) { fail( 'Please give us an email so we can reach you about this order.' ); }
if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) { fail( "That email address doesn't look right." ); }
if ( '' === $addr )  { fail( 'We need a shipping address to send your order.' ); }

// sane bounds — stops abuse without being fussy about format
if ( mb_strlen( $name )  > 120 ) { fail( 'That name is too long.' ); }
if ( mb_strlen( $email ) > 180 ) { fail( 'That email address is too long.' ); }
if ( mb_strlen( $addr )  > 600 ) { fail( 'That address is too long — please shorten it.' ); }
if ( mb_strlen( $phone ) > 40 )  { fail( 'That phone number is too long.' ); }

$refund = preg_replace( '/\s+/', '', (string) req( 'return_address', '' ) );
if ( '' === $refund ) { fail( 'Please give an XMR return address, so anything owed back to you can be sent.' ); }
if ( ! xmr()->refundAddressValid( $refund ) ) {
	fail( "That XMR return address isn't a valid Monero address for this network. Copy a standard (4…) or subaddress (8…) from your wallet; integrated addresses aren't accepted." );
}
if ( $refund === Config::primaryAddress()
	|| store()->one( 'SELECT 1 FROM orders WHERE subaddress=? LIMIT 1', array( $refund ) ) ) {
	fail( "That's one of this shop's own addresses. Give an address from your wallet." );
}

// a usable postal address needs more than one word
if ( mb_strlen( $addr ) < 10 ) { fail( 'That address looks incomplete — we need enough to post to.' ); }

// keep the legacy single-field column populated for anything that still reads it
$contact = $name . ' <' . $email . '>'
	. ( '' !== $phone ? ' · ' . $phone : '' )
	. "\n" . $addr;
$rate = price()->xmrRate();
if ( $rate <= 0 ) { fail( 'Live pricing is unavailable this moment — please try again shortly.' ); }

$tip = xmr()->tipHeight();
if ( null === $tip ) { fail( 'The payment network is unreachable right now — please try again in a minute.' ); }

$cur = strtolower( (string) Config::get( 'store_currency', 'usd' ) );
// Snapshot the effective (ratcheted) unit price at order-create time so a
// later spot move never changes what an already-placed order owes. Downstream
// (pay.php, notification email, admin) all read the snapshot and inherit.
$totalFiat = 0.0;
$units     = 0;
foreach ( $lines as $k => $l ) {
	$unit = product_effective_price( $l['p'] );
	$lines[ $k ]['unit'] = $unit;
	$lines[ $k ]['line'] = round( $unit * $l['qty'], 2 );
	$totalFiat += $lines[ $k ]['line'];
	$units     += $l['qty'];
}
$totalFiat = round( $totalFiat, 2 );
$xmrAmount = fiat_to_xmr( $totalFiat, $rate );
$expected  = xmr_to_pico( $xmrAmount );
if ( $expected === '0' ) { fail( 'Could not compute the Monero amount. Please retry.' ); }

// Allocated OUTSIDE the transaction below: nextSubMinor() opens its own, and
// SQLite/PDO can't nest them. A minor burned by a failed reserve is harmless.
$minor = store()->nextSubMinor();
$sub   = xmr()->subaddress( $minor );
if ( $sub === '' ) { fail( 'Could not derive a payment address. Check the store wallet configuration.' ); }

$token = bin2hex( random_bytes( 16 ) );
$ttl   = (int) Config::get( 'order_ttl_minutes', 90 ) * 60;
$first = $lines[0]['p'];
// Legacy single-line columns: first product, total units. order_items is the truth.
$legacyName = count( $lines ) > 1 ? $first['name'] . ' + ' . ( count( $lines ) - 1 ) . ' more' : $first['name'];

// Reserve every line's stock and write the order in ONE transaction: all lines
// or none, so a limited batch can't oversell. Released on expiry/cancel.
$db = store()->db;
$db->exec( 'BEGIN IMMEDIATE' );
try {
	foreach ( $lines as $l ) {
		$reserve = store()->q( 'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', array( $l['qty'], $l['p']['id'], $l['qty'] ) );
		if ( $reserve->rowCount() < 1 ) {
			$db->exec( 'ROLLBACK' );
			fail( 'Someone just took the last of “' . $l['p']['name'] . '” — stock ran out.' );
		}
	}
	store()->q(
		'INSERT INTO orders
		 (token,product_id,product_name,product_subhead,qty,currency,price_fiat,xmr_rate,xmr_amount,expected_pico,
		  sub_minor,subaddress,contact,ship_name,ship_email,ship_addr,ship_phone,return_address,
		  status,created_height,checkpoint_height,created_at,expires_at)
		 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "pending", ?,?,?,?)',
		array(
			$token, $first['id'], $legacyName, (string) ( $first['subhead'] ?? '' ), $units, $cur, $totalFiat, $rate, $xmrAmount, $expected,
			$minor, $sub, $contact, $name, $email, $addr, $phone, $refund,
			(int) $tip, max( 0, (int) $tip - 3 ), now(), now() + $ttl,
		)
	);
	$orderId = (int) $db->lastInsertId();
	foreach ( $lines as $l ) {
		store()->q(
			'INSERT INTO order_items (order_id,product_id,product_name,product_subhead,sku,qty,unit_fiat,line_fiat) VALUES (?,?,?,?,?,?,?,?)',
			array( $orderId, $l['p']['id'], $l['p']['name'], (string) ( $l['p']['subhead'] ?? '' ), (string) $l['p']['sku'], $l['qty'], $l['unit'], $l['line'] )
		);
	}
	$db->exec( 'COMMIT' );
} catch ( \Throwable $e ) {
	try { $db->exec( 'ROLLBACK' ); } catch ( \Throwable $ignored ) {} // raw BEGIN: PDO can't tell if one is open
	fail( 'Could not create the order. Please try again.' );
}

redirect( 'pay.php?t=' . $token . ( $fromCart ? '&cart=done' : '' ) );
