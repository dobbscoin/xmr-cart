<?php
/**
 * Common admin bootstrap — every tab includes this via /admin/index.php.
 *
 * Populates the shared $store / $orders / $batches / $products etc. state,
 * prints warning banners + the stat-cards row that live above the tab nav.
 * Consumed by admin/tabs/*.php as globals.
 */
require_once __DIR__ . '/inc.php';

$store = store();
$real  = xmr()->isReal();
$node  = xmr()->nodeInfo();
$keys  = xmr()->verifyKeys();
$rate  = price()->xmrRate();
$cur   = strtoupper( (string) Config::get( 'store_currency', 'usd' ) );

$openCount = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status IN ('pending','confirming')" )['c'];
$paidCount = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status IN ('paid','shipped')" )['c'];
$toShip    = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status='paid'" )['c'];

$activeBatch = active_batch();
$selBatchId  = (int) req( 'batch', $activeBatch ? $activeBatch['id'] : 0 );
$batches     = $store->all( 'SELECT * FROM batches ORDER BY sort ASC, id ASC' );
$products    = $selBatchId ? $store->all( 'SELECT * FROM products WHERE batch_id=? ORDER BY sort,id', array( $selBatchId ) ) : array();
$orders      = $store->all( 'SELECT * FROM orders ORDER BY id DESC LIMIT 60' );
$editId      = (int) req( 'edit', 0 );
$editP       = $editId ? $store->one( 'SELECT * FROM products WHERE id=?', array( $editId ) ) : null;
$editImgs    = $editId ? $store->all( 'SELECT * FROM product_images WHERE product_id=? ORDER BY sort,id', array( $editId ) ) : array();

console_head( 'Dashboard', $real );

$msg = (string) req( 'msg', '' );
if ( 'hidden' === $msg ) : ?>
<div class="loud"><strong>Product hidden, not deleted.</strong> It has orders against it, so its record is kept for your books. It no longer appears in the store.</div>
<?php elseif ( 'deleted' === $msg ) : ?>
<div class="loud">Product deleted.</div>
<?php endif;

// Wallet-not-configured is the only remaining install-time banner that survives
// after the first-run passphrase wizard (which handles the pass-hash case).
if ( ! Config::configured() ) : ?>
<div class="loud"><strong>Wallet not configured.</strong> Set <span class="mono">primary_address</span> and <span class="mono">view_key</span> in config.php.</div>
<?php endif;

$missing = xmr()->missingExtensions();
if ( $missing ) : ?>
<div class="loud"><strong>Missing PHP extensions:</strong> <span class="mono"><?php echo h( implode( ', ', $missing ) ); ?></span>. The real payment engine needs these — install them before going live.</div>
<?php endif; ?>

<div class="cards">
	<div class="stat"><div class="k">Node</div><div class="v <?php echo $node['ok'] ? 'ok' : 'bad'; ?>"><?php echo $node['ok'] ? 'reachable' : 'down'; ?></div><div class="muted mono" style="font-size:12px"><?php echo h( $node['nettype'] ); ?></div></div>
	<div class="stat"><div class="k">Chain tip</div><div class="v"><?php echo $node['height'] !== null ? number_format( (int) $node['height'] ) : '—'; ?></div></div>
	<div class="stat"><div class="k">View key ↔ address</div><div class="v <?php echo ! empty( $keys['key_match'] ) ? 'ok' : 'bad'; ?>"><?php echo ! empty( $keys['address_valid'] ) ? ( ! empty( $keys['key_match'] ) ? 'match' : 'mismatch' ) : 'invalid'; ?></div></div>
	<div class="stat"><div class="k">XMR rate</div><div class="v"><?php echo $rate > 0 ? number_format( $rate, 2 ) : '—'; ?></div><div class="muted" style="font-size:12px"><?php echo h( $cur ); ?> / XMR</div></div>
	<div class="stat"><div class="k">Open orders</div><div class="v"><?php echo $openCount; ?></div></div>
	<div class="stat"><div class="k">Awaiting shipment</div><div class="v <?php echo $toShip ? 'ok' : ''; ?>"><?php echo $toShip; ?></div></div>
</div>
