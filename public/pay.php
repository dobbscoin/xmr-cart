<?php
require_once __DIR__ . '/inc.php';

$token = (string) req( 't', '' );
$order = store()->one( 'SELECT * FROM orders WHERE token=?', array( $token ) );
if ( ! $order ) { store_head( 'Order' ); echo '<div class="panel">Order not found. <a href="index.php">Back to catalog.</a></div>'; store_foot(); exit; }

// Demo-only shortcut: inject a confirmed payment so the flow completes without a chain.
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && req( 'action' ) === 'demo_pay' && ! xmr()->isReal() ) {
	csrf_check();
	$tip    = (int) xmr()->tipHeight();
	$minc   = (int) Config::get( 'min_confirmations', 10 );
	store()->q(
		'INSERT OR IGNORE INTO payments(order_id,out_key,txid,amount_atomic,block_height,commitment_ok,locked,first_seen)
		 VALUES(?,?,?,?,?,1,0,?)',
		array( $order['id'], 'demo:' . $order['id'], 'demo' . substr( hash( 'sha256', $order['token'] ), 0, 60 ),
			$order['expected_pico'], max( 0, $tip - $minc ), now() )
	);
	require_once __DIR__ . '/../lib/settle.php';
	settle_order( store(), xmr(), $order, $tip );
	redirect( 'pay.php?t=' . $token );
}

$cur     = strtoupper( $order['currency'] );
$items   = order_items( $order['id'] );
$minc    = (int) Config::get( 'min_confirmations', 10 );
$uri     = 'monero:' . $order['subaddress'] . '?tx_amount=' . $order['xmr_amount'] . '&tx_description=' . rawurlencode( store_name() . ' order ' . substr( $token, 0, 8 ) );
$demo    = ! xmr()->isReal();

// A dead order must never show a payable address. Coin sent to an expired order's
// subaddress is NOT credited — settle_order() returns early on a terminal status —
// so the buyer would simply lose it. See NOTES.md.
$dead = in_array( $order['status'], array( 'expired', 'cancelled' ), true );

csrf_token(); // set the cookie before any output (demo form)
store_head( 'Complete payment' );

if ( $dead ) {
	$word = 'cancelled' === $order['status'] ? 'cancelled' : 'expired';
	echo '<div class="panel">';
	echo '<h1 style="font-family:var(--serif);font-size:26px;margin:0 0 10px">This quote has ' . h( $word ) . '</h1>';
	echo '<p><strong>Do not send payment to this order.</strong> The rate was locked for a limited '
		. 'window and that window has closed. Anything sent to the old address now will not be credited.</p>';
	echo '<p>The items have been returned to stock. Start a new order and you will be quoted a fresh '
		. 'rate and a fresh address.</p>';
	$due = refund_due_pico( $order );
	if ( '0' !== $due ) {
		echo '<div class="notice" style="margin-top:12px">We received <strong class="mono">' . h( pico_to_xmr( $due ) )
			. ' XMR</strong> for this order. It will be sent back to the return address you gave at checkout.</div>';
	}
	echo '<p style="margin-top:18px"><a class="btn" href="index.php">Back to the catalog</a></p>';
	echo '</div>';
	store_foot();
	exit;
}
?>
<div class="cert" data-token="<?php echo h( $token ); ?>" data-minconf="<?php echo $minc; ?>" data-status="<?php echo h( $order['status'] ); ?>">
	<div class="top" id="meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Payment progress">
		<div class="fill" id="meterfill"></div>
	</div>
	<div class="meter-cap"><span id="meterlabel">Waiting for payment…</span><span class="mono" id="metercount"></span></div>
	<div class="inner">
		<h1><?php echo h( order_headline( $items ) ); ?></h1>
		<?php if ( 1 === count( $items ) ) : $osh = trim( (string) $items[0]['product_subhead'] ); if ( '' !== $osh ) : ?><p class="subhead"><?php echo h( $osh ); ?></p><?php endif; endif; ?>
		<p class="hint" style="margin-top:0">Order <span class="mono"><?php echo h( substr( $token, 0, 8 ) ); ?></span> · status <span id="statuspill"><?php echo pill( $order['status'] ); ?></span></p>

		<?php if ( $demo ) : ?>
		<div class="notice">Demo mode — no live Monero node is attached. The address below is a placeholder. Use “Simulate payment” to watch the order settle.</div>
		<?php endif; ?>

		<?php if ( count( $items ) > 1 ) : ?>
		<div class="order-items">
			<?php foreach ( $items as $it ) : $ish = trim( (string) $it['product_subhead'] ); ?>
			<div class="rowline"><span><?php echo h( $it['product_name'] ); ?><?php echo (int) $it['qty'] > 1 ? ' ×' . (int) $it['qty'] : ''; ?>
				<?php if ( '' !== $ish ) : ?><span class="item-sub"><?php echo h( $ish ); ?></span><?php endif; ?></span>
				<span class="v"><?php echo h( number_format( (float) $it['line_fiat'], 2 ) . ' ' . $cur ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="rowline"><span>Send exactly</span><span class="big-value"><?php echo h( $order['xmr_amount'] ); ?> XMR</span></div>
		<div class="rowline"><span>Order total</span><span class="v"><?php echo h( number_format( (float) $order['price_fiat'], 2 ) . ' ' . $cur ); ?></span></div>
		<div class="rowline"><span>Locked rate</span><span class="v">1 XMR = <?php echo h( number_format( (float) $order['xmr_rate'], 2 ) . ' ' . $cur ); ?></span></div>
		<div class="rowline"><span>Confirmations needed</span><span class="v"><?php echo $minc; ?></span></div>
		<div class="rowline"><span>Quote expires</span><span class="v" id="countdown" data-exp="<?php echo (int) $order['expires_at']; ?>">—</span></div>

		<div id="payblock">
			<?php $xnote = trim( site_copy( 'exchange_note' ) ); ?>
			<?php if ( '' !== $xnote ) : ?>
				<div class="notice warn" style="margin-top:16px">
					<strong>Do not pay from an exchange wallet.</strong>
					<?php echo nl2br( h( $xnote ) ); ?>
				</div>
			<?php endif; ?>

			<div class="qr" id="qr" data-uri="<?php echo h( $uri ); ?>"></div>

			<p style="margin:6px 0 4px" class="hint">Payment address (subaddress unique to this order)</p>
			<div><span class="addr" id="addr"><?php echo h( $order['subaddress'] ); ?></span>
				<button class="copy" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('addr').textContent)">copy</button></div>
		</div>

		<div id="lapsed" class="notice warn" style="display:none;margin-top:16px">
			<strong>This quote has expired — do not send payment.</strong>
			The locked rate has lapsed and anything sent to this address now will not be credited.
			<a href="index.php">Start a new order</a> for a fresh quote and address.
		</div>

		<div id="done" style="display:none;margin-top:18px" class="notice" >Payment confirmed — thank you. Your order will be shipped to the address you provided.</div>
		<?php $due = refund_due_pico( $order ); ?>
		<div id="overpaid" class="notice" style="<?php echo '0' === $due ? 'display:none;' : ''; ?>margin-top:12px">You sent <strong class="mono" id="overpaid-amt"><?php echo h( pico_to_xmr( $due ) ); ?></strong> XMR more than this order. The difference will be sent back to the return address you gave at checkout.</div>

		<?php if ( $demo ) : ?>
		<form method="post" style="margin-top:20px">
			<?php echo csrf_field(); ?>
			<input type="hidden" name="action" value="demo_pay">
			<button class="btn ghost" type="submit">Simulate payment (demo)</button>
		</form>
		<?php endif; ?>
	</div>
</div>
<script src="assets/qrcode.js"></script>
<script src="assets/shop.js"></script>
<?php store_foot();
