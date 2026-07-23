<?php
/**
 * Nodes tab — health of the settlement-node fleet. Read-only for now;
 * editing the list still means editing config.php.
 */
if ( ! defined( 'XMRCART_ADMIN' ) ) { die( 'no direct access' ); }

$_probe   = nodeprobe()->results();
$_page    = $_probe['results'] ?? array();
$_probeAt = $_probe['probed_at'] ?? 0;
$_pAge    = nodeprobe()->cacheAge();
// max healthy height so we can flag laggers relative to the front-of-pack
$_maxH = 0;
foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) && (int) $_n['height'] > $_maxH ) { $_maxH = (int) $_n['height']; } }
$_okCount = 0; foreach ( $_page as $_n ) { if ( ! empty( $_n['ok'] ) ) { $_okCount++; } }
?>
<h2 style="margin-top:0">Settlement nodes <span class="muted" style="font-size:13px">&mdash; the Monero node fleet the scanner falls over across</span></h2>

<div class="grid2" style="margin-bottom:14px">
	<div>
		<div class="muted" style="font-size:12px">Configured nodes</div>
		<div class="mono" style="font-size:22px;color:<?php echo $_okCount === 0 ? '#e26a6a' : ( $_okCount < count( $_page ) ? '#ff9b40' : '#78c86f' ); ?>">
			<?php echo (int) $_okCount; ?> / <?php echo count( $_page ); ?> healthy
		</div>
		<div class="muted" style="font-size:11px">
			<?php
			if ( null === $_pAge ) { echo 'never probed'; }
			else { echo 'probed ' . (int) $_pAge . 's ago (auto-refresh every ' . NodeProbe::TTL . 's)'; }
			?>
		</div>
		<form method="post" action="actions.php" style="margin-top:6px">
			<?php echo csrf_field(); ?>
			<input type="hidden" name="action" value="probe_nodes_refresh">
			<button class="cbtn">Test all now</button>
		</form>
	</div>
	<div>
		<div class="muted" style="font-size:12px">How failover works</div>
		<div style="font-size:13px;margin-top:2px;color:#eceef1">Scanner tries each node in order until one answers. <span class="mono">tipHeight</span> is the MIN across responders, so a laggard can only delay settlement, never bring it forward.</div>
		<div class="muted" style="font-size:11px;margin-top:4px">
			<strong>Pruned nodes fail-CLOSED</strong> at the commitment check &mdash; a payment is identified but not settled. Node list must be un-pruned full nodes only. Edit <span class="mono">nodes</span> in <span class="mono">config.php</span>.
		</div>
	</div>
</div>

<?php if ( ! $_page ) : ?>
	<p class="muted">No nodes configured.</p>
<?php else : ?>
	<table>
		<thead><tr><th>#</th><th>URL</th><th>Status</th><th>Height</th><th>Flags</th><th>Latency</th></tr></thead>
		<tbody>
		<?php foreach ( $_page as $_i => $_n ) :
			$_isPrimary = ( 0 === $_i );
			$_h = (int) ( $_n['height'] ?? 0 );
			$_behind = ( $_maxH > 0 && $_h > 0 ) ? ( $_maxH - $_h ) : null;
		?>
			<tr>
				<td class="mono"><?php echo $_i + 1; ?><?php if ( $_isPrimary ) : ?> <span style="color:#ff9b40" title="primary">◆</span><?php endif; ?></td>
				<td class="mono" style="font-size:11px;word-break:break-all"><?php echo h( $_n['url'] ); ?></td>
				<td>
					<?php if ( ! empty( $_n['ok'] ) ) : ?>
						<span class="mono" style="background:#0e2a12;color:#78c86f;padding:1px 6px;border-radius:3px;font-size:11px">OK</span>
					<?php else : ?>
						<span class="mono" style="background:#2a0f0f;color:#e26a6a;padding:1px 6px;border-radius:3px;font-size:11px">FAIL</span>
						<?php if ( ! empty( $_n['error'] ) ) : ?><div class="muted" style="font-size:11px"><?php echo h( $_n['error'] ); ?></div><?php endif; ?>
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
				</td>
				<td class="mono muted" style="font-size:11px"><?php echo isset( $_n['elapsed_ms'] ) ? (int) $_n['elapsed_ms'] . 'ms' : '&mdash;'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
