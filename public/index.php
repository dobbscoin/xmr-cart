<?php
require_once __DIR__ . '/inc.php';

$batches = live_batches();
$cur     = strtoupper( (string) Config::get( 'store_currency', 'usd' ) );
$disp    = price_display_mode();
$rate    = price()->xmrRate();

// Only show batches that still have something in them.
$open = array();
foreach ( $batches as $b ) {
	$prods = batch_products( $b['id'] );
	if ( $prods ) { $open[] = array( 'batch' => $b, 'products' => $prods ); }
}

store_head( '' );
?>
<?php if ( ! $open ) : ?>

	<h1 class="lede">No batch is open right now</h1>
	<p class="sub"><?php echo nl2br( h( site_copy( 'blurb_closed' ) ) ); ?></p>

<?php else : ?>

	<p class="sub"><?php echo nl2br( h( site_copy( 'blurb_open' ) ) ); ?></p>

	<?php foreach ( $open as $i => $sec ) : $b = $sec['batch']; ?>
	<section class="batch<?php echo $i > 0 ? ' batch-next' : ''; ?>">
		<h1 class="lede"><?php echo h( $b['name'] ); ?></h1>

		<?php if ( trim( (string) ( $b['description'] ?? '' ) ) !== '' ) : ?>
		<p class="batch-note"><?php echo nl2br( linkify( h( $b['description'] ) ) ); ?></p>
		<?php endif; ?>

		<div class="grid">
			<?php foreach ( $sec['products'] as $p ) : $img = product_img_url( $p ); $out = (int) $p['stock'] <= 0; ?>
			<article class="card">
				<div class="ph"><?php echo $img ? '<img src="' . $img . '" alt="' . h( $p['name'] ) . '">' : '<span class="noimg">no image</span>'; ?></div>
				<div class="body">
					<h3><?php echo h( $p['name'] ); ?></h3>
					<?php if ( $p['sku'] !== '' ) : ?><div class="spec"><span class="stamp mono"><?php echo h( $p['sku'] ); ?></span></div><?php endif; ?>
					<?php
					$eff = product_effective_price( $p );
					$xmr = $rate > 0 ? fiat_to_xmr_display( $eff, $rate ) : null;
					$usd_str = number_format( $eff, 2 ) . ' ' . $cur;
					$xmr_str = ( null !== $xmr ) ? $xmr . ' XMR' : null;
					?>
					<div class="price">
						<span class="fiat">
							<?php if ( 'xmr' === $disp && null !== $xmr_str ) : ?>
								<?php echo h( $xmr_str ); ?>
							<?php elseif ( 'both_xmr' === $disp && null !== $xmr_str ) : ?>
								<?php echo h( $xmr_str ); ?>
								<span class="xmr-sec">≈ <?php echo h( $usd_str ); ?></span>
							<?php elseif ( 'both' === $disp ) : ?>
								<?php echo h( $usd_str ); ?>
								<?php if ( null !== $xmr_str ) : ?><span class="xmr-sec">≈ <?php echo h( $xmr_str ); ?></span><?php endif; ?>
							<?php else : ?>
								<?php echo h( $usd_str ); ?>
							<?php endif; ?>
						</span>
						<span class="stk <?php echo $out ? 'out' : ''; ?>"><?php echo $out ? 'sold out' : ( (int) $p['stock'] . ' available' ); ?></span>
					</div>
					<a class="btn block" href="product.php?id=<?php echo (int) $p['id']; ?>"<?php echo $out ? ' aria-disabled="true"' : ''; ?>><?php echo $out ? 'Sold out' : 'View & buy'; ?></a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endforeach; ?>

<?php endif; ?>
<?php store_foot();
