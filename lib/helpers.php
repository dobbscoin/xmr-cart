<?php
/** Small shared helpers. Money math is exact (integer piconero), no floats. */

function h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

function redirect( $url ) { header( 'Location: ' . $url ); exit; }

function json_out( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

function req( $key, $default = '' ) {
	return isset( $_REQUEST[ $key ] ) ? $_REQUEST[ $key ] : $default;
}

/* ---- CSRF (double-submit cookie, signed) --------------------------------- */
function csrf_secret() {
	$s = (string) Config::get( 'cookie_secret', '' );
	return $s !== '' ? $s : 'insecure-default-change-me';
}
function csrf_token() {
	if ( empty( $_COOKIE['csrf'] ) ) {
		$raw = bin2hex( random_bytes( 16 ) );
		setcookie( 'csrf', $raw, array( 'httponly' => false, 'samesite' => 'Strict', 'path' => '/' ) );
		$_COOKIE['csrf'] = $raw;
	}
	return hash_hmac( 'sha256', $_COOKIE['csrf'], csrf_secret() );
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h( csrf_token() ) . '">'; }
function csrf_check() {
	$sent = (string) req( '_csrf', '' );
	$want = isset( $_COOKIE['csrf'] ) ? hash_hmac( 'sha256', $_COOKIE['csrf'], csrf_secret() ) : '';
	if ( $sent === '' || ! hash_equals( $want, $sent ) ) {
		http_response_code( 400 );
		exit( 'Bad or missing CSRF token.' );
	}
}

/* ---- Exact money math (string in, string out; GMP) ----------------------- */
const XMR_DECIMALS = 12;

/** Decimal XMR string -> exact piconero string. Rejects nothing; clamps garbage to "0". */
function xmr_to_pico( $xmr ) {
	$xmr = trim( (string) $xmr );
	if ( ! preg_match( '/^\d+(\.\d+)?$/', $xmr ) ) { return '0'; }
	$parts = explode( '.', $xmr );
	$int   = $parts[0];
	$frac  = isset( $parts[1] ) ? substr( $parts[1], 0, XMR_DECIMALS ) : '';
	$frac  = str_pad( $frac, XMR_DECIMALS, '0' );
	$pico  = gmp_add( gmp_mul( gmp_init( $int, 10 ), gmp_init( '1000000000000', 10 ) ), gmp_init( $frac === '' ? '0' : $frac, 10 ) );
	return gmp_strval( $pico );
}

/** Exact piconero string -> canonical XMR string (trailing zeros trimmed). */
function pico_to_xmr( $pico ) {
	$p = gmp_init( (string) $pico, 10 );
	if ( gmp_cmp( $p, 0 ) <= 0 ) { return '0'; }
	$d    = gmp_init( '1000000000000', 10 );
	$int  = gmp_strval( gmp_div_q( $p, $d ) );
	$frac = (int) gmp_strval( gmp_mod( $p, $d ) );
	if ( 0 === $frac ) { return $int; }
	$fs = rtrim( str_pad( (string) $frac, XMR_DECIMALS, '0', STR_PAD_LEFT ), '0' );
	return $int . '.' . $fs;
}

/** fiat total / rate -> canonical XMR string, rounded up to the piconero. */
/**
 * Effective per-unit price for a product row. Rounded to cents.
 * Returns the sticker unchanged; retained as a seam for future dynamic-pricing hooks.
 */
function product_effective_price( $p, $spot = null ) {
	return round( (float) $p['price_fiat'], 2 );
}

/* ---- Storefront display currency -----------------------------------------
 * The internal source of truth is always USD (sticker + spot ratchet, then
 * snapshotted at checkout). This toggle only controls what a browsing buyer
 * sees on the catalog card and product page: 'usd' | 'xmr' | 'both'.
 *
 * Note: pay.php intentionally does NOT respect this toggle — it shows the
 * order snapshot, which is the number the buyer agreed to owe.
 */
/**
 * Storefront display mode. Four values:
 *   'usd'       — USD only, everywhere
 *   'xmr'       — XMR only, everywhere
 *   'both'      — both shown, USD primary (default; preserves prior behaviour)
 *   'both_xmr'  — both shown, XMR primary
 */
function price_display_modes() {
	return array( 'usd', 'xmr', 'both', 'both_xmr' );
}
function price_display_mode() {
	$row = store()->one( "SELECT v FROM kv WHERE k='pricing:display'" );
	$v   = $row ? strtolower( trim( (string) $row['v'] ) ) : 'both';
	return in_array( $v, price_display_modes(), true ) ? $v : 'both';
}
function price_display_mode_set( $mode ) {
	if ( ! in_array( $mode, price_display_modes(), true ) ) { $mode = 'both'; }
	store()->q(
		'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
		array( 'pricing:display', $mode, now() )
	);
}

/* ---- Chain connection pill ----------------------------------------------
 * A small live-status chip under the tagline: "Connected · block 3,718,796".
 * Trust signal — a stale/dead store can't fake a fresh chain height. Uses
 * the cached NodeProbe results (auto-refreshes on the 5min TTL when someone
 * hits the storefront), so a storefront pageload never pays for a fresh probe
 * unless the cache is truly cold.
 *
 * Returns HTML ready to echo inside .lockup. Empty string only if we truly
 * have no data to show; the CSS in style.css handles the visual.
 */
function connection_pill_html() {
	$probe = nodeprobe()->results();
	$rows  = isset( $probe['results'] ) && is_array( $probe['results'] ) ? $probe['results'] : array();
	$ok    = 0; $total = count( $rows ); $maxH = 0;
	foreach ( $rows as $r ) {
		if ( ! empty( $r['ok'] ) ) {
			$ok++;
			if ( (int) $r['height'] > $maxH ) { $maxH = (int) $r['height']; }
		}
	}

	if ( 0 === $total ) {
		return '<div class="chainstatus chainstatus-unknown" title="No settlement nodes configured"><span class="dot"></span><span class="txt">Verifying chain…</span></div>';
	}
	if ( 0 === $ok ) {
		return '<div class="chainstatus chainstatus-down" title="Settlement nodes unreachable — order intake paused"><span class="dot"></span><span class="txt">Reconnecting…</span></div>';
	}
	$state    = ( $ok === $total ) ? 'ok' : 'partial';
	$tooltip  = $ok === $total
		? number_format( $total ) . ' of ' . number_format( $total ) . ' settlement nodes healthy'
		: number_format( $ok ) . ' of ' . number_format( $total ) . ' settlement nodes healthy';
	$hStr     = $maxH > 0 ? 'block ' . number_format( $maxH ) : 'live';
	return '<div class="chainstatus chainstatus-' . $state . '" title="' . h( $tooltip ) . '">'
		. '<span class="dot"></span>'
		. '<span class="txt">Connected · <span class="mono">' . h( $hStr ) . '</span></span>'
		. '</div>';
}

function fiat_to_xmr( $total, $rate ) {
	$rate = (float) $rate;
	if ( $rate <= 0 ) { return '0'; }
	// round to 12 dp using string formatting to avoid a float tail.
	$xmr = number_format( (float) $total / $rate, XMR_DECIMALS, '.', '' );
	return pico_to_xmr( xmr_to_pico( $xmr ) );
}

/**
 * Human-friendly XMR amount for display on cards / product pages. Full 12-dp
 * precision is still used at checkout (`fiat_to_xmr`) — this only trims for
 * the eye. Strips trailing zeros but keeps at least 4 decimals.
 */
function fiat_to_xmr_display( $total, $rate, $dp = 4 ) {
	$rate = (float) $rate;
	if ( $rate <= 0 ) { return '0'; }
	$xmr = (float) $total / $rate;
	$s   = number_format( $xmr, max( 4, (int) $dp ), '.', '' );
	// trim trailing zeros beyond the 4th decimal
	if ( strpos( $s, '.' ) !== false ) {
		$s = rtrim( $s, '0' );
		$s = rtrim( $s, '.' );
		// pad back up to 4 dp if we over-trimmed
		if ( strpos( $s, '.' ) === false ) { $s .= '.0000'; }
		else {
			$dec = strlen( $s ) - strpos( $s, '.' ) - 1;
			if ( $dec < 4 ) { $s .= str_repeat( '0', 4 - $dec ); }
		}
	}
	return $s;
}

function money( $amount, $currency ) {
	return number_format( (float) $amount, 2 ) . ' ' . strtoupper( $currency );
}

function now() { return time(); }

/**
 * Editable site copy. Returns the admin-set value from kv, or $default.
 * Keys are defined in copy_defaults(); the console edits them.
 */
function copy_defaults() {
	return array(
		'tagline'       => 'Settled in Monero',
		'masthead_note' => 'Paid in XMR. No cards, no chargebacks.',
		'blurb_open'    => 'Prices are shown in {CUR}; the exact XMR amount is fixed when you check out. Send from any Monero wallet — the order settles once the payment confirms on-chain.',
		'blurb_closed'  => "Nothing is for sale right now — check back when the next batch opens.",
		'exchange_note' => "Send from a Monero wallet you control — not straight from an exchange account. "
			. "Exchange withdrawals are frequently held long past the quote window, and some send a short "
			. "amount after deducting their own fee — either one leaves the order unpaid. Withdraw to your "
			. "own wallet first, then pay from there.",
		'contact_email' => '',
		'footer'        => 'Payment is verified on-chain against a Monero node. Prices are locked to XMR at checkout for a limited window.',
	);
}

function store_name() {
	return (string) Config::get( 'store_name', 'XMR Shop' );
}

function site_copy( $key ) {
	static $cache = null;
	if ( null === $cache ) {
		$cache = array();
		foreach ( store()->all( "SELECT k, v FROM kv WHERE k LIKE 'copy:%'" ) as $r ) {
			$cache[ substr( $r['k'], 5 ) ] = $r['v'];
		}
	}
	$defs = copy_defaults();
	$val  = isset( $cache[ $key ] ) && '' !== trim( (string) $cache[ $key ] ) ? $cache[ $key ] : ( $defs[ $key ] ?? '' );
	return str_replace( '{CUR}', strtoupper( (string) Config::get( 'store_currency', 'usd' ) ), $val );
}

function site_copy_set( $key, $val ) {
	store()->q(
		'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
		array( 'copy:' . $key, (string) $val, now() )
	);
}

/* ---- Masthead banner -----------------------------------------------------
 * One wide image that sits BEHIND the header band — everything above the
 * hairline rule, full-bleed, not just the 980px column.
 *
 * WHY it isn't in copy_defaults(): that array is *words*, edited as textareas
 * and saved in one loop. A banner is a file plus a handful of typed knobs, so
 * it gets its own kv namespace ('banner:*') and its own console panel.
 */
function banner_defaults() {
	return array(
		'file'   => '',        // filename inside public/assets/banner/
		'fit'    => 'cover',   // cover | contain | stretch
		'pos'    => 'center',  // focal point (CSS background-position)
		'height' => '',        // px; blank = the header sizes to its own text
		'scrim'  => '15',      // 0-100 readability wash between image and type
		'text'   => 'dark',    // dark | light
	);
}

function banner_get( $key ) {
	static $cache = null;
	if ( null === $cache ) {
		$cache = array();
		foreach ( store()->all( "SELECT k, v FROM kv WHERE k LIKE 'banner:%'" ) as $r ) {
			$cache[ substr( $r['k'], 7 ) ] = $r['v'];
		}
	}
	$defs = banner_defaults();
	// note: '0' is a legitimate scrim value, so test against '' not falsiness.
	$raw = isset( $cache[ $key ] ) ? trim( (string) $cache[ $key ] ) : '';
	return '' !== $raw ? $raw : (string) ( $defs[ $key ] ?? '' );
}

function banner_set( $key, $val ) {
	store()->q(
		'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
		array( 'banner:' . $key, (string) $val, now() )
	);
}

function banner_dir() {
	return (string) Config::get( 'banner_dir', dirname( __DIR__ ) . '/public/assets/banner' );
}

/**
 * URL for the banner, cache-busted on mtime. '' = no banner.
 *
 * $base MUST yield a ROOT-ABSOLUTE path ('/assets/...' on the store,
 * '/public/assets/...' in the console).
 *
 * WHY, and do not "tidy" this back to a relative path:
 * this URL ends up inside a CSS *custom property*. A relative url() in a custom
 * property is resolved against the stylesheet in which the var() is SUBSTITUTED
 * — style.css — not against the document, even though the property is declared in
 * an inline style attribute. So 'assets/banner/x.jpg' became
 * '/assets/' + 'assets/banner/x.jpg' = /assets/assets/banner/x.jpg → 404, and the
 * banner silently did not render. Root-absolute has nothing to resolve.
 */
function banner_url( $base = '/' ) {
	$f = basename( (string) banner_get( 'file' ) );
	if ( '' === $f ) { return ''; }
	$path = banner_dir() . '/' . $f;
	if ( ! is_file( $path ) ) { return ''; }   // file vanished — behave as "no banner"
	return $base . 'assets/banner/' . rawurlencode( $f ) . '?v=' . (int) @filemtime( $path );
}

/** Pixel size of the stored banner, or null. Used by the console to warn on shape. */
function banner_size() {
	$f = basename( (string) banner_get( 'file' ) );
	if ( '' === $f ) { return null; }
	$i = @getimagesize( banner_dir() . '/' . $f );
	return $i ? array( 'w' => (int) $i[0], 'h' => (int) $i[1] ) : null;
}

/**
 * Custom properties for <header class="masthead">. '' = no banner, in which
 * case the header keeps its plain paper background and nothing changes.
 */
function banner_style( $base = '/' ) {
	$url = banner_url( $base );
	if ( '' === $url ) { return ''; }

	$fit  = banner_get( 'fit' );
	$size = 'contain' === $fit ? 'contain' : ( 'stretch' === $fit ? '100% 100%' : 'cover' );

	// whitelist the focal point rather than trusting it into a style attribute
	$ok  = array( 'center', 'top', 'bottom', 'left', 'right', 'left top', 'right top', 'left bottom', 'right bottom' );
	$pos = banner_get( 'pos' );
	if ( ! in_array( $pos, $ok, true ) ) { $pos = 'center'; }

	$scrim = max( 0, min( 100, (int) banner_get( 'scrim' ) ) ) / 100;
	$wash  = 'light' === banner_get( 'text' )
		? 'rgba(12,11,9,' . $scrim . ')'        // dark wash beneath light type
		: 'rgba(246,245,241,' . $scrim . ')';   // paper wash beneath dark type

	$css = "--banner-img:url('" . $url . "');"
		. '--banner-size:' . $size . ';'
		. '--banner-pos:' . $pos . ';'
		. '--banner-scrim:' . $wash . ';';

	$h = (int) banner_get( 'height' );
	if ( $h > 0 ) { $css .= '--banner-min-h:' . min( 800, $h ) . 'px;'; }

	return $css;
}

/**
 * Email the operator when an order is paid. Fire-and-forget: a mail failure
 * must never break settlement, so everything here is wrapped and non-fatal.
 */
function notify_order_paid( $order ) {
	$to = trim( (string) Config::get( 'notify_email', '' ) );
	if ( '' === $to ) { return; }

	$from = trim( (string) Config::get( 'notify_from', 'orders@localhost' ) );
	$cur  = strtoupper( (string) Config::get( 'store_currency', 'usd' ) );

	$xmr = isset( $order['received_pico'] ) ? pico_to_xmr( $order['received_pico'] ) : '?';
	$sub = sprintf( 'PAID — order #%d', (int) $order['id'] );

	// DATA MINIMISATION: buyer name / email / phone / address / txid are DELIBERATELY
	// not placed in this message. Mail leaves the box, lands in a third-party mailbox
	// forever, and bounces land in /var/mail/www-data where the webserver user can read
	// them. The console (Tailscale-only) is the ONE place ship-to data is exposed.
	// Do not "helpfully" add the address back in here.
	$body  = "An order has been paid and is ready to ship.\n\n";
	$body .= "Order : #" . (int) $order['id'] . "\n";
	$body .= "Item  : " . $order['product_name'] . " x" . (int) $order['qty'] . "\n";
	$osh = trim( (string) ( $order['product_subhead'] ?? '' ) );
	if ( '' !== $osh ) { $body .= "        " . $osh . "\n"; }
	$body .= "Price : " . $cur . " " . number_format( (float) $order['price_fiat'], 2 ) . "\n";
	$body .= "Paid  : " . $xmr . " XMR\n";
	$body .= "\nShip-to details are in the console — not emailed, by design.\n";
	$body .= "Mark it shipped in the console when it's in the post.\n";

	$headers = "From: " . store_name() . " <" . $from . ">\r\n"
		. "Reply-To: " . $from . "\r\n"
		. "X-Mailer: xmr-cart\r\n"
		. "Content-Type: text/plain; charset=UTF-8";

	// never let a mail problem take down settlement
	try {
		@mail( $to, $sub, $body, $headers, '-f' . $from );
	} catch ( \Throwable $e ) {
		error_log( 'notify_order_paid failed: order #' . (int) $order['id'] );
	}
}
