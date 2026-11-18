<?php
/**
 * Where travelers are searching: every results page is logged (airport, destination, how many offers came
 * back), with no personal data. Control → Insights turns it into the recruitment list: airports and areas
 * with demand and nobody to sell. Also the public traveler count.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Insights {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** Same person, same month: same id. Rotates monthly and can't be turned back into an IP. */
	public static function visitor(): string {
		$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore
		return substr( hash_hmac( 'sha256', $ip . '|' . $ua . '|' . gmdate( 'Y-m' ), wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function is_bot(): bool {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|headless|lighthouse|pingdom|monitor/i', $ua );
	}

	/** A results page was shown. Our team and robots don't count; a reload within 30 minutes doesn't either. */
	public static function log( array $res ): void {
		global $wpdb;
		if ( self::is_bot() || ( is_user_logged_in() && current_user_can( 'atc_manage' ) ) ) {
			return;
		}
		$q    = (array) $res['query'];
		$vis  = self::visitor();
		$dest = mb_substr( trim( (string) ( $res['destination']['label'] ?? $q['to'] ?? '' ) ), 0, 190 );
		$air  = (int) ( $res['airport']['id'] ?? 0 );
		$key  = 'atc_s_' . md5( $vis . '|' . $air . '|' . $q['airport'] . '|' . $dest . '|' . $q['date'] );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );
		$lead = null;
		if ( ! empty( $q['date'] ) ) {
			$lead = (int) floor( ( strtotime( $q['date'] . ' 12:00 UTC' ) - time() ) / DAY_IN_SECONDS );
		}
		$aff = null;
		if ( ! empty( $_COOKIE[ ATC_Affiliates::COOKIE ] ) ) {
			$a   = ATC_Affiliates::by_code( sanitize_text_field( wp_unslash( $_COOKIE[ ATC_Affiliates::COOKIE ] ) ) );
			$aff = $a ? (int) $a['id'] : null;
		}
		$wpdb->insert(
			self::t( 'searches' ),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'site'         => mb_substr( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 0, 60 ),
				'airport_id'   => $air,
				'airport_text' => $air ? '' : mb_substr( trim( (string) $q['airport'] ), 0, 120 ),
				'dest'         => $dest,
				'zone_id'      => ! empty( $res['destination']['zone_id'] ) ? (int) $res['destination']['zone_id'] : null,
				'results'      => count( (array) $res['offers'] ),
				'reason'       => $res['reason'] ? mb_substr( (string) $res['reason'], 0, 20 ) : null,
				'trip'         => 'round_trip' === $q['trip'] ? 'round_trip' : ( 'to_airport' === ( $q['dir'] ?? '' ) ? 'to_airport' : 'one_way' ),
				'pax'          => (int) $q['adults'] + (int) $q['children'],
				'lead_days'    => $lead,
				'visitor'      => $vis,
				'affiliate_id' => $aff,
			)
		);
		if ( class_exists( 'ATC_Analytics' ) ) {
			ATC_Analytics::server_event( 'atcom_transfer_searched', $vis, array( 'airport' => (string) ( $res['airport']['iata'] ?? $q['airport'] ), 'zone' => (string) ( $res['destination']['zone'] ?? '' ), 'direction' => (string) ( $q['dir'] ?? 'from_airport' ), 'trip' => (string) $q['trip'], 'passengers' => (int) $q['adults'] + (int) $q['children'], 'days_before_trip' => $lead, 'results_count' => count( (array) $res['offers'] ), 'no_result_reason' => (string) $res['reason'] ) );
		}
	}

	/** The range from the screen: 7, 30, 90 or 365 days, or two dates. */
	public static function range( array $in ): array {
		$from = isset( $in['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['from'] ) ? $in['from'] : '';
		$to   = isset( $in['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['to'] ) ? $in['to'] : '';
		if ( ! $from || ! $to ) {
			$days = in_array( (int) ( $in['days'] ?? 30 ), array( 1, 7, 30, 90, 365 ), true ) ? (int) $in['days'] : 30;
			$to   = gmdate( 'Y-m-d' );
			$from = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		}
		if ( $from > $to ) {
			[ $from, $to ] = array( $to, $from );
		}
		return array( $from, $to, $from . ' 00:00:00', $to . ' 23:59:59' );
	}

	public static function report( array $in ): array {
		global $wpdb;
		[ $from, $to, $a, $b ] = self::range( $in );
		$s  = self::t( 'searches' );
		$ap = self::t( 'airports' );

		$tot = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COUNT(DISTINCT visitor) v, SUM(results = 0) none FROM {$s} WHERE created_at BETWEEN %s AND %s", $a, $b ), ARRAY_A ); // phpcs:ignore

		// Providers that can quote at each airport: a live service of a live provider with at least one price.
		$ready = $wpdb->get_results(
			'SELECT s.airport_id, COUNT(DISTINCT s.party_id) n FROM ' . self::t( 'services' ) . ' s JOIN ' . self::t( 'parties' ) . " p ON p.id = s.party_id AND p.status = 'live' WHERE s.status = 'live' AND EXISTS (SELECT 1 FROM " . self::t( 'prices' ) . ' x WHERE x.service_id = s.id) GROUP BY s.airport_id',
			OBJECT_K
		); // phpcs:ignore

		$airports = $wpdb->get_results( $wpdb->prepare( "SELECT x.airport_id, a.iata, a.name, a.city, COUNT(*) n, SUM(x.results = 0) none, COUNT(DISTINCT x.visitor) v FROM {$s} x JOIN {$ap} a ON a.id = x.airport_id WHERE x.created_at BETWEEN %s AND %s AND x.airport_id > 0 GROUP BY x.airport_id ORDER BY n DESC LIMIT 60", $a, $b ), ARRAY_A ); // phpcs:ignore
		$no_prov  = 0;
		foreach ( $airports as &$r ) {
			$r['ready'] = isset( $ready[ $r['airport_id'] ] ) ? (int) $ready[ $r['airport_id'] ]->n : 0;
			if ( ! $r['ready'] ) {
				++$no_prov;
			}
			$r = array( 'id' => (int) $r['airport_id'], 'iata' => (string) $r['iata'], 'name' => $r['name'], 'searches' => (int) $r['n'], 'none' => (int) $r['none'], 'visitors' => (int) $r['v'], 'ready' => $r['ready'] );
		}
		unset( $r );

		$dests = $wpdb->get_results( $wpdb->prepare( "SELECT x.dest, a.iata, COUNT(*) n, SUM(x.results = 0) none FROM {$s} x LEFT JOIN {$ap} a ON a.id = x.airport_id WHERE x.created_at BETWEEN %s AND %s AND x.dest <> '' GROUP BY x.dest, a.iata ORDER BY n DESC LIMIT 25", $a, $b ), ARRAY_A ); // phpcs:ignore
		$unserved = $wpdb->get_results( $wpdb->prepare( "SELECT airport_text t, COUNT(*) n FROM {$s} WHERE created_at BETWEEN %s AND %s AND airport_id = 0 AND airport_text <> '' GROUP BY airport_text ORDER BY n DESC LIMIT 25", $a, $b ), ARRAY_A ); // phpcs:ignore
		$reasons  = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(reason, 'ok') r, COUNT(*) n FROM {$s} WHERE created_at BETWEEN %s AND %s GROUP BY r ORDER BY n DESC", $a, $b ), OBJECT_K ); // phpcs:ignore
		$per_day  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT visitor) v FROM {$s} WHERE created_at BETWEEN %s AND %s GROUP BY d ORDER BY d", $a, $b ), ARRAY_A ); // phpcs:ignore

		// Bookings in the same range (paid ones), for the top lists and the funnel.
		$bk  = self::t( 'bookings' );
		$lg  = self::t( 'booking_legs' );
		$pt  = self::t( 'parties' );
		$top_air = $wpdb->get_results( $wpdb->prepare( "SELECT a.iata, a.name, COUNT(DISTINCT b.id) n FROM {$bk} b JOIN {$lg} l ON l.booking_id = b.id JOIN " . self::t( 'services' ) . " sv ON sv.id = l.service_id JOIN {$ap} a ON a.id = sv.airport_id WHERE b.status IN ('confirmed','completed') AND b.snapshot NOT LIKE %s AND b.created_at BETWEEN %s AND %s GROUP BY a.id ORDER BY n DESC LIMIT 8", '%"legacy":true%', $a, $b ), ARRAY_A ); // phpcs:ignore
		$top_prov = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.name, COUNT(DISTINCT l.id) n FROM {$lg} l JOIN {$bk} b ON b.id = l.booking_id JOIN {$pt} p ON p.id = l.party_id WHERE b.status IN ('confirmed','completed') AND b.snapshot NOT LIKE %s AND b.created_at BETWEEN %s AND %s GROUP BY p.id ORDER BY n DESC LIMIT 8", '%"legacy":true%', $a, $b ), ARRAY_A ); // phpcs:ignore
		$top_aff  = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.name, COUNT(*) n, SUM(b.total_cents) sales FROM {$bk} b JOIN {$pt} p ON p.id = b.affiliate_id WHERE b.status IN ('confirmed','completed') AND b.created_at BETWEEN %s AND %s GROUP BY p.id ORDER BY n DESC LIMIT 8", $a, $b ), ARRAY_A ); // phpcs:ignore
		$funnel   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) started, SUM(snapshot LIKE %s) details, SUM(status IN ('confirmed','completed')) paid, SUM(CASE WHEN status IN ('confirmed','completed') THEN total_cents ELSE 0 END) sales FROM {$bk} WHERE created_at BETWEEN %s AND %s AND snapshot NOT LIKE %s", '%"details"%', $a, $b, '%"legacy":true%' ), ARRAY_A ); // phpcs:ignore

		return array(
			'from'      => $from,
			'to'        => $to,
			'searches'  => (int) $tot['n'],
			'visitors'  => (int) $tot['v'],
			'none'      => (int) $tot['none'],
			'no_provider_airports' => $no_prov,
			'airports'  => $airports,
			'destinations' => array_map( static fn( $r ) => array( 'dest' => $r['dest'], 'iata' => (string) $r['iata'], 'searches' => (int) $r['n'], 'none' => (int) $r['none'] ), $dests ),
			'unserved'  => array_map( static fn( $r ) => array( 'airport' => $r['t'], 'searches' => (int) $r['n'] ), $unserved ),
			'reasons'   => array_map( static fn( $r ) => (int) $r->n, $reasons ),
			'per_day'   => array_map( static fn( $r ) => array( 'day' => $r['d'], 'searches' => (int) $r['n'], 'visitors' => (int) $r['v'] ), $per_day ),
			'top_airports'   => array_map( static fn( $r ) => array( 'iata' => (string) $r['iata'], 'name' => $r['name'], 'bookings' => (int) $r['n'] ), $top_air ),
			'top_providers'  => array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'name' => $r['name'], 'transfers' => (int) $r['n'] ), $top_prov ),
			'top_affiliates' => array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'name' => $r['name'], 'bookings' => (int) $r['n'], 'sales' => (int) $r['sales'] ), $top_aff ),
			'funnel'    => array( 'searches' => (int) $tot['n'], 'started' => (int) $funnel['started'], 'details' => (int) $funnel['details'], 'paid' => (int) $funnel['paid'], 'sales' => (int) $funnel['sales'] ),
			'travelers' => self::traveler_numbers(),
		);
	}

	/* ---------- The public traveler count ---------- */

	/** Searchers in the last 30 days (distinct visitors, all sites). Cached for an hour. */
	public static function searchers_30d(): int {
		$n = get_transient( 'atc_searchers_30d' );
		if ( false === $n ) {
			global $wpdb;
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT visitor) FROM ' . self::t( 'searches' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			set_transient( 'atc_searchers_30d', $n, HOUR_IN_SECONDS );
		}
		return (int) $n;
	}

	/**
	 * The figure shown on the homepage and checkout: the base, moving toward the real 30-day searchers as that
	 * grows (never below the base). $span: '30d' (default), '7d' (a quarter) or '24h' (a 30th), for the pop-up.
	 */
	public static function traveler_count( string $span = '30d' ): int {
		$base = (int) ATC_Settings::get( 'traveler_base' );
		$real = self::searchers_30d();
		$n    = max( $base, $real );
		if ( '7d' === $span ) {
			return max( 1, (int) round( $n / 4 ) );
		}
		if ( '24h' === $span ) {
			return max( 1, (int) round( $n / 30 ) );
		}
		return $n;
	}

	public static function traveler_numbers(): array {
		return array( 'base' => (int) ATC_Settings::get( 'traveler_base' ), 'searchers' => self::searchers_30d(), 'public' => self::traveler_count() );
	}

	public static function save_base( int $base ) {
		$all                  = ATC_Settings::all();
		$all['traveler_base'] = max( 0, min( 10000000, $base ) );
		update_option( ATC_Settings::OPTION, $all );
		delete_transient( 'atc_searchers_30d' );
		return self::traveler_numbers();
	}
}
