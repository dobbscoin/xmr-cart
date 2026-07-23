<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// NOTE: the real gate is nginx binding this directory to the tailnet (see
// nginx.conf.example). Auth here is defense-in-depth.
if ( basename( $_SERVER['SCRIPT_NAME'] ) !== 'login.php' ) {
	Auth::require_admin();
}

function console_head( $title, $envReal ) {
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . h( $title ) . ' · ' . h( store_name() ) . ' console</title>';
	echo '<link rel="icon" href="/assets/brand/favicon-32.png" type="image/png">';
	echo '<link rel="stylesheet" href="/assets/style.css"></head><body class="console">';
	echo '<div class="bar"><span class="t">' . h( store_name() ) . ' console</span>';
	echo '<span class="env' . ( $envReal ? '' : ' demo' ) . '">' . ( $envReal ? 'LIVE NODE' : 'DEMO MODE' ) . '</span>';
	echo '<span class="spacer"></span>';
	$storeUrl = rtrim( (string) Config::get( 'store_url', '/' ), '/' ) . '/';
	echo '<a class="cbtn" href="' . h( $storeUrl ) . '" target="_blank" rel="noopener">View store ↗</a> ';
	echo '<a class="cbtn" href="logout.php">Sign out</a></div>';
	echo '<div class="wrap2">';
}
function console_foot() { echo '</div></body></html>'; }

function post_is( $action ) {
	return $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['action'] ?? '' ) === $action;
}
