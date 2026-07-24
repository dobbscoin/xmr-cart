<?php
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
