<?php
/**
 * Catalog tab.
 *
 * Two rendering modes:
 *   1. Normal browse — batches (left) + products (right) grid2 layout.
 *      Includes the inline "add a product to this batch" form.
 *   2. Focused edit — when ?edit=<pid> is set, the whole tab is the product
 *      edit form (batch dropdown at the top, so you can move the product
 *      between batches from right there). "← Done" returns to browse.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }
$uurl = rtrim( (string) Config::get( 'uploads_url', 'assets/products' ), '/' );
?>

<?php if ( $editP ) : /* ================= FOCUSED EDIT MODE ================= */ ?>

<div class="edit-focus">
	<div class="edit-header">
		<a class="cbtn" href="?tab=catalog&amp;batch=<?php echo (int) $editP['batch_id']; ?>">← Done</a>
		<h2 style="margin:0;font-family:var(--serif);color:#eceef1;font-size:22px">
			Editing <span style="color:#ff9b40"><?php echo h( $editP['name'] ); ?></span>
			<span class="muted mono" style="font-size:13px;font-family:var(--mono);font-weight:normal">#<?php echo (int) $editP['id']; ?></span>
		</h2>
	</div>

	<div class="panel2" style="max-width:820px">
		<form method="post" action="actions.php" enctype="multipart/form-data">
			<?php echo csrf_field(); ?>
			<input type="hidden" name="action" value="edit_product">
			<input type="hidden" name="id" value="<?php echo (int) $editP['id']; ?>">

			<div class="field">
				<label for="pb">Batch</label>
				<select id="pb" name="batch_id">
					<?php foreach ( $batches as $bb ) : ?>
					<option value="<?php echo (int) $bb['id']; ?>"<?php echo (int) $bb['id'] === (int) $editP['batch_id'] ? ' selected' : ''; ?>>
						#<?php echo (int) $bb['id']; ?> — <?php echo h( $bb['name'] ); ?> [<?php echo h( $bb['status'] ); ?>]
					</option>
					<?php endforeach; ?>
				</select>
				<span class="hint">Change this to move the product between batches.</span>
			</div>

			<div class="field"><label for="pn">Name</label><input id="pn" name="name" placeholder="Product name" required value="<?php echo h( $editP['name'] ); ?>"></div>
			<div class="field"><label for="ph">Sub-header <span class="muted">(optional)</span></label><input id="ph" name="subhead" placeholder="Short second line" value="<?php echo h( $editP['subhead'] ?? '' ); ?>"><span class="hint">Second line under the product name on the product and payment pages.</span></div>
			<div class="grid2">
				<div class="field"><label for="pp">Price (<?php echo h( $cur ); ?>)</label><input id="pp" name="price_fiat" inputmode="decimal" placeholder="42.00" value="<?php echo h( $editP['price_fiat'] ); ?>"></div>
				<div class="field"><label for="pq">Stock</label><input id="pq" name="stock" inputmode="numeric" placeholder="50" value="<?php echo h( $editP['stock'] ); ?>"></div>
			</div>
			<div class="field"><label for="ps">SKU <span class="muted">(optional)</span></label><input id="ps" name="sku" placeholder="SKU-001" value="<?php echo h( $editP['sku'] ); ?>"></div>
			<div class="field"><label for="pd">Description</label><textarea id="pd" name="description" placeholder="What the buyer would want to know before committing."><?php echo h( $editP['description'] ); ?></textarea></div>
			<div class="field"><label for="pi">Replace main image (jpg / png / webp)</label><input id="pi" name="image" type="file" accept="image/png,image/jpeg,image/webp"><span class="hint">Leave empty to keep the current main image.</span></div>
			<div class="field"><label for="pg">Add more photos (optional — select several)</label><input id="pg" name="gallery[]" type="file" multiple accept="image/png,image/jpeg,image/webp"><span class="hint">Reverse, edge, packaging, close-ups…</span></div>

			<div style="display:flex;gap:8px;align-items:center;margin-top:6px">
				<button class="cbtn go" type="submit">Save changes</button>
				<a class="cbtn" href="?tab=catalog&amp;batch=<?php echo (int) $editP['batch_id']; ?>">Cancel</a>
			</div>
		</form>

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
						<?php $common = csrf_field() . '<input type="hidden" name="batch_id" value="' . (int) $editP['batch_id'] . '"><input type="hidden" name="id" value="' . (int) $im['id'] . '">'; ?>
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
				<input type="hidden" name="batch_id" value="<?php echo (int) $editP['batch_id']; ?>">
				<div class="field"><label for="gi">Add images (select several at once)</label>
					<input id="gi" name="images[]" type="file" multiple accept="image/png,image/jpeg,image/webp"></div>
				<button class="cbtn">Upload images</button>
			</form>
		</div>
	</div>
</div>

<?php else : /* ================= BROWSE MODE ================= */ ?>

<div class="grid2">
	<div>
		<h2 style="margin-top:0">Batches</h2>
		<table>
			<thead><tr><th>Batch</th><th>Status</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $batches as $bi => $b ) : ?>
				<tr>
					<td>
						<span class="muted mono" style="font-size:11px">#<?php echo (int) $b['id']; ?></span>
						<a href="?tab=catalog&amp;batch=<?php echo (int) $b['id']; ?>"><?php echo h( $b['name'] ); ?></a>
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
							<textarea name="description" rows="10" placeholder="Batch description — shown on the storefront above this batch's products. Line breaks are kept." style="display:block;width:100%;max-width:640px;margin-top:5px;font-size:12.5px;line-height:1.5;resize:vertical"><?php echo h( $b['description'] ?? '' ); ?></textarea>
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

		<?php
		$_hasDemoBatch = (bool) $store->one( "SELECT id FROM batches WHERE name='Demo' LIMIT 1" );
		if ( ! $_hasDemoBatch ) :
		?>
		<div class="panel2" style="margin-top:10px">
			<div style="font-size:13px;color:#eceef1;margin-bottom:6px">Nothing to sell yet?</div>
			<p class="muted" style="font-size:12px;margin:0 0 8px">Drop in three <strong>DEMO</strong> products (Sticker / Hat / Coffee) to click through the buyer flow. Editable or deletable at any time.</p>
			<form method="post" action="actions.php">
				<?php echo csrf_field(); ?>
				<input type="hidden" name="action" value="seed_demo">
				<button class="cbtn">Seed demo products</button>
			</form>
		</div>
		<?php endif; ?>
	</div>

	<div>
		<h2 style="margin-top:0">Products <?php if ( $selBatchId ) : $bn = $store->one( 'SELECT name FROM batches WHERE id=?', array( $selBatchId ) ); echo '<span class="muted" style="font-size:14px">— ' . h( $bn['name'] ?? '' ) . '</span>'; endif; ?></h2>
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
						<a class="cbtn" href="?tab=catalog&amp;batch=<?php echo $selBatchId; ?>&amp;edit=<?php echo (int) $p['id']; ?>">Edit</a>
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
			<h3 style="margin:0 0 10px;font-family:var(--serif);color:#eceef1;font-size:16px">Add a product to this batch</h3>
			<form method="post" action="actions.php" enctype="multipart/form-data">
				<?php echo csrf_field(); ?>
				<input type="hidden" name="action" value="add_product">
				<input type="hidden" name="batch_id" value="<?php echo $selBatchId; ?>">
				<div class="field"><label for="pn">Name</label><input id="pn" name="name" placeholder="Product name" required></div>
				<div class="field"><label for="ph">Sub-header <span class="muted">(optional)</span></label><input id="ph" name="subhead" placeholder="Short second line"><span class="hint">Second line under the product name on the product and payment pages.</span></div>
				<div class="grid2">
					<div class="field"><label for="pp">Price (<?php echo h( $cur ); ?>)</label><input id="pp" name="price_fiat" inputmode="decimal" placeholder="42.00"></div>
					<div class="field"><label for="pq">Stock</label><input id="pq" name="stock" inputmode="numeric" placeholder="50"></div>
				</div>
				<div class="field"><label for="ps">SKU <span class="muted">(optional)</span></label><input id="ps" name="sku" placeholder="SKU-001"></div>
				<div class="field"><label for="pd">Description</label><textarea id="pd" name="description" placeholder="What the buyer would want to know before committing."></textarea></div>
				<div class="field"><label for="pi">Main image (jpg / png / webp)</label><input id="pi" name="image" type="file" accept="image/png,image/jpeg,image/webp"></div>
				<div class="field"><label for="pg">More photos (optional — select several)</label><input id="pg" name="gallery[]" type="file" multiple accept="image/png,image/jpeg,image/webp"><span class="hint">Reverse, edge, packaging, close-ups…</span></div>
				<button class="cbtn go">Add product</button>
			</form>
		</div>
		<?php endif; ?>
	</div>
</div>

<?php endif; /* end mode branch */ ?>
