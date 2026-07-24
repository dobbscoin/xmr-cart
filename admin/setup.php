<?php
/**
 * First-run setup. If no admin_pass_hash is set (neither in the kv table nor
 * in config.php), inc.php redirects here from every other /admin/ path.
 *
 * On POST: writes a bcrypt passphrase hash into kv, and optionally the wallet
 * identity (primary_address + view_key) into kv too — via the kv-override
 * seam the Wallet tab also writes to. config.php stays operator-managed.
 *
 * Wallet fields are OPTIONAL here: an operator who hasn't generated their
 * wallet yet can proceed and set it later on the Wallet tab. If they DO paste
 * a pair, it's cryptographically verified (view-key ↔ address) before save —
 * a typo or wrong-key paste is rejected inline, not silently accepted.
 */
require_once __DIR__ . '/inc.php';

if ( Auth::passSet() ) { redirect( 'login.php' ); }

// ---- Preflight: PHP extension gate ----
// The scanner (Config::configured -> Xmr -> XmrPay_Scanner) needs bcmath for
// base58 decoding + gmp for RCT/ed25519 math. If they're missing, decode_address
// throws "undefined function bcdiv" internally and the setup wizard's crypto
// validator silently returns "bad checksum" — actively misleading. Refuse to
// render the form until deps are green.
$_missing = xmr()->missingExtensions();
if ( $_missing ) {
	$_pv    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	$_pkgs  = implode( ' ', array_map( function ( $e ) use ( $_pv ) { return "php{$_pv}-{$e}"; }, $_missing ) );

	console_head( 'First-run setup', xmr()->isReal() );
	?>
	<div class="panel2" style="max-width:620px;margin:6vh auto">
		<h2 style="margin-top:0;border:none">Install a few PHP extensions first</h2>
		<p style="font-size:14px">Before you set up your store, this server needs a couple of PHP extensions the payment scanner depends on. Once they're installed, refresh this page.</p>
		<div class="loud"><strong>Missing:</strong> <span class="mono"><?php echo h( implode( ', ', $_missing ) ); ?></span></div>

		<h3 style="font-family:var(--serif);color:#eceef1;font-size:15px;margin:18px 0 4px">Debian / Ubuntu</h3>
		<pre class="setup-code" style="background:#0e0f13;padding:10px;border-radius:6px;font-size:12px;overflow-x:auto"><?php echo h( "sudo apt install {$_pkgs}\nsudo systemctl reload php{$_pv}-fpm" ); ?></pre>

		<h3 style="font-family:var(--serif);color:#eceef1;font-size:15px;margin:18px 0 4px">RHEL / Rocky / Alma</h3>
		<pre class="setup-code" style="background:#0e0f13;padding:10px;border-radius:6px;font-size:12px;overflow-x:auto"><?php echo h( 'sudo dnf install ' . implode( ' ', array_map( function ( $e ) { return "php-{$e}"; }, $_missing ) ) . "\nsudo systemctl reload php-fpm" ); ?></pre>

		<p class="muted" style="font-size:12px;margin-top:16px">
			<strong>Why these?</strong> <span class="mono">bcmath</span> is used by the base58 address decoder;
			<span class="mono">gmp</span> is used for ed25519 point math + RingCT amount decoding.
			<span class="mono">mbstring</span> / <span class="mono">pdo_sqlite</span> / <span class="mono">curl</span>
			are used for I/O and text handling. All are standard packages in every mainstream distro.
		</p>

		<form method="get" style="margin-top:14px">
			<button class="cbtn go" type="submit">Recheck</button>
		</form>
	</div>
	<?php
	console_foot();
	exit;
}

/**
 * Insert one Demo batch + 3 example products so a first-time operator lands
 * on a clickable, browseable storefront instead of an empty catalog. Called
 * only from the setup wizard when the operator leaves the "Seed with example
 * products" box checked (which is the default).
 *
 * Idempotency: refuses to seed if any batches already exist. So a user who
 * resets the passphrase mid-life and re-runs setup won't accidentally get
 * DEMO products dumped into their real inventory.
 */
function xmrcart_seed_demo_products() {
	$s = store();
	$existing = (int) $s->one( 'SELECT COUNT(*) c FROM batches' )['c'];
	if ( $existing > 0 ) { return false; }
	$now = time();
	$s->q(
		'INSERT INTO batches(name, status, created_at, sort, description) VALUES(?,?,?,?,?)',
		array(
			'Demo',
			'live',
			$now,
			0,
			'Example batch created by the first-run wizard. Delete or hide it from the Catalog tab when you\'re ready to sell for real.',
		)
	);
	$batch_id = (int) $s->db->lastInsertId();
	$rows = array(
		array( 'DEMO — Sticker', 'Peel-and-stick vinyl.',        'One low-friction thing to test the buyer flow with.',        3.00,   500, 0 ),
		array( 'DEMO — Hat',     'Six-panel, embroidered logo.', 'Mid-range item to try a bigger checkout amount.',           25.00,  12,  1 ),
		array( 'DEMO — Coffee',  'Single-origin, 12oz bag.',     'Consumable good — good for testing stock decrement + fulfillment.', 18.00, 24, 2 ),
	);
	foreach ( $rows as $r ) {
		$s->q(
			'INSERT INTO products(batch_id, sku, name, subhead, description, image, price_fiat, stock, active, sort, created_at) VALUES(?,?,?,?,?,?,?,?,1,?,?)',
			array( $batch_id, '', $r[0], $r[1], $r[2], '', $r[3], $r[4], $r[5], $now )
		);
	}
	return true;
}

$err  = '';
$vals = array( 'address' => '', 'viewkey' => '', 'seed' => true );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	csrf_check();
	$pass    = (string) req( 'pass', '' );
	$confirm = (string) req( 'confirm', '' );
	$address = trim( (string) req( 'primary_address', '' ) );
	$viewkey = trim( (string) req( 'view_key', '' ) );
	$seed    = req( 'seed_demo', '' ) === '1';
	$vals    = array( 'address' => $address, 'viewkey' => $viewkey, 'seed' => $seed );

	if ( strlen( $pass ) < 12 ) {
		$err = 'Passphrase must be at least 12 characters.';
	} elseif ( $pass !== $confirm ) {
		$err = 'Passphrases did not match.';
	} elseif ( ( $address !== '' ) !== ( $viewkey !== '' ) ) {
		// One field filled but not the other — half a wallet is worse than none.
		$err = 'Fill in BOTH the primary address AND the private view key, or leave both blank.';
	} elseif ( $address !== '' ) {
		// Both fields present — cryptographically validate the pair.
		if ( strlen( $address ) !== 95 || $address[0] !== '4' ) {
			$err = 'Primary address must be 95 characters and start with 4.';
		} elseif ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $viewkey ) ) {
			$err = 'Private view key must be 64 hexadecimal characters.';
		} else {
			$check = xmr()->verifyKeysPair( $address, $viewkey );
			if ( empty( $check['address_valid'] ) ) {
				$err = 'That address doesn\'t decode as a valid Monero address (bad checksum?).';
			} elseif ( empty( $check['key_match'] ) ) {
				$err = 'That private view key doesn\'t belong to that address. Double-check you copied the SECRET view key (not the public one) from the wallet you meant.';
			}
		}
	}

	if ( $err === '' ) {
		Auth::setPassphrase( $pass );
		if ( $address !== '' ) {
			store()->kvSet( 'wallet_primary_address', $address );
			store()->kvSet( 'wallet_view_key',        strtolower( $viewkey ) );
		}
		if ( $seed ) { xmrcart_seed_demo_products(); }
		redirect( 'login.php' );
	}
}

console_head( 'First-run setup', xmr()->isReal() );
?>
<div class="panel2" style="max-width:560px;margin:6vh auto">
	<h2 style="margin-top:0;border:none">Welcome — first-run setup</h2>
	<p class="muted" style="font-size:13px">Set a passphrase for the admin console, and (optionally) point the store at a Monero wallet now so you can start taking payments immediately.</p>
	<?php if ( $err ) : ?><div class="loud"><?php echo h( $err ); ?></div><?php endif; ?>
	<form method="post" autocomplete="off">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="setup">

		<h3 style="font-family:var(--serif);color:#eceef1;font-size:15px;margin:18px 0 4px">1. Admin passphrase</h3>
		<div class="field"><label for="pass">Passphrase <span class="muted">(12+ characters)</span></label><input id="pass" name="pass" type="password" autofocus autocomplete="new-password" minlength="12" required></div>
		<div class="field"><label for="confirm">Confirm</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="12" required></div>

		<h3 style="font-family:var(--serif);color:#eceef1;font-size:15px;margin:22px 0 4px">2. Wallet identity <span class="muted" style="font-weight:normal;font-size:12px">(optional — set later on the Wallet tab if you skip)</span></h3>
		<p class="muted" style="font-size:12px;margin:0 0 8px">
			Where to find these values: open <span class="mono">monero-wallet-gui</span> → <strong>Settings</strong> → <strong>Info</strong> tab.
			Copy the <strong>Primary address</strong> (95 chars, starts with <span class="mono">4</span>) and the <strong>Secret view key</strong>
			(64 hex chars). Nothing here can spend your coins — only the private VIEW key is asked for, never the SPEND key.
		</p>
		<div class="field"><label for="primary_address">Primary address</label><input id="primary_address" name="primary_address" class="mono" style="font-size:12px" maxlength="95" value="<?php echo h( $vals['address'] ); ?>"></div>
		<div class="field"><label for="view_key">Secret view key</label><input id="view_key" name="view_key" class="mono" style="font-size:12px" maxlength="64" value="<?php echo h( $vals['viewkey'] ); ?>"></div>

		<h3 style="font-family:var(--serif);color:#eceef1;font-size:15px;margin:22px 0 4px">3. Example products <span class="muted" style="font-weight:normal;font-size:12px">(optional — you can add real inventory anytime)</span></h3>
		<label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;padding:8px 0">
			<input type="checkbox" name="seed_demo" value="1" <?php echo ! empty( $vals['seed'] ) ? 'checked' : ''; ?> style="margin-top:3px">
			<span>Seed the catalog with three <strong>DEMO</strong> products (Sticker, Hat, Coffee) so you can walk through the buyer flow immediately. Delete or edit them from the Catalog tab whenever you're ready.</span>
		</label>

		<button class="cbtn go" type="submit" style="margin-top:10px">Save &amp; continue</button>
	</form>
	<p class="muted" style="font-size:12px;margin-top:14px">The passphrase hash is stored in the store's SQLite kv table, not <span class="mono">config.php</span>. To reset it, edit the database or run <span class="mono">DELETE FROM kv WHERE k='admin_pass_hash';</span> from the shell.</p>
</div>
<?php console_foot();
