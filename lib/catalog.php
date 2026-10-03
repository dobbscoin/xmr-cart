<?php
/** #anchor for an item card: its SKU slugged ("AG-XMR-1" -> "ag-xmr-1"), else "item-<id>". */
function item_anchor( $p ) {
	$a = trim( preg_replace( '~[^a-z0-9]+~', '-', strtolower( (string) ( $p['sku'] ?? '' ) ) ), '-' );
	return '' !== $a ? $a : 'item-' . (int) $p['id'];
}
/** Catalog + status helpers shared by the storefront and the admin console. */

/** Every batch currently on sale, in display order. */
function live_batches() {
	return store()->all( "SELECT * FROM batches WHERE status='live' ORDER BY sort ASC, id ASC" );
}

/** Kept for compatibility: the first live batch, or null. */
function active_batch() {
	$b = live_batches();
	return $b ? $b[0] : null;
}

/** Products in one batch. */
function batch_products( $batch_id ) {
	return store()->all(
		'SELECT * FROM products WHERE batch_id=? AND active=1 ORDER BY sort ASC, id ASC',
		array( (int) $batch_id )
	);
}

/** Every product on sale across all live batches. */
function live_products() {
	$out = array();
	foreach ( live_batches() as $b ) {
		foreach ( batch_products( $b['id'] ) as $p ) { $out[] = $p; }
	}
	return $out;
}
function find_product( $id ) {
	return store()->one( 'SELECT * FROM products WHERE id=? AND active=1', array( (int) $id ) );
}
/**
 * A product that can be ordered right now: active AND in a live batch.
 * find_product() only checks active=1, which is fine for showing a page but
 * would let a direct POST check out a closed batch.
 */
function find_buyable_product( $id ) {
	return store()->one(
		"SELECT p.* FROM products p JOIN batches b ON b.id = p.batch_id
		  WHERE p.id=? AND p.active=1 AND b.status='live'",
		array( (int) $id )
	);
}

/** Most lines one order may hold. */
const CART_MAX_LINES = 25;

/**
 * Parse a cart from the browser: array( product_id => qty ) or "id:qty,id:qty".
 * Returns a clean array( id => qty ), ids > 0, qty clamped to 1..99, at most
 * CART_MAX_LINES lines. Says nothing about stock or availability.
 */
function cart_parse( $raw ) {
	if ( is_string( $raw ) ) {
		$pairs = array();
		foreach ( explode( ',', $raw ) as $bit ) {
			$kv = explode( ':', $bit, 2 );
			if ( 2 === count( $kv ) ) { $pairs[ $kv[0] ] = $kv[1]; }
		}
		$raw = $pairs;
	}
	$out = array();
	if ( ! is_array( $raw ) ) { return $out; }
	foreach ( $raw as $id => $qty ) {
		$id = (int) $id;
		if ( $id <= 0 || is_array( $qty ) ) { continue; }
		$out[ $id ] = max( 1, min( 99, (int) $qty ) );
		if ( count( $out ) >= CART_MAX_LINES ) { break; }
	}
	return $out;
}

/** Line items of an order, in the order they were added. */
function order_items( $orderId ) {
	return store()->all( 'SELECT * FROM order_items WHERE order_id=? ORDER BY id ASC', array( (int) $orderId ) );
}

/** One-line description of an order: "Name ×2", or "3 items" for a multi-line order. */
function order_headline( array $items ) {
	if ( 1 === count( $items ) ) {
		$i = $items[0];
		return $i['product_name'] . ( (int) $i['qty'] > 1 ? ' ×' . (int) $i['qty'] : '' );
	}
	$units = 0;
	foreach ( $items as $i ) { $units += (int) $i['qty']; }
	return $units . ' items';
}

/**
 * Move an open order to a closed status (expired / cancelled) and give every
 * line's stock back, in one transaction. The status guard means two callers
 * racing (worker expiry vs admin cancel) release the stock exactly once.
 * Returns true if this call closed the order.
 */
function order_close_and_release( $orderId, $newStatus ) {
	$db = store()->db;
	$db->exec( 'BEGIN IMMEDIATE' );
	try {
		$st = store()->q(
			"UPDATE orders SET status=? WHERE id=? AND status IN ('pending','confirming')",
			array( $newStatus, (int) $orderId )
		);
		$closed = $st->rowCount() === 1;
		if ( $closed ) {
			foreach ( order_items( $orderId ) as $i ) {
				store()->q( 'UPDATE products SET stock = stock + ? WHERE id = ?', array( (int) $i['qty'], (int) $i['product_id'] ) );
			}
		}
		$db->exec( 'COMMIT' );
		return $closed;
	} catch ( \Throwable $e ) {
		$db->exec( 'ROLLBACK' );
		throw $e;
	}
}
function product_img_url( $p ) {
	return $p['image'] !== '' ? h( Config::get( 'uploads_url', 'assets/products' ) . '/' . $p['image'] ) : '';
}
/**
 * Status pill. $status picks the colour class; $label overrides the text.
 * (Batches reuse the order colours — green/grey/amber — but must show their
 * own words: Live / Closed / Draft, not Paid / Expired / Pending.)
 */
function pill( $status, $label = null ) {
	$text = ( null === $label ) ? ucfirst( $status ) : $label;
	return '<span class="pill ' . h( $status ) . '">' . h( $text ) . '</span>';
}

/** Pill for a batch: correct colour, correct word. */
function batch_pill( $status ) {
	$map = array(
		'live'   => array( 'paid',    'Live' ),     // green
		'closed' => array( 'expired', 'Closed' ),   // grey
		'draft'  => array( 'pending', 'Draft' ),    // amber
	);
	$m = isset( $map[ $status ] ) ? $map[ $status ] : array( 'pending', ucfirst( $status ) );
	return pill( $m[0], $m[1] );
}

/** Secondary images for a product (main image lives on products.image). */
function product_gallery( $id ) {
	return store()->all( 'SELECT * FROM product_images WHERE product_id=? ORDER BY sort ASC, id ASC', array( (int) $id ) );
}
/** URL for a stored image filename. */
function img_url( $file ) {
	return $file !== '' ? h( Config::get( 'uploads_url', 'assets/products' ) . '/' . $file ) : '';
}

/**
 * Seed the catalog with one "Demo" batch + three placeholder products so a
 * first-time operator lands on a browsable storefront instead of empty state.
 * Called from the first-run wizard (opt-in checkbox) and the Catalog tab's
 * "Seed demo products" button.
 *
 * Idempotency: refuses if a batch named exactly "Demo" already exists.
 * That lets an operator with real inventory click the button and get seeded
 * without duplication, and lets them click it more than once safely.
 *
 * Returns:
 *   true       — inserted 1 batch + 3 products
 *   'exists'   — a "Demo" batch already exists, nothing changed
 */
function seed_demo_products() {
	$s = store();
	$dupe = $s->one( "SELECT id FROM batches WHERE name='Demo' LIMIT 1" );
	if ( $dupe ) { return 'exists'; }
	$now = time();
	$s->q(
		'INSERT INTO batches(name, status, created_at, sort, description) VALUES(?,?,?,?,?)',
		array(
			'Demo',
			'live',
			$now,
			0,
			'Example batch. Delete or hide it from the Catalog tab when you\'re ready to sell for real.',
		)
	);
	$batch_id = (int) $s->db->lastInsertId();
	$rows = array(
		array( 'DEMO — Sticker', 'Peel-and-stick vinyl.',        'One low-friction thing to test the buyer flow with.',                       3.00,   500, 0 ),
		array( 'DEMO — Hat',     'Six-panel, embroidered logo.', 'Mid-range item to try a bigger checkout amount.',                          25.00,  12,  1 ),
		array( 'DEMO — Coffee',  'Single-origin, 12oz bag.',     'Consumable good — good for testing stock decrement + fulfillment.',        18.00,  24,  2 ),
	);
	foreach ( $rows as $r ) {
		$s->q(
			'INSERT INTO products(batch_id, sku, name, subhead, description, image, price_fiat, stock, active, sort, created_at) VALUES(?,?,?,?,?,?,?,?,1,?,?)',
			array( $batch_id, '', $r[0], $r[1], $r[2], '', $r[3], $r[4], $r[5], $now )
		);
	}
	return true;
}
