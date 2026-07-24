<?php
/** Loads config.php once and exposes typed getters. */
class Config {
	private static $c = null;

	public static function load() {
		if ( null !== self::$c ) { return self::$c; }
		$path = dirname( __DIR__ ) . '/config.php';
		if ( ! is_file( $path ) ) {
			http_response_code( 500 );
			exit( "Missing config.php — copy config.example.php to config.php and fill it in.\n" );
		}
		self::$c = require $path;
		return self::$c;
	}

	public static function get( $key, $default = null ) {
		$c = self::load();
		return array_key_exists( $key, $c ) ? $c[ $key ] : $default;
	}

	/**
	 * Comma-separated node URLs. Lookup order: kv 'nodes_override' > config.php.
	 * Lets the admin console edit the node fleet without touching config.php,
	 * while still honouring an operator-set config value if kv is empty.
	 */
	public static function nodesRaw() {
		if ( class_exists( 'Store' ) && isset( $GLOBALS['store'] ) ) {
			$row = store()->kvGet( 'nodes_override' );
			if ( $row && trim( (string) $row['v'] ) !== '' ) {
				return trim( (string) $row['v'] );
			}
		}
		return (string) self::get( 'nodes', '' );
	}

	public static function nodesArray() {
		return array_values( array_filter( array_map( 'trim', explode( ',', self::nodesRaw() ) ) ) );
	}

	public static function configured() {
		$addr = self::primaryAddress();
		$vk   = self::viewKey();
		return $addr !== '' && strpos( $addr, 'YOUR_' ) !== 0
			&& $vk !== '' && strpos( $vk, 'YOUR_' ) !== 0;
	}

	/**
	 * Primary Monero address for the store. Lookup order: kv 'wallet_primary_address'
	 * > config.php['primary_address']. Lets the first-run wizard + Wallet tab set
	 * the wallet identity without touching config.php, while still honouring an
	 * operator-set config value when kv is empty.
	 */
	public static function primaryAddress() {
		if ( class_exists( 'Store' ) && isset( $GLOBALS['store'] ) ) {
			$row = store()->kvGet( 'wallet_primary_address' );
			if ( $row && trim( (string) $row['v'] ) !== '' ) {
				return trim( (string) $row['v'] );
			}
		}
		return (string) self::get( 'primary_address', '' );
	}

	/**
	 * Private view key for the store. Same override pattern as primaryAddress().
	 * Only the SECRET view key ever lives here — never the spend key.
	 */
	public static function viewKey() {
		if ( class_exists( 'Store' ) && isset( $GLOBALS['store'] ) ) {
			$row = store()->kvGet( 'wallet_view_key' );
			if ( $row && trim( (string) $row['v'] ) !== '' ) {
				return trim( (string) $row['v'] );
			}
		}
		return (string) self::get( 'view_key', '' );
	}

	/** Is the wallet identity currently coming from kv (console-set) or config.php? */
	public static function walletSource() {
		if ( ! ( class_exists( 'Store' ) && isset( $GLOBALS['store'] ) ) ) { return 'config.php'; }
		$a = store()->kvGet( 'wallet_primary_address' );
		$v = store()->kvGet( 'wallet_view_key' );
		$has_kv = ( $a && trim( (string) $a['v'] ) !== '' ) || ( $v && trim( (string) $v['v'] ) !== '' );
		return $has_kv ? 'console' : 'config.php';
	}
}
