<?php
/**
 * Orders tab — the daily workflow. Recent orders, one action per row
 * (mark shipped / cancel / delete). Consumed by admin/index.php.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }
?>
<h2 style="margin-top:0">Orders</h2>
<table>
	<thead><tr><th>#</th><th>Product</th><th>Amount</th><th>Status</th><th>Received</th><th>Ship to</th><th>Tx</th><th></th></tr></thead>
	<tbody>
	<?php if ( ! $orders ) : ?>
		<tr><td colspan="8" class="muted">No orders yet. Open a batch and share the store link.</td></tr>
	<?php endif; ?>
	<?php foreach ( $orders as $o ) :
		$firstTx = strtok( (string) $o['txids'], ',' ); ?>
		<tr>
			<td class="mono muted" title="<?php echo h( $o['token'] ); ?>"><?php echo (int) $o['id']; ?></td>
			<td><?php foreach ( order_items( $o['id'] ) as $it ) : ?><div><?php echo h( $it['product_name'] ); ?><?php echo (int) $it['qty'] > 1 ? ' ×' . (int) $it['qty'] : ''; ?></div><?php endforeach; ?></td>
			<td class="mono"><?php echo h( $o['xmr_amount'] ); ?> XMR<div class="muted" style="font-size:11px"><?php echo h( number_format( (float) $o['price_fiat'], 2 ) . ' ' . strtoupper( $o['currency'] ) ); ?></div></td>
			<td><?php echo pill( $o['status'] ); ?><?php if ( 'confirming' === $o['status'] ) echo '<div class="muted mono" style="font-size:11px">' . (int) $o['confirmations'] . ' conf</div>'; ?></td>
			<td class="mono"><?php echo h( pico_to_xmr( $o['received_pico'] ) ); ?></td>
			<td style="max-width:260px">
				<?php
				$sn = trim( (string) ( $o['ship_name']  ?? '' ) );
				$se = trim( (string) ( $o['ship_email'] ?? '' ) );
				$sa = trim( (string) ( $o['ship_addr']  ?? '' ) );
				$sp = trim( (string) ( $o['ship_phone'] ?? '' ) );
				if ( '' === $sn && '' === $sa ) : // legacy order, single blob ?>
					<div class="muted" style="font-size:12px;white-space:pre-wrap;max-height:3.4em;overflow:hidden" title="<?php echo h( $o['contact'] ); ?>"><?php echo h( $o['contact'] ); ?></div>
				<?php else : ?>
					<div style="font-size:12.5px;color:#eceef1"><?php echo h( $sn ); ?></div>
					<?php if ( '' !== $se ) : ?><div class="mono" style="font-size:11px"><a href="mailto:<?php echo h( $se ); ?>" style="color:#e0854f"><?php echo h( $se ); ?></a></div><?php endif; ?>
					<?php if ( '' !== $sp ) : ?><div class="muted mono" style="font-size:11px"><?php echo h( $sp ); ?></div><?php endif; ?>
					<div class="muted" style="font-size:11.5px;white-space:pre-wrap;margin-top:3px"><?php echo h( $sa ); ?></div>
				<?php endif; ?>
				<?php echo refund_block_html( $o ); ?>
			</td>
			<td class="mono" style="font-size:12px"><?php echo $firstTx ? explorer_tx( $firstTx, Config::get( 'network', 'mainnet' ) ) : '—'; ?></td>
			<td style="white-space:nowrap">
				<?php if ( 'paid' === $o['status'] ) : ?>
					<form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="order_ship"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>"><button class="cbtn go">Mark shipped</button></form>
				<?php elseif ( in_array( $o['status'], array( 'pending', 'confirming' ), true ) ) : ?>
					<?php if ( ! $real ) : ?><form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="sim_pay"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>"><button class="cbtn">Simulate pay</button></form> <?php endif; ?>
					<form class="inline" method="post" action="actions.php" onsubmit="return confirm('Cancel this order?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="order_cancel"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>"><button class="cbtn warn">Cancel</button></form>
				<?php endif; ?>
				<?php
				$delMsg = 'paid' === $o['status']
					? "Delete PAID order #{$o['id']} permanently?\n\nThis removes the order and its payment record. Stock is NOT restored. Only use for test/junk orders."
					: ( 'shipped' === $o['status']
						? "Delete SHIPPED order #{$o['id']} permanently?\n\nThis destroys the shipment record. Are you sure?"
						: "Delete order #{$o['id']} permanently? This cannot be undone." );
				?>
				<form class="inline" method="post" action="actions.php" onsubmit="return confirm(<?php echo htmlspecialchars( json_encode( $delMsg ), ENT_QUOTES ); ?>)"><?php echo csrf_field(); ?><input type="hidden" name="action" value="order_delete"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>"><button class="cbtn warn" title="Remove this order from the database">Delete</button></form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
