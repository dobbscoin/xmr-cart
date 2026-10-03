<?php
/**
 * Price of 1 XMR in the store currency. Locked into each order at checkout so a
 * later market move never changes what an already-placed order owes.
 *
 * Order of preference: manual_xmr_rate (if > 0) > fresh cache > CoinGecko fetch >
 * recent cache. Returns 0.0 when there is no trustworthy rate, and checkout
 * refuses a 0 rate, so a dead or lying feed stops sales instead of mispricing them:
 *   - a cached rate older than price_max_stale_minutes (default 15) is not used;
 *   - a fetched rate under half or over double the last good one (if that is
 *     under 6h old) is treated as a failed fetch.
 */
class Price {
	private $store;
	public function __construct( Store $store ) { $this->store = $store; }

	public function xmrRate() {
		$manual = (float) Config::get( 'manual_xmr_rate', 0 );
		if ( $manual > 0 ) { return $manual; }

		$cur = strtolower( (string) Config::get( 'store_currency', 'usd' ) );
		$ttl = (int) Config::get( 'price_cache_seconds', 120 );
		$key = 'xmr_rate_' . $cur;

		$cached = $this->store->kvGet( $key );
		if ( $cached && ( time() - (int) $cached['updated_at'] ) < $ttl ) {
			return (float) $cached['v'];
		}

		$age     = $cached ? time() - (int) $cached['updated_at'] : PHP_INT_MAX;
		$last    = $cached ? (float) $cached['v'] : 0.0;
		$fetched = $this->fetch( $cur );
		if ( $fetched > 0 && is_finite( $fetched ) && $last > 0 && $age < 6 * 3600
			&& ( $fetched < $last / 2 || $fetched > $last * 2 ) ) {
			$fetched = 0.0;   // implausible jump against a recent good rate: treat as a bad fetch
		}
		if ( $fetched > 0 && is_finite( $fetched ) ) {
			$this->store->kvSet( $key, (string) $fetched );
			return $fetched;
		}
		// network hiccup: a recent rate is fine; an old one would misprice every order.
		$maxStale = 60 * max( 1, (int) Config::get( 'price_max_stale_minutes', 15 ) );
		if ( $cached && $age < $maxStale ) { return $last; }
		return 0.0;
	}

	private function fetch( $cur ) {
		$url = 'https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=' . rawurlencode( $cur );
		$apiKey = trim( (string) Config::get( 'coingecko_api_key', '' ) );
		if ( $apiKey !== '' ) { $url .= '&x_cg_demo_api_key=' . rawurlencode( $apiKey ); }

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 12 );
		// CoinGecko rejects requests with no User-Agent (HTTP 403).
		curl_setopt( $ch, CURLOPT_USERAGENT, 'xmr-cart/1.0' );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( ! $body || $code !== 200 ) { return 0.0; }
		$j = json_decode( $body, true );
		return isset( $j['monero'][ $cur ] ) ? (float) $j['monero'][ $cur ] : 0.0;
	}
}
