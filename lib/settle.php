<?php
/**
 * Settlement — the money decision, kept in one place and driven by the worker.
 *
 * Per open order: scan the new block range for outputs to the order's subaddress,
 * persist each committed output once (dedup by one-time out_key), then recompute
 * the verdict over ALL persisted outputs with confirmations refreshed against the
 * current tip. Only cryptographically committed amounts are ever credited. This
 * mirrors XmrPay_Util::summarize_payments; kept local so mock and real modes take
 * the exact same path.
 */

/** Sum committed outputs and return the settlement verdict. */
function summarize_rows( array $rows, $expected_pico, $tol_pico, $min_conf ) {
	$min_conf  = max( 0, (int) $min_conf );
	$confirmed = gmp_init( 0 );
	$pending   = gmp_init( 0 );
	$locked    = gmp_init( 0 );
	$minConfs  = null;
	$pendConfs = null;
	$txids     = array();

	foreach ( $rows as $r ) {
		if ( empty( $r['commitment_ok'] ) ) { continue; }         // never credit an uncommitted amount
		$amt = gmp_init( (string) $r['amount_atomic'], 10 );
		if ( ! empty( $r['txid'] ) ) { $txids[ (string) $r['txid'] ] = true; }
		if ( ! empty( $r['locked'] ) ) { $locked = gmp_add( $locked, $amt ); continue; }
		$confs = isset( $r['confirmations'] ) ? (int) $r['confirmations'] : 0;
		if ( $confs >= $min_conf ) {
			$confirmed = gmp_add( $confirmed, $amt );
			$minConfs  = ( null === $minConfs ) ? $confs : min( $minConfs, $confs );
		} else {
			$pending = gmp_add( $pending, $amt );
			// track depth of not-yet-mature outputs too, so the UI can show progress
			$pendConfs = ( null === $pendConfs ) ? $confs : min( $pendConfs, $confs );
		}
	}

	$exp = gmp_init( (string) $expected_pico, 10 );
	$tol = gmp_init( (string) $tol_pico, 10 );
	if ( gmp_cmp( $tol, 0 ) < 0 ) { $tol = gmp_init( 0 ); }
	$maxTol = gmp_cmp( $exp, 0 ) <= 0 ? gmp_init( 0 ) : gmp_sub( $exp, gmp_init( 1 ) );
	if ( gmp_cmp( $tol, $maxTol ) > 0 ) { $tol = $maxTol; }
	$threshold = gmp_sub( $exp, $tol );

	$out = array(
		'confirmed_pico' => gmp_strval( $confirmed ),
		'pending_pico'   => gmp_strval( $pending ),
		'locked_pico'    => gmp_strval( $locked ),
		// what the buyer/console should SEE: matured depth if any, else the depth of
		// the maturing output. Display only — settlement still uses confirmed_pico.
		'confirmations'  => ( null !== $minConfs ) ? (int) $minConfs : ( ( null !== $pendConfs ) ? (int) $pendConfs : 0 ),
		'seen_pico'      => gmp_strval( gmp_add( $confirmed, $pending ) ),
		'txids'          => array_keys( $txids ),
	);
	if ( gmp_cmp( $confirmed, $threshold ) >= 0 && gmp_cmp( $exp, 0 ) > 0 ) {
		$out['status'] = 'paid';
	} elseif ( gmp_cmp( gmp_add( $confirmed, $pending ), $threshold ) >= 0 ) {
		$out['status'] = 'mempool';   // enough seen, waiting on confirmations
	} elseif ( gmp_cmp( $confirmed, 0 ) > 0 || gmp_cmp( $pending, 0 ) > 0 ) {
		$out['status'] = 'partial';
	} else {
		$out['status'] = 'pending';
	}
	return $out;
}

/**
 * Advance one order. Returns the (possibly unchanged) status string.
 * Pass $tip to reuse one tip read across many orders.
 */
function settle_order( Store $store, Xmr $xmr, array $order, $tip = null ) {
	$status = $order['status'];
	if ( in_array( $status, array( 'paid', 'shipped', 'expired', 'cancelled' ), true ) ) {
		return $status;
	}

	if ( null === $tip ) { $tip = $xmr->tipHeight(); }
	if ( null === $tip ) { return $status; }  // node unreachable — try again next tick

	$minConf = (int) Config::get( 'min_confirmations', 10 );
	$tolPico = (string) Config::get( 'tolerance_atomic', 0 );

	// 1) scan the new range for this order's subaddress, persist committed outputs.
	$from = max( 0, (int) $order['checkpoint_height'] + 1 );
	if ( (int) $tip >= $from && strpos( (string) $order['subaddress'], 'DEMO-' ) !== 0 ) {
		$res = $xmr->scanAll( $order['subaddress'], $from, (int) $tip );
		foreach ( ( isset( $res['matches'] ) ? $res['matches'] : array() ) as $m ) {
			$store->q(
				'INSERT OR IGNORE INTO payments(order_id,out_key,txid,amount_atomic,block_height,commitment_ok,locked,first_seen)
				 VALUES(?,?,?,?,?,?,?,?)',
				array(
					$order['id'],
					(string) ( isset( $m['out_key'] ) ? $m['out_key'] : ( $m['txid'] . ':' . $m['output_index'] ) ),
					(string) ( isset( $m['txid'] ) ? $m['txid'] : '' ),
					(string) $m['amount_atomic'],
					(int) ( isset( $m['block_height'] ) ? $m['block_height'] : 0 ),
					! empty( $m['commitment_ok'] ) ? 1 : 0,
					! empty( $m['locked'] ) ? 1 : 0,
					now(),
				)
			);
		}
		$scannedTo = isset( $res['scanned_to'] ) ? (int) $res['scanned_to'] : $from - 1;
		if ( $scannedTo >= (int) $order['checkpoint_height'] ) {
			$store->q( 'UPDATE orders SET checkpoint_height=? WHERE id=?', array( $scannedTo, $order['id'] ) );
		}
	}

	// 2) recompute the verdict over ALL persisted outputs, confirmations vs current tip.
	$rows = array();
	foreach ( $store->all( 'SELECT * FROM payments WHERE order_id=?', array( $order['id'] ) ) as $p ) {
		$rows[] = array(
			'txid'          => $p['txid'],
			'amount_atomic' => $p['amount_atomic'],
			'commitment_ok' => (int) $p['commitment_ok'],
			'locked'        => (int) $p['locked'],
			'confirmations' => max( 0, (int) $tip - (int) $p['block_height'] ),
		);
	}
	$v = summarize_rows( $rows, $order['expected_pico'], $tolPico, $minConf );

	// 3) map to an order status and persist.
	$new = $status;
	if ( 'paid' === $v['status'] ) {
		$new = 'paid';
	} elseif ( in_array( $v['status'], array( 'mempool', 'partial' ), true ) ) {
		$new = 'confirming';
	} else {
		// nothing credited yet — expire an untouched order past its quote window.
		if ( now() > (int) $order['expires_at'] ) {
			$new = 'expired';
			$store->q( 'UPDATE products SET stock = stock + ? WHERE id = ?', array( (int) $order['qty'], (int) $order['product_id'] ) ); // release reservation
		}
	}

	$paidAt = ( 'paid' === $new && 'paid' !== $status ) ? now() : ( isset( $order['paid_at'] ) ? $order['paid_at'] : null );
	$store->q(
		'UPDATE orders SET status=?, received_pico=?, confirmations=?, txids=?, paid_at=? WHERE id=?',
		// received_pico is a DISPLAY figure: what has actually landed on-chain (confirmed +
		// still-maturing). The paid/unpaid decision above uses confirmed_pico only, so an
		// immature output can never settle an order — it just stops the UI reading "0".
		array( $new, $v['seen_pico'], (int) $v['confirmations'], implode( ',', $v['txids'] ), $paidAt, $order['id'] )
	);
	// Fired exactly once, on the pending->paid edge ($paidAt is only set on that tick).
	if ( 'paid' === $new && 'paid' !== $status ) {
		$fresh = $store->one( 'SELECT * FROM orders WHERE id=?', array( $order['id'] ) );
		if ( $fresh ) { notify_order_paid( $fresh ); }
	}

	return $new;
}
