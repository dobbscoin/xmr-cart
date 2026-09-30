<?php
/**
 * Cart. The cart itself lives in the buyer's browser (localStorage, see
 * assets/cart.js); this page gets it as ?c=<id>:<qty>,<id>:<qty> and looks every
 * line up server-side for the current name, price and stock. The browser only
 * ever supplies ids and quantities. No session, no server-side cart.
 */
require_once __DIR__ . '/inc.php';

$loaded = isset( $_GET['c'] );
$want   = cart_parse( (string) req( 'c', '' ) );
$cur    = strtoupper( (string) Config::get( 'store_currency', 'usd' ) );
$rate   = price()->xmrRate();

$lines   = array();
$notes   = array();
$total   = 0.0;
foreach ( $want as $pid => $q ) {
	$p = find_buyable_product( $pid );
	if ( ! $p ) { $notes[] = 'An item in your cart is no longer available and was removed.'; continue; }
	$stock = (int) $p['stock'];
	if ( $stock <= 0 ) { $notes[] = '“' . $p['name'] . '” sold out and was removed.'; continue; }
	if ( $q > $stock ) { $q = $stock; $notes[] = 'Only ' . $stock . ' of “' . $p['name'] . '” left, so your quantity was lowered.'; }
	$unit     = product_effective_price( $p );
	$line     = round( $unit * $q, 2 );
	$total   += $line;
	$lines[]  = array( 'p' => $p, 'qty' => $q, 'unit' => $unit, 'line' => $line );
}
$total = round( $total, 2 );
$clean = implode( ',', array_map( function ( $l ) { return (int) $l['p']['id'] . ':' . (int) $l['qty']; }, $lines ) );

csrf_token(); // set the cookie before any output, or the form's token has nothing behind it
store_head( 'Cart' );
?>
<p><a href="index.php" style="font-size:13px">← Keep shopping</a></p>
<div id="cartpage" data-load="<?php echo $loaded ? '0' : '1'; ?>" data-cart="<?php echo h( $clean ); ?>">
	<h1 class="lede">Your cart</h1>

	<?php foreach ( array_unique( $notes ) as $n ) : ?><div class="notice"><?php echo h( $n ); ?></div><?php endforeach; ?>

	<?php if ( ! $lines ) : ?>
		<p class="sub" id="cart-empty"<?php echo $loaded ? '' : ' hidden'; ?>>Your cart is empty. <a href="index.php">Browse the catalog.</a></p>
		<noscript><p class="sub">The cart needs JavaScript. You can still buy any item from its own page.</p></noscript>
	<?php else : ?>
		<div class="cart-lines">
			<?php foreach ( $lines as $l ) : $p = $l['p']; $ph = trim( (string) ( $p['subhead'] ?? '' ) ); ?>
			<div class="cart-line">
				<div class="cl-name">
					<a href="product.php?id=<?php echo (int) $p['id']; ?>"><?php echo h( $p['name'] ); ?></a>
					<?php if ( '' !== $ph ) : ?><div class="subhead"><?php echo h( $ph ); ?></div><?php endif; ?>
					<div class="hint"><?php echo h( number_format( $l['unit'], 2 ) . ' ' . $cur ); ?> each</div>
				</div>
				<div class="cl-qty">
					<label class="sr" for="q<?php echo (int) $p['id']; ?>">Quantity</label>
					<select id="q<?php echo (int) $p['id']; ?>" data-id="<?php echo (int) $p['id']; ?>">
						<?php for ( $i = 1; $i <= max( min( 10, (int) $p['stock'] ), $l['qty'] ); $i++ ) echo '<option' . ( $i === $l['qty'] ? ' selected' : '' ) . '>' . $i . '</option>'; ?>
					</select>
					<button type="button" class="linkbtn" data-remove="<?php echo (int) $p['id']; ?>">Remove</button>
				</div>
				<div class="cl-total mono"><?php echo h( number_format( $l['line'], 2 ) . ' ' . $cur ); ?></div>
			</div>
			<?php endforeach; ?>
		</div>

		<div class="rowline"><span>Subtotal</span><span class="v"><?php echo h( number_format( $total, 2 ) . ' ' . $cur ); ?></span></div>
		<div class="rowline"><span>Approx. in Monero<?php echo $rate > 0 ? '' : ' (rate unavailable)'; ?></span>
			<span class="v"><?php echo $rate > 0 ? '≈ ' . h( fiat_to_xmr_display( $total, $rate ) ) . ' XMR' : '—'; ?></span></div>
		<p class="hint" style="margin-top:8px">One order, one payment, one parcel. The exact XMR total is locked when you place the order and held for the quote window.</p>

		<form method="post" action="checkout.php" class="panel" style="margin-top:22px">
			<?php echo csrf_field(); ?>
			<?php foreach ( $lines as $l ) : ?>
				<input type="hidden" name="items[<?php echo (int) $l['p']['id']; ?>]" value="<?php echo (int) $l['qty']; ?>">
			<?php endforeach; ?>
			<?php echo ship_fields_html(); ?>
			<button class="btn block" type="submit"<?php echo $rate > 0 ? '' : ' disabled'; ?>>
				<?php echo $rate > 0 ? 'Place order & get payment address' : 'Pricing temporarily unavailable'; ?>
			</button>
		</form>
	<?php endif; ?>
</div>
<?php store_foot();
