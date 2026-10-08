<?php
/**
 * xmr-cart installer — writes config.php and gets the box ready.
 *
 *   php install.php          (or ./install.sh)
 *
 * Asks for the store's name, URL, admin email (and whether every notification
 * goes there), mail sender, network and node. Generates cookie_secret and
 * setup_key, writes config.php from config.example.php, creates the writable
 * dirs, and prints the web-server, cron and first-login steps for this path.
 * The wallet (address + view key) is entered on the /admin/ setup page, where
 * it is checked cryptographically before it is saved.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit( "Run this from a shell: php install.php\n" ); }

$ROOT = __DIR__;
$tty  = function_exists( 'posix_isatty' ) ? posix_isatty( STDOUT ) : true;
function c( $code, $s ) { global $tty; return $tty ? "\033[{$code}m{$s}\033[0m" : $s; }
function say( $s = '' ) { echo $s, "\n"; }
function head( $s ) { say(); say( c( '1;33', '== ' . $s ) ); }
function ask( $q, $default = '', $validate = null ) {
	while ( true ) {
		echo c( '1', $q ) . ( '' !== $default ? ' [' . $default . ']' : '' ) . ': ';
		$line = fgets( STDIN );
		if ( false === $line ) { say(); exit( "Input ended; nothing written.\n" ); }
		$a = trim( $line );
		if ( '' === $a ) { $a = $default; }
		if ( null === $validate ) { return $a; }
		$err = $validate( $a );
		if ( true === $err ) { return $a; }
		say( c( '31', '  ' . $err ) );
	}
}
function yes( $q, $default = true ) {
	$a = strtolower( ask( $q . ' ' . ( $default ? '[Y/n]' : '[y/N]' ), '' ) );
	return '' === $a ? $default : in_array( $a, array( 'y', 'yes' ), true );
}
$isEmail = function ( $a ) { return filter_var( $a, FILTER_VALIDATE_EMAIL ) ? true : 'That does not look like an email address.'; };
$isEmailOrBlank = function ( $a ) use ( $isEmail ) { return '' === $a ? true : $isEmail( $a ); };

say( c( '1;38;5;208', "xmr-cart installer" ) . "  —  " . $ROOT );

// ---- preflight -------------------------------------------------------------------
head( 'Checking PHP' );
$ok = version_compare( PHP_VERSION, '8.0', '>=' );
say( ( $ok ? c( '32', 'ok ' ) : c( '31', 'NO ' ) ) . ' PHP ' . PHP_VERSION . ( $ok ? '' : ' (8.0+ required)' ) );
$need = array( 'pdo_sqlite', 'curl', 'gd', 'bcmath', 'gmp', 'mbstring' );
$missing = array_values( array_filter( $need, function ( $e ) { return ! extension_loaded( $e ); } ) );
foreach ( $need as $e ) { say( ( in_array( $e, $missing, true ) ? c( '31', 'NO ' ) : c( '32', 'ok ' ) ) . " $e" ); }
if ( $missing ) {
	$v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	say();
	say( 'Missing extensions. On Debian/Ubuntu:' );
	say( '  sudo apt install ' . implode( ' ', array_map( function ( $e ) use ( $v ) { return 'php' . $v . '-' . str_replace( 'pdo_', '', $e ); }, $missing ) ) );
	if ( ! yes( 'Continue anyway (the store will not run until they are installed)?', false ) ) { exit( 1 ); }
}
if ( ! $ok ) { exit( "PHP 8.0 or newer is required.\n" ); }

$cfg = $ROOT . '/config.php';
if ( file_exists( $cfg ) ) {
	head( 'config.php already exists' );
	if ( ! yes( 'Back it up and write a new one?', false ) ) { say( 'Nothing changed.' ); exit( 0 ); }
	$bak = $cfg . '.bak-' . date( 'Ymd-His' );
	copy( $cfg, $bak ) or exit( "Could not back up config.php\n" );
	chmod( $bak, 0600 );
	say( 'Backed up to ' . basename( $bak ) );
}

// ---- questions -------------------------------------------------------------------
head( 'Your store' );
$name = ask( 'Store name', 'XMR Shop' );
$url  = rtrim( ask( 'Public URL of the store', 'https://shop.example.com', function ( $a ) {
	return preg_match( '~^https?://[^/\s]+~i', $a ) ? true : 'Use the full address, e.g. https://shop.example.com';
} ), '/' );
$host = strtolower( preg_replace( '~^www\.~', '', (string) parse_url( $url, PHP_URL_HOST ) ) );
$cur  = strtolower( ask( 'Price currency (usd, eur, gbp…)', 'usd', function ( $a ) {
	return preg_match( '/^[a-z]{3}$/i', $a ) ? true : 'Three letters, e.g. usd';
} ) );

head( 'Email' );
say( 'Notifications: new orders, paid orders, refunds due, outages (node / price feed), low stock.' );
$admin = ask( 'Admin email', '', $isEmail );
$split = array( 'orders' => $admin, 'refunds' => $admin, 'health' => $admin, 'stock' => $admin );
if ( ! yes( "[x] Send ALL notifications to $admin?", true ) ) {
	say( 'Blank = same as the admin email.' );
	$labels = array( 'orders' => 'New + paid orders', 'refunds' => 'Refunds due', 'health' => 'Outages (node / price feed)', 'stock' => 'Low stock / sold out' );
	foreach ( $labels as $k => $label ) {
		$v = ask( "  $label", '', $isEmailOrBlank );
		$split[ $k ] = '' === $v ? $admin : $v;
	}
}
// Suggest orders@<domain> only for a real domain name (not an IP or localhost), so the
// default always passes the email check instead of looping.
$fromDefault = ( '' !== $host && filter_var( $host, FILTER_VALIDATE_IP ) === false && false !== strpos( $host, '.' ) ) ? 'orders@' . $host : '';
$from   = ask( 'Send email from', $fromDefault, $isEmail );
$buyers = yes( 'Email buyers a payment receipt and shipping notice?', true );
say( c( '2', "  (Mail goes out through this server's mail setup. For it to arrive, $from's domain needs SPF/DKIM that allow this server.)" ) );

head( 'Monero' );
$net = strtolower( ask( 'Network (mainnet, stagenet, testnet)', 'mainnet', function ( $a ) {
	return in_array( strtolower( $a ), array( 'mainnet', 'stagenet', 'testnet' ), true ) ? true : 'mainnet, stagenet or testnet';
} ) );
$port = array( 'mainnet' => 18081, 'stagenet' => 38081, 'testnet' => 28081 )[ $net ];
say( 'Use a full (not pruned) node that YOU run. Settlement trusts whichever node answers, so for real money list only your own.' );
$node = rtrim( ask( 'Your node RPC URL', "http://127.0.0.1:$port", function ( $a ) {
	return preg_match( '~^https?://[^\s@]+$~i', $a ) ? true : 'e.g. http://127.0.0.1:18081 (no user:pass in the URL)';
} ), '/' );
if ( function_exists( 'curl_init' ) ) {
	$ch = curl_init( $node . '/json_rpc' );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
		CURLOPT_HTTPHEADER => array( 'Content-Type: application/json' ),
		CURLOPT_POSTFIELDS => '{"jsonrpc":"2.0","id":"0","method":"get_info"}' ) );
	$r = json_decode( (string) curl_exec( $ch ), true );
	curl_close( $ch );
	$info = $r['result'] ?? null;
	if ( ! $info ) {
		say( c( '33', "  Could not reach $node right now. Saved anyway; payments wait until it answers." ) );
	} else {
		$nt = (string) ( $info['nettype'] ?? '?' );
		say( ( $nt === $net ? c( '32', '  ok' ) : c( '31', '  WRONG NETWORK' ) ) . " — height {$info['height']}, $nt" . ( empty( $info['synchronized'] ) ? ', still syncing' : '' ) );
		if ( $nt !== $net && ! yes( "  The node is $nt but you chose $net. Keep it anyway?", false ) ) { exit( 1 ); }
	}
}

// ---- write config.php -------------------------------------------------------------
$secret   = bin2hex( random_bytes( 32 ) );
$setupKey = implode( '-', str_split( bin2hex( random_bytes( 9 ) ), 6 ) );
$set = array(
	'store_name' => $name, 'store_url' => $url, 'site_url' => $url, 'store_currency' => $cur,
	'network' => $net, 'nodes' => $node,
	'cookie_secret' => $secret, 'setup_key' => $setupKey,
	'notify_email' => $admin, 'notify_from' => $from, 'notify_buyers' => $buyers,
	'notify_email_orders' => $split['orders'] === $admin ? '' : $split['orders'],
	'notify_email_refunds' => $split['refunds'] === $admin ? '' : $split['refunds'],
	'notify_email_health' => $split['health'] === $admin ? '' : $split['health'],
	'notify_email_stock' => $split['stock'] === $admin ? '' : $split['stock'],
);
$tpl = file_get_contents( $ROOT . '/config.example.php' );
if ( false === $tpl ) { exit( "config.example.php is missing.\n" ); }
foreach ( $set as $k => $v ) {
	$lit = var_export( $v, true );
	$n = 0;
	$tpl = preg_replace_callback( "~^(\s*'" . preg_quote( $k, '~' ) . "'\s*=>\s*)('(?:[^'\\\\]|\\\\.)*'|true|false|-?\d+(?:\.\d+)?)~m",
		function ( $m ) use ( $lit ) { return $m[1] . $lit; }, $tpl, 1, $n );
	if ( ! $n ) { exit( "config.example.php has no '$k' setting; is this an older copy?\n" ); }
}
$um = umask( 0027 );
file_put_contents( $cfg, $tpl ) or exit( "Could not write config.php\n" );
umask( $um );
chmod( $cfg, 0640 );
exec( 'php -l ' . escapeshellarg( $cfg ) . ' 2>&1', $lint, $rc );
if ( 0 !== $rc ) { exit( "config.php failed to parse:\n" . implode( "\n", $lint ) . "\n" ); }

// ---- dirs + ownership -------------------------------------------------------------
head( 'Files' );
$dirs = array( 'data', 'public/assets/products', 'public/assets/banner' );
foreach ( $dirs as $d ) { if ( ! is_dir( "$ROOT/$d" ) ) { mkdir( "$ROOT/$d", 0750, true ); } }
$webUser = '';
foreach ( array( 'www-data', 'nginx', 'apache', 'http', 'www' ) as $u ) {
	if ( function_exists( 'posix_getpwnam' ) && posix_getpwnam( $u ) ) { $webUser = $u; break; }
}
$webUser = $webUser ?: 'www-data';
$isRoot = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
$cmds = array(
	"chgrp $webUser " . escapeshellarg( $cfg ),
	'chown -R ' . $webUser . ':' . $webUser . ' ' . implode( ' ', array_map( function ( $d ) use ( $ROOT ) { return escapeshellarg( "$ROOT/$d" ); }, $dirs ) ),
	'chmod 2770 ' . escapeshellarg( "$ROOT/data" ),
);
if ( $isRoot ) {
	foreach ( $cmds as $cmd ) { exec( $cmd ); }
	say( c( '32', 'ok ' ) . " config.php (640, group $webUser) and the data/upload dirs (owned by $webUser)" );
} else {
	say( "Wrote config.php (640). Give the web server ($webUser) access with:" );
	foreach ( $cmds as $cmd ) { say( '  sudo ' . $cmd ); }
}
say( 'The code itself should stay read-only to the web server; only data/ and the two upload dirs need to be writable.' );

// ---- next steps -------------------------------------------------------------------
$fpm = '/run/php/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm.sock';
head( 'Web server (nginx)' );
say( "Point the store at public/ and alias /admin/ to admin/ (it lives outside the docroot on purpose):" );
say( c( '2', <<<NGINX
  server {
      server_name $host;
      root $ROOT/public;
      index index.php;
      location / { try_files \$uri \$uri/ =404; }
      location ~ \\.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:$fpm; }
      location = /admin { return 301 /admin/; }
      location /admin/ {
          alias $ROOT/admin/;
          index index.php;
          try_files \$uri \$uri/ /admin/index.php?\$args;
          location ~ ^/admin/(.+\\.php)\$ {
              alias $ROOT/admin/\$1;
              if (!-f \$request_filename) { return 404; }
              include fastcgi.conf;
              fastcgi_pass unix:$fpm;
          }
      }
      location ~ /\\.(?!well-known) { deny all; }
      # + TLS (certbot --nginx -d $host), and see README "Hardening the admin"
  }
NGINX
) );

head( 'Payment checker + nightly purge' );
say( 'Add to cron (e.g. /etc/cron.d/xmr-cart):' );
say( c( '2', "  * * * * * $webUser php $ROOT/worker/poll.php >> /var/log/xmr-cart.log 2>&1" ) );
say( c( '2', "  17 3 * * * $webUser php $ROOT/worker/purge_pii.php >> /var/log/xmr-cart-pii.log 2>&1" ) );
say( 'No cron on this box? Use a systemd timer that runs the same two commands as ' . $webUser . '.' );

head( 'First login' );
say( "Open $url/admin/ . The setup page asks for:" );
say( '  setup key  : ' . c( '1;32', $setupKey ) . '   (also saved in config.php as setup_key)' );
say( '  a passphrase (12+ characters), then your wallet address + private VIEW key' );
say();
say( c( '1;32', 'Done.' ) . ' config.php written. Notifications go to ' . ( count( array_unique( $split ) ) === 1 ? $admin : 'the addresses you chose' ) . '.' );
