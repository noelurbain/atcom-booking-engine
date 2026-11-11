<?php
/**
 * Traveler search: airport + drop-off + date and time + passengers → vehicles with prices.
 *
 * Pricing rule (requirements): a hand-placed hotel wins; otherwise the zone shape that contains
 * the address; otherwise nothing to sell (we say so and show the nearest places we serve).
 * Only live providers with live services that serve the zone and have a price for the direction.
 * Every provider's offer is shown (two providers with a Minivan = two cards), with the provider's
 * name and stars (from 3 traveler reviews). Sorted by price, or by stars when the traveler asks.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Search {

	const DEFAULT_TIME = '12:00';
	const MIN_REVIEWS  = 3;

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** Our airport from an id, an IATA code, or the text of the theme's "Pickup" box ("CUN · Cancún"). */
	public static function find_airport( $value ): ?array {
		global $wpdb;
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			return ATC_Catalog::airport( (int) $value );
		}
		if ( preg_match_all( '/\b([A-Z]{3})\b/', strtoupper( $value ), $m ) ) {
			foreach ( $m[1] as $code ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'airports' ) . " WHERE iata = %s AND status = 'active'", $code ) );
				if ( $id ) {
					return ATC_Catalog::airport( $id );
				}
			}
		}
		$list = ATC_Catalog::search_airports( $value, 1 );
		return $list ? ATC_Catalog::airport( (int) $list[0]['id'] ) : null;
	}

	/** Read and clean a search from a query string (ours or the theme's older one). */
	public static function parse( array $in ): array {
		$s = static fn( $k ) => isset( $in[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $in[ $k ] ) ) : '';
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'date' ) ) ? $s( 'date' ) : '';
		$time = preg_match( '/^\d{1,2}:\d{2}$/', $s( 'time' ) ) ? $s( 'time' ) : '';
		$ret  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'return_date' ) ) ? $s( 'return_date' ) : '';
		$adults   = '' !== $s( 'adults' ) ? (int) $s( 'adults' ) : ( '' !== $s( 'pax' ) ? (int) $s( 'pax' ) : 2 );
		$children = (int) $s( 'children' );
		// to_airport: from a hotel or address to the airport (one way); the place stays in 'to'.
		$dir = 'to_airport' === $s( 'dir' ) ? 'to_airport' : 'from_airport';
		return array(
			'dir'         => $dir,
			'airport'     => $s( 'airport' ) ?: $s( 'pickup' ),
			'to'          => $s( 'to' ) ?: $s( 'dropoff' ),
			'lat'         => is_numeric( $s( 'lat' ) ) ? (float) $s( 'lat' ) : null,
			'lng'         => is_numeric( $s( 'lng' ) ) ? (float) $s( 'lng' ) : null,
			'pid'         => preg_replace( '/[^A-Za-z0-9_-]/', '', $s( 'pid' ) ),
			'date'        => $date,
			'time'        => $time,
			'trip'        => 'from_airport' === $dir && ( 'round_trip' === $s( 'trip' ) || '' !== $ret ) ? 'round_trip' : 'one_way',
			'return_date' => 'from_airport' === $dir ? $ret : '',
			'adults'      => max( 1, min( 60, $adults ) ),
			'children'    => max( 0, min( 40, $children ) ),
			'sort'        => 'rating' === $s( 'sort' ) ? 'rating' : 'price',
		);
	}

	/** The same search as a query string, for links between steps. */
	public static function query( array $q, array $extra = array() ): string {
		$keep = array_filter(
			array(
				'dir'         => 'to_airport' === ( $q['dir'] ?? '' ) ? 'to_airport' : '',
				'airport'     => $q['airport_code'] ?? $q['airport'],
				'to'          => $q['to'],
				'lat'         => null === $q['lat'] ? '' : $q['lat'],
				'lng'         => null === $q['lng'] ? '' : $q['lng'],
				'pid'         => $q['pid'],
				'date'        => $q['date'],
				'time'        => $q['time'],
				'trip'        => 'round_trip' === $q['trip'] ? 'round_trip' : '',
				'return_date' => $q['return_date'],
				'adults'      => $q['adults'],
				'children'    => $q['children'] ?: '',
				'sort'        => 'rating' === ( $q['sort'] ?? '' ) ? 'rating' : '',
			),
			static fn( $v ) => '' !== $v && null !== $v
		);
		return http_build_query( $keep + $extra );
	}

	/** Pickup in the airport's local time → UTC "Y-m-d H:i:s", or null without a date. */
	public static function pickup_utc( array $q, array $airport ): ?string {
		if ( '' === $q['date'] ) {
			return null;
		}
		try {
			$d = new DateTime( $q['date'] . ' ' . ( $q['time'] ?: self::DEFAULT_TIME ), new DateTimeZone( $airport['timezone'] ?: 'UTC' ) );
			$d->setTimezone( new DateTimeZone( 'UTC' ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Live offers for a zone and direction, before the passenger and notice checks. */
	private static function raw_offers( int $airport_id, int $zone_id, string $direction ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.id service_id, s.party_id, s.max_passengers, s.max_bags, s.free_wait_minutes,
					COALESCE(s.min_notice_minutes, p.min_notice_minutes, 1440) min_notice, p.name provider, p.rating_avg, p.rating_count, v.id vehicle_type_id, v.slug, v.name, v.sort, pr.retail_cents
				FROM ' . self::t( 'services' ) . ' s
				JOIN ' . self::t( 'parties' ) . " p ON p.id = s.party_id AND p.status = 'live' AND p.type = 'provider'
				JOIN " . self::t( 'service_zones' ) . ' sz ON sz.service_id = s.id AND sz.zone_id = %d
				JOIN ' . self::t( 'prices' ) . ' pr ON pr.service_id = s.id AND pr.zone_id = %d AND pr.direction = %s AND pr.valid_from IS NULL
				JOIN ' . self::t( 'vehicle_types' ) . " v ON v.id = s.vehicle_type_id
				WHERE s.status = 'live' AND s.airport_id = %d AND pr.retail_cents > 0
				ORDER BY v.sort, pr.retail_cents",
				$zone_id,
				$zone_id,
				$direction,
				$airport_id
			),
			ARRAY_A
		);
	}

	/** Zone polygon center, for "nearest places we serve". */
	private static function center( array $poly ): ?array {
		if ( ! $poly ) {
			return null;
		}
		$lat = 0;
		$lng = 0;
		foreach ( $poly as $p ) {
			$lat += (float) $p[0];
			$lng += (float) $p[1];
		}
		return array( $lat / count( $poly ), $lng / count( $poly ) );
	}

	/** Up to three zones of the airport that have prices, nearest to the address first. */
	private static function nearest_served( array $airport, ?float $lat, ?float $lng, string $direction ): array {
		$out = array();
		foreach ( ATC_Catalog::zones( (int) $airport['id'] ) as $z ) {
			$offers = self::raw_offers( (int) $airport['id'], (int) $z['id'], $direction );
			if ( ! $offers ) {
				continue;
			}
			$c     = self::center( $z['polygon'] );
			$out[] = array(
				'zone_id' => (int) $z['id'],
				'name'    => $z['name'],
				'from'    => min( array_map( static fn( $o ) => (int) $o['retail_cents'], $offers ) ),
				'km'      => ( $c && null !== $lat ) ? round( ATC_Geo::km( $lat, $lng, $c[0], $c[1] ) ) : null,
				'lat'     => $c ? $c[0] : null,
				'lng'     => $c ? $c[1] : null,
			);
		}
		usort( $out, static fn( $a, $b ) => ( $a['km'] ?? 9999 ) <=> ( $b['km'] ?? 9999 ) );
		return array_slice( $out, 0, 3 );
	}

	/**
	 * Run a parsed search. Returns: ok, airport, destination, pickup (local + utc), offers,
	 * and when nothing can be sold a reason (no_airport | no_destination | coverage | notice | party | none).
	 */
	public static function run( array $q ): array {
		$airport = self::find_airport( $q['airport'] );
		$res     = array( 'query' => $q, 'airport' => null, 'destination' => null, 'offers' => array(), 'reason' => null );
		if ( ! $airport ) {
			$res['reason'] = 'no_airport';
			return $res;
		}
		$res['airport']        = array(
			'id'       => (int) $airport['id'],
			'iata'     => $airport['iata'],
			'name'     => $airport['name'],
			'label'    => ATC_Catalog::airport_label( $airport ),
			'city'     => $airport['city'],
			'country'  => $airport['country'],
			'lat'      => null === $airport['lat'] ? null : (float) $airport['lat'],
			'lng'      => null === $airport['lng'] ? null : (float) $airport['lng'],
			'timezone' => $airport['timezone'],
		);
		$res['query']['airport_code'] = $airport['iata'] ?: (string) $airport['id'];

		// Where to: the place picked from Google, or the typed text looked up near the airport.
		$lat = $q['lat'];
		$lng = $q['lng'];
		$pid = $q['pid'];
		$to  = $q['to'];
		if ( ( null === $lat || null === $lng ) && '' !== $to && null !== $airport['lat'] ) {
			$key   = 'atc_dest_' . md5( $airport['id'] . '|' . strtolower( $to ) );
			$found = get_transient( $key );
			if ( false === $found ) {
				[ $found ] = ATC_Partner::rate_ok( 'search_place', 60 ) ? ATC_Geo::find_place( $to . ', ' . $airport['city'], (float) $airport['lat'], (float) $airport['lng'] ) : array( null );
				set_transient( $key, $found ?: array(), DAY_IN_SECONDS );
			}
			if ( $found ) {
				$lat = (float) $found['lat'];
				$lng = (float) $found['lng'];
				$pid = (string) $found['google_place_id'];
				$to  = $found['name'] . ( $found['address'] && false === strpos( $found['address'], $found['name'] ) ? ', ' . $found['address'] : '' );
				$res['query']['lat'] = $lat;
				$res['query']['lng'] = $lng;
				$res['query']['pid'] = $pid;
				$res['query']['to']  = $to;
			}
		}
		if ( null === $lat || null === $lng ) {
			$res['reason'] = 'no_destination';
			return $res;
		}
		$at                 = ATC_Catalog::zone_at( (int) $airport['id'], $lat, $lng, $pid );
		$res['destination'] = array( 'label' => $to, 'lat' => $lat, 'lng' => $lng, 'pid' => $pid, 'zone_id' => $at['zone_id'], 'zone' => $at['zone'] );

		$utc              = self::pickup_utc( $q, $airport );
		$res['pickup']    = array( 'utc' => $utc, 'date' => $q['date'], 'time' => $q['time'] );
		$direction        = 'round_trip' === $q['trip'] ? 'round_trip' : 'one_way';
		if ( ! $at['zone_id'] ) {
			$res['reason']  = 'coverage';
			$res['nearest'] = self::nearest_served( $airport, $lat, $lng, $direction );
			return $res;
		}

		$pax       = (int) $q['adults'] + (int) $q['children'];
		$now       = time();
		$lead      = null === $utc ? null : ( strtotime( $utc . ' UTC' ) - $now ) / 60;
		$raw       = self::raw_offers( (int) $airport['id'], (int) $at['zone_id'], $direction );
		$offers    = array();
		$too_small = 0;
		$too_soon  = array();
		$largest   = 0;
		foreach ( $raw as $o ) {
			$largest = max( $largest, (int) $o['max_passengers'] );
			if ( (int) $o['max_passengers'] < $pax ) {
				++$too_small;
				continue;
			}
			if ( null !== $lead && $lead < (int) $o['min_notice'] ) {
				$too_soon[] = (int) $o['min_notice'];
				continue;
			}
			$rated    = (int) $o['rating_count'] >= self::MIN_REVIEWS && null !== $o['rating_avg'];
			$offers[] = array(
					'service_id'      => (int) $o['service_id'],
					'party_id'        => (int) $o['party_id'],
					'vehicle_type_id' => (int) $o['vehicle_type_id'],
					'provider'        => $o['provider'],
					'rating'          => $rated ? round( (float) $o['rating_avg'], 1 ) : null,
					'reviews'         => $rated ? (int) $o['rating_count'] : 0,
					'slug'            => $o['slug'],
					'name'            => $o['name'],
					'max_passengers'  => (int) $o['max_passengers'],
					'max_bags'        => (int) $o['max_bags'],
					'free_wait'       => (int) $o['free_wait_minutes'],
					'retail'          => (int) $o['retail_cents'],
					'sort'            => (int) $o['sort'],
			);
		}
		$by_rating = 'rating' === ( $q['sort'] ?? '' );
		usort(
			$offers,
			static function ( $a, $b ) use ( $by_rating ) {
				if ( $by_rating ) {
					$c = ( $b['rating'] ?? -1 ) <=> ( $a['rating'] ?? -1 ) ?: $b['reviews'] <=> $a['reviews'];
					if ( $c ) {
						return $c;
					}
				}
				return $a['retail'] <=> $b['retail'] ?: $a['sort'] <=> $b['sort'];
			}
		);
		$res['offers'] = $offers;
		$res['rated']  = (bool) array_filter( $offers, static fn( $o ) => null !== $o['rating'] );
		if ( ! $offers ) {
			if ( $too_soon ) {
				$res['reason']   = 'notice';
				$res['earliest'] = self::local( gmdate( 'Y-m-d H:i:s', $now + 60 * min( $too_soon ) + 600 ), $airport['timezone'] );
				$res['hours']    = (int) ceil( min( $too_soon ) / 60 );
			} elseif ( $too_small ) {
				$res['reason']  = 'party';
				$res['largest'] = $largest;
			} else {
				$res['reason']  = 'none';
				$res['nearest'] = self::nearest_served( $airport, $lat, $lng, $direction );
			}
		}
		return $res;
	}

	/**
	 * Remember a search we could not sell, so our team sees the demand: an airport we don't have
	 * ('airport'), an address outside every zone ('address'), or a zone nobody has priced ('unpriced').
	 */
	public static function log_miss( string $kind, string $term, int $airport_id = 0, ?float $lat = null, ?float $lng = null ): void {
		global $wpdb;
		$term = mb_substr( trim( preg_replace( '/\s+/', ' ', $term ) ), 0, 190 );
		if ( mb_strlen( $term ) < 2 || ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( '/bot|crawl|spider|slurp|preview/i', (string) $_SERVER['HTTP_USER_AGENT'] ) ) ) { // phpcs:ignore
			return;
		}
		if ( ! ATC_Partner::rate_ok( 'demand', 30 ) ) {
			return;
		}
		$now  = current_time( 'mysql', true );
		$geo  = null === $lat || null === $lng ? 'NULL, NULL' : sprintf( '%.7F, %.7F', $lat, $lng );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::t( 'demand' ) . " (kind, airport_id, term, lat, lng, hits, first_at, last_at, status) VALUES (%s, %d, %s, {$geo}, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at), status = IF(status = %s, status, %s)",
				$kind,
				$airport_id,
				$term,
				$now,
				$now,
				'open',
				'ignored',
				'open'
			)
		); // phpcs:ignore
	}

	public static function local( string $utc, string $tz, string $fmt = 'D j M, H:i' ): string {
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( new DateTimeZone( $tz ?: 'UTC' ) );
			return $d->format( $fmt );
		} catch ( Exception $e ) {
			return $utc;
		}
	}
}
