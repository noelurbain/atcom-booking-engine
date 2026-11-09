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
