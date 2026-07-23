<?php
/**
 * Per-node health probe. Independent of the scanner — the scanner still handles
 * actual payments and has its own failover; this class is purely diagnostic for
 * the admin console.
 *
 * For each URL in Config::nodesArray(), does a direct curl POST to
 * /json_rpc get_info and captures reachable / elapsed_ms / height / pruned /
 * synchronized / bootstrap_daemon_address. Results are cached in kv under
 * 'nodeprobe:results' with a JSON-encoded array; refresh() clears the cache.
 *
 * WHY not go through the scanner: node_info() returns only {ok,height,nettype}
 * and its failover would mask "node #2 is down" behind "node #1 answered." We
 * want per-node truth here, not the aggregate happy-path answer.
 */
class NodeProbe {
	const KV_KEY   = 'nodeprobe:results';
	const TTL      = 300;   // 5 min cache — probe re-runs when older than this
	const TIMEOUT  = 4;     // per-node budget; keep admin page from hanging

	private $store;
	public function __construct( Store $store ) { $this->store = $store; }

	/**
	 * Returns an array of per-node result rows plus a probed_at timestamp,
	 * either from the fresh cache or by running a new probe.
	 *   ['probed_at' => int, 'results' => [ {url, ok, elapsed_ms, height, pruned, synced, bootstrap, error}, ... ]]
	 */
	public function results() {
		$cached = $this->store->kvGet( self::KV_KEY );
		if ( $cached && ( time() - (int) $cached['updated_at'] ) < self::TTL ) {
			$d = json_decode( (string) $cached['v'], true );
			if ( is_array( $d ) ) { return $d; }
		}
		return $this->refresh();
	}

	/** Age in seconds of the cached probe, or null if never run. */
	public function cacheAge() {
		$row = $this->store->kvGet( self::KV_KEY );
		return $row ? ( time() - (int) $row['updated_at'] ) : null;
	}

	/** Run the probe now, store it, return it. */
	public function refresh() {
		$nodes = Config::nodesArray();
		$rows  = array();
		foreach ( $nodes as $url ) { $rows[] = $this->probeOne( $url ); }
		$out = array( 'probed_at' => time(), 'results' => $rows );
		$this->store->kvSet( self::KV_KEY, json_encode( $out ) );
		return $out;
	}

	private function probeOne( $url ) {
		$row = array(
			'url'        => $url,
			'ok'         => false,
			'elapsed_ms' => null,
			'height'     => null,
			'pruned'     => false,
			'synced'     => null,
			'bootstrap'  => null,
			'error'      => null,
		);

		$ch = curl_init( rtrim( $url, '/' ) . '/json_rpc' );
		if ( false === $ch ) { $row['error'] = 'curl_init'; return $row; }
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::TIMEOUT );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( array(
			'jsonrpc' => '2.0', 'id' => '0', 'method' => 'get_info',
		) ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );

		$t0   = microtime( true );
		$body = curl_exec( $ch );
		$row['elapsed_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( false === $body ) {
			$row['error'] = 'transport: ' . curl_error( $ch );
			curl_close( $ch ); return $row;
		}
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( $code < 200 || $code >= 300 ) { $row['error'] = 'http ' . $code; return $row; }

		$j = json_decode( (string) $body, true );
		$r = isset( $j['result'] ) && is_array( $j['result'] ) ? $j['result'] : null;
		if ( ! $r ) { $row['error'] = 'malformed response'; return $row; }

		$row['ok']        = 'OK' === ( $r['status'] ?? '' );
		$row['height']    = isset( $r['height'] ) ? (int) $r['height'] : null;
		$row['pruned']    = ! empty( $r['pruned'] );
		$row['synced']    = ! empty( $r['synchronized'] );
		$row['bootstrap'] = ! empty( $r['bootstrap_daemon_address'] ) ? (string) $r['bootstrap_daemon_address'] : null;
		return $row;
	}
}
