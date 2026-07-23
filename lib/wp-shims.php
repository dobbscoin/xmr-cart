<?php
/**
 * Minimal shims for the handful of WordPress functions the vendored scanner and
 * util call. With these defined, XmrPay_Scanner + XmrPay_Util run outside
 * WordPress unchanged. Everything here is plain PHP + curl.
 *
 * We deliberately DEFINE the HTTP helpers (wp_safe_remote_*) with curl instead
 * of leaving them undefined, so the scanner uses a robust curl path rather than
 * its file_get_contents() fallback (which needs allow_url_fopen).
 */

if ( ! defined( 'ABSPATH' ) ) {
	// The scanner/util files guard direct access with: if ( ! defined('ABSPATH') ) exit;
	// Define it so they load. This is NOT WordPress — just satisfying that guard.
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
}
if ( ! function_exists( 'remove_action' ) ) {
	// v1.1.4 scanner uses a try/finally around a Digest-auth curl hook and
	// calls remove_action() on the way out. Under non-WP add_action never
	// registered anything, so remove_action just needs to no-op safely.
	function remove_action( $hook, $cb = null, $priority = 10 ) { return true; }
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $cb = null, $priority = 10 ) { return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	// Our wp_remote_* shims return an array on success and a WShimError on failure.
	function is_wp_error( $thing ) { return ( $thing instanceof WShimError ); }
}

class WShimError {
	public $message;
	public function __construct( $m = '' ) { $this->message = $m; }
}

/** Shared curl worker. $method GET|POST. Returns a wp-style array or WShimError. */
function _wpshim_http( $method, $url, $args = array() ) {
	$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 20;
	$ch = curl_init( $url );
	if ( false === $ch ) { return new WShimError( 'curl_init failed' ); }
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	// Only http(s) — mirrors the scanner's own scheme guard.
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return new WShimError( 'bad scheme' ); }
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, isset( $args['body'] ) ? $args['body'] : '' );
		$headers = array( 'Content-Type: application/json' );
		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array();
			foreach ( $args['headers'] as $k => $v ) { $headers[] = $k . ': ' . $v; }
		}
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	}
	$body = curl_exec( $ch );
	if ( false === $body ) { $e = curl_error( $ch ); curl_close( $ch ); return new WShimError( $e ); }
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( 'body' => $body, 'response' => array( 'code' => $code ) );
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = array() ) { return _wpshim_http( 'POST', $url, $args ); }
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) { return _wpshim_http( 'GET', $url, $args ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $res ) { return is_array( $res ) && isset( $res['body'] ) ? $res['body'] : ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $res ) { return is_array( $res ) && isset( $res['response']['code'] ) ? $res['response']['code'] : 0; }
}
