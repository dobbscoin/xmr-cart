<?php
require_once __DIR__ . '/inc.php';

if ( Auth::loggedIn() ) { redirect( 'index.php' ); }

$err = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	csrf_check();
	if ( Auth::login( (string) req( 'pass', '' ) ) ) { redirect( 'index.php' ); }
	$err = 'Incorrect passphrase.';
}

console_head( 'Sign in', xmr()->isReal() );
?>
<div class="panel2" style="max-width:420px;margin:8vh auto">
	<h2 style="margin-top:0;border:none">Console access</h2>
	<p class="muted" style="font-size:13px">This console should already be limited to your private network. The passphrase is a second lock.</p>
	<?php if ( $err ) : ?><div class="loud"><?php echo h( $err ); ?></div><?php endif; ?>
	<form method="post">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="login">
		<div class="field"><label for="pass">Passphrase</label><input id="pass" name="pass" type="password" autofocus></div>
		<button class="cbtn go" type="submit">Sign in</button>
	</form>
</div>
<?php console_foot();
