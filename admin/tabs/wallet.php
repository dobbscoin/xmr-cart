<?php
/**
 * Wallet tab — the store's Monero identity (primary address + private view key).
 *
 * Reads via Config::primaryAddress() / Config::viewKey() so the kv override
 * (set here or by the first-run wizard) wins over config.php.
 *
 * Elevated: any save requires re-entering the admin passphrase. Wallet identity
 * is the one edit that lets an admin-compromise silently redirect real payments,
 * so a fresh confirmation is worth the 5 seconds.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }

$_address = Config::primaryAddress();
$_viewkey = Config::viewKey();
$_source  = Config::walletSource();
$_status  = xmr()->verifyKeys();

$_err     = (string) req( 'err', '' );
$_saved   = req( 'saved', '' ) !== '';
$_revert  = req( 'reverted', '' ) !== '';

$_errmsg = array(
	'wrong_pass'  => 'That passphrase is wrong. Wallet not changed.',
	'missing'     => 'Both the primary address AND the private view key are required.',
	'bad_address' => 'That address is not a valid Monero primary address (must be 95 chars, start with 4, and pass checksum).',
	'bad_viewkey' => 'The private view key must be exactly 64 hexadecimal characters.',
	'mismatch'    => 'That private view key does not belong to that address. Double-check you copied the SECRET view key (not the public one) from the wallet you meant.',
);
?>
<h2 style="margin-top:0">Wallet identity <span class="muted" style="font-size:13px">&mdash; the address buyer payments derive from</span></h2>

<?php if ( $_saved ) : ?>
	<div class="loud" style="background:#0e2a12;border-color:#295b34;color:#78c86f"><strong>Saved.</strong> The store is now pointing at the new address. All new orders derive subaddresses from it.</div>
<?php elseif ( $_revert ) : ?>
	<div class="loud" style="background:#0e2a12;border-color:#295b34;color:#78c86f"><strong>Reverted.</strong> The wallet identity now falls back to whatever's in <span class="mono">config.php</span>.</div>
<?php elseif ( $_err && isset( $_errmsg[ $_err ] ) ) : ?>
	<div class="loud"><?php echo h( $_errmsg[ $_err ] ); ?></div>
<?php endif; ?>

<div class="grid2" style="margin-bottom:14px">
	<div>
		<div class="muted" style="font-size:12px">Current wallet identity</div>
		<div class="mono" style="font-size:22px;color:<?php
			echo empty( $_status['address_valid'] ) ? '#e26a6a'
				: ( empty( $_status['key_match'] ) ? '#ff9b40' : '#78c86f' );
		?>">
			<?php
			if ( empty( $_status['address_valid'] ) ) { echo 'not configured'; }
			elseif ( empty( $_status['key_match'] ) )  { echo 'key mismatch'; }
			else                                       { echo 'valid'; }
			?>
		</div>
		<div class="muted" style="font-size:11px">source: <span class="mono"><?php echo h( $_source ); ?></span></div>
	</div>
	<div>
		<div class="muted" style="font-size:12px">How this is used</div>
		<div style="font-size:13px;margin-top:2px;color:#eceef1">Every order gets a fresh <em>subaddress</em> derived from this primary address + your private view key. Buyer sends coin to their subaddress; the scanner watches for it. Only the SECRET view key is stored here &mdash; the spend key never touches this box.</div>
	</div>
</div>

<div class="panel2" style="margin-top:10px">
	<h3 style="margin:0 0 8px;font-family:var(--serif);color:#eceef1;font-size:16px">Current values</h3>
	<?php if ( $_address !== '' ) : ?>
		<div class="field">
			<label>Primary address</label>
			<div class="mono" style="font-size:12px;background:#0e0f13;padding:6px 8px;border-radius:4px;word-break:break-all"><?php echo h( $_address ); ?></div>
		</div>
		<div class="field">
			<label>Private view key <button type="button" class="cbtn" onclick="var v=document.getElementById('_vkbox');v.textContent = v.dataset.on==='1' ? v.dataset.masked : v.dataset.real; v.dataset.on = v.dataset.on==='1' ? '0' : '1'; this.textContent = v.dataset.on==='1' ? 'hide' : 'reveal'" style="font-size:11px;margin-left:8px">reveal</button></label>
			<div id="_vkbox" class="mono" data-on="0"
				data-masked="<?php echo h( substr( $_viewkey, 0, 4 ) . str_repeat( '•', max( 0, strlen( $_viewkey ) - 8 ) ) . substr( $_viewkey, -4 ) ); ?>"
				data-real="<?php echo h( $_viewkey ); ?>"
				style="font-size:12px;background:#0e0f13;padding:6px 8px;border-radius:4px;word-break:break-all"><?php
					echo h( substr( $_viewkey, 0, 4 ) . str_repeat( '•', max( 0, strlen( $_viewkey ) - 8 ) ) . substr( $_viewkey, -4 ) );
				?></div>
		</div>
	<?php else : ?>
		<p class="muted">Wallet identity is not configured. Fill in the form below to set it.</p>
	<?php endif; ?>
</div>

<div class="panel2" style="margin-top:14px">
	<h3 style="margin:0 0 8px;font-family:var(--serif);color:#eceef1;font-size:16px"><?php echo $_address !== '' ? 'Change wallet identity' : 'Set wallet identity'; ?></h3>
	<p class="muted" style="font-size:12px;margin:0 0 10px">
		Where to find these: <span class="mono">monero-wallet-gui</span> → <strong>Settings</strong> → <strong>Info</strong> tab. Copy the <strong>Primary address</strong> + <strong>Secret view key</strong>. The pair is cryptographically verified before save &mdash; a typo or wrong-wallet paste is rejected inline.
	</p>
	<form method="post" action="actions.php" autocomplete="off" onsubmit="return confirm('Change the store wallet? All FUTURE orders will derive subaddresses from the new address. In-flight orders keep their existing subaddresses.')">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="wallet_save">
		<div class="field">
			<label for="primary_address">Primary address</label>
			<input id="primary_address" name="primary_address" class="mono" style="font-size:12px" maxlength="95" value="" placeholder="4… (95 chars)" required>
		</div>
		<div class="field">
			<label for="view_key">Secret view key</label>
			<input id="view_key" name="view_key" class="mono" style="font-size:12px" maxlength="64" value="" placeholder="64 hex chars" required>
		</div>
		<div class="field">
			<label for="confirm_pass">Confirm with admin passphrase</label>
			<input id="confirm_pass" name="confirm_pass" type="password" autocomplete="current-password" required>
		</div>
		<button class="cbtn go" type="submit">Save wallet identity</button>
	</form>
</div>

<?php if ( 'console' === $_source ) : ?>
<div class="panel2" style="margin-top:14px">
	<h3 style="margin:0 0 8px;font-family:var(--serif);color:#eceef1;font-size:16px">Revert to <span class="mono">config.php</span></h3>
	<p class="muted" style="font-size:12px;margin:0 0 10px">Drops the console override. The store falls back to whatever <span class="mono">primary_address</span> + <span class="mono">view_key</span> are in <span class="mono">config.php</span>. Reverting requires the passphrase.</p>
	<form method="post" action="actions.php" onsubmit="return confirm('Drop the console-set wallet identity and fall back to config.php values?')">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="wallet_revert">
		<div class="field">
			<label for="revert_pass">Confirm with admin passphrase</label>
			<input id="revert_pass" name="confirm_pass" type="password" autocomplete="current-password" required>
		</div>
		<button class="cbtn warn" type="submit">Revert to config.php</button>
	</form>
</div>
<?php endif; ?>
