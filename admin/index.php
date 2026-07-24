<?php
/**
 * Admin console router. All state is set up by _bootstrap.php; each tab file
 * renders its own content. Tab is chosen via ?tab=; ?edit or ?batch in the
 * URL implies catalog for backward-compat with old bookmarked links.
 */
define( 'XMRCART_ADMIN', true );
require_once __DIR__ . '/_bootstrap.php';

$tab = strtolower( trim( (string) req( 'tab', '' ) ) );
if ( $tab === '' ) {
	if ( req( 'edit', '' ) !== '' || req( 'batch', '' ) !== '' ) { $tab = 'catalog'; }
	else { $tab = 'orders'; }
}
$valid = array( 'orders', 'catalog', 'storefront', 'nodes', 'wallet' );
if ( ! in_array( $tab, $valid, true ) ) { $tab = 'orders'; }

// Tab counts for badges
$_pendingCount = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status IN ('pending','confirming')" )['c'];
$_shipCount    = (int) $store->one( "SELECT COUNT(*) c FROM orders WHERE status='paid'" )['c'];
$_orderBadge   = max( $_pendingCount, $_shipCount );

$_labels = array(
	'orders'     => 'Orders',
	'catalog'    => 'Catalog',
	'storefront' => 'Storefront',
	'nodes'      => 'Nodes',
	'wallet'     => 'Wallet',
);
?>
<nav class="tabs">
	<?php foreach ( $_labels as $_slug => $_label ) : ?>
		<a href="?tab=<?php echo $_slug; ?>" class="<?php echo $tab === $_slug ? 'active' : ''; ?>">
			<?php echo h( $_label ); ?><?php
			if ( 'orders' === $_slug && $_orderBadge > 0 ) {
				echo ' <span class="badge">' . (int) $_orderBadge . '</span>';
			}
			?>
		</a>
	<?php endforeach; ?>
</nav>

<?php require __DIR__ . '/tabs/' . $tab . '.php'; ?>

<?php console_foot();
