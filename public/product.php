<?php
require_once __DIR__ . '/inc.php';

$p = find_product( (int) req( 'id', 0 ) );
if ( ! $p ) { store_head( 'Not found' ); echo '<div class="panel">That item isn\'t available. <a href="index.php">Back to the catalog.</a></div>'; store_foot(); exit; }

$cur  = strtoupper( (string) Config::get( 'store_currency', 'usd' ) );
$rate = price()->xmrRate();
$out  = (int) $p['stock'] <= 0;
$img  = product_img_url( $p );
$eff  = product_effective_price( $p );
$estXmr = $rate > 0 ? fiat_to_xmr_display( $eff, $rate ) : null;
$disp = price_display_mode();
$gallery = product_gallery( $p['id'] );

store_head( $p['name'] );
?>
<p><a href="index.php" style="font-size:13px">← Back to catalog</a></p>
<div class="detail">
	<div>
		<div class="ph" id="mainph"><?php echo $img ? '<img id="mainimg" src="' . $img . '" alt="' . h( $p['name'] ) . '">' : '<span class="noimg">no image</span>'; ?></div>
		<?php if ( $img && count( $gallery ) >= 1 ) : ?>
		<div class="shots">
			<button type="button" class="shot active" data-src="<?php echo $img; ?>"><img src="<?php echo $img; ?>" alt=""></button>
			<?php foreach ( $gallery as $g ) : if ( $g['file'] === $p['image'] ) continue; $u = img_url( $g['file'] ); ?>
				<button type="button" class="shot" data-src="<?php echo $u; ?>"><img src="<?php echo $u; ?>" alt=""></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<div>
		<h1><?php echo h( $p['name'] ); ?></h1>
		<?php $ph = trim( (string) ( $p['subhead'] ?? '' ) ); if ( '' !== $ph ) : ?><p class="subhead"><?php echo h( $ph ); ?></p><?php endif; ?>
		<?php if ( $p['sku'] !== '' ) : ?><div class="spec" style="margin-bottom:14px"><span class="stamp mono"><?php echo h( $p['sku'] ); ?></span></div><?php endif; ?>
		<?php if ( $p['description'] !== '' ) : ?><p class="sub"><?php echo nl2br( h( $p['description'] ) ); ?></p><?php endif; ?>

		<?php if ( 'xmr' === $disp && null !== $estXmr ) : /* XMR only */ ?>
			<div class="rowline"><span>Price</span><span class="v"><?php echo h( $estXmr ) . ' XMR'; ?></span></div>
		<?php elseif ( 'both_xmr' === $disp && null !== $estXmr ) : /* both, XMR primary */ ?>
			<div class="rowline"><span>Price</span><span class="v"><?php echo h( $estXmr ) . ' XMR'; ?></span></div>
			<div class="rowline"><span>Approx. in <?php echo h( $cur ); ?></span>
				<span class="v">≈ <?php echo h( number_format( $eff, 2 ) . ' ' . $cur ); ?></span></div>
		<?php elseif ( 'usd' === $disp ) : /* USD only */ ?>
			<div class="rowline"><span>Price</span><span class="v"><?php echo h( number_format( $eff, 2 ) . ' ' . $cur ); ?></span></div>
		<?php else : /* both, USD primary (default) */ ?>
			<div class="rowline"><span>Price</span><span class="v"><?php echo h( number_format( $eff, 2 ) . ' ' . $cur ); ?></span></div>
			<div class="rowline"><span>Approx. in Monero<?php echo $rate > 0 ? '' : ' (rate unavailable)'; ?></span>
				<span class="v"><?php echo $estXmr !== null ? '≈ ' . h( $estXmr ) . ' XMR' : '—'; ?></span></div>
		<?php endif; ?>
		<p class="hint" style="margin-top:8px">The exact XMR total is locked when you place the order and held for the quote window.</p>

		<?php if ( $out ) : ?>
			<p class="notice">This item is sold out.</p>
		<?php else : ?>
		<form method="post" action="checkout.php" style="margin-top:18px">
			<?php echo csrf_field(); ?>
			<input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
			<div class="field">
				<label for="qty">Quantity</label>
				<select id="qty" name="qty">
					<?php for ( $i = 1; $i <= min( 10, (int) $p['stock'] ); $i++ ) echo '<option>' . $i . '</option>'; ?>
				</select>
			</div>
			<div class="field">
				<label for="ship_name">Name <span class="req">*</span></label>
				<input id="ship_name" name="ship_name" type="text" autocomplete="name" maxlength="120" required
				       placeholder="Who the order is going to">
			</div>

			<div class="field">
				<label for="ship_email">Email <span class="req">*</span></label>
				<input id="ship_email" name="ship_email" type="email" autocomplete="email" maxlength="180" required
				       placeholder="you@example.com">
				<div class="hint">Used only to reach you about this order.</div>
			</div>

			<div class="field">
				<label for="ship_addr">Shipping address <span class="req">*</span></label>
				<textarea id="ship_addr" name="ship_addr" rows="5" maxlength="600" required
				          autocomplete="street-address"
				          placeholder="Street&#10;City, State / Region&#10;Postcode&#10;Country"></textarea>
				<div class="hint">However your post office likes it. Line breaks are kept.</div>
			</div>

			<div class="field">
				<label for="ship_phone">Phone <span class="muted">(optional)</span></label>
				<input id="ship_phone" name="ship_phone" type="tel" autocomplete="tel" maxlength="40"
				       placeholder="Only if your courier needs it">
				<span class="hint">Stored only to fulfil your order.</span>
			</div>
			<button class="btn block" type="submit"<?php echo $rate > 0 ? '' : ' disabled'; ?>>
				<?php echo $rate > 0 ? 'Place order & get payment address' : 'Pricing temporarily unavailable'; ?>
			</button>
		</form>
		<?php endif; ?>
	</div>
</div>
<script>
(function(){
  var main = document.getElementById('mainimg');
  if (!main) return;
  document.querySelectorAll('.shot').forEach(function(b){
    b.addEventListener('click', function(){
      main.src = b.dataset.src;
      document.querySelectorAll('.shot').forEach(function(x){ x.classList.remove('active'); });
      b.classList.add('active');
    });
  });
})();
</script>
<?php store_foot();
