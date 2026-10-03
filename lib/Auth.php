<?php
/**
 * Admin auth. Passphrase is the gate; the console is reachable from the open
 * internet by default. See README § "Hardening the admin" if you want to add
 * a VPN / Tailscale / localhost bind on top.
 *
 * Hash lookup order: kv table (written by the first-run setup wizard) → then
 * config.php admin_pass_hash. This lets a fresh install self-configure without
 * touching config.php, while still honouring an operator-set config value.
 *
 * If no hash is set anywhere, admin/ requests are redirected to setup.php.
 * There is no "unlocked by default" fallback — the wizard is the only way in.
 */
class Auth {
	const TTL = 43200; // 12h

	/**
	 * Effective admin passphrase hash. Empty string if none set anywhere.
	 * kv > config.php.
	 */
	private static function hash() {
		$row = store()->kvGet( 'admin_pass_hash' );
		if ( $row && trim( (string) $row['v'] ) !== '' ) {
			return trim( (string) $row['v'] );
		}
		return trim( (string) Config::get( 'admin_pass_hash', '' ) );
	}

	public static function passSet() {
		return self::hash() !== '';
	}

	public static function loggedIn() {
		if ( ! self::passSet() ) { return false; }  // no hash → setup.php will catch it
		$c = isset( $_COOKIE['xsadmin'] ) ? (string) $_COOKIE['xsadmin'] : '';
		$parts = explode( '.', $c );
		if ( count( $parts ) !== 2 ) { return false; }
		list( $ts, $sig ) = $parts;
		if ( ! ctype_digit( $ts ) || ( time() - (int) $ts ) > self::TTL ) { return false; }
		return hash_equals( self::sign( $ts ), $sig );
	}

	public static function login( $pass ) {
		if ( ! self::passSet() ) { return false; }
		if ( ! password_verify( (string) $pass, self::hash() ) ) {
			return false;
		}
		$ts  = (string) time();
		setcookie( 'xsadmin', $ts . '.' . self::sign( $ts ), array(
			'expires'  => time() + self::TTL,
			'httponly' => true,
			'samesite' => 'Strict',
			'path'     => '/',
			'secure'   => is_https(),
		) );
		return true;
	}

	/** Cookie signature. Binding the passphrase hash in means a passphrase change logs out every old session. */
	private static function sign( $ts ) {
		return hash_hmac( 'sha256', $ts . '|' . self::hash(), csrf_secret() );
	}

	public static function logout() {
		setcookie( 'xsadmin', '', array( 'expires' => time() - 3600, 'path' => '/' ) );
	}

	/**
	 * Persist a bcrypt hash of $pass into the kv table. Used by setup.php only.
	 * Returns true on success.
	 */
	public static function setPassphrase( $pass ) {
		$hash = password_hash( (string) $pass, PASSWORD_DEFAULT );
		store()->q(
			'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
			array( 'admin_pass_hash', $hash, time() )
		);
		return true;
	}

	public static function require_admin() {
		if ( ! self::passSet() ) { redirect( 'setup.php' ); }
		if ( ! self::loggedIn() )  { redirect( 'login.php' ); }
	}
}
