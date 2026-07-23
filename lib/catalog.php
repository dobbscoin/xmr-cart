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
