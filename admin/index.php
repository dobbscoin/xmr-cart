<?php
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

function explorer_tx( $txid, $network ) {
	if ( $txid === '' || strpos( $txid, 'demo' ) === 0 ) { return h( substr( $txid, 0, 10 ) ); }
	$base = 'mainnet' === $network ? 'https://xmrchain.net/tx/' : 'https://stagenet.xmrchain.net/tx/';
	return '<a href="' . h( $base . $txid ) . '" target="_blank" rel="noopener noreferrer">' . h( substr( $txid, 0, 10 ) ) . '…</a>';
}

console_head( 'Dashboard', $real );

$msg = (string) req( 'msg', '' );
if ( 'hidden' === $msg ) : ?>
<div class="loud"><strong>Product hidden, not deleted.</strong> It has orders against it, so its record is kept for your books. It no longer appears in the store.</div>
<?php elseif ( 'deleted' === $msg ) : ?>
<div class="loud">Product deleted.</div>
<?php endif;

if ( ! Auth::passSet() ) : ?>
<div class="loud"><strong>No admin passphrase is set.</strong> This console is relying entirely on network isolation. Set <span class="mono">admin_pass_hash</span> in config.php as a second lock — generate one with
<span class="mono">php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT);"</span></div>
<?php endif;

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

<h2>Orders</h2>
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
			<td><?php echo h( $o['product_name'] ); ?><?php echo (int) $o['qty'] > 1 ? ' ×' . (int) $o['qty'] : ''; ?></td>
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

<div class="grid2">
	<div>
		<h2>Batches</h2>
		<table>
			<thead><tr><th>Batch</th><th>Status</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $batches as $bi => $b ) : ?>
				<tr>
					<td>
						<span class="muted mono" style="font-size:11px">#<?php echo (int) $b['id']; ?></span>
						<a href="?batch=<?php echo (int) $b['id']; ?>"><?php echo h( $b['name'] ); ?></a>
						<span style="margin-left:6px;white-space:nowrap">
							<?php $bc = csrf_field() . '<input type="hidden" name="id" value="' . (int) $b['id'] . '"><input type="hidden" name="action" value="batch_move">'; ?>
							<?php if ( $bi > 0 ) : ?>
							<form class="inline" method="post" action="actions.php"><?php echo $bc; ?><input type="hidden" name="dir" value="up"><button class="cbtn" style="padding:2px 6px;font-size:11px" title="Move up">↑</button></form>
							<?php endif; ?>
							<?php if ( $bi < count( $batches ) - 1 ) : ?>
							<form class="inline" method="post" action="actions.php"><?php echo $bc; ?><input type="hidden" name="dir" value="down"><button class="cbtn" style="padding:2px 6px;font-size:11px" title="Move down">↓</button></form>
							<?php endif; ?>
						</span>
						<form class="inline" method="post" action="actions.php" style="margin-top:4px;display:block">
							<?php echo csrf_field(); ?>
							<input type="hidden" name="action" value="rename_batch">
							<input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
							<input name="name" value="<?php echo h( $b['name'] ); ?>" style="width:190px;font-size:12px">
							<textarea name="description" rows="10" placeholder="Batch story — mintage, strike date, finish, why it's limited. Shown on the storefront. Line breaks are kept." style="display:block;width:100%;max-width:640px;margin-top:5px;font-size:12.5px;line-height:1.5;resize:vertical"><?php echo h( $b['description'] ?? '' ); ?></textarea>
							<button class="cbtn tiny" style="margin-top:4px">Save batch</button>
						</form>
					</td>
					<td><?php echo batch_pill( (string) $b['status'] ); ?></td>
					<td style="white-space:nowrap">
						<?php foreach ( array( 'live' => 'Open', 'closed' => 'Close', 'draft' => 'Draft' ) as $st => $lbl ) : if ( $st === $b['status'] ) continue; ?>
							<form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="batch_status"><input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="status" value="<?php echo $st; ?>"><button class="cbtn<?php echo 'live' === $st ? ' go' : ''; ?>"><?php echo $lbl; ?></button></form>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="panel2">
			<form method="post" action="actions.php">
				<?php echo csrf_field(); ?><input type="hidden" name="action" value="create_batch">
				<div class="field"><label for="bn">New batch name</label><input id="bn" name="name" placeholder="Batch name"></div>
				<button class="cbtn go">Create batch</button>
			</form>
		</div>
	</div>

	<div>
		<h2>Products <?php if ( $selBatchId ) : $bn = $store->one( 'SELECT name FROM batches WHERE id=?', array( $selBatchId ) ); echo '<span class="muted" style="font-size:14px">— ' . h( $bn['name'] ?? '' ) . '</span>'; endif; ?></h2>
		<?php if ( ! $selBatchId ) : ?><p class="muted">Pick a batch to manage its products.</p><?php else : ?>
		<table>
			<thead><tr><th>Item</th><th>Sticker / Live</th><th>Stock</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $products ) : ?><tr><td colspan="4" class="muted">No products in this batch yet.</td></tr><?php endif; ?>
			<?php foreach ( $products as $p ) : ?>
				<tr>
					<td>
						<span class="muted mono" style="font-size:11px">#<?php echo (int) $p['id']; ?></span>
						<?php echo h( $p['name'] ); ?> <?php echo (int) $p['active'] ? '' : '<span class="muted">(hidden)</span>'; ?>
						<div class="muted mono" style="font-size:11px"><?php echo h( $p['sku'] ); ?><?php
							// flag anything a buyer would notice is missing
							$gaps = array();
							if ( trim( (string) $p['description'] ) === '' ) { $gaps[] = 'no description'; }
							if ( trim( (string) $p['image'] ) === '' )       { $gaps[] = 'no image'; }
							if ( $gaps ) { echo ' <span style="color:#d98a3a">· ' . h( implode( ', ', $gaps ) ) . '</span>'; }
						?></div>
					</td>
					<td class="mono"><?php echo h( number_format( (float) $p['price_fiat'], 2 ) ); ?></td>
					<td>
						<form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="product_stock"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input class="mono" name="stock" value="<?php echo (int) $p['stock']; ?>" style="width:60px" inputmode="numeric"><button class="cbtn">set</button></form>
					</td>
					<td style="white-space:nowrap">
						<a class="cbtn" href="?batch=<?php echo $selBatchId; ?>&edit=<?php echo (int) $p['id']; ?>">Edit</a>
						<form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="product_active"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="active" value="<?php echo (int) $p['active'] ? 0 : 1; ?>"><button class="cbtn"><?php echo (int) $p['active'] ? 'Hide' : 'Show'; ?></button></form>
						<form class="inline" method="post" action="actions.php" onsubmit="return confirm('Delete this product?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><button class="cbtn warn">Del</button></form>
					</td>
				</tr>
				<tr class="photorow">
					<td colspan="4">
						<div class="thumbs">
							<?php if ( $p['image'] !== '' ) : ?>
								<figure class="thumb main">
									<img src="<?php echo '/' . img_url( $p['image'] ); ?>" alt="">
									<figcaption>main</figcaption>
								</figure>
							<?php else : ?>
								<figure class="thumb empty"><figcaption>no main image</figcaption></figure>
							<?php endif; ?>

							<?php foreach ( product_gallery( $p['id'] ) as $g ) : ?>
								<figure class="thumb">
									<img src="<?php echo '/' . img_url( $g['file'] ); ?>" alt="">
									<figcaption>
										<form class="inline" method="post" action="actions.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="image_main"><input type="hidden" name="id" value="<?php echo (int) $g['id']; ?>"><button class="cbtn tiny" title="Make this the main image">main</button></form>
										<form class="inline" method="post" action="actions.php" onsubmit="return confirm('Delete this photo?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="image_delete"><input type="hidden" name="id" value="<?php echo (int) $g['id']; ?>"><button class="cbtn tiny warn" title="Delete photo">&times;</button></form>
									</figcaption>
								</figure>
							<?php endforeach; ?>

							<form class="thumb addbox" method="post" action="actions.php" enctype="multipart/form-data">
								<?php echo csrf_field(); ?>
								<input type="hidden" name="action" value="add_images">
								<input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
								<label class="addlabel">
									<span>+ photos</span>
									<input type="file" name="gallery[]" multiple accept="image/png,image/jpeg,image/webp" onchange="this.form.submit()">
								</label>
							</form>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="panel2">
			<?php if ( $editP ) : ?><h3 style="margin:0 0 10px;font-family:var(--serif);color:#eceef1">Editing #<?php echo (int) $editP['id']; ?> <a class="cbtn" style="float:right" href="?batch=<?php echo $selBatchId; ?>">Cancel</a></h3><?php endif; ?>
			<form method="post" action="actions.php" enctype="multipart/form-data">
				<?php echo csrf_field(); ?>
				<input type="hidden" name="action" value="<?php echo $editP ? 'edit_product' : 'add_product'; ?>">
				<?php if ( $editP ) : ?><input type="hidden" name="id" value="<?php echo (int) $editP['id']; ?>"><?php endif; ?>
				<?php if ( $editP ) : ?>
				<div class="field">
					<label for="pb">Batch</label>
					<select id="pb" name="batch_id">
						<?php foreach ( $batches as $bb ) : ?>
						<option value="<?php echo (int) $bb['id']; ?>"<?php echo (int) $bb['id'] === (int) $editP['batch_id'] ? ' selected' : ''; ?>>
							#<?php echo (int) $bb['id']; ?> — <?php echo h( $bb['name'] ); ?> [<?php echo h( $bb['status'] ); ?>]
						</option>
						<?php endforeach; ?>
					</select>
					<div class="muted" style="font-size:11px;margin-top:2px">Move this product to a different batch.</div>
				</div>
				<?php else : ?>
				<input type="hidden" name="batch_id" value="<?php echo $selBatchId; ?>">
				<?php endif; ?>
				<div class="field"><label for="pn">Name</label><input id="pn" name="name" placeholder="Product name" required value="<?php echo $editP ? h( $editP['name'] ) : ''; ?>"></div>
				<div class="field"><label for="ph">Sub-header <span class="muted">(optional)</span></label><input id="ph" name="subhead" placeholder="Short second line" value="<?php echo $editP ? h( $editP['subhead'] ?? '' ) : ''; ?>"><span class="hint">Second line under the product name on the product and payment pages.</span></div>
				<div class="grid2">
					<div class="field"><label for="pp">Price (<?php echo h( $cur ); ?>)</label><input id="pp" name="price_fiat" inputmode="decimal" placeholder="42.00" value="<?php echo $editP ? h( $editP['price_fiat'] ) : ''; ?>"></div>
					<div class="field"><label for="pq">Stock</label><input id="pq" name="stock" inputmode="numeric" placeholder="50" value="<?php echo $editP ? h( $editP['stock'] ) : ''; ?>"></div>
				</div>
				<div class="field"><label for="ps">SKU <span class="muted">(optional)</span></label><input id="ps" name="sku" placeholder="SKU-001" value="<?php echo $editP ? h( $editP['sku'] ) : ''; ?>"></div>
				<div class="field"><label for="pd">Description</label><textarea id="pd" name="description" placeholder="Mint, purity, finish, any notes."><?php echo $editP ? h( $editP['description'] ) : ''; ?></textarea></div>
				<div class="field"><label for="pi">Main image (jpg / png / webp)</label><input id="pi" name="image" type="file" accept="image/png,image/jpeg,image/webp"></div>
				<div class="field"><label for="pg">More photos (optional — select several)</label><input id="pg" name="gallery[]" type="file" multiple accept="image/png,image/jpeg,image/webp"><span class="hint">Reverse, edge, packaging, close-ups…</span></div>
				<button class="cbtn go"><?php echo $editP ? 'Save changes' : 'Add product'; ?></button>
			</form>

			<?php if ( $editP ) : $uurl = rtrim( (string) Config::get( 'uploads_url', 'assets/products' ), '/' ); ?>
			<div style="margin-top:22px;border-top:1px solid #2a2f38;padding-top:16px">
				<h3 style="margin:0 0 4px;font-family:var(--serif);color:#eceef1">Images <span class="muted" style="font-size:13px">— first is MAIN (shown on the catalog card)</span></h3>

				<?php if ( ! $editImgs ) : ?><p class="muted">No images yet.</p><?php endif; ?>

				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;margin:12px 0">
				<?php foreach ( $editImgs as $ix => $im ) : $isMain = ( 0 === (int) $im['sort'] ); ?>
					<div style="border:1px solid <?php echo $isMain ? '#ff6600' : '#2a2f38'; ?>;border-radius:4px;overflow:hidden;background:#101115">
						<div style="position:relative;aspect-ratio:1/1;background:#0b0c0f">
							<img src="/<?php echo h( $uurl . '/' . $im['file'] ); ?>" style="width:100%;height:100%;object-fit:cover" alt="">
							<?php if ( $isMain ) : ?><span style="position:absolute;top:4px;left:4px;background:#ff6600;color:#fff;font-size:10px;letter-spacing:.1em;padding:2px 6px;border-radius:3px">MAIN</span><?php endif; ?>
							<span style="position:absolute;top:4px;right:4px;background:#000a;color:#aeb4bf;font-size:10px;padding:2px 6px;border-radius:3px"><?php echo (int) $im['sort'] + 1; ?></span>
						</div>
						<div style="display:flex;gap:3px;padding:6px;flex-wrap:wrap">
							<?php $common = csrf_field() . '<input type="hidden" name="batch_id" value="' . $selBatchId . '"><input type="hidden" name="id" value="' . (int) $im['id'] . '">'; ?>
							<?php if ( ! $isMain ) : ?>
							<form class="inline" method="post" action="actions.php"><?php echo $common; ?><input type="hidden" name="action" value="img_main"><button class="cbtn" style="padding:3px 7px;font-size:11px" title="Make MAIN">★</button></form>
							<form class="inline" method="post" action="actions.php"><?php echo $common; ?><input type="hidden" name="action" value="img_move"><input type="hidden" name="dir" value="up"><button class="cbtn" style="padding:3px 7px;font-size:11px" title="Move earlier">↑</button></form>
							<?php endif; ?>
							<?php if ( $ix < count( $editImgs ) - 1 ) : ?>
							<form class="inline" method="post" action="actions.php"><?php echo $common; ?><input type="hidden" name="action" value="img_move"><input type="hidden" name="dir" value="down"><button class="cbtn" style="padding:3px 7px;font-size:11px" title="Move later">↓</button></form>
							<?php endif; ?>
							<form class="inline" method="post" action="actions.php" onsubmit="return confirm('Delete this image?')"><?php echo $common; ?><input type="hidden" name="action" value="img_delete"><button class="cbtn warn" style="padding:3px 7px;font-size:11px" title="Delete">✕</button></form>
						</div>
					</div>
				<?php endforeach; ?>
				</div>

				<form method="post" action="actions.php" enctype="multipart/form-data">
					<?php echo csrf_field(); ?>
					<input type="hidden" name="action" value="img_add">
					<input type="hidden" name="product_id" value="<?php echo (int) $editP['id']; ?>">
					<input type="hidden" name="batch_id" value="<?php echo $selBatchId; ?>">
					<div class="field"><label for="gi">Add images (select several at once)</label>
						<input id="gi" name="images[]" type="file" multiple accept="image/png,image/jpeg,image/webp"></div>
					<button class="cbtn">Upload images</button>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
<div class="panel2" id="nodes" style="margin-top:22px">
	<h2>Settlement nodes <span class="muted" style="font-size:13px">&mdash; the Monero node fleet the scanner falls over across</span></h2>
	<?php
	$_probe   = nodeprobe()->results();
	$_page    = $_probe['results'] ?? array();
	$_probeAt = $_probe['probed_at'] ?? 0;
	$_pAge    = nodeprobe()->cacheAge();
	// max healthy height so we can flag laggers relative to the front-of-pack
	$_maxH = 0;
	foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) && (int) $_n['height'] > $_maxH ) { $_maxH = (int) $_n['height']; } }
	$_okCount = 0; foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) ) { $_okCount++; } }
	?>
	<div class="grid2" style="margin-bottom:14px">
		<div>
			<div class="muted" style="font-size:12px">Configured nodes</div>
			<div class="mono" style="font-size:22px;color:<?php echo $_okCount === 0 ? '#e26a6a' : ( $_okCount < count( $_page ) ? '#ff9b40' : '#78c86f' ); ?>">
				<?php echo (int) $_okCount; ?> / <?php echo count( $_page ); ?> healthy
			</div>
			<div class="muted" style="font-size:11px">
				<?php
				if ( null === $_pAge ) { echo 'never probed'; }
				else { echo 'probed ' . (int) $_pAge . 's ago (auto-refresh every ' . NodeProbe::TTL . 's)'; }
				?>
			</div>
			<form method="post" action="actions.php" style="margin-top:6px">
				<?php echo csrf_field(); ?>
				<input type="hidden" name="action" value="probe_nodes_refresh">
				<button class="cbtn">Test all now</button>
			</form>
		</div>
		<div>
			<div class="muted" style="font-size:12px">How failover works</div>
			<div style="font-size:13px;margin-top:2px;color:#eceef1">Scanner tries each node in order until one answers. <span class="mono">tipHeight</span> is the MIN across responders, so a laggard can only delay settlement, never bring it forward.</div>
			<div class="muted" style="font-size:11px;margin-top:4px">
				<strong>Pruned nodes fail-CLOSED</strong> at the commitment check — a payment is identified but not settled. Node list must be un-pruned full nodes only. Config lives in <span class="mono">config.php['nodes']</span>.
			</div>
		</div>
	</div>

	<?php if ( ! $_page ) : ?>
		<p class="muted">No nodes configured.</p>
	<?php else : ?>
		<table>
			<thead><tr><th>#</th><th>URL</th><th>Status</th><th>Height</th><th>Flags</th><th>Latency</th></tr></thead>
			<tbody>
			<?php foreach ( $_page as $_i => $_n ) :
				$_isPrimary = ( 0 === $_i );
				$_h = (int) ( $_n['height'] ?? 0 );
				$_behind = ( $_maxH > 0 && $_h > 0 ) ? ( $_maxH - $_h ) : null;
			?>
				<tr>
					<td class="mono"><?php echo $_i + 1; ?><?php if ( $_isPrimary ) : ?> <span style="color:#ff9b40" title="primary">◆</span><?php endif; ?></td>
					<td class="mono" style="font-size:11px;word-break:break-all"><?php echo h( $_n['url'] ); ?></td>
					<td>
						<?php if ( ! empty( $_n['ok'] ) ) : ?>
							<span class="mono" style="background:#0e2a12;color:#78c86f;padding:1px 6px;border-radius:3px;font-size:11px">OK</span>
						<?php else : ?>
							<span class="mono" style="background:#2a0f0f;color:#e26a6a;padding:1px 6px;border-radius:3px;font-size:11px">FAIL</span>
							<?php if ( ! empty( $_n['error'] ) ) : ?><div class="muted" style="font-size:11px"><?php echo h( $_n['error'] ); ?></div><?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="mono"><?php
						echo $_h > 0 ? h( number_format( $_h ) ) : '<span class="muted">&mdash;</span>';
						if ( null !== $_behind && $_behind > 0 ) {
							echo '<div class="muted" style="font-size:11px;color:' . ( $_behind > 3 ? '#ff9b40' : '#7c8598' ) . '">-' . (int) $_behind . '</div>';
						}
					?></td>
					<td style="font-size:11px">
						<?php if ( ! empty( $_n['pruned'] ) ) : ?><span class="mono" style="background:#2a0f0f;color:#e26a6a;padding:1px 5px;border-radius:2px;font-size:10px" title="pruned nodes fail commitment check">PRUNED</span> <?php endif; ?>
						<?php if ( isset( $_n['synced'] ) && $_n['synced'] === false ) : ?><span class="mono" style="background:#2a1f0f;color:#ff9b40;padding:1px 5px;border-radius:2px;font-size:10px">SYNCING</span> <?php endif; ?>
						<?php if ( ! empty( $_n['bootstrap'] ) ) : ?><span class="mono muted" style="font-size:10px" title="serving via bootstrap: <?php echo h( $_n['bootstrap'] ); ?>">bootstrap</span><?php endif; ?>
					</td>
					<td class="mono muted" style="font-size:11px"><?php echo isset( $_n['elapsed_ms'] ) ? (int) $_n['elapsed_ms'] . 'ms' : '&mdash;'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="panel2" id="display" style="margin-top:22px">
	<h2>Storefront pricing display <span class="muted" style="font-size:13px">&mdash; USD, XMR, or both</span></h2>
	<?php $_disp = price_display_mode(); ?>
	<form method="post" action="actions.php" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="pricing_display_set">
		<?php foreach ( array( 'usd' => 'USD only', 'xmr' => 'XMR only', 'both' => 'Both — USD big', 'both_xmr' => 'Both — XMR big' ) as $_m => $_lbl ) :
			$_sel = ( $_disp === $_m ); ?>
			<label class="cbtn" style="cursor:pointer;<?php echo $_sel ? 'background:#ff6600;color:#fff;border-color:#ff6600' : ''; ?>">
				<input type="radio" name="mode" value="<?php echo $_m; ?>"<?php echo $_sel ? ' checked' : ''; ?> style="margin-right:6px;vertical-align:middle">
				<?php echo h( $_lbl ); ?>
			</label>
		<?php endforeach; ?>
		<button class="cbtn go" style="margin-left:8px">Save</button>
	</form>
	<span class="hint">Applies to catalog card and product page. Order and payment pages always show the fiat snapshot the buyer agreed to at checkout, regardless of this setting.</span>
</div>

<div class="panel2" style="margin-top:22px">
	<h2>Site copy <span class="muted" style="font-size:13px">— the words a visitor reads</span></h2>
	<form method="post" action="actions.php">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="save_copy">
		<?php
		$labels = array(
			'tagline'       => 'Tagline (under the wordmark)',
			'masthead_note' => 'Masthead note (top right)',
			'blurb_open'    => 'Catalog blurb — when a batch is OPEN ({CUR} = currency)',
			'blurb_closed'  => 'Catalog blurb — when nothing is for sale',
			'exchange_note' => 'Payment page: exchange-wallet warning (shown above the address; blank = hide)',
			'contact_email' => 'Contact address (shown under the masthead note; blank = hide)',
			'footer'        => 'Footer text (bottom of every page)',
		);
		$defs = copy_defaults();
		foreach ( $labels as $ck => $lbl ) :
			$row = $store->one( 'SELECT v FROM kv WHERE k=?', array( 'copy:' . $ck ) );
			$val = $row ? $row['v'] : '';
		?>
		<div class="field">
			<label for="c_<?php echo $ck; ?>"><?php echo h( $lbl ); ?></label>
			<textarea id="c_<?php echo $ck; ?>" name="copy_<?php echo $ck; ?>" rows="2" placeholder="<?php echo h( $defs[ $ck ] ); ?>"><?php echo h( $val ); ?></textarea>
			<div class="muted" style="font-size:11px;margin-top:2px">Blank = use the default shown above.</div>
		</div>
		<?php endforeach; ?>
		<button class="cbtn go">Save copy</button>
	</form>
</div>

<div class="panel2" id="banner" style="margin-top:22px">
	<h2>Masthead banner <span class="muted" style="font-size:13px">&mdash; the image behind the header</span></h2>
	<p class="muted" style="margin-top:-4px;font-size:13px">
		A wide image fills the whole strip above the hairline rule &mdash; edge to edge, behind the
		wordmark and the notes. Long and short works best (roughly 2000&times;400). It&rsquo;s cropped
		to fill, so keep anything you can&rsquo;t lose away from the far left and right edges.
	</p>

	<?php
	$b_file  = banner_get( 'file' );
	$b_url   = banner_url( '/public/' );
	$b_style = banner_style( '/public/' );
	$b_dim   = banner_size();
	?>

	<?php if ( '' !== $b_url ) : ?>
		<p class="muted" style="font-size:12px;margin-bottom:4px">Preview &mdash; this is the real crop and the real wash, exactly as the store draws it:</p>
		<div class="banner-preview banner-<?php echo 'light' === banner_get( 'text' ) ? 'light' : 'dark'; ?>" style="<?php echo h( $b_style ); ?>">
			<div class="demo">Crypto <span>Dollar</span><em>.info</em></div>
		</div>

		<?php if ( $b_dim ) : $ar = $b_dim['h'] ? $b_dim['w'] / $b_dim['h'] : 0; ?>
			<p class="muted" style="font-size:12px;margin-top:6px">
				Uploaded image: <?php echo (int) $b_dim['w']; ?>&times;<?php echo (int) $b_dim['h']; ?>px
				(<?php echo number_format( $ar, 2 ); ?>:1).
				<?php if ( $ar < 3 ) : ?>
					<span style="color:#e0a074">This isn&rsquo;t banner-shaped. The band is roughly 6:1, so &ldquo;fill the band&rdquo;
					keeps only a narrow horizontal strip from the middle and throws the rest away. Either raise the banner height
					below, switch Fill to <em>fit the whole image</em>, or crop the picture long and short before uploading.</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<p class="muted">No banner set &mdash; the header shows the plain paper background.</p>
	<?php endif; ?>

	<form method="post" action="actions.php" enctype="multipart/form-data">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="banner_save">

		<div class="field">
			<label for="b_file">Banner image (jpg / png / webp)</label>
			<input id="b_file" name="banner" type="file" accept="image/png,image/jpeg,image/webp">
			<span class="hint"><?php echo '' !== $b_file ? 'Choosing a new file replaces the current banner.' : 'Leave empty to change only the settings below.'; ?></span>
		</div>

		<div class="field">
			<label for="b_fit">Fill</label>
			<?php $bfit = banner_get( 'fit' ); ?>
			<select id="b_fit" name="fit">
				<option value="cover"   <?php echo 'cover'   === $bfit ? 'selected' : ''; ?>>Fill the band, crop the overflow (recommended)</option>
				<option value="contain" <?php echo 'contain' === $bfit ? 'selected' : ''; ?>>Fit the whole image inside (may leave gaps)</option>
				<option value="stretch" <?php echo 'stretch' === $bfit ? 'selected' : ''; ?>>Stretch to fit exactly (distorts)</option>
			</select>
		</div>

		<div class="field">
			<label for="b_pos">Focal point &mdash; which part survives the crop</label>
			<?php $bpos = banner_get( 'pos' ); $poss = array( 'center' => 'Centre', 'top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right' ); ?>
			<select id="b_pos" name="pos">
				<?php foreach ( $poss as $pv => $pl ) : ?>
					<option value="<?php echo h( $pv ); ?>" <?php echo $pv === $bpos ? 'selected' : ''; ?>><?php echo h( $pl ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="field">
			<label for="b_h">Banner height, px</label>
			<input id="b_h" name="height" type="number" min="0" max="800" step="10" value="<?php echo h( banner_get( 'height' ) ); ?>">
			<span class="hint">Blank or 0 = the band is as tall as the text it holds (about 215px). Set a number to make it taller.</span>
		</div>

		<div class="field">
			<label for="b_scrim">Readability wash, 0&ndash;100</label>
			<input id="b_scrim" name="scrim" type="number" min="0" max="100" step="5" value="<?php echo h( banner_get( 'scrim' ) ); ?>">
			<span class="hint">0 = raw image. 100 = solid, image invisible. Around 50 keeps the type legible over a busy photo.</span>
		</div>

		<div class="field">
			<label for="b_text">Header text colour</label>
			<?php $btext = banner_get( 'text' ); ?>
			<select id="b_text" name="text">
				<option value="dark"  <?php echo 'light' !== $btext ? 'selected' : ''; ?>>Dark text (light banner)</option>
				<option value="light" <?php echo 'light' === $btext ? 'selected' : ''; ?>>Light text (dark banner)</option>
			</select>
			<span class="hint">This also decides which way the wash goes &mdash; pale over a light banner, dark over a dark one.</span>
		</div>

		<button class="cbtn go">Save banner</button>
	</form>

	<?php if ( '' !== $b_file ) : ?>
	<form method="post" action="actions.php" style="margin-top:12px" onsubmit="return confirm('Remove the banner image? The header goes back to plain paper.')">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="banner_remove">
		<button class="cbtn warn">Remove banner</button>
	</form>
	<?php endif; ?>
</div>

<?php console_foot();
