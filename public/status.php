<?php
require_once __DIR__ . '/inc.php';

// Read-only: reflects what the worker has already settled. No node calls here, so
// buyer refreshes never touch the Monero node.
$order = store()->one( 'SELECT status, received_pico, confirmations, expected_pico, expires_at FROM orders WHERE token=?', array( (string) req( 't', '' ) ) );
// (the return address is deliberately not selected: this endpoint answers to the token alone)
if ( ! $order ) { json_out( array( 'error' => 'not_found' ), 404 ); }

json_out( array(
	'status'        => $order['status'],
	'confirmations' => (int) $order['confirmations'],
	'received_xmr'  => pico_to_xmr( $order['received_pico'] ),
	'expected_xmr'  => pico_to_xmr( $order['expected_pico'] ),
	'expires_in'    => max( 0, (int) $order['expires_at'] - time() ),
	'refund_xmr'    => pico_to_xmr( refund_due_pico( $order ) ),
) );
