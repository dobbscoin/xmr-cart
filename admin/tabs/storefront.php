<?php
/**
 * Storefront tab — everything that changes how a visitor sees the store:
 * pricing display, site copy, masthead banner.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }
?>
<div class="panel2" id="display" style="margin-top:0">
	<h2 style="margin-top:0">Storefront pricing display <span class="muted" style="font-size:13px">&mdash; USD, XMR, or both</span></h2>
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
	<h2 style="margin-top:0">Site copy <span class="muted" style="font-size:13px">— the words a visitor reads</span></h2>
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
	<h2 style="margin-top:0">Masthead banner <span class="muted" style="font-size:13px">&mdash; the image behind the header</span></h2>
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
			<div class="demo"><?php echo h( store_name() ); ?></div>
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
