<?php
/**
 * Nodes tab — settlement-node fleet management. Add / edit / remove / reorder
 * from the console; changes land in kv['nodes_override'] and take precedence
 * over config.php['nodes']. Health probe re-runs after every edit.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }

$_probe   = nodeprobe()->results();
$_page    = $_probe['results'] ?? array();
$_pAge    = nodeprobe()->cacheAge();
$_config  = Config::nodesArray();
// max healthy height so we can flag laggers relative to the front-of-pack
$_maxH = 0;
foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) && (int) $_n['height'] > $_maxH ) { $_maxH = (int) $_n['height']; } }
$_okCount = 0; foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) ) { $_okCount++; } }

// Health rows are keyed by URL — merge with the config-ordered list so the
// table shows every configured node whether or not it's been probed yet.
$_health = array();
foreach ( $_page as $_n ) { $_health[ (string) $_n['url'] ] = $_n; }

// Is the current list an override (kv) or the config.php default?
$_kv     = store()->kvGet( 'nodes_override' );
$_source = ( $_kv && trim( (string) $_kv['v'] ) !== '' ) ? 'console' : 'config.php';
?>
<h2 style="margin-top:0">Settlement nodes <span class="muted" style="font-size:13px">&mdash; the Monero node fleet the scanner falls over across</span></h2>

<div class="grid2" style="margin-bottom:14px">
	<div>
		<div class="muted" style="font-size:12px">Configured nodes</div>
		<div class="mono" style="font-size:22px;color:<?php echo $_okCount === 0 ? '#e26a6a' : ( $_okCount < count( $_config ) ? '#ff9b40' : '#78c86f' ); ?>">
			<?php echo (int) $_okCount; ?> / <?php echo count( $_config ); ?> healthy
		</div>
		<div class="muted" style="font-size:11px">
			<?php
			if ( null === $_pAge ) { echo 'never probed'; }
			else { echo 'probed ' . (int) $_pAge . 's ago (auto-refresh every ' . NodeProbe::TTL . 's)'; }
			?>
			· source: <span class="mono"><?php echo h( $_source ); ?></span>
		</div>
		<form method="post" action="actions.php" style="margin-top:6px;display:inline">
			<?php echo csrf_field(); ?>
			<input type="hidden" name="action" value="probe_nodes_refresh">
			<button class="cbtn">Test all now</button>
		</form>
		<?php if ( 'console' === $_source ) : ?>
			<form method="post" action="actions.php" style="margin-top:6px;display:inline" onsubmit="return confirm('Revert to the node list in config.php? Any console edits will be lost.')">
				<?php echo csrf_field(); ?>
				<input type="hidden" name="action" value="nodes_revert">
				<button class="cbtn warn" title="Wipe kv['nodes_override'] and fall back to config.php['nodes']">Revert to config.php</button>
			</form>
		<?php endif; ?>
	</div>
	<div>
		<div class="muted" style="font-size:12px">How failover works</div>
		<div style="font-size:13px;margin-top:2px;color:#eceef1">Scanner tries each node in order until one answers. <span class="mono">tipHeight</span> is the MIN across responders, so a laggard can only delay settlement, never bring it forward.</div>
		<div class="muted" style="font-size:11px;margin-top:4px">
			<strong>Pruned nodes fail-CLOSED</strong> at the commitment check &mdash; a payment is identified but not settled. Node list must be un-pruned full nodes only.
		</div>
	</div>
</div>

<?php if ( ! $_config ) : ?>
	<p class="muted">No nodes configured. Add one below.</p>
<?php else : ?>
	<table>
		<thead><tr><th style="width:36px">#</th><th>URL</th><th>Status</th><th>Height</th><th>Flags</th><th style="width:280px">Actions</th></tr></thead>
		<tbody>
		<?php foreach ( $_config as $_i => $_url ) :
			$_n = $_health[ $_url ] ?? array();
			$_h = (int) ( $_n['height'] ?? 0 );
			$_behind = ( $_maxH > 0 && $_h > 0 ) ? ( $_maxH - $_h ) : null;
			$_isPrimary = ( 0 === $_i );
		?>
			<tr>
				<td class="mono"><?php echo $_i + 1; ?><?php if ( $_isPrimary ) : ?> <span style="color:#ff9b40" title="primary">◆</span><?php endif; ?></td>
				<td>
					<form class="inline" method="post" action="actions.php" style="display:flex;gap:4px;align-items:center">
						<?php echo csrf_field(); ?>
						<input type="hidden" name="action" value="node_edit">
						<input type="hidden" name="idx" value="<?php echo (int) $_i; ?>">
						<input name="url" value="<?php echo h( $_url ); ?>" class="mono" style="flex:1;min-width:260px;font-size:12px" required>
						<button class="cbtn" title="Save URL">✎</button>
					</form>
				</td>
				<td>
					<?php if ( ! empty( $_n['ok'] ) ) : ?>
						<span class="mono" style="background:#0e2a12;color:#78c86f;padding:1px 6px;border-radius:3px;font-size:11px">OK</span>
					<?php elseif ( isset( $_n['ok'] ) ) : ?>
						<span class="mono" style="background:#2a0f0f;color:#e26a6a;padding:1px 6px;border-radius:3px;font-size:11px">FAIL</span>
						<?php if ( ! empty( $_n['error'] ) ) : ?><div class="muted" style="font-size:11px"><?php echo h( $_n['error'] ); ?></div><?php endif; ?>
					<?php else : ?>
						<span class="muted mono" style="font-size:11px">not probed</span>
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
					<?php if ( isset( $_n['elapsed_ms'] ) ) : ?><div class="muted mono" style="font-size:11px"><?php echo (int) $_n['elapsed_ms']; ?>ms</div><?php endif; ?>
				</td>
				<td style="white-space:nowrap">
					<?php if ( $_i > 0 ) : ?>
					<form class="inline" method="post" action="actions.php">
						<?php echo csrf_field(); ?>
						<input type="hidden" name="action" value="node_move">
						<input type="hidden" name="idx" value="<?php echo (int) $_i; ?>">
						<input type="hidden" name="dir" value="up">
						<button class="cbtn" title="Move up">↑</button>
					</form>
					<?php endif; ?>
					<?php if ( $_i < count( $_config ) - 1 ) : ?>
					<form class="inline" method="post" action="actions.php">
						<?php echo csrf_field(); ?>
						<input type="hidden" name="action" value="node_move">
						<input type="hidden" name="idx" value="<?php echo (int) $_i; ?>">
						<input type="hidden" name="dir" value="down">
						<button class="cbtn" title="Move down">↓</button>
					</form>
					<?php endif; ?>
					<form class="inline" method="post" action="actions.php" onsubmit="return confirm('Remove <?php echo h( $_url ); ?> from the node list?')">
						<?php echo csrf_field(); ?>
						<input type="hidden" name="action" value="node_remove">
						<input type="hidden" name="idx" value="<?php echo (int) $_i; ?>">
						<button class="cbtn warn" title="Remove">−</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<div class="panel2" style="margin-top:18px">
	<h3 style="margin:0 0 8px;font-family:var(--serif);color:#eceef1;font-size:16px">Add a node</h3>
	<form method="post" action="actions.php" style="display:flex;gap:6px;align-items:center">
		<?php echo csrf_field(); ?>
		<input type="hidden" name="action" value="node_add">
		<input name="url" placeholder="http://your-node.local:18081" class="mono" style="flex:1;font-size:13px" required pattern="https?://.+">
		<button class="cbtn go">+ Add node</button>
	</form>
	<span class="hint">
		Order matters — scanner tries in listed order. First responder wins.
		Full nodes only (no <span class="mono">--prune-blockchain</span>).
	</span>
</div>

<details class="setup-guide" style="margin-top:18px">
	<summary>Setting up your own <code>monerod</code></summary>
	<div class="setup-body">
		<p class="muted" style="margin-top:8px">
			Running your own primary is a privacy + reliability upgrade — the node operator
			you're querying sees which subaddresses your buyers pay. Full copy is in the README;
			the essentials are below.
		</p>

		<h4>1. Install</h4>
<pre class="setup-code" data-copy>sudo apt install monero</pre>
		<p class="muted" style="font-size:12px;margin-top:2px">Or download the current release from <a href="https://www.getmonero.org/downloads/" target="_blank" rel="noopener">getmonero.org/downloads</a>.</p>

		<h4>2. Minimum startup (same-box: store and node on the same machine)</h4>
<pre class="setup-code" data-copy>monerod \
  --data-dir /var/lib/monero \
  --rpc-bind-ip 127.0.0.1 \
  --rpc-bind-port 18081 \
  --non-interactive \
  --log-file /var/log/monero/monerod.log \
  --log-level 0</pre>

		<h4>3. Systemd unit</h4>
		<p class="muted" style="font-size:12px;margin:-2px 0 4px">Drop at <span class="mono">/etc/systemd/system/monerod.service</span>:</p>
<pre class="setup-code" data-copy>[Unit]
Description=Monero full node
After=network-online.target
Wants=network-online.target

[Service]
User=monero
Group=monero
ExecStart=/usr/bin/monerod \
  --data-dir /var/lib/monero \
  --rpc-bind-ip 127.0.0.1 \
  --rpc-bind-port 18081 \
  --non-interactive \
  --log-file /var/log/monero/monerod.log
Restart=on-failure
RestartSec=30
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target</pre>

		<h4>4. Enable + start</h4>
<pre class="setup-code" data-copy>sudo useradd -r -s /usr/sbin/nologin monero || true
sudo mkdir -p /var/lib/monero /var/log/monero
sudo chown monero:monero /var/lib/monero /var/log/monero
sudo systemctl daemon-reload
sudo systemctl enable --now monerod</pre>

		<p style="margin-top:14px">
			Point your primary node URL (top of the list above) at
			<span class="mono">http://127.0.0.1:18081</span>. First sync is 8–48h on a decent VPS;
			the store keeps working during it and orders just won't settle against blocks the
			node hasn't seen yet — the chain-status pill will say "syncing".
		</p>

		<h4 style="color:#e26a6a">Flags NOT to pass</h4>
		<table style="margin-top:6px">
			<thead><tr><th style="width:38%">flag</th><th>why not</th></tr></thead>
			<tbody>
				<tr>
					<td class="mono">--prune-blockchain</td>
					<td>pruned nodes can't serve commitments → the scanner fails <strong>CLOSED</strong> → nothing settles.</td>
				</tr>
				<tr>
					<td class="mono">--restricted-rpc</td>
					<td>may block <span class="mono">get_transactions</span> with <span class="mono">rct=true</span> and <span class="mono">get_outs</span>, which the scanner needs. If you're also serving public queries, put restricted on a <em>separately</em> bound port.</td>
				</tr>
				<tr>
					<td class="mono">--limit-rate-* (low)</td>
					<td>scanner does bursty queries at settlement time. Strangling the RPC just delays orders.</td>
				</tr>
			</tbody>
		</table>
	</div>
</details>

<script>
(function(){
	document.querySelectorAll('.setup-code[data-copy]').forEach(function(pre){
		var btn = document.createElement('button');
		btn.textContent = 'copy';
		btn.className = 'copy-btn';
		btn.type = 'button';
		btn.addEventListener('click', function(){
			navigator.clipboard.writeText(pre.textContent.trim()).then(function(){
				var was = btn.textContent;
				btn.textContent = 'copied';
				btn.classList.add('copied');
				setTimeout(function(){ btn.textContent = was; btn.classList.remove('copied'); }, 1200);
			});
		});
		pre.appendChild(btn);
	});
})();
</script>
