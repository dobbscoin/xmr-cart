<?php
require_once __DIR__ . '/../lib/bootstrap.php';

function store_head( $title ) {
	$t = $title ? $title . ' — ' . store_name() : store_name();
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<meta name="referrer" content="no-referrer">';
	echo '<title>' . h( $t ) . '</title>';
	echo '<link rel="icon" href="/favicon.ico" sizes="any">';
	echo '<link rel="icon" type="image/png" sizes="32x32" href="assets/brand/favicon-32.png">';
	echo '<link rel="icon" type="image/png" sizes="16x16" href="assets/brand/favicon-16.png">';
	echo '<link rel="apple-touch-icon" sizes="180x180" href="assets/brand/favicon-180.png">';
	echo '<meta name="theme-color" content="#ff6600">';
	// cache-bust on file mtime, so a stylesheet change is never served stale
	$cssv = @filemtime( __DIR__ . '/assets/style.css' ) ?: time();
	echo '<link rel="stylesheet" href="assets/style.css?v=' . $cssv . '"></head><body class="store">';

	// Optional admin-uploaded banner behind the whole header band. banner_style()
	// returns '' when no banner is set, in which case the markup is exactly as before.
	$bstyle = banner_style();
	$bcls   = 'masthead';
	$battr  = '';
	if ( '' !== $bstyle ) {
		$bcls .= ' has-banner banner-' . ( 'light' === banner_get( 'text' ) ? 'light' : 'dark' );
		$battr = ' style="' . h( $bstyle ) . '"';
	}
	echo '<header class="' . $bcls . '"' . $battr . '><div class="wrap">';

	// The lockup = mark + wordmark + tagline, as ONE block. It exists so the
	// masthead note can be top-aligned against it on desktop (see .masthead .wrap
	// in the min-width media query). Without this wrapper the note has no single
	// sibling to align to — brand and tagline were separate stacked children.
	echo '<div class="lockup">';

	// Row 1: logo + wordmark, left-justified.
	echo '<div class="brand">';
	echo '<a class="mark mark-img" href="index.php" aria-label="' . h( store_name() ) . ' home"><img src="assets/brand/mark.png" alt="" width="38" height="38"></a>';
	echo '<a class="wordmark" href="index.php" style="text-decoration:none">' . h( store_name() ) . '</a>';
	echo '</div>';

	// Row 2: tagline, directly under the wordmark.
	echo '<div class="tagline">' . nl2br( h( site_copy( 'tagline' ) ) ) . '</div>';

	// Row 3: chain-connection pill — live trust signal. A dead/stale store
	// can't fake a fresh block height. Renders from cached NodeProbe results
	// so the storefront pageload isn't gated on 4 nodes replying.
	echo connection_pill_html();

	echo '</div>';   // .lockup

	echo '<div class="masthead-note">' . nl2br( h( site_copy( 'masthead_note' ) ) );
	$cmail = trim( site_copy( 'contact_email' ) );
	if ( '' !== $cmail ) {
		echo '<div class="contact"><a href="mailto:' . h( $cmail ) . '">' . h( $cmail ) . '</a></div>';
	}
	echo '</div>';
	echo '</div></header><main class="wrap">';
	// Cart link: filled in and shown by assets/cart.js when the cart isn't empty.
	echo '<div class="cartbar"><a id="cartbar" href="cart.php" hidden>Cart · <span class="n">0</span></a></div>';
}
function store_foot() {
	$foot = trim( site_copy( 'footer' ) );
	$year = date( 'Y' );
	$name = h( store_name() );
	echo '</main><footer class="footer"><div class="wrap">';
	if ( '' !== $foot ) {
		echo '<div class="footer-note">' . nl2br( h( $foot ) ) . '</div>';
	}
	echo '<div class="footer-legal">'
		. '&copy; ' . $year . ' ' . $name
		. ' <span class="sep">&middot;</span> '
		. 'Built on <a href="https://git.subgenius.finance/SubGeniusFinance/xmr-cart" rel="noopener">xmr-cart</a> '
		. '<span class="mit">(MIT)</span>'
		. '</div>';
	echo '</div></footer>';
	$jsv = @filemtime( __DIR__ . '/assets/cart.js' ) ?: time();
	echo '<script src="assets/cart.js?v=' . $jsv . '"></script>';
	echo '</body></html>';
}
// Catalog helpers (active_batch, live_products, find_product, product_img_url, pill)
// live in lib/catalog.php so the admin console can use them too.

/** Name / email / address / phone fields shared by the Buy form and the cart. */
function ship_fields_html() {
	ob_start(); ?>
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
<?php
	return ob_get_clean();
}
