<?php
/**
 * Admin auth. The PRIMARY control is binding admin/ to your Tailscale interface in
 * nginx so the open internet can't reach it at all (see nginx.conf.example). This
 * passphrase is defense-in-depth. If no hash is set, access is allowed but the
 * dashboard shows a loud warning to set one.
 */
class Auth {
	const TTL = 43200; // 12h

	public static function passSet() {
		return trim( (string) Config::get( 'admin_pass_hash', '' ) ) !== '';
	}

	public static function loggedIn() {
		if ( ! self::passSet() ) { return true; } // network-gated; warn elsewhere
		$c = isset( $_COOKIE['xsadmin'] ) ? (string) $_COOKIE['xsadmin'] : '';
		$parts = explode( '.', $c );
		if ( count( $parts ) !== 2 ) { return false; }
		list( $ts, $sig ) = $parts;
		if ( ! ctype_digit( $ts ) || ( time() - (int) $ts ) > self::TTL ) { return false; }
		$want = hash_hmac( 'sha256', $ts, csrf_secret() );
		return hash_equals( $want, $sig );
	}

	public static function login( $pass ) {
		if ( ! self::passSet() ) { return true; }
		if ( ! password_verify( (string) $pass, (string) Config::get( 'admin_pass_hash', '' ) ) ) {
			return false;
		}
		$ts  = (string) time();
		$sig = hash_hmac( 'sha256', $ts, csrf_secret() );
		setcookie( 'xsadmin', $ts . '.' . $sig, array(
			'expires'  => time() + self::TTL,
			'httponly' => true,
			'samesite' => 'Strict',
			'path'     => '/',
		) );
		return true;
	}

	public static function logout() {
		setcookie( 'xsadmin', '', array( 'expires' => time() - 3600, 'path' => '/' ) );
	}

	public static function require_admin() {
		if ( ! self::loggedIn() ) { redirect( 'login.php' ); }
	}
}
