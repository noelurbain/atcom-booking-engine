<?php
/**
 * Maps: zone matching (point in polygon), Google Places search and geocoding with the server key.
 * Polygons are stored as JSON arrays of [lat, lng] pairs.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Geo {

	/** Ray casting. $polygon: list of [lat, lng]. Points on an edge count as inside. */
	public static function point_in_polygon( float $lat, float $lng, array $polygon ): bool {
		$n = count( $polygon );
		if ( $n < 3 ) {
			return false;
		}
		$inside = false;
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			[ $yi, $xi ] = array_map( 'floatval', $polygon[ $i ] );
			[ $yj, $xj ] = array_map( 'floatval', $polygon[ $j ] );

			// On the edge?
			$cross = ( $lng - $xi ) * ( $yj - $yi ) - ( $lat - $yi ) * ( $xj - $xi );
			if ( abs( $cross ) < 1e-12 && $lng >= min( $xi, $xj ) && $lng <= max( $xi, $xj ) && $lat >= min( $yi, $yj ) && $lat <= max( $yi, $yj ) ) {
				return true;
			}

			if ( ( $yi > $lat ) !== ( $yj > $lat ) ) {
				$x_at = ( $xj - $xi ) * ( $lat - $yi ) / ( $yj - $yi ) + $xi;
				if ( $lng < $x_at ) {
					$inside = ! $inside;
				}
			}
		}
		return $inside;
	}

	/** Clean a polygon coming from the browser: at least 3 points, valid coordinates, max 500 points. */
	public static function clean_polygon( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || count( $raw ) < 3 || count( $raw ) > 500 ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $pt ) {
			if ( ! is_array( $pt ) || count( $pt ) < 2 || ! is_numeric( $pt[0] ) || ! is_numeric( $pt[1] ) ) {
				return null;
			}
			$lat = round( (float) $pt[0], 7 );
			$lng = round( (float) $pt[1], 7 );
			if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
				return null;
			}
			$out[] = array( $lat, $lng );
		}
		return $out;
	}

	/**
	 * First zone (by sort order) of an airport that contains the point.
	 *
	 * @param array $zones rows with id, polygon (JSON), sort.
	 */
	public static function match_zone( float $lat, float $lng, array $zones ): ?int {
		usort( $zones, static fn( $a, $b ) => ( (int) $a['sort'] <=> (int) $b['sort'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
		foreach ( $zones as $z ) {
			$poly = json_decode( (string) $z['polygon'], true );
			if ( is_array( $poly ) && self::point_in_polygon( $lat, $lng, $poly ) ) {
				return (int) $z['id'];
			}
		}
		return null;
	}

	/**
	 * Places API (New) text search near an airport, with the server key.
	 *
	 * @return array{0:?array,1:?string} [place or null, error or null]
	 */
	public static function find_place( string $query, float $near_lat, float $near_lng ): array {
		$key = ATC_Settings::maps_server_key();
		if ( '' === $key ) {
			return array( null, 'The Server key (ATC_MAPS_SERVER_KEY) is not in wp-config.php.' );
		}
		$res = wp_remote_post(
			'https://places.googleapis.com/v1/places:searchText',
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
				),
				'body'    => wp_json_encode(
					array(
						'textQuery'    => $query,
						'pageSize'     => 1,
						'languageCode' => 'en',
						'locationBias' => array(
							'circle' => array(
								'center' => array( 'latitude' => $near_lat, 'longitude' => $near_lng ),
								'radius' => 50000.0,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( null, $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return array( null, $body['error']['message'] ?? ( 'Google returned HTTP ' . $code ) );
		}
		$p = $body['places'][0] ?? null;
		if ( ! $p || empty( $p['location'] ) ) {
			return array( null, 'Not found' );
		}
		return array(
			array(
				'google_place_id' => (string) $p['id'],
				'name'            => (string) ( $p['displayName']['text'] ?? $query ),
				'address'         => (string) ( $p['formattedAddress'] ?? '' ),
				'lat'             => (float) $p['location']['latitude'],
				'lng'             => (float) $p['location']['longitude'],
			),
			null,
		);
	}

	/**
	 * One page of up to 20 hotels and other lodging strictly inside a rectangle (Places API New).
	 * $rect: ['low'=>['latitude','longitude'],'high'=>[...]].
	 */
	public static function lodging_in_rect( array $rect, string $page_token = '' ): array {
		$key = ATC_Settings::maps_server_key();
		if ( '' === $key ) {
			return array( null, 'The Server key (ATC_MAPS_SERVER_KEY) is not in wp-config.php.' );
		}
		$body = array(
			'textQuery'           => 'hotel',
			'includedType'        => 'lodging',
			'strictTypeFiltering' => true,
			'pageSize'            => 20,
			'languageCode'        => 'en',
			'locationRestriction' => array( 'rectangle' => $rect ),
		);
		if ( '' !== $page_token ) {
			$body['pageToken'] = $page_token;
		}
		$res = null;
		for ( $try = 0; $try < 3; $try++ ) {
			if ( $try ) {
				usleep( 700000 * $try );
			}
			$res = wp_remote_post(
				'https://places.googleapis.com/v1/places:searchText',
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'     => 'application/json',
						'X-Goog-Api-Key'   => $key,
						'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.businessStatus,nextPageToken',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) < 500 ) {
				break;
			}
		}
		if ( is_wp_error( $res ) ) {
			return array( null, $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( null, $data['error']['message'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$out = array();
		foreach ( (array) ( $data['places'] ?? array() ) as $p ) {
			if ( 'CLOSED_PERMANENTLY' === ( $p['businessStatus'] ?? '' ) || empty( $p['location'] ) ) {
				continue;
			}
			$out[] = array(
				'google_place_id' => (string) ( $p['id'] ?? '' ),
				'name'            => (string) ( $p['displayName']['text'] ?? '' ),
				'address'         => (string) ( $p['formattedAddress'] ?? '' ),
				'lat'             => (float) $p['location']['latitude'],
				'lng'             => (float) $p['location']['longitude'],
			);
		}
		return array( array( 'places' => $out, 'next' => (string) ( $data['nextPageToken'] ?? '' ) ), null );
	}

	/**
	 * Splits a polygon's bounding box into tiles of about 3.5 km and keeps the ones the shape touches.
	 * Google returns at most 60 results per search, so small tiles find every hotel in a dense area.
	 */
	public static function tiles( array $ring, float $deg = 0.035, int $max = 150 ): array {
		if ( count( $ring ) < 3 ) {
			return array();
		}
		$lats = array_column( $ring, 0 );
		$lngs = array_column( $ring, 1 );
		$s    = min( $lats );
		$n    = max( $lats );
		$w    = min( $lngs );
		$e    = max( $lngs );
		$rows = max( 1, (int) ceil( ( $n - $s ) / $deg ) );
		$cols = max( 1, (int) ceil( ( $e - $w ) / $deg ) );
		$dy   = ( $n - $s ) / $rows;
		$dx   = ( $e - $w ) / $cols;
		$out  = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			for ( $c = 0; $c < $cols; $c++ ) {
				$bs    = $s + $r * $dy;
				$bw    = $w + $c * $dx;
				$probe = array( array( $bs, $bw ), array( $bs + $dy, $bw ), array( $bs, $bw + $dx ), array( $bs + $dy, $bw + $dx ), array( $bs + $dy / 2, $bw + $dx / 2 ) );
				$touch = false;
				foreach ( $probe as $pt ) {
					if ( self::point_in_polygon( $pt[0], $pt[1], $ring ) ) {
						$touch = true;
						break;
					}
				}
				if ( ! $touch ) {
					foreach ( $ring as $v ) {
						if ( $v[0] >= $bs && $v[0] <= $bs + $dy && $v[1] >= $bw && $v[1] <= $bw + $dx ) {
							$touch = true;
							break;
						}
					}
				}
				if ( $touch ) {
					$out[] = array(
						'low'  => array( 'latitude' => round( $bs, 6 ), 'longitude' => round( $bw, 6 ) ),
						'high' => array( 'latitude' => round( $bs + $dy, 6 ), 'longitude' => round( $bw + $dx, 6 ) ),
					);
				}
			}
		}
		return array_slice( $out, 0, $max );
	}

	/* ---------- Shape suggestions for a zone name ---------- */

	/**
	 * Ready-made shapes for an area name, best first:
	 * OpenStreetMap boundaries (real outlines, when the area has one) and Google's box for the place.
	 *
	 * @return array{0:array,1:array} [candidates, errors]
	 */
	public static function shape_suggestions( string $name, array $airport ): array {
		$cache = 'atc_shape_' . md5( strtolower( $name ) . '|' . $airport['iata'] );
		$hit   = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return array( $hit, array() );
		}

		$out    = array();
		$errors = array();

		[ $osm, $err ] = self::osm_boundaries( $name, $airport );
		if ( $err ) {
			$errors[] = 'OpenStreetMap: ' . $err;
		}
		$out = array_merge( $out, $osm );

		[ $google, $err ] = self::google_boxes( $name, $airport );
		if ( $err ) {
			$errors[] = 'Google: ' . $err;
		}
		$out = array_merge( $out, $google );

		if ( $out ) {
			set_transient( $cache, $out, DAY_IN_SECONDS );
		}
		return array( $out, $errors );
	}

	/** OpenStreetMap (Nominatim) boundaries near the airport. One request; their rules allow light use with a clear User-Agent. */
	private static function osm_boundaries( string $name, array $airport ): array {
		$lat = (float) $airport['lat'];
		$lng = (float) $airport['lng'];
		$url = add_query_arg(
			array(
				'q'                 => rawurlencode( $name ),
				'format'            => 'jsonv2',
				'polygon_geojson'   => 1,
				'polygon_threshold' => '0.0003',
				'countrycodes'      => strtolower( (string) $airport['country'] ),
				'viewbox'           => implode( ',', array( $lng - 2.5, $lat + 2.5, $lng + 2.5, $lat - 2.5 ) ),
				'bounded'           => 1,
				'limit'             => 6,
				'accept-language'   => 'en',
			),
			'https://nominatim.openstreetmap.org/search'
		);
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array( 'User-Agent' => 'AirportTransportation.com booking engine (' . ATC_Settings::get( 'from_email' ) . ')' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( array(), $res->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( array(), 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$rows = json_decode( wp_remote_retrieve_body( $res ), true );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$ring = self::outer_ring( $r['geojson'] ?? null );
			if ( ! $ring ) {
				continue;
			}
			$ring = self::simplify( $ring, 150 );
			$out[] = array(
				'source'  => 'osm',
				'label'   => (string) ( $r['display_name'] ?? $name ),
				'kind'    => ucfirst( str_replace( '_', ' ', (string) ( $r['addresstype'] ?? $r['type'] ?? 'area' ) ) ),
				'polygon' => $ring,
				'km2'     => round( self::area_km2( $ring ), 1 ),
			);
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return array( $out, null );
	}

	/** Google Places (New): the map box ("viewport") of the place, as a rectangle. */
	private static function google_boxes( string $name, array $airport ): array {
		$key = ATC_Settings::maps_server_key();
		if ( '' === $key ) {
			return array( array(), 'Server key not set' );
		}
		$res = wp_remote_post(
			'https://places.googleapis.com/v1/places:searchText',
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.viewport,places.types',
				),
				'body'    => wp_json_encode(
					array(
						'textQuery'    => $name,
						'pageSize'     => 3,
						'languageCode' => 'en',
						'locationBias' => array(
							'circle' => array(
								'center' => array( 'latitude' => (float) $airport['lat'], 'longitude' => (float) $airport['lng'] ),
								'radius' => 50000.0,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( array(), $res->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( array(), $body['error']['message'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$out = array();
		foreach ( (array) ( $body['places'] ?? array() ) as $p ) {
			$v = $p['viewport'] ?? null;
			if ( ! $v || empty( $v['low'] ) || empty( $v['high'] ) ) {
				continue;
			}
			$ring = self::box( (float) $v['low']['latitude'], (float) $v['low']['longitude'], (float) $v['high']['latitude'], (float) $v['high']['longitude'] );
			$out[] = array(
				'source'  => 'google',
				'label'   => trim( ( $p['displayName']['text'] ?? $name ) . ' · ' . ( $p['formattedAddress'] ?? '' ), ' ·' ),
				'kind'    => 'Google map box',
				'polygon' => $ring,
				'km2'     => round( self::area_km2( $ring ), 1 ),
			);
		}
		return array( $out, null );
	}

	/** Rectangle [lat,lng] ring, at least about 1 km a side so it can be grabbed and adjusted. */
	public static function box( float $s, float $w, float $n, float $e ): array {
		$min = 0.0045;
		if ( $n - $s < $min ) {
			$c = ( $n + $s ) / 2;
			$s = $c - $min / 2;
			$n = $c + $min / 2;
		}
		if ( $e - $w < $min ) {
			$c = ( $e + $w ) / 2;
			$w = $c - $min / 2;
			$e = $c + $min / 2;
		}
		return array( array( round( $n, 6 ), round( $w, 6 ) ), array( round( $n, 6 ), round( $e, 6 ) ), array( round( $s, 6 ), round( $e, 6 ) ), array( round( $s, 6 ), round( $w, 6 ) ) );
	}

	/** Largest outer ring of a GeoJSON Polygon or MultiPolygon, as [lat,lng] without the closing point. */
	public static function outer_ring( $geo ): ?array {
		if ( ! is_array( $geo ) || empty( $geo['coordinates'] ) ) {
			return null;
		}
		$rings = array();
		if ( 'Polygon' === ( $geo['type'] ?? '' ) ) {
			$rings[] = $geo['coordinates'][0] ?? array();
		} elseif ( 'MultiPolygon' === ( $geo['type'] ?? '' ) ) {
			foreach ( $geo['coordinates'] as $poly ) {
				$rings[] = $poly[0] ?? array();
			}
		} else {
			return null;
		}
		$best = null;
		$area = -1;
		foreach ( $rings as $ring ) {
			$pts = array();
			foreach ( $ring as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 ) {
					$pts[] = array( round( (float) $pt[1], 6 ), round( (float) $pt[0], 6 ) );
				}
			}
			if ( count( $pts ) > 1 && $pts[0] === $pts[ count( $pts ) - 1 ] ) {
				array_pop( $pts );
			}
			if ( count( $pts ) < 3 ) {
				continue;
			}
			$a = self::area_km2( $pts );
			if ( $a > $area ) {
				$area = $a;
				$best = $pts;
			}
		}
		return $best;
	}

	/** Approximate area in km² (fine at this scale). */
	public static function area_km2( array $ring ): float {
		$n = count( $ring );
		if ( $n < 3 ) {
			return 0.0;
		}
		$lat0 = deg2rad( array_sum( array_column( $ring, 0 ) ) / $n );
		$kx   = 111.32 * cos( $lat0 );
		$ky   = 110.57;
		$sum  = 0.0;
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			$sum += ( $ring[ $j ][1] * $kx ) * ( $ring[ $i ][0] * $ky ) - ( $ring[ $i ][1] * $kx ) * ( $ring[ $j ][0] * $ky );
		}
		return abs( $sum ) / 2;
	}

	/** Fewer points, same outline (Douglas-Peucker, tolerance raised until it fits $max points). */
	public static function simplify( array $ring, int $max = 150 ): array {
		if ( count( $ring ) <= $max ) {
			return $ring;
		}
		$tol = 0.0002;
		$out = $ring;
		for ( $k = 0; $k < 20 && count( $out ) > $max; $k++ ) {
			$closed = array_merge( $ring, array( $ring[0] ) );
			$out    = self::dp( $closed, $tol );
			array_pop( $out );
			$tol *= 1.6;
		}
		return count( $out ) >= 3 ? $out : array_slice( $ring, 0, $max );
	}

	private static function dp( array $pts, float $tol ): array {
		$n = count( $pts );
		if ( $n < 3 ) {
			return $pts;
		}
		$keep              = array_fill( 0, $n, false );
		$keep[0]           = true;
		$keep[ $n - 1 ]    = true;
		$stack             = array( array( 0, $n - 1 ) );
		while ( $stack ) {
			[ $a, $b ] = array_pop( $stack );
			$max  = 0.0;
			$idx  = -1;
			for ( $i = $a + 1; $i < $b; $i++ ) {
				$d = self::seg_dist( $pts[ $i ], $pts[ $a ], $pts[ $b ] );
				if ( $d > $max ) {
					$max = $d;
					$idx = $i;
				}
			}
			if ( $idx > 0 && $max > $tol ) {
				$keep[ $idx ] = true;
				$stack[]      = array( $a, $idx );
				$stack[]      = array( $idx, $b );
			}
		}
		$out = array();
		foreach ( $pts as $i => $p ) {
			if ( $keep[ $i ] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function seg_dist( array $p, array $a, array $b ): float {
		$dx = $b[1] - $a[1];
		$dy = $b[0] - $a[0];
		$len = $dx * $dx + $dy * $dy;
		if ( 0.0 === (float) $len ) {
			return hypot( $p[1] - $a[1], $p[0] - $a[0] );
		}
		$t = max( 0, min( 1, ( ( $p[1] - $a[1] ) * $dx + ( $p[0] - $a[0] ) * $dy ) / $len ) );
		return hypot( $p[1] - ( $a[1] + $t * $dx ), $p[0] - ( $a[0] + $t * $dy ) );
	}

	/** Checks the Server key with one Geocoding call. Returns [ok, message]. */
	public static function test_server_key(): array {
		$key = ATC_Settings::maps_server_key();
		if ( '' === $key ) {
			return array( false, 'Not set in wp-config.php' );
		}
		$url = add_query_arg(
			array(
				'address' => rawurlencode( 'Cancun International Airport' ),
				'key'     => $key,
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return array( false, $res->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$status = $body['status'] ?? 'UNKNOWN';
		if ( 'OK' === $status ) {
			return array( true, 'Working: Geocoding found Cancún airport.' );
		}
		return array( false, $status . ( isset( $body['error_message'] ) ? ': ' . $body['error_message'] : '' ) );
	}
}
