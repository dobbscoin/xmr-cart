<?php
/**
 * First-run setup. If no admin_pass_hash is set (neither in the kv table nor
 * in config.php), inc.php redirects here from every other /admin/ path.
 *
 * On POST: writes a bcrypt hash into the kv table (not config.php — that file
 * stays operator-managed). On next load, Auth::passSet() returns true and this
 * page redirects to login.php.
 */
require_once __DIR__ . '/inc.php';

if ( Auth::passSet() ) { redirect( 'login.php' ); }

$err = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	csrf_check();
	$pass    = (string) req( 'pass', '' );
	$confirm = (string) req( 'confirm', '' );
	if ( strlen( $pass ) < 12 ) {
		$err = 'Passphrase must be at least 12 characters.';
	} elseif ( $pass !== $confirm ) {
		$err = 'Passphrases did not match.';
	} else {
		Auth::setPassphrase( $pass );
		redirect( 'login.php' );
	}
}

console_head( 'First-run setup', xmr()->isReal() );
?>
<div class="panel2" style="max-width:520px;margin:8vh auto">
	<h2 style="margin-top:0;border:none">Set your admin passphrase</h2>
	<p class="muted" style="font-size:13px">First time this console has been opened. Pick a strong passphrase — the console is reachable from the open internet by default. If you'd rather lock it down at the web-server layer too, see the README.</p>
	<?php if ( $err ) : ?><div class="loud"><?php echo h( $err ); ?></div><?php endif; ?>
	<form method="post" autocomplete="off">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="setup">
		<div class="field"><label for="pass">Passphrase <span class="muted">(12+ characters)</span></label><input id="pass" name="pass" type="password" autofocus autocomplete="new-password" minlength="12" required></div>
		<div class="field"><label for="confirm">Confirm</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="12" required></div>
		<button class="cbtn go" type="submit">Set passphrase &amp; continue</button>
	</form>
	<p class="muted" style="font-size:12px;margin-top:14px">The hash is stored in the store's SQLite kv table, not <span class="mono">config.php</span>. To reset it, edit the database or run <span class="mono">DELETE FROM kv WHERE k='admin_pass_hash';</span> from the shell.</p>
</div>
<?php console_foot();
