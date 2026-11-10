<?php
/**
 * Price editor and price-list upload, shared by the provider portal and the control panel.
 *
 * Grid: one airport, zones as rows, vehicle categories as columns, one way and round trip.
 * The provider types either the Suggested retail price or the Amount they get paid; the other follows
 * from the commission (retail is rounded up to whole dollars so the provider never gets less than typed).
 *
 * Upload: their spreadsheet is read in the browser and sent as rows of text. We match their vehicle
 * names to our categories and their place names to our zones (their earlier names first, then our zone
 * names, then Google: a place inside one of our zone shapes takes that zone). A place outside every zone
 * becomes a proposed zone our team approves; its prices are saved and wait ("zone pending").
 * Their names are remembered as aliases; travelers only ever see our zone names.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Prices {

	const MIN_CENTS    = 1000;    // under USD 10: flagged
	const MAX_CENTS    = 500000;  // over USD 5,000: flagged
	const HARD_MAX     = 5000000; // over USD 50,000: refused
	const GOOGLE_LIMIT = 40;      // lookups per upload

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function commission_bp( int $party_id ): int {
		$p = ATC_Catalog::party( $party_id );
		return ( $p && null !== $p['commission_bp'] ) ? (int) $p['commission_bp'] : (int) ATC_Settings::get( 'provider_commission_bp' );
	}

	/** Airports this provider sells or has prices at, plus its home airport. */
	public static function airports_for( int $party_id ): array {
		global $wpdb;
		$p   = ATC_Catalog::party( $party_id );
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT airport_id FROM ' . self::t( 'services' ) . ' WHERE party_id = %d', $party_id ) ) );
		if ( $p && ! empty( $p['home_airport_id'] ) ) {
			array_unshift( $ids, (int) $p['home_airport_id'] );
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$a = ATC_Catalog::airport( $id );
			if ( $a ) {
				$out[] = array( 'id' => (int) $a['id'], 'label' => ATC_Catalog::airport_label( $a ), 'iata' => $a['iata'] );
			}
		}
		return $out;
	}

	/** Zones a provider may price at an airport: our active zones plus the ones they proposed. */
	private static function zone_rows( int $party_id, int $airport_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, name, slug, sort, status, (polygon IS NOT NULL AND polygon <> \'\' AND polygon <> \'[]\') AS shaped FROM ' . self::t( 'zones' ) . "
				WHERE airport_id = %d AND (status = 'active' OR (status = 'proposed' AND source = %s)) ORDER BY sort, name",
				$airport_id,
				'provider:' . $party_id
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['id']     = (int) $r['id'];
			$r['shaped'] = (bool) $r['shaped'];
		}
		return $rows;
	}

	/** Everything the price grid needs for one airport. */
	public static function grid( int $party_id, int $airport_id ): array {
		global $wpdb;
		$airport = ATC_Catalog::airport( $airport_id );
		if ( ! $airport ) {
			return array( 'airport' => null );
		}
		$services = $wpdb->get_results( $wpdb->prepare( 'SELECT id, vehicle_type_id, status FROM ' . self::t( 'services' ) . ' WHERE party_id = %d AND airport_id = %d', $party_id, $airport_id ), ARRAY_A );
		$by_svc   = array();
		$offered  = array();
		foreach ( $services as $s ) {
			$by_svc[ (int) $s['id'] ]                 = (int) $s['vehicle_type_id'];
			$offered[ (int) $s['vehicle_type_id'] ] = $s['status'];
		}
		$served = array();
		$cells  = array();
		if ( $by_svc ) {
			$in = implode( ',', array_map( 'intval', array_keys( $by_svc ) ) );
			foreach ( $wpdb->get_results( 'SELECT service_id, zone_id FROM ' . self::t( 'service_zones' ) . " WHERE service_id IN ({$in})", ARRAY_A ) as $sz ) { // phpcs:ignore
				$served[ (int) $sz['zone_id'] ] = true;
			}
			foreach ( $wpdb->get_results( 'SELECT service_id, zone_id, direction, retail_cents, provider_cents FROM ' . self::t( 'prices' ) . " WHERE valid_from IS NULL AND service_id IN ({$in})", ARRAY_A ) as $p ) { // phpcs:ignore
				$key                                = (int) $p['zone_id'] . ':' . $by_svc[ (int) $p['service_id'] ];
				$cells[ $key ][ $p['direction'] ] = array( 'retail' => (int) $p['retail_cents'], 'provider' => (int) $p['provider_cents'] );
				$served[ (int) $p['zone_id'] ]      = true;
			}
		}
		$zones = self::zone_rows( $party_id, $airport_id );
		foreach ( $zones as &$z ) {
			$z['served'] = isset( $served[ $z['id'] ] );
		}
		unset( $z );
		$types = ATC_Catalog::vehicle_types();
		foreach ( $types as &$t ) {
			$t['id']      = (int) $t['id'];
			$t['offered'] = $offered[ $t['id'] ] ?? null;
		}
		return array(
			'airport'       => array( 'id' => (int) $airport['id'], 'label' => ATC_Catalog::airport_label( $airport ), 'iata' => $airport['iata'] ),
			'airports'      => self::airports_for( $party_id ),
			'commission_bp' => self::commission_bp( $party_id ),
			'zones'         => $zones,
			'vehicle_types' => $types,
			'cells'         => (object) $cells,
			'limits'        => array( 'min' => self::MIN_CENTS, 'max' => self::MAX_CENTS ),
		);
	}

	/** The service for (provider, airport, category), created when missing; live straight away for live providers. */
	private static function service_id( int $party_id, int $airport_id, int $type_id ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'services' ) . ' WHERE party_id = %d AND airport_id = %d AND vehicle_type_id = %d', $party_id, $airport_id, $type_id ) );
		if ( $id ) {
			return $id;
		}
		$p   = ATC_Catalog::party( $party_id );
		$res = ATC_Catalog::save_service(
			$party_id,
			array(
				'airport_id'         => $airport_id,
				'vehicle_type_id'    => $type_id,
				'status'             => ( $p && 'live' === $p['status'] ) ? 'live' : 'draft',
				'min_notice_minutes' => ( $p && null !== $p['min_notice_minutes'] ) ? (int) $p['min_notice_minutes'] : 1440,
			)
		);
		return is_wp_error( $res ) ? $res : (int) $res['id'];
	}

	/**
	 * Save price cells. Each cell: zone_id, vehicle_type_id, direction (one_way|round_trip) and either
	 * retail (cents), provider (cents) or clear=true.
	 *
	 * @return array|WP_Error {saved, cleared, flagged[]}
	 */
	public static function save( int $party_id, int $airport_id, array $cells ) {
		global $wpdb;
		if ( ! ATC_Catalog::airport( $airport_id ) ) {
			return new WP_Error( 'atc_airport', 'Unknown airport.', array( 'status' => 400 ) );
		}
		$bp      = self::commission_bp( $party_id );
		$zones   = array();
		foreach ( self::zone_rows( $party_id, $airport_id ) as $z ) {
			$zones[ $z['id'] ] = $z;
		}
		$types = array();
		foreach ( ATC_Catalog::vehicle_types() as $t ) {
			$types[ (int) $t['id'] ] = $t['name'];
		}
		$out = array( 'saved' => 0, 'cleared' => 0, 'flagged' => array() );
		$t   = self::t( 'prices' );
		$sz  = self::t( 'service_zones' );
		foreach ( array_slice( $cells, 0, 3000 ) as $c ) {
			$zid = (int) ( $c['zone_id'] ?? 0 );
			$vid = (int) ( $c['vehicle_type_id'] ?? 0 );
			$dir = 'round_trip' === ( $c['direction'] ?? '' ) ? 'round_trip' : 'one_way';
			if ( ! isset( $zones[ $zid ], $types[ $vid ] ) ) {
				continue;
			}
			$svc = self::service_id( $party_id, $airport_id, $vid );
			if ( is_wp_error( $svc ) ) {
				return $svc;
			}
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE service_id = %d AND zone_id = %d AND direction = %s AND valid_from IS NULL", $svc, $zid, $dir ) );
			if ( ! empty( $c['clear'] ) ) {
				if ( $existing ) {
					$wpdb->delete( $t, array( 'id' => $existing ) );
					++$out['cleared'];
				}
				continue;
			}
			if ( isset( $c['retail'] ) && '' !== $c['retail'] && null !== $c['retail'] ) {
				$retail   = max( 0, (int) $c['retail'] );
				$provider = ATC_Money::provider_from_retail( $retail, $bp );
			} elseif ( isset( $c['provider'] ) && '' !== $c['provider'] && null !== $c['provider'] ) {
				$provider = max( 0, (int) $c['provider'] );
				$retail   = ATC_Money::retail_from_provider( $provider, $bp );
			} else {
				continue;
			}
			if ( $retail <= 0 || $retail > self::HARD_MAX ) {
				$out['flagged'][] = $zones[ $zid ]['name'] . ' · ' . $types[ $vid ] . ': USD ' . number_format( $retail / 100, 2 ) . ' was not saved.';
				continue;
			}
			if ( $retail < self::MIN_CENTS || $retail > self::MAX_CENTS ) {
				$out['flagged'][] = $zones[ $zid ]['name'] . ' · ' . $types[ $vid ] . ': USD ' . number_format( $retail / 100, 2 ) . ' looks unusual. Check it is in US dollars.';
			}
			$data = array(
				'retail_cents'   => $retail,
				'provider_cents' => $provider,
				'currency'       => 'USD',
				'updated_by'     => get_current_user_id() ?: null,
				'updated_at'     => self::now(),
			);
			if ( $existing ) {
				$wpdb->update( $t, $data, array( 'id' => $existing ) );
			} else {
				$wpdb->insert( $t, $data + array( 'service_id' => $svc, 'zone_id' => $zid, 'direction' => $dir ) );
			}
			// A priced zone is a served zone.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$sz} (service_id, zone_id) VALUES (%d, %d)", $svc, $zid ) ); // phpcs:ignore
			++$out['saved'];
		}
		if ( $out['saved'] || $out['cleared'] ) {
			ATC_Install::log( 'party', $party_id, 'prices_saved', array( 'airport_id' => $airport_id, 'saved' => $out['saved'], 'cleared' => $out['cleared'] ) );
		}
		return $out;
	}

	/* ---------- Upload ---------- */

	private static function norm( string $s ): string {
		$s = remove_accents( mb_strtolower( trim( $s ) ) );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/** Words that say nothing about which place it is. */
	private static function core( string $s ): string {
		$s = ' ' . self::norm( $s ) . ' ';
		$s = preg_replace( '/ (area|zone|zona|region|district|the|de|del|la|el|centre|center|centro) /', ' ', $s );
		$s = preg_replace( '/ (area|zone|zona|region|district|the|de|del|la|el|centre|center|centro) /', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/** Our category for their vehicle name, or null. */
	public static function match_vehicle( int $party_id, string $raw ): array {
		global $wpdb;
		$types = array();
		foreach ( ATC_Catalog::vehicle_types() as $t ) {
			$types[ $t['slug'] ] = (int) $t['id'];
		}
		$alias = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT target_id FROM ' . self::t( 'aliases' ) . " WHERE party_id = %d AND kind = 'vehicle' AND alias = %s", $party_id, mb_substr( trim( $raw ), 0, 190 ) ) );
		if ( $alias ) {
			return array( $alias, 'your earlier name' );
		}
		$n = self::norm( $raw );
		foreach ( ATC_Catalog::vehicle_types() as $t ) {
			if ( self::norm( $t['name'] ) === $n || self::norm( $t['slug'] ) === $n ) {
				return array( (int) $t['id'], 'same name' );
			}
		}
		$has = static fn( string $re ) => (bool) preg_match( '/\b(' . $re . ')\b/', $n );
		$slug = null;
		if ( $has( 'large coach|big coach|full size coach|50 seat|55 seat|56 seat' ) ) {
			$slug = 'large-coach';
		} elseif ( $has( 'midi coach|midicoach|midi bus|35 seat|33 seat' ) ) {
			$slug = 'midi-coach';
		} elseif ( $has( 'mini coach|minicoach|coaster|rosa|25 seat|24 seat' ) ) {
			$slug = 'mini-coach';
		} elseif ( $has( 'coach|autobus|bus 45|45 seat' ) ) {
			$slug = 'coach';
		} elseif ( $has( 'minibus|mini bus|sprinter|crafter|transit|hiace|urvan|h1|h 1|toyota hiace|van 10|van 12|van 14|14 seat|12 seat' ) ) {
			$slug = 'minibus';
		} elseif ( $has( 'minivan|mini van|van|mpv|sienna|odyssey|alphard|vito|v class|v class|caravan|carnival|staria' ) ) {
			$slug = 'minivan';
		} elseif ( $has( 'suv' ) || $has( 'suburban|tahoe|yukon|escalade|navigator|expedition|range rover|x5|gls|q7' ) ) {
			if ( $has( 'premium' ) ) {
				$slug = 'premium-suv';
			} elseif ( $has( 'luxury|lux|vip|escalade|navigator|range rover|black|executive|exec' ) ) {
				$slug = 'luxury-suv';
			} else {
				$slug = 'standard-suv';
			}
		} elseif ( $has( 'sedan|saloon|car|taxi|berline|auto|coche|camry|corolla|e class|s class|5 series|tesla' ) ) {
			$slug = $has( 'executive|exec|business|luxury|lux|vip|premium|first|e class|s class|5 series|7 series|mercedes|bmw|audi|lexus' ) ? 'executive-sedan' : 'standard-sedan';
		}
		return ( $slug && isset( $types[ $slug ] ) ) ? array( $types[ $slug ], 'looks like it' ) : array( null, null );
	}

	/** Our zone for their place name: their earlier name, any provider's name, our zone name, then Google. */
	private static function match_place( int $party_id, int $airport_id, string $raw, array $zones, int &$google_left ): array {
		global $wpdb;
		$alias = mb_substr( trim( $raw ), 0, 190 );
		$zt    = self::t( 'zones' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT a.target_id FROM ' . self::t( 'aliases' ) . " a JOIN {$zt} z ON z.id = a.target_id WHERE a.kind = 'zone' AND a.alias = %s AND z.airport_id = %d AND (z.status = 'active' OR z.source = %s) ORDER BY a.party_id = %d DESC LIMIT 1", $alias, $airport_id, 'provider:' . $party_id, $party_id ) );
		if ( $id ) {
			return array( 'zone_id' => $id, 'how' => 'known name' );
		}
		$n = self::norm( $raw );
		$c = self::core( $raw );
		foreach ( $zones as $z ) {
			if ( self::norm( $z['name'] ) === $n || $z['slug'] === sanitize_title( $raw ) ) {
				return array( 'zone_id' => $z['id'], 'how' => 'same name' );
			}
		}
		if ( '' !== $c ) {
			foreach ( $zones as $z ) {
				if ( self::core( $z['name'] ) === $c ) {
					return array( 'zone_id' => $z['id'], 'how' => 'same name' );
				}
			}
		}
		if ( $google_left <= 0 ) {
			return array( 'zone_id' => null, 'how' => 'not looked up' );
		}
		--$google_left;
		$airport = ATC_Catalog::airport( $airport_id );
		$key     = 'atc_pl_' . md5( $airport_id . '|' . $n );
		$found   = get_transient( $key );
		if ( false === $found ) {
			[ $found, $err ] = ATC_Geo::find_place( $raw . ', ' . $airport['city'], (float) $airport['lat'], (float) $airport['lng'] );
			if ( $err && 'Not found' !== $err ) {
				return array( 'zone_id' => null, 'how' => 'Google: ' . $err );
			}
			set_transient( $key, $found ?: array(), DAY_IN_SECONDS );
		}
		if ( empty( $found ) ) {
			return array( 'zone_id' => null, 'how' => 'not found on Google' );
		}
		$far = ATC_Geo::km( (float) $airport['lat'], (float) $airport['lng'], (float) $found['lat'], (float) $found['lng'] );
		$at  = ATC_Catalog::zone_at( $airport_id, (float) $found['lat'], (float) $found['lng'], (string) $found['google_place_id'] );
		if ( $at['zone_id'] ) {
			return array( 'zone_id' => (int) $at['zone_id'], 'how' => 'on the map, inside ' . $at['zone'], 'place' => $found, 'km' => round( $far ) );
		}
		return array( 'zone_id' => null, 'how' => 'on the map, outside our zones', 'place' => $found, 'km' => round( $far ) );
	}

	private static function amount( $v ): ?int {
		$s = trim( (string) $v );
		if ( '' === $s ) {
			return null;
		}
		$s = preg_replace( '/[^0-9.,]/', '', $s );
		if ( '' === $s ) {
			return null;
		}
		if ( false !== strpos( $s, ',' ) && false !== strpos( $s, '.' ) ) {
			$s = strrpos( $s, ',' ) > strrpos( $s, '.' ) ? str_replace( array( '.', ',' ), array( '', '.' ), $s ) : str_replace( ',', '', $s );
		} elseif ( false !== strpos( $s, ',' ) ) {
			$s = preg_match( '/,\d{3}$/', $s ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s );
		}
		return is_numeric( $s ) ? (int) round( (float) $s * 100 ) : null;
	}

	private static function header_kind( string $h ): ?string {
		$n = self::norm( $h );
		if ( '' === $n ) {
			return null;
		}
		if ( preg_match( '/\b(round|roundtrip|rt|return|redondo|vuelta|aller retour|ar)\b/', $n ) ) {
			return 'round_trip';
		}
		if ( preg_match( '/\b(one way|oneway|ow|single|sencillo|ida|price|rate|precio|tarifa|prix|cost|amount|usd)\b/', $n ) ) {
			return 'one_way';
		}
		if ( preg_match( '/\b(vehicle|vehicles|vehiculo|vehicule|unit|type|category|categoria|service|servicio|fleet)\b/', $n ) ) {
			return 'vehicle';
		}
		if ( preg_match( '/\b(zone|zona|area|destination|destino|place|hotel|city|location|to|from|route|ruta|lugar)\b/', $n ) ) {
			return 'place';
		}
		return null;
	}

	/**
	 * Read their rows (text) into price lines: [place, vehicle, direction, amount].
	 * Two layouts: "long" (a vehicle column, one-way and round-trip columns) and "wide" (one column per vehicle,
	 * optionally "… round trip" columns). The first row with two or more filled cells is the header.
	 */
	public static function parse_rows( array $rows ): array {
		$rows = array_values( array_filter( array_map( static fn( $r ) => array_map( static fn( $c ) => trim( (string) $c ), (array) $r ), array_slice( $rows, 0, 2000 ) ), static fn( $r ) => count( array_filter( $r, 'strlen' ) ) > 0 ) );
		$hi   = null;
		foreach ( $rows as $i => $r ) {
			if ( count( array_filter( $r, 'strlen' ) ) >= 2 ) {
				$hi = $i;
				break;
			}
		}
		if ( null === $hi ) {
			return array( array(), 'We could not find a header row (place, then vehicles or prices).' );
		}
		$head  = $rows[ $hi ];
		$kinds = array_map( array( __CLASS__, 'header_kind' ), $head );
		$place = array_search( 'place', $kinds, true );
		$veh   = array_search( 'vehicle', $kinds, true );
		if ( false === $place ) {
			$place = 0;
		}
		$lines = array();
		if ( false !== $veh ) {
			$ow = array_search( 'one_way', $kinds, true );
			$rt = array_search( 'round_trip', $kinds, true );
			if ( false === $ow && false === $rt ) {
				return array( array(), 'There is a vehicle column but no price column (one way or round trip).' );
			}
			foreach ( array_slice( $rows, $hi + 1 ) as $r ) {
				$p = $r[ $place ] ?? '';
				$v = $r[ $veh ] ?? '';
				if ( '' === $p || '' === $v ) {
					continue;
				}
				foreach ( array( 'one_way' => $ow, 'round_trip' => $rt ) as $dir => $col ) {
					if ( false !== $col && null !== ( $amt = self::amount( $r[ $col ] ?? '' ) ) && $amt > 0 ) {
						$lines[] = array( $p, $v, $dir, $amt );
					}
				}
			}
			return array( $lines, null );
		}
		// Wide: every other headed column is a vehicle; "round trip" in the header makes it a round-trip column.
		$cols = array();
		foreach ( $head as $i => $h ) {
			if ( $i === $place || '' === $h ) {
				continue;
			}
			$dir  = 'round_trip' === $kinds[ $i ] ? 'round_trip' : 'one_way';
			$name = trim( preg_replace( '/\b(round ?trip|rt|return|redondo|ida y vuelta|one ?way|ow|sencillo|price|precio|usd|\$)\b/i', ' ', $h ) );
			$name = trim( preg_replace( '/[\s\-–\/()]+$|^[\s\-–\/()]+/u', '', $name ) );
			if ( '' === $name ) {
				// "One way" / "Round trip" without a vehicle: a single, unnamed vehicle.
				$name = '';
			}
			$cols[ $i ] = array( $name, $dir );
		}
		foreach ( array_slice( $rows, $hi + 1 ) as $r ) {
			$p = $r[ $place ] ?? '';
			if ( '' === $p ) {
				continue;
			}
			foreach ( $cols as $i => [ $name, $dir ] ) {
				$amt = self::amount( $r[ $i ] ?? '' );
				if ( null !== $amt && $amt > 0 ) {
					$lines[] = array( $p, $name, $dir, $amt );
				}
			}
		}
		return array( $lines, $lines ? null : 'No prices found under the header row.' );
	}

	/** Upload, step 1: what we understood and how we matched it. Nothing is saved. */
	public static function preview( int $party_id, int $airport_id, array $rows ) {
		if ( ! ATC_Catalog::airport( $airport_id ) ) {
			return new WP_Error( 'atc_airport', 'Pick the airport these prices are for.', array( 'status' => 400 ) );
		}
		[ $lines, $err ] = self::parse_rows( $rows );
		if ( $err ) {
			return new WP_Error( 'atc_prices_file', $err, array( 'status' => 400 ) );
		}
		$zones   = self::zone_rows( $party_id, $airport_id );
		$places  = array();
		$vehicles = array();
		$left    = self::GOOGLE_LIMIT;
		foreach ( $lines as [ $p, $v ] ) {
			if ( ! isset( $places[ $p ] ) ) {
				$places[ $p ] = array( 'name' => $p ) + self::match_place( $party_id, $airport_id, $p, $zones, $left );
			}
			if ( ! isset( $vehicles[ $v ] ) ) {
				[ $id, $how ]    = '' === $v ? array( null, null ) : self::match_vehicle( $party_id, $v );
				$vehicles[ $v ] = array( 'name' => $v, 'vehicle_type_id' => $id, 'how' => $how );
			}
		}
		$count = array();
		foreach ( $lines as [ $p ] ) {
			$count[ $p ] = ( $count[ $p ] ?? 0 ) + 1;
		}
		foreach ( $places as $k => &$pl ) {
			$pl['prices'] = $count[ $k ];
		}
		unset( $pl );
		return array(
			'lines'         => $lines,
			'places'        => array_values( $places ),
			'vehicles'      => array_values( $vehicles ),
			'zones'         => array_map( static fn( $z ) => array( 'id' => $z['id'], 'name' => $z['name'], 'status' => $z['status'] ), $zones ),
			'vehicle_types' => ATC_Catalog::vehicle_types(),
			'commission_bp' => self::commission_bp( $party_id ),
			'google_used'   => self::GOOGLE_LIMIT - $left,
		);
	}

	/** Make a zone the provider proposed; our team approves it and draws its shape. */
	private static function propose_zone( int $party_id, int $airport_id, string $name, ?array $place ): int {
		global $wpdb;
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 100 );
		$t    = self::t( 'zones' );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE airport_id = %d AND name = %s AND status IN ('active','proposed')", $airport_id, $name ) );
		if ( $id ) {
			return $id;
		}
		$base = sanitize_title( $name ) ?: 'zone';
		$slug = $base;
		for ( $i = 2; $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE airport_id = %d AND slug = %s", $airport_id, $slug ) ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort),0) + 10 FROM {$t} WHERE airport_id = %d", $airport_id ) );
		$wpdb->insert( $t, array( 'airport_id' => $airport_id, 'name' => $name, 'slug' => $slug, 'polygon' => null, 'source' => 'provider:' . $party_id, 'sort' => $sort, 'status' => 'proposed' ) );
		$id = (int) $wpdb->insert_id;
		// Where Google found it, so our team sees it on the map.
		if ( $place && isset( $place['lat'], $place['lng'] ) ) {
			$res = ATC_Catalog::save_place( array( 'airport_id' => $airport_id, 'name' => $place['name'] ?? $name, 'address' => $place['address'] ?? '', 'lat' => $place['lat'], 'lng' => $place['lng'], 'google_place_id' => $place['google_place_id'] ?? '', 'type' => 'other' ) );
			if ( ! is_wp_error( $res ) ) {
				$wpdb->update( self::t( 'places' ), array( 'zone_id' => $id, 'matched_by' => 'manual' ), array( 'id' => (int) $res['id'] ) );
			}
		}
		ATC_Install::log( 'zone', $id, 'proposed', array( 'party_id' => $party_id, 'name' => $name ) );
		self::notify_team( $party_id, $airport_id, $name );
		return $id;
	}

	private static function notify_team( int $party_id, int $airport_id, string $name ): void {
		$p = ATC_Catalog::party( $party_id );
		$a = ATC_Catalog::airport( $airport_id );
		wp_mail(
			(string) ATC_Settings::get( 'from_email' ),
			'New zone to approve: ' . $name . ' (' . ( $a ? ATC_Catalog::airport_label( $a ) : '' ) . ')',
			( $p ? $p['name'] : 'A provider' ) . " uploaded prices for a place outside our zones: {$name}.\n\nIts prices are saved and wait until the zone is approved and has a shape.\n\nApprove it from the control panel dashboard: " . home_url( '/control/' )
		);
	}

	/**
	 * Upload, step 2: save with the matches the person confirmed.
	 * $places: their name => zone id | 'new' | 'skip'; $vehicles: their name => vehicle type id | 'skip';
	 * $basis: 'retail' (amounts are what travelers pay) or 'provider' (what the provider gets paid).
	 */
	public static function apply( int $party_id, int $airport_id, array $lines, array $places, array $vehicles, string $basis, array $found = array() ) {
		$zones = array();
		foreach ( self::zone_rows( $party_id, $airport_id ) as $z ) {
			$zones[ $z['id'] ] = true;
		}
		$types = array_map( 'intval', wp_list_pluck( ATC_Catalog::vehicle_types(), 'id' ) );
		$zmap  = array();
		$new   = array();
		foreach ( $places as $name => $choice ) {
			$name = (string) $name;
			if ( 'new' === $choice ) {
				$zmap[ $name ] = self::propose_zone( $party_id, $airport_id, $name, isset( $found[ $name ] ) && is_array( $found[ $name ] ) ? $found[ $name ] : null );
				$zones[ $zmap[ $name ] ] = true;
				$new[]         = $name;
			} elseif ( is_numeric( $choice ) && isset( $zones[ (int) $choice ] ) ) {
				$zmap[ $name ] = (int) $choice;
			}
		}
		$vmap = array();
		foreach ( $vehicles as $name => $choice ) {
			if ( is_numeric( $choice ) && in_array( (int) $choice, $types, true ) ) {
				$vmap[ (string) $name ] = (int) $choice;
			}
		}
		$cells = array();
		foreach ( array_slice( $lines, 0, 3000 ) as $l ) {
			[ $p, $v, $dir, $amt ] = array( (string) ( $l[0] ?? '' ), (string) ( $l[1] ?? '' ), (string) ( $l[2] ?? '' ), (int) ( $l[3] ?? 0 ) );
			if ( ! isset( $zmap[ $p ], $vmap[ $v ] ) || $amt <= 0 ) {
				continue;
			}
			$cells[] = array( 'zone_id' => $zmap[ $p ], 'vehicle_type_id' => $vmap[ $v ], 'direction' => $dir, ( 'provider' === $basis ? 'provider' : 'retail' ) => $amt );
		}
		$res = self::save( $party_id, $airport_id, $cells );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// Remember their names, so the next upload matches by itself.
		foreach ( $zmap as $name => $zid ) {
			ATC_Migration::alias_set( $party_id, 'zone', $name, $zid );
		}
		foreach ( $vmap as $name => $vid ) {
			if ( '' !== $name ) {
				ATC_Migration::alias_set( $party_id, 'vehicle', $name, $vid );
			}
		}
		$res['new_zones'] = $new;
		return $res;
	}

	/* ---------- Proposed zones (our team) ---------- */

	public static function proposed_zones(): array {
		global $wpdb;
		$z    = self::t( 'zones' );
		$rows = $wpdb->get_results(
			"SELECT z.id, z.name, z.airport_id, z.source, a.iata, a.name airport,
				(SELECT COUNT(*) FROM " . self::t( 'prices' ) . " p WHERE p.zone_id = z.id) prices,
				(SELECT CONCAT(pl.lat, ',', pl.lng) FROM " . self::t( 'places' ) . " pl WHERE pl.zone_id = z.id LIMIT 1) at
			FROM {$z} z JOIN " . self::t( 'airports' ) . " a ON a.id = z.airport_id WHERE z.status = 'proposed' ORDER BY z.id DESC LIMIT 50",
			ARRAY_A
		); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$pid           = (int) substr( (string) $r['source'], 9 );
			$p             = $pid ? ATC_Catalog::party( $pid ) : null;
			$r['provider'] = $p ? $p['name'] : '';
			$r['party_id'] = $pid;
			$r['id']       = (int) $r['id'];
			$r['prices']   = (int) $r['prices'];
			unset( $r['source'] );
		}
		return $rows;
	}

	/** Approve (the zone becomes ours, still needing a shape) or reject (zone and its prices go). */
	public static function decide_zone( int $id, bool $approve ) {
		global $wpdb;
		$z = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'zones' ) . " WHERE id = %d AND status = 'proposed'", $id ), ARRAY_A );
		if ( ! $z ) {
			return new WP_Error( 'atc_not_found', 'That zone is no longer waiting.', array( 'status' => 404 ) );
		}
		if ( $approve ) {
			$wpdb->update( self::t( 'zones' ), array( 'status' => 'active' ), array( 'id' => $id ) );
		} else {
			$wpdb->update( self::t( 'zones' ), array( 'status' => 'archived' ), array( 'id' => $id ) );
			$wpdb->delete( self::t( 'prices' ), array( 'zone_id' => $id ) );
			$wpdb->delete( self::t( 'service_zones' ), array( 'zone_id' => $id ) );
		}
		ATC_Install::log( 'zone', $id, $approve ? 'approved' : 'rejected' );
		return array( 'ok' => true, 'airport_id' => (int) $z['airport_id'] );
	}
}
