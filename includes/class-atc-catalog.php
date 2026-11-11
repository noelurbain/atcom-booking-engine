<?php
/**
 * Catalog data: airports, zones, hotels and addresses, providers, their services and vehicles.
 * Every write is validated here and logged to the audit table.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Catalog {

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function t( string $name ): string {
		return ATC_Schema::table( $name );
	}

	/* ---------- Airports and vehicle types ---------- */

	public static function airports(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, iata, name, city, country, timezone, lat, lng, status FROM ' . self::t( 'airports' ) . " WHERE status = 'active' ORDER BY city, name", ARRAY_A );
	}

	/** "Cancún (CUN)", or the airport's name while it has no code yet. */
	public static function airport_label( array $a ): string {
		return ! empty( $a['iata'] ) ? $a['city'] . ' (' . $a['iata'] . ')' : (string) $a['name'];
	}

	/**
	 * Our airports matching what a person types: a code ("CUN"), a city, an airport name, or a mix
	 * ("cancun airport", "aeropuerto de cancún"). Every meaningful word must match the code, city or name;
	 * words like "airport", "international" or "aeropuerto" are ignored. Accents don't matter.
	 */
	public static function search_airports( string $q, int $limit = 6 ): array {
		global $wpdb;
		$q = trim( preg_replace( '/[\s,·\-–()]+/u', ' ', $q ) );
		if ( '' === $q ) {
			return array();
		}
		$stop  = array( 'airport', 'airports', 'international', 'intl', 'int', 'aeropuerto', 'internacional', 'aeroport', 'aéroport', 'flughafen', 'aeroporto', 'de', 'del', 'the', 'of', 'la', 'el', 'terminal', 'apt' );
		$words = array();
		foreach ( explode( ' ', mb_strtolower( $q ) ) as $w ) {
			if ( '' !== $w && ! in_array( remove_accents( $w ), $stop, true ) && ! in_array( $w, $stop, true ) ) {
				$words[] = $w;
			}
		}
		if ( ! $words ) {
			return array();
		}
		$t     = self::t( 'airports' );
		$where = array();
		$args  = array();
		foreach ( array_slice( $words, 0, 5 ) as $w ) {
			$like    = '%' . $wpdb->esc_like( $w ) . '%';
			$where[] = '(iata = %s OR city LIKE %s OR name LIKE %s)';
			array_push( $args, $w, $like, $like );
		}
		$code = strtoupper( $words[0] );
		$sql  = "SELECT id, iata, name, city, country FROM {$t} WHERE status = 'active' AND " . implode( ' AND ', $where ) . ' ORDER BY (iata = %s) DESC, city, name LIMIT %d';
		array_push( $args, $code, $limit );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
	}

	/** The airport we already have for a Google place: same place id, or within 4 km of the point. */
	public static function airport_matching( string $google_place_id, float $lat, float $lng ): ?array {
		global $wpdb;
		$t = self::t( 'airports' );
		if ( '' !== $google_place_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE google_place_id = %s AND status = 'active' LIMIT 1", $google_place_id ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'active' AND lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f", $lat - 0.1, $lat + 0.1, $lng - 0.1, $lng + 0.1 ), ARRAY_A );
		$best = null;
		$min  = 4.0;
		foreach ( $rows as $r ) {
			$d = ATC_Geo::km( $lat, $lng, (float) $r['lat'], (float) $r['lng'] );
			if ( $d <= $min ) {
				$min  = $d;
				$best = $r;
			}
		}
		return $best;
	}

	/**
	 * The airport for a Google place a provider picked at sign-up: the one we already have, or a new one.
	 * New airports have no IATA code and no zones until our team sets them up.
	 *
	 * @return array|WP_Error airport row, with 'is_new' => bool
	 */
	public static function airport_from_google( string $place_id ) {
		global $wpdb;
		[ $g, $err ] = ATC_Geo::airport_details( $place_id );
		if ( ! $g ) {
			return new WP_Error( 'atc_airport', 'We could not check that airport with Google. Search for it again.', array( 'status' => 400, 'detail' => $err ) );
		}
		$t        = self::t( 'airports' );
		$existing = self::airport_matching( $g['google_place_id'], $g['lat'], $g['lng'] );
		if ( $existing ) {
			if ( empty( $existing['google_place_id'] ) ) {
				$wpdb->update( $t, array( 'google_place_id' => $g['google_place_id'] ), array( 'id' => (int) $existing['id'] ) );
			}
			return $existing + array( 'is_new' => false );
		}
		if ( '' === $g['country'] ) {
			return new WP_Error( 'atc_airport', 'We could not read the country of that airport. Search for it again.', array( 'status' => 400 ) );
		}
		$zones = timezone_identifiers_list();
		$wpdb->insert(
			$t,
			array(
				'iata'            => null,
				'name'            => mb_substr( $g['name'], 0, 190 ),
				'city'            => mb_substr( $g['city'], 0, 100 ),
				'country'         => $g['country'],
				'timezone'        => in_array( $g['timezone'], $zones, true ) ? $g['timezone'] : 'UTC',
				'lat'             => $g['lat'],
				'lng'             => $g['lng'],
				'status'          => 'active',
				'google_place_id' => $g['google_place_id'],
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'atc_airport', 'We could not add that airport. Please try again.', array( 'status' => 500 ) );
		}
		ATC_Install::log( 'airport', $id, 'created_from_google', array( 'name' => $g['name'], 'place_id' => $g['google_place_id'] ) );
		return self::airport( $id ) + array( 'is_new' => true );
	}

	public static function airport( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'airports' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function vehicle_types(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, slug, name, max_passengers, max_bags FROM ' . self::t( 'vehicle_types' ) . ' ORDER BY sort, id', ARRAY_A );
	}

	/* ---------- Zones ---------- */

	public static function zones( int $airport_id, bool $with_archived = false ): array {
		global $wpdb;
		$z   = self::t( 'zones' );
		$p   = self::t( 'places' );
		$sql = "SELECT z.id, z.airport_id, z.name, z.slug, z.polygon, z.sort, z.status,
				(SELECT COUNT(*) FROM {$p} p WHERE p.zone_id = z.id AND p.status = 'active') AS places
			FROM {$z} z WHERE z.airport_id = %d" . ( $with_archived ? '' : " AND z.status = 'active'" ) . ' ORDER BY z.sort, z.id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $airport_id ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$r['polygon'] = json_decode( (string) $r['polygon'], true ) ?: array();
			$r['places']  = (int) $r['places'];
			$r['id']      = (int) $r['id'];
			$r['sort']    = (int) $r['sort'];
		}
		return $rows;
	}

	public static function save_zone( array $in, ?int $id = null ) {
		global $wpdb;
		$name = isset( $in['name'] ) ? trim( sanitize_text_field( $in['name'] ) ) : '';
		$data = array();

		if ( null === $id || isset( $in['name'] ) ) {
			if ( '' === $name || mb_strlen( $name ) > 100 ) {
				return new WP_Error( 'atc_zone_name', 'Give the zone a name (up to 100 characters).', array( 'status' => 400 ) );
			}
			$data['name'] = $name;
		}
		// A zone can exist by name first and get its shape later.
		if ( array_key_exists( 'polygon', $in ) && null !== $in['polygon'] ) {
			$poly = ATC_Geo::clean_polygon( $in['polygon'] );
			if ( ! $poly ) {
				return new WP_Error( 'atc_zone_polygon', 'A zone needs at least 3 points on the map.', array( 'status' => 400 ) );
			}
			$data['polygon'] = wp_json_encode( $poly );
		}
		if ( isset( $in['sort'] ) ) {
			$data['sort'] = (int) $in['sort'];
		}

		if ( null === $id ) {
			$airport_id = (int) ( $in['airport_id'] ?? 0 );
			if ( ! self::airport( $airport_id ) ) {
				return new WP_Error( 'atc_airport', 'Unknown airport.', array( 'status' => 400 ) );
			}
			$data['airport_id'] = $airport_id;
			$data['slug']       = self::unique_zone_slug( $airport_id, $name );
			$data['status']     = 'active';
			if ( ! isset( $data['sort'] ) ) {
				$data['sort'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort),0)+10 FROM ' . self::t( 'zones' ) . ' WHERE airport_id = %d', $airport_id ) );
			}
			$wpdb->insert( self::t( 'zones' ), $data );
			$id = (int) $wpdb->insert_id;
			ATC_Install::log( 'zone', $id, 'created', array( 'name' => $name ) );
		} else {
			$zone = self::zone( $id );
			if ( ! $zone ) {
				return new WP_Error( 'atc_not_found', 'Zone not found.', array( 'status' => 404 ) );
			}
			$airport_id = (int) $zone['airport_id'];
			if ( $data ) {
				$wpdb->update( self::t( 'zones' ), $data, array( 'id' => $id ) );
				ATC_Install::log( 'zone', $id, 'updated', array_diff_key( $data, array( 'polygon' => 1 ) ) + ( isset( $data['polygon'] ) ? array( 'reshaped' => true ) : array() ) );
			}
		}

		$moved = self::rematch_places( $airport_id );
		return array( 'id' => $id, 'places_moved' => $moved );
	}

	public static function zone( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'zones' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Zones are archived, never deleted, because prices and bookings point at them. */
	public static function archive_zone( int $id ) {
		global $wpdb;
		$zone = self::zone( $id );
		if ( ! $zone ) {
			return new WP_Error( 'atc_not_found', 'Zone not found.', array( 'status' => 404 ) );
		}
		$wpdb->update( self::t( 'zones' ), array( 'status' => 'archived' ), array( 'id' => $id ) );
		$wpdb->delete( self::t( 'service_zones' ), array( 'zone_id' => $id ) );
		ATC_Install::log( 'zone', $id, 'archived', array( 'name' => $zone['name'] ) );
		return array( 'id' => $id, 'places_moved' => self::rematch_places( (int) $zone['airport_id'] ) );
	}

	private static function unique_zone_slug( int $airport_id, string $name ): string {
		global $wpdb;
		$base = sanitize_title( $name ) ?: 'zone';
		$slug = $base;
		$i    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'zones' ) . ' WHERE airport_id = %d AND slug = %s', $airport_id, $slug ) ) ) {
			$slug = $base . '-' . $i++;
		}
		return $slug;
	}

	/** Re-run zone matching for places matched by address (hand-placed ones stay). Returns how many changed. */
	public static function rematch_places( int $airport_id ): int {
		global $wpdb;
		$zones  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, polygon, sort FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active'", $airport_id ), ARRAY_A );
		$places = $wpdb->get_results( $wpdb->prepare( 'SELECT id, zone_id, lat, lng FROM ' . self::t( 'places' ) . " WHERE airport_id = %d AND status = 'active' AND (matched_by IS NULL OR matched_by <> 'manual') AND lat IS NOT NULL AND lng IS NOT NULL", $airport_id ), ARRAY_A );
		$moved  = 0;
		foreach ( $places as $p ) {
			$zone = ATC_Geo::match_zone( (float) $p['lat'], (float) $p['lng'], $zones );
			$old  = null === $p['zone_id'] ? null : (int) $p['zone_id'];
			if ( $zone !== $old ) {
				$wpdb->update( self::t( 'places' ), array( 'zone_id' => $zone, 'matched_by' => $zone ? 'address' : null ), array( 'id' => (int) $p['id'] ) );
				++$moved;
			}
		}
		return $moved;
	}

	/* ---------- Hotels and addresses ---------- */

	public static function places( int $airport_id, array $args = array() ): array {
		global $wpdb;
		$p     = self::t( 'places' );
		$where = array( $wpdb->prepare( 'airport_id = %d', $airport_id ), "status = 'active'" );
		if ( ! empty( $args['unmatched'] ) ) {
			$where[] = 'zone_id IS NULL';
		} elseif ( ! empty( $args['zone_id'] ) ) {
			$where[] = $wpdb->prepare( 'zone_id = %d', (int) $args['zone_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR address LIKE %s)', $like, $like );
		}
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 200 ) ) );
		$rows  = $wpdb->get_results( "SELECT id, zone_id, type, name, address, lat, lng, google_place_id, matched_by FROM {$p} WHERE " . implode( ' AND ', $where ) . " ORDER BY name LIMIT {$limit}", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$r['id']      = (int) $r['id'];
			$r['zone_id'] = null === $r['zone_id'] ? null : (int) $r['zone_id'];
			$r['lat']     = null === $r['lat'] ? null : (float) $r['lat'];
			$r['lng']     = null === $r['lng'] ? null : (float) $r['lng'];
		}
		return $rows;
	}

	public static function save_place( array $in ) {
		global $wpdb;
		$airport_id = (int) ( $in['airport_id'] ?? 0 );
		if ( ! self::airport( $airport_id ) ) {
			return new WP_Error( 'atc_airport', 'Unknown airport.', array( 'status' => 400 ) );
		}
		$name = trim( sanitize_text_field( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'atc_place_name', 'The hotel needs a name.', array( 'status' => 400 ) );
		}
		$lat = isset( $in['lat'] ) && is_numeric( $in['lat'] ) ? (float) $in['lat'] : null;
		$lng = isset( $in['lng'] ) && is_numeric( $in['lng'] ) ? (float) $in['lng'] : null;
		if ( null === $lat || null === $lng ) {
			return new WP_Error( 'atc_place_location', 'Pick the hotel from the Google search so we know where it is.', array( 'status' => 400 ) );
		}
		$gid = isset( $in['google_place_id'] ) ? sanitize_text_field( $in['google_place_id'] ) : '';

		if ( '' !== $gid ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, zone_id, status FROM ' . self::t( 'places' ) . ' WHERE airport_id = %d AND google_place_id = %s', $airport_id, $gid ), ARRAY_A );
			if ( $existing ) {
				if ( 'active' !== $existing['status'] ) {
					$wpdb->update( self::t( 'places' ), array( 'status' => 'active' ), array( 'id' => (int) $existing['id'] ) );
				}
				return array( 'id' => (int) $existing['id'], 'zone_id' => null === $existing['zone_id'] ? null : (int) $existing['zone_id'], 'existing' => true );
			}
		}

		$zones = $wpdb->get_results( $wpdb->prepare( 'SELECT id, polygon, sort FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active'", $airport_id ), ARRAY_A );
		$zone  = ATC_Geo::match_zone( $lat, $lng, $zones );

		$wpdb->insert(
			self::t( 'places' ),
			array(
				'airport_id'      => $airport_id,
				'zone_id'         => $zone,
				'type'            => in_array( $in['type'] ?? '', array( 'hotel', 'address', 'port', 'other' ), true ) ? $in['type'] : 'hotel',
				'name'            => mb_substr( $name, 0, 190 ),
				'address'         => mb_substr( sanitize_text_field( $in['address'] ?? '' ), 0, 255 ),
				'lat'             => $lat,
				'lng'             => $lng,
				'google_place_id' => '' === $gid ? null : $gid,
				'matched_by'      => $zone ? 'address' : null,
				'status'          => 'active',
			)
		);
		$id = (int) $wpdb->insert_id;
		ATC_Install::log( 'place', $id, 'created', array( 'name' => $name, 'zone_id' => $zone ) );
		return array( 'id' => $id, 'zone_id' => $zone, 'existing' => false );
	}

	/** Move a place to a zone by hand (null puts it back to automatic matching). */
	public static function set_place_zone( int $id, $zone_id ) {
		global $wpdb;
		$place = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'places' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $place ) {
			return new WP_Error( 'atc_not_found', 'Hotel not found.', array( 'status' => 404 ) );
		}
		if ( null === $zone_id || '' === $zone_id || 'auto' === $zone_id ) {
			$wpdb->update( self::t( 'places' ), array( 'matched_by' => null ), array( 'id' => $id ) );
			self::rematch_places( (int) $place['airport_id'] );
			ATC_Install::log( 'place', $id, 'zone_auto' );
		} else {
			$zone = self::zone( (int) $zone_id );
			if ( ! $zone || (int) $zone['airport_id'] !== (int) $place['airport_id'] ) {
				return new WP_Error( 'atc_zone', 'That zone is not at this airport.', array( 'status' => 400 ) );
			}
			$wpdb->update( self::t( 'places' ), array( 'zone_id' => (int) $zone_id, 'matched_by' => 'manual' ), array( 'id' => $id ) );
			ATC_Install::log( 'place', $id, 'zone_manual', array( 'zone_id' => (int) $zone_id ) );
		}
		return array( 'id' => $id );
	}

	public static function archive_place( int $id ) {
		global $wpdb;
		$wpdb->update( self::t( 'places' ), array( 'status' => 'archived' ), array( 'id' => $id ) );
		ATC_Install::log( 'place', $id, 'archived' );
		return array( 'id' => $id );
	}

	/** Find up to 25 hotels by name with Google and add them. */
	public static function import_places( int $airport_id, array $lines ): array {
		$airport = self::airport( $airport_id );
		$out     = array();
		if ( ! $airport ) {
			return $out;
		}
		$lines = array_slice( array_values( array_unique( array_filter( array_map( static fn( $l ) => trim( sanitize_text_field( (string) $l ) ), $lines ) ) ) ), 0, 25 );
		foreach ( $lines as $line ) {
			[ $place, $err ] = ATC_Geo::find_place( $line . ' ' . $airport['city'], (float) $airport['lat'], (float) $airport['lng'] );
			if ( ! $place ) {
				$out[] = array( 'query' => $line, 'ok' => false, 'error' => $err );
				continue;
			}
			$saved = self::save_place( $place + array( 'airport_id' => $airport_id ) );
			$out[] = is_wp_error( $saved )
				? array( 'query' => $line, 'ok' => false, 'error' => $saved->get_error_message() )
				: array( 'query' => $line, 'ok' => true, 'name' => $place['name'], 'address' => $place['address'], 'zone_id' => $saved['zone_id'], 'existing' => $saved['existing'] );
		}
		return $out;
	}

	/** Tiles of a zone for "Find all hotels inside". */
	public static function zone_tiles( int $zone_id ) {
		$zone = self::zone( $zone_id );
		if ( ! $zone ) {
			return new WP_Error( 'atc_not_found', 'Zone not found.', array( 'status' => 404 ) );
		}
		$ring = json_decode( (string) $zone['polygon'], true );
		if ( ! is_array( $ring ) || count( $ring ) < 3 ) {
			return new WP_Error( 'atc_zone_shape', 'Give this zone a shape first.', array( 'status' => 400 ) );
		}
		return ATC_Geo::tiles( $ring );
	}

	/** Finds lodging in one tile of a zone and adds what lies inside the zone. */
	public static function import_zone_tile( int $zone_id, int $index ) {
		$tiles = self::zone_tiles( $zone_id );
		if ( is_wp_error( $tiles ) ) {
			return $tiles;
		}
		if ( ! isset( $tiles[ $index ] ) ) {
			return new WP_Error( 'atc_tile', 'No such part of the zone.', array( 'status' => 400 ) );
		}
		$zone = self::zone( $zone_id );
		$ring = json_decode( (string) $zone['polygon'], true );
		$res  = array( 'added' => 0, 'existing' => 0, 'outside' => 0, 'other_zone' => 0, 'names' => array() );
		$token = '';
		for ( $page = 0; $page < 3; $page++ ) {
			[ $batch, $err ] = ATC_Geo::lodging_in_rect( $tiles[ $index ], $token );
			if ( ! $batch ) {
				return new WP_Error( 'atc_google', 'Google: ' . $err, array( 'status' => 502 ) );
			}
			foreach ( $batch['places'] as $p ) {
				if ( ! ATC_Geo::point_in_polygon( $p['lat'], $p['lng'], $ring ) ) {
					++$res['outside'];
					continue;
				}
				$saved = self::save_place( $p + array( 'airport_id' => (int) $zone['airport_id'], 'type' => 'hotel' ) );
				if ( is_wp_error( $saved ) ) {
					continue;
				}
				if ( $saved['existing'] ) {
					++$res['existing'];
				} else {
					++$res['added'];
					$res['names'][] = $p['name'];
				}
				if ( $saved['zone_id'] && (int) $saved['zone_id'] !== $zone_id ) {
					++$res['other_zone'];
				}
			}
			$token = $batch['next'];
			if ( '' === $token ) {
				break;
			}
			usleep( 300000 );
		}
		return $res;
	}

	/** Which zone a point falls in (hand-placed hotels first, then shapes). Same rule the booking search will use. */
	public static function zone_at( int $airport_id, float $lat, float $lng, string $google_place_id = '' ): array {
		global $wpdb;
		if ( '' !== $google_place_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT zone_id, matched_by, name FROM ' . self::t( 'places' ) . " WHERE airport_id = %d AND google_place_id = %s AND status = 'active'", $airport_id, $google_place_id ), ARRAY_A );
			if ( $row && 'manual' === $row['matched_by'] && $row['zone_id'] ) {
				$z = self::zone( (int) $row['zone_id'] );
				return array( 'zone_id' => (int) $row['zone_id'], 'zone' => $z ? $z['name'] : '', 'how' => 'placed by hand', 'listed' => true );
			}
			$listed = (bool) $row;
		}
		$zones = $wpdb->get_results( $wpdb->prepare( 'SELECT id, polygon, sort FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active'", $airport_id ), ARRAY_A );
		$id    = ATC_Geo::match_zone( $lat, $lng, $zones );
		$z     = $id ? self::zone( $id ) : null;
		return array( 'zone_id' => $id, 'zone' => $z ? $z['name'] : null, 'how' => $id ? 'inside the shape' : 'outside every zone', 'listed' => ! empty( $listed ) );
	}

	/* ---------- Providers ---------- */

	const PARTY_FIELDS = array( 'name', 'legal_name', 'country', 'email', 'phone', 'languages', 'commission_bp', 'min_notice_minutes', 'payout_method', 'payout_currency', 'status' );

	public static function parties( string $type ): array {
		global $wpdb;
		$p   = self::t( 'parties' );
		$s   = self::t( 'services' );
		$sql = "SELECT pa.id, pa.type, pa.name, pa.legal_name, pa.country, pa.email, pa.phone, pa.languages, pa.commission_bp,
				pa.min_notice_minutes, pa.payout_method, pa.payout_currency, pa.status, pa.created_at,
				(SELECT COUNT(*) FROM {$s} s WHERE s.party_id = pa.id AND s.status = 'live') AS services_live
			FROM {$p} pa WHERE pa.type = %s ORDER BY pa.status = 'archived', pa.name";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $type ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$r['id']            = (int) $r['id'];
			$r['services_live'] = (int) $r['services_live'];
		}
		return $rows;
	}

	public static function party( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		unset( $row['payout_details'] );
		$row['id'] = (int) $row['id'];
		return $row;
	}

	public static function save_party( array $in, ?int $id = null ) {
		global $wpdb;
		$data = array();

		if ( null === $id || isset( $in['name'] ) ) {
			$name = trim( sanitize_text_field( $in['name'] ?? '' ) );
			if ( '' === $name ) {
				return new WP_Error( 'atc_party_name', 'The provider needs a name.', array( 'status' => 400 ) );
			}
			$data['name'] = mb_substr( $name, 0, 190 );
		}
		if ( isset( $in['legal_name'] ) ) {
			$data['legal_name'] = mb_substr( sanitize_text_field( $in['legal_name'] ), 0, 190 );
		}
		if ( isset( $in['country'] ) ) {
			$c               = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['country'] ) );
			$data['country'] = 2 === strlen( $c ) ? $c : null;
		}
		if ( isset( $in['email'] ) ) {
			$e = sanitize_email( $in['email'] );
			if ( '' !== (string) $in['email'] && ! is_email( $e ) ) {
				return new WP_Error( 'atc_party_email', 'That email address doesn\'t look right.', array( 'status' => 400 ) );
			}
			$data['email'] = $e ?: null;
		}
		if ( isset( $in['phone'] ) ) {
			$data['phone'] = mb_substr( preg_replace( '/[^0-9+ ()\-]/', '', (string) $in['phone'] ), 0, 40 );
		}
		if ( isset( $in['about'] ) ) {
			$data['about'] = mb_substr( sanitize_textarea_field( (string) $in['about'] ), 0, 2000 );
		}
		foreach ( array( 'contact_name', 'website' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$data[ $k ] = mb_substr( 'website' === $k ? esc_url_raw( $in[ $k ] ) : sanitize_text_field( $in[ $k ] ), 0, 190 );
			}
		}
		if ( isset( $in['languages'] ) ) {
			$data['languages'] = mb_substr( sanitize_text_field( $in['languages'] ), 0, 100 );
		}
		if ( array_key_exists( 'commission_bp', $in ) ) {
			$data['commission_bp'] = ( '' === $in['commission_bp'] || null === $in['commission_bp'] ) ? null : max( 0, min( 9000, (int) $in['commission_bp'] ) );
		}
		if ( array_key_exists( 'min_notice_minutes', $in ) ) {
			$data['min_notice_minutes'] = ( '' === $in['min_notice_minutes'] || null === $in['min_notice_minutes'] ) ? null : max( 0, min( 10080, (int) $in['min_notice_minutes'] ) );
		}
		if ( isset( $in['payout_method'] ) ) {
			$data['payout_method'] = in_array( $in['payout_method'], array( 'manual', 'wise', 'bank', 'stripe' ), true ) ? $in['payout_method'] : 'manual';
		}
		if ( isset( $in['payout_currency'] ) ) {
			$data['payout_currency'] = in_array( $in['payout_currency'], array( 'USD', 'MXN', 'EUR' ), true ) ? $in['payout_currency'] : 'USD';
		}
		if ( isset( $in['status'] ) ) {
			$data['status'] = in_array( $in['status'], array( 'pending', 'live', 'paused', 'suspended', 'archived' ), true ) ? $in['status'] : 'pending';
		}

		$data['updated_at'] = self::now();

		if ( null === $id ) {
			$data['type']       = 'provider';
			$data['created_at'] = self::now();
			$data['status']     = $data['status'] ?? 'pending';
			$wpdb->insert( self::t( 'parties' ), $data );
			$id = (int) $wpdb->insert_id;
			ATC_Install::log( 'party', $id, 'created', array( 'name' => $data['name'] ) );
		} else {
			if ( ! self::party( $id ) ) {
				return new WP_Error( 'atc_not_found', 'Provider not found.', array( 'status' => 404 ) );
			}
			$before = self::party( $id );
			$wpdb->update( self::t( 'parties' ), $data, array( 'id' => $id ) );
			ATC_Install::log( 'party', $id, 'updated', array_diff_key( $data, array( 'updated_at' => 1 ) ) );
			// Going live switches on the vehicle categories the provider offered.
			if ( isset( $data['status'] ) && 'live' === $data['status'] && 'live' !== $before['status'] ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'services' ) . " SET status = 'live' WHERE party_id = %d AND status = 'draft'", $id ) );
				if ( $before['email'] ) {
					wp_mail( $before['email'], 'You are live on Airport Transportation', 'Good news: ' . $before['name'] . " is approved. Travelers can now book you in the zones and vehicles you set up.\n\n" . home_url( '/portal/' ), array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
				}
			}
		}
		return self::party( $id );
	}

	/* ---------- Services (a provider's vehicle category at an airport) ---------- */

	public static function services( int $party_id ): array {
		global $wpdb;
		$s    = self::t( 'services' );
		$v    = self::t( 'vehicle_types' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.airport_id, s.vehicle_type_id, v.name AS vehicle_type, s.max_passengers, s.max_bags, s.free_wait_minutes, s.min_notice_minutes, s.status
				FROM {$s} s JOIN {$v} v ON v.id = s.vehicle_type_id WHERE s.party_id = %d ORDER BY v.sort",
				$party_id
			),
			ARRAY_A
		);
		$sz = self::t( 'service_zones' );
		foreach ( $rows as &$r ) {
			$r['id']       = (int) $r['id'];
			$r['zone_ids'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT zone_id FROM {$sz} WHERE service_id = %d", $r['id'] ) ) );
		}
		return $rows;
	}

	/** Create or update the service for (provider, airport, vehicle category), with its zones. */
	public static function save_service( int $party_id, array $in ) {
		global $wpdb;
		if ( ! self::party( $party_id ) ) {
			return new WP_Error( 'atc_not_found', 'Provider not found.', array( 'status' => 404 ) );
		}
		$airport_id = (int) ( $in['airport_id'] ?? 0 );
		$type_id    = (int) ( $in['vehicle_type_id'] ?? 0 );
		$type       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'vehicle_types' ) . ' WHERE id = %d', $type_id ), ARRAY_A );
		if ( ! self::airport( $airport_id ) || ! $type ) {
			return new WP_Error( 'atc_service', 'Pick an airport and a vehicle category.', array( 'status' => 400 ) );
		}

		$data = array(
			'max_passengers'     => max( 1, min( 60, (int) ( $in['max_passengers'] ?? $type['max_passengers'] ) ) ),
			'max_bags'           => max( 0, min( 60, (int) ( $in['max_bags'] ?? $type['max_bags'] ) ) ),
			'free_wait_minutes'  => max( 0, min( 240, (int) ( $in['free_wait_minutes'] ?? 60 ) ) ),
			'min_notice_minutes' => max( 0, min( 10080, (int) ( $in['min_notice_minutes'] ?? 1440 ) ) ),
			'status'             => in_array( $in['status'] ?? '', array( 'draft', 'live', 'paused' ), true ) ? $in['status'] : 'draft',
			'updated_at'         => self::now(),
		);

		$s  = self::t( 'services' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$s} WHERE party_id = %d AND airport_id = %d AND vehicle_type_id = %d", $party_id, $airport_id, $type_id ) );
		if ( $id ) {
			$wpdb->update( $s, $data, array( 'id' => $id ) );
			ATC_Install::log( 'service', $id, 'updated', array_diff_key( $data, array( 'updated_at' => 1 ) ) );
		} else {
			$wpdb->insert( $s, $data + array( 'party_id' => $party_id, 'airport_id' => $airport_id, 'vehicle_type_id' => $type_id, 'created_at' => self::now() ) );
			$id = (int) $wpdb->insert_id;
			// A new category serves the same zones as the provider's other categories at this airport.
			if ( ! isset( $in['zone_ids'] ) ) {
				$sibling = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$s} WHERE party_id = %d AND airport_id = %d AND id <> %d ORDER BY id LIMIT 1", $party_id, $airport_id, $id ) );
				if ( $sibling ) {
					$in['zone_ids'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT zone_id FROM ' . self::t( 'service_zones' ) . ' WHERE service_id = %d', $sibling ) ) );
				}
			}
			ATC_Install::log( 'service', $id, 'created', array( 'party_id' => $party_id, 'vehicle_type' => $type['name'] ) );
		}

		if ( isset( $in['zone_ids'] ) && is_array( $in['zone_ids'] ) ) {
			$valid = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active'", $airport_id ) ) );
			$want  = array_values( array_intersect( array_map( 'intval', $in['zone_ids'] ), $valid ) );
			$sz    = self::t( 'service_zones' );
			$wpdb->delete( $sz, array( 'service_id' => $id ) );
			foreach ( $want as $zid ) {
				$wpdb->insert( $sz, array( 'service_id' => $id, 'zone_id' => $zid ) );
			}
		}
		return array( 'id' => $id );
	}

	/* ---------- Prices ---------- */

	/**
	 * A provider's prices, grouped per airport: zones as rows, vehicle categories as columns,
	 * each cell with one-way and round-trip Suggested retail price and Amount you will get paid (cents).
	 */
	public static function price_table( int $party_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id AS airport_id, a.iata, a.name AS airport, z.id AS zone_id, z.name AS zone, z.sort AS zsort,
					v.id AS vehicle_type_id, v.name AS vehicle, v.sort AS vsort, s.status AS service_status,
					p.direction, p.retail_cents, p.provider_cents
				FROM ' . self::t( 'prices' ) . ' p
				JOIN ' . self::t( 'services' ) . ' s ON s.id = p.service_id
				JOIN ' . self::t( 'zones' ) . ' z ON z.id = p.zone_id
				JOIN ' . self::t( 'vehicle_types' ) . ' v ON v.id = s.vehicle_type_id
				JOIN ' . self::t( 'airports' ) . ' a ON a.id = s.airport_id
				WHERE s.party_id = %d AND p.valid_from IS NULL
				ORDER BY a.iata, z.sort, z.name, v.sort',
				$party_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$k = $r['iata'];
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'airport_id' => (int) $r['airport_id'], 'iata' => $r['iata'], 'airport' => $r['airport'], 'vehicles' => array(), 'zones' => array() );
			}
			$out[ $k ]['vehicles'][ (int) $r['vehicle_type_id'] ] = array( 'id' => (int) $r['vehicle_type_id'], 'name' => $r['vehicle'], 'sort' => (int) $r['vsort'], 'status' => $r['service_status'] );
			$zk = (int) $r['zone_id'];
			if ( ! isset( $out[ $k ]['zones'][ $zk ] ) ) {
				$out[ $k ]['zones'][ $zk ] = array( 'id' => $zk, 'name' => $r['zone'], 'cells' => array() );
			}
			$out[ $k ]['zones'][ $zk ]['cells'][ (int) $r['vehicle_type_id'] ][ $r['direction'] ] = array( 'retail' => (int) $r['retail_cents'], 'provider' => (int) $r['provider_cents'] );
		}
		foreach ( $out as &$a ) {
			uasort( $a['vehicles'], static fn( $x, $y ) => $x['sort'] <=> $y['sort'] );
			$a['vehicles'] = array_values( $a['vehicles'] );
			$a['zones']    = array_values( $a['zones'] );
		}
		return array_values( $out );
	}

	/* ---------- Vehicles ---------- */

	public static function vehicles( int $party_id ): array {
		global $wpdb;
		$v    = self::t( 'vehicles' );
		$t    = self::t( 'vehicle_types' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT v.id, v.vehicle_type_id, t.name AS vehicle_type, v.make_model, v.year, v.plate, v.seats, v.insurance_until, v.status FROM {$v} v JOIN {$t} t ON t.id = v.vehicle_type_id WHERE v.party_id = %d AND v.status <> 'archived' ORDER BY t.sort, v.make_model", $party_id ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['id'] = (int) $r['id'];
		}
		return $rows;
	}

	public static function save_vehicle( int $party_id, array $in, ?int $id = null ) {
		global $wpdb;
		if ( ! self::party( $party_id ) ) {
			return new WP_Error( 'atc_not_found', 'Provider not found.', array( 'status' => 404 ) );
		}
		$model = trim( sanitize_text_field( $in['make_model'] ?? '' ) );
		$type  = (int) ( $in['vehicle_type_id'] ?? 0 );
		if ( '' === $model || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'vehicle_types' ) . ' WHERE id = %d', $type ) ) ) {
			return new WP_Error( 'atc_vehicle', 'Give the vehicle a make and model, and a category.', array( 'status' => 400 ) );
		}
		$date = isset( $in['insurance_until'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['insurance_until'] ) ? $in['insurance_until'] : null;
		$data = array(
			'vehicle_type_id' => $type,
			'make_model'      => mb_substr( $model, 0, 100 ),
			'year'            => isset( $in['year'] ) && (int) $in['year'] > 1980 ? (int) $in['year'] : null,
			'plate'           => mb_substr( strtoupper( sanitize_text_field( $in['plate'] ?? '' ) ), 0, 20 ),
			'seats'           => isset( $in['seats'] ) ? max( 1, min( 60, (int) $in['seats'] ) ) : null,
			'insurance_until' => $date,
		);
		if ( null === $id ) {
			$wpdb->insert( self::t( 'vehicles' ), $data + array( 'party_id' => $party_id, 'status' => 'active' ) );
			$id = (int) $wpdb->insert_id;
			ATC_Install::log( 'vehicle', $id, 'created', array( 'party_id' => $party_id ) );
		} else {
			$wpdb->update( self::t( 'vehicles' ), $data, array( 'id' => $id, 'party_id' => $party_id ) );
			ATC_Install::log( 'vehicle', $id, 'updated' );
		}
		return array( 'id' => $id );
	}

	public static function archive_vehicle( int $party_id, int $id ) {
		global $wpdb;
		$wpdb->update( self::t( 'vehicles' ), array( 'status' => 'archived' ), array( 'id' => $id, 'party_id' => $party_id ) );
		ATC_Install::log( 'vehicle', $id, 'archived' );
		return array( 'id' => $id );
	}
}
