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

	public static function nodesArray() {
		$raw = (string) self::get( 'nodes', '' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	public static function configured() {
		$addr = (string) self::get( 'primary_address', '' );
		$vk   = (string) self::get( 'view_key', '' );
		return $addr !== '' && strpos( $addr, 'YOUR_' ) !== 0
			&& $vk !== '' && strpos( $vk, 'YOUR_' ) !== 0;
	}
}
