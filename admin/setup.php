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

$err  = '';
$vals = array( 'address' => '', 'viewkey' => '' );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	csrf_check();
	$pass    = (string) req( 'pass', '' );
	$confirm = (string) req( 'confirm', '' );
	$address = trim( (string) req( 'primary_address', '' ) );
	$viewkey = trim( (string) req( 'view_key', '' ) );
	$vals    = array( 'address' => $address, 'viewkey' => $viewkey );

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

		<button class="cbtn go" type="submit" style="margin-top:10px">Save &amp; continue</button>
	</form>
	<p class="muted" style="font-size:12px;margin-top:14px">The passphrase hash is stored in the store's SQLite kv table, not <span class="mono">config.php</span>. To reset it, edit the database or run <span class="mono">DELETE FROM kv WHERE k='admin_pass_hash';</span> from the shell.</p>
</div>
<?php console_foot();
