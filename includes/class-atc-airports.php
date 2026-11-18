<?php
/**
 * Airports in Control: fix the code, name, city, country, time zone and position of an airport (those added
 * from Google by a provider come without an IATA code, and Google's city or time zone can be off).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Airports {

	private static function t(): string {
		return ATC_Schema::table( 'airports' );
	}

	public static function list( string $q ): array {
		global $wpdb;
		$t     = self::t();
		$z     = ATC_Schema::table( 'zones' );
		$s     = ATC_Schema::table( 'services' );
		$where = '';
		$args  = array();
		$q     = trim( $q );
		if ( '' !== $q ) {
			$like  = '%' . $wpdb->esc_like( $q ) . '%';
			$where = 'WHERE a.iata LIKE %s OR a.name LIKE %s OR a.city LIKE %s OR a.country = %s';
			$args  = array( $like, $like, $like, strtoupper( $q ) );
		}
		$sql  = "SELECT a.*, (SELECT COUNT(*) FROM {$z} WHERE airport_id = a.id AND party_id IS NULL) zones, (SELECT COUNT(DISTINCT party_id) FROM {$s} WHERE airport_id = a.id) providers FROM {$t} a {$where} ORDER BY (a.iata IS NULL) DESC, a.country, a.city LIMIT 300";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$r['id']        = (int) $r['id'];
			$r['zones']     = (int) $r['zones'];
			$r['providers'] = (int) $r['providers'];
			$r['lat']       = null === $r['lat'] ? null : (float) $r['lat'];
			$r['lng']       = null === $r['lng'] ? null : (float) $r['lng'];
		}
		return array( 'airports' => $rows, 'timezones' => timezone_identifiers_list() );
	}

	public static function save( int $id, array $in ) {
		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $a ) {
			return new WP_Error( 'atc_not_found', 'Airport not found.', array( 'status' => 404 ) );
		}
		$iata = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['iata'] ?? '' ) ) );
		$name = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$city = trim( sanitize_text_field( (string) ( $in['city'] ?? '' ) ) );
		$cc   = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['country'] ?? '' ) ) );
		$tz   = (string) ( $in['timezone'] ?? '' );
		$lat  = is_numeric( $in['lat'] ?? null ) ? (float) $in['lat'] : null;
		$lng  = is_numeric( $in['lng'] ?? null ) ? (float) $in['lng'] : null;
		$st   = in_array( $in['status'] ?? '', array( 'active', 'hidden' ), true ) ? $in['status'] : $a['status'];
		if ( '' !== $iata && 3 !== strlen( $iata ) ) {
			return new WP_Error( 'atc_airport', 'The IATA code has 3 letters (e.g. CUN), or leave it empty.', array( 'status' => 400 ) );
		}
		if ( '' !== $iata && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE iata = %s AND id <> %d', $iata, $id ) ) ) {
			return new WP_Error( 'atc_airport', 'Another airport already has the code ' . $iata . '.', array( 'status' => 400 ) );
		}
		if ( '' === $name || '' === $city ) {
			return new WP_Error( 'atc_airport', 'Name and city are needed.', array( 'status' => 400 ) );
		}
		if ( 2 !== strlen( $cc ) ) {
			return new WP_Error( 'atc_airport', 'The country is a 2-letter code (e.g. MX).', array( 'status' => 400 ) );
		}
		if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			return new WP_Error( 'atc_airport', 'Pick the time zone from the list.', array( 'status' => 400 ) );
		}
		if ( ( null !== $lat && ( $lat < -90 || $lat > 90 ) ) || ( null !== $lng && ( $lng < -180 || $lng > 180 ) ) ) {
			return new WP_Error( 'atc_airport', 'Check the latitude and longitude.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t(), array( 'iata' => '' === $iata ? null : $iata, 'name' => mb_substr( $name, 0, 190 ), 'city' => mb_substr( $city, 0, 100 ), 'country' => $cc, 'timezone' => $tz, 'lat' => $lat, 'lng' => $lng, 'status' => $st ), array( 'id' => $id ) );
		ATC_Install::log( 'airport', $id, 'edited', array( 'iata' => $iata, 'timezone' => $tz ) );
		$row = self::list( '' )['airports'];
		foreach ( $row as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return array( 'ok' => true );
	}
}
