<?php
/**
 * The only file that talks to the payment engine. It loads the vendored, audited
 * scanner (XmrPay_Scanner + XmrPay_Util) if you have dropped it into lib/scanner/,
 * and otherwise falls back to a MOCK so the whole store — catalog, checkout, admin,
 * poller — runs end to end while you evaluate it. See lib/scanner/README.md.
 *
 * Real entry points used here (all read-only, no spend key ever touched):
 *   verify_keys()  subaddress()  tip_height()  scan_all()   + XmrPay_Util money math
 */
class Xmr {
	private $scanner;
	private $real = false;
	private $address;
	private $view;
	private $network;

	public function __construct() {
		$this->address = (string) Config::get( 'primary_address', '' );
		$this->view    = (string) Config::get( 'view_key', '' );
		$this->network = (string) Config::get( 'network', 'mainnet' );

		$dir     = __DIR__ . '/scanner';
		$scanner = $dir . '/class-xmrpay-scanner.php';
		$util    = $dir . '/class-xmrpay-util.php';

		if ( is_file( $scanner ) && is_file( $util ) ) {
			require_once __DIR__ . '/wp-shims.php';
			require_once $util;
			require_once $scanner;
			$nodes = Config::nodesRaw();
			$this->scanner = new XmrPay_Scanner( $nodes, $this->network, 20 );
			$this->real    = true;
		} else {
			$this->scanner = new Xmr_MockScanner( $this->network );
			$this->real    = false;
		}
	}

	public function isReal() { return $this->real; }

	/** Extensions the real crypto needs: GMP + BCMath + mbstring. */
	public function cryptoReady() {
		return extension_loaded( 'gmp' ) && extension_loaded( 'bcmath' ) && extension_loaded( 'mbstring' );
	}
	/** Which required extensions are missing (for a clear dashboard hint). */
	public function missingExtensions() {
		$need = array( 'gmp', 'bcmath', 'mbstring', 'pdo_sqlite', 'curl' );
		return array_values( array_filter( $need, function ( $e ) { return ! extension_loaded( $e ); } ) );
	}

	/** ['address_valid'=>bool, 'key_match'=>bool] — catches a wrong/typoed view key. */
	public function verifyKeys() {
		return $this->scanner->verify_keys( $this->address, $this->view );
	}

	/**
	 * Same check against an arbitrary pair — used by the first-run wizard and
	 * the Wallet tab to validate values BEFORE writing them into kv/config.
	 */
	public function verifyKeysPair( $address, $view_key ) {
		return $this->scanner->verify_keys( (string) $address, (string) $view_key );
	}

	/** Per-order subaddress string for account 0, index $minor. */
	public function subaddress( $minor ) {
		$r = $this->scanner->subaddress( 0, (int) $minor, $this->view, $this->address );
		return is_array( $r ) && ! empty( $r['address'] ) ? $r['address'] : '';
	}

	/** Current chain tip (min across configured nodes), or null if unreachable. */
	public function tipHeight() { return $this->scanner->tip_height(); }

	/** node reachability + nettype: ['ok'=>bool,'height'=>?int,'nettype'=>string]. */
	public function nodeInfo() {
		if ( method_exists( $this->scanner, 'node_info' ) ) { return $this->scanner->node_info(); }
		$h = $this->tipHeight();
		return array( 'ok' => null !== $h, 'height' => $h, 'nettype' => $this->network );
	}

	/**
	 * Reason the last RPC call failed, or null if the last call succeeded.
	 * Shape: ['code'=>'transport|unauthorized|http|digest_unavailable', 'url'=>?, 'status'=>?].
	 * Backed by the upstream v1.1.4 scanner's last_node_error() primitive; on the
	 * mock (or a pre-1.1.4 scanner) returns null so callers can code straight-through.
	 */
	public function lastNodeError() {
		if ( method_exists( $this->scanner, 'last_node_error' ) ) { return $this->scanner->last_node_error(); }
		return null;
	}

	/**
	 * Scan blocks [$from..$to] for payments to $subaddress. Bounded internally
	 * (max_blocks / time budget); returns ['matches'=>[...], 'scanned_to'=>int].
	 */
	public function scanAll( $subaddress, $from, $to, $opts = array() ) {
		$opts = array_merge( array( 'tip' => (int) $to, 'require_commitment' => true, 'max_blocks' => 60, 'time_budget' => 8.0 ), $opts );
		return $this->scanner->scan_all( $subaddress, $this->view, (int) $from, (int) $to, $opts );
	}
}

/**
 * Stand-in used only when the vendored scanner is absent. Same method signatures,
 * fake data. It never sees the chain — "payments" are injected by the admin
 * "Simulate payment" action so you can watch an order move to paid → shipped.
 */
class Xmr_MockScanner {
	private $network;
	public function __construct( $network = 'mainnet' ) { $this->network = $network; }

	public function verify_keys( $address, $view ) {
		$ok = $address !== '' && strpos( $address, 'YOUR_' ) !== 0;
		return array( 'address_valid' => $ok, 'key_match' => $ok );
	}
	public function subaddress( $major, $minor, $view, $primary ) {
		// Obviously-fake but stable string so it's clear this is demo mode.
		$tag = substr( hash( 'sha256', $primary . ':' . $major . ':' . $minor ), 0, 40 );
		return array( 'address' => 'DEMO-' . $tag, 'spend_pub' => '' );
	}
	/** ~one demo "block" every 15s so confirmations visibly accrue. */
	public function tip_height() { return 3000000 + (int) floor( time() / 15 ); }
	public function node_info() { return array( 'ok' => true, 'height' => $this->tip_height(), 'nettype' => $this->network . ' (demo)' ); }
	public function scan_all( $address, $view, $from, $to, $opts = array() ) {
		return array( 'matches' => array(), 'scanned_to' => (int) $to );
	}
}
