<?php
/**
 * Price of 1 XMR in the store currency. Locked into each order at checkout so a
 * later market move never changes what an already-placed order owes.
 *
 * Order of preference: manual_xmr_rate (if > 0) > fresh cache > CoinGecko fetch >
 * stale cache. Returns 0.0 only if there is genuinely no rate to be had.
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

		$fetched = $this->fetch( $cur );
		if ( $fetched > 0 ) {
			$this->store->kvSet( $key, (string) $fetched );
			return $fetched;
		}
		// network hiccup: fall back to the last known rate rather than blocking checkout.
		if ( $cached ) { return (float) $cached['v']; }
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
