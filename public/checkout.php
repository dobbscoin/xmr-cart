<?php
require_once __DIR__ . '/inc.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { redirect( 'index.php' ); }
csrf_check();

function fail( $msg ) { store_head( 'Checkout' ); echo '<div class="panel"><h1 style="font-family:var(--serif)">Hold on</h1><p>' . h( $msg ) . '</p><p><a class="btn ghost" href="index.php">Back to catalog</a></p></div>'; store_foot(); exit; }

$p = find_product( (int) req( 'product_id', 0 ) );
if ( ! $p ) { fail( 'That item is no longer available.' ); }

$qty     = max( 1, min( (int) req( 'qty', 1 ), (int) $p['stock'] ) );
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

// a usable postal address needs more than one word
if ( mb_strlen( $addr ) < 10 ) { fail( 'That address looks incomplete — we need enough to post to.' ); }

// keep the legacy single-field column populated for anything that still reads it
$contact = $name . ' <' . $email . '>'
	. ( '' !== $phone ? ' · ' . $phone : '' )
	. "\n" . $addr;
if ( (int) $p['stock'] < $qty ) { fail( 'Not enough stock for that quantity.' ); }

$rate = price()->xmrRate();
if ( $rate <= 0 ) { fail( 'Live pricing is unavailable this moment — please try again shortly.' ); }

$tip = xmr()->tipHeight();
if ( null === $tip ) { fail( 'The payment network is unreachable right now — please try again in a minute.' ); }

$cur        = strtolower( (string) Config::get( 'store_currency', 'usd' ) );
// Snapshot the effective (ratcheted) unit price at order-create time so a
// later spot move never changes what an already-placed order owes. Downstream
// (pay.php, notification email, admin) all read orders.price_fiat and inherit.
$unitFiat   = product_effective_price( $p );
$totalFiat  = round( $unitFiat * $qty, 2 );
$xmrAmount  = fiat_to_xmr( $totalFiat, $rate );
$expected   = xmr_to_pico( $xmrAmount );
if ( $expected === '0' ) { fail( 'Could not compute the Monero amount. Please retry.' ); }

$minor = store()->nextSubMinor();
$sub   = xmr()->subaddress( $minor );
if ( $sub === '' ) { fail( 'Could not derive a payment address. Check the store wallet configuration.' ); }

// Reserve stock atomically so a limited batch can't oversell. Released on expiry/cancel.
$reserve = store()->q( 'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', array( $qty, $p['id'], $qty ) );
if ( $reserve->rowCount() < 1 ) { fail( 'Someone just took the last of these — stock ran out.' ); }

$token = bin2hex( random_bytes( 16 ) );
$ttl   = (int) Config::get( 'order_ttl_minutes', 90 ) * 60;

try {
	store()->q(
		'INSERT INTO orders
		 (token,product_id,product_name,product_subhead,qty,currency,price_fiat,xmr_rate,xmr_amount,expected_pico,
		  sub_minor,subaddress,contact,ship_name,ship_email,ship_addr,ship_phone,
		  status,created_height,checkpoint_height,created_at,expires_at)
		 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "pending", ?,?,?,?)',
		array(
			$token, $p['id'], $p['name'], (string) ( $p['subhead'] ?? '' ), $qty, $cur, $totalFiat, $rate, $xmrAmount, $expected,
			$minor, $sub, $contact, $name, $email, $addr, $phone,
			(int) $tip, max( 0, (int) $tip - 3 ), now(), now() + $ttl,
		)
	);
} catch ( \Throwable $e ) {
	store()->q( 'UPDATE products SET stock = stock + ? WHERE id = ?', array( $qty, $p['id'] ) ); // release on failure
	fail( 'Could not create the order. Please try again.' );
}

redirect( 'pay.php?t=' . $token );
