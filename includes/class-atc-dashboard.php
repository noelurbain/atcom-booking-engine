<?php
/**
 * The control panel's home: bookings and money, trips coming up, and the work waiting for our team.
 * Reads only. Bookings brought over from the old site count like any other (their money is what
 * happened there, at the old 30% commission).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Dashboard {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** A UTC datetime shown in the airport's local time. */
	private static function local( ?string $utc, string $tz ): ?string {
		if ( ! $utc ) {
			return null;
		}
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( new DateTimeZone( $tz ?: 'UTC' ) );
			return $d->format( 'D j M Y, H:i' );
		} catch ( Exception $e ) {
			return $utc . ' UTC';
		}
	}

	/** Bookings, paid sales and the split, from a date (UTC) on; null = all time. */
	private static function money( ?string $since ): array {
		global $wpdb;
		$b     = self::t( 'bookings' );
		$l     = self::t( 'booking_legs' );
		$where = $since ? $wpdb->prepare( ' AND b.created_at >= %s', $since ) : '';
		$paid  = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(b.total_cents),0) sales FROM {$b} b WHERE b.status IN ('confirmed','completed'){$where}", ARRAY_A ); // phpcs:ignore
		$prov  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(l.provider_cents),0) FROM {$l} l JOIN {$b} b ON b.id = l.booking_id WHERE b.status IN ('confirmed','completed'){$where}" ); // phpcs:ignore
		$other = $wpdb->get_results( "SELECT b.status, COUNT(*) n FROM {$b} b WHERE 1=1{$where} GROUP BY b.status", OBJECT_K ); // phpcs:ignore
		return array(
			'paid'      => (int) $paid['n'],
			'sales'     => (int) $paid['sales'],
			'providers' => $prov,
			'ours'      => (int) $paid['sales'] - $prov,
			'unpaid'    => (int) ( $other['held']->n ?? 0 ),
			'cancelled' => (int) ( $other['cancelled']->n ?? 0 ),
		);
	}

	public static function data(): array {
		global $wpdb;
		$b   = self::t( 'bookings' );
		$l   = self::t( 'booking_legs' );
		$a   = self::t( 'airports' );
		$z   = self::t( 'zones' );
		$p   = self::t( 'parties' );
		$s   = self::t( 'services' );
		$v   = self::t( 'vehicle_types' );
		$now = current_time( 'mysql', true );

		// Trips coming up (paid), and paid trips nobody is driving yet.
		$trip_sql = "SELECT b.number, b.snapshot, b.adults + b.children pax, l.direction, l.pickup_at, l.status, l.from_place, l.to_place,
				a.iata, a.name airport, a.timezone, z.name zone, p.id party_id, p.name provider, v.name vehicle
			FROM {$l} l JOIN {$b} b ON b.id = l.booking_id
			LEFT JOIN {$s} s ON s.id = l.service_id LEFT JOIN {$v} v ON v.id = s.vehicle_type_id
			LEFT JOIN {$p} p ON p.id = l.party_id LEFT JOIN {$z} z ON z.id = l.zone_id
			LEFT JOIN {$a} a ON a.id = COALESCE(s.airport_id, z.airport_id)
			WHERE b.status IN ('confirmed','completed') AND l.status <> 'cancelled' AND l.pickup_at >= %s";
		$upcoming = $wpdb->get_results( $wpdb->prepare( $trip_sql . ' ORDER BY l.pickup_at LIMIT 12', $now ), ARRAY_A ); // phpcs:ignore
		$no_prov  = $wpdb->get_results( $wpdb->prepare( $trip_sql . ' AND l.party_id = 0 ORDER BY l.pickup_at LIMIT 12', $now ), ARRAY_A ); // phpcs:ignore
		$trips    = static function ( array $rows ): array {
			return array_map(
				static fn( $r ) => array(
					'number'    => $r['number'],
					'when'      => self::local( $r['pickup_at'], (string) ( $r['timezone'] ?: ( json_decode( (string) $r['snapshot'], true )['timezone'] ?? 'UTC' ) ) ),
					'direction' => $r['direction'],
					'airport'   => $r['iata'] ?: ( $r['airport'] ?: ( json_decode( (string) $r['snapshot'], true )['iata'] ?? '' ) ),
					'zone'      => $r['zone'],
					'place'     => 'arrival' === $r['direction'] ? $r['to_place'] : $r['from_place'],
					'vehicle'   => $r['vehicle'],
					'pax'       => (int) $r['pax'],
					'provider'  => $r['provider'],
					'party_id'  => (int) $r['party_id'],
				),
				$rows
			);
		};

		// Latest bookings, any status.
		$recent = $wpdb->get_results(
			"SELECT b.number, b.status, b.trip_type, b.total_cents, b.created_at, b.snapshot,
				(SELECT COALESCE(a.iata, a.name) FROM {$l} l LEFT JOIN {$z} z ON z.id = l.zone_id LEFT JOIN {$s} s ON s.id = l.service_id
					LEFT JOIN {$a} a ON a.id = COALESCE(s.airport_id, z.airport_id) WHERE l.booking_id = b.id ORDER BY l.id LIMIT 1) airport
			FROM {$b} b ORDER BY b.created_at DESC LIMIT 10",
			ARRAY_A
		); // phpcs:ignore
		foreach ( $recent as &$r ) {
			$snap          = json_decode( (string) $r['snapshot'], true ) ?: array();
			$r['airport']  = $r['airport'] ?: ( $snap['iata'] ?? '' );
			$r['payment']  = $snap['payment'] ?? ( 'confirmed' === $r['status'] ? 'paid' : 'unpaid' );
			$r['legacy']   = ! empty( $snap['legacy'] );
			$r['booked']   = self::local( $r['created_at'], 'UTC' ) . ' UTC';
			unset( $r['snapshot'], $r['created_at'] );
		}
		unset( $r );

		// Airports by paid sales, all time.
		$by_airport = $wpdb->get_results(
			"SELECT COALESCE(a.iata, a.name) airport, a.name, COUNT(DISTINCT b.id) bookings, SUM(l.retail_cents) sales
			FROM {$l} l JOIN {$b} b ON b.id = l.booking_id LEFT JOIN {$z} z ON z.id = l.zone_id LEFT JOIN {$s} s ON s.id = l.service_id
			LEFT JOIN {$a} a ON a.id = COALESCE(s.airport_id, z.airport_id)
			WHERE b.status IN ('confirmed','completed') GROUP BY a.id ORDER BY sales DESC LIMIT 8",
			ARRAY_A
		); // phpcs:ignore

		// Providers.
		$status = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$p} WHERE type = 'provider' GROUP BY status", OBJECT_K ); // phpcs:ignore
		$portal = array( 'not_invited' => 0, 'invited' => 0, 'active' => 0 );
		foreach ( $wpdb->get_col( "SELECT id FROM {$p} WHERE type = 'provider' AND status <> 'archived'" ) as $id ) { // phpcs:ignore
			++$portal[ ATC_Partner::portal_state( (int) $id )['state'] ];
		}
		$legacy_t = self::t( 'legacy' );
		$new_signups = $wpdb->get_results(
			"SELECT p.id, p.name, p.created_at FROM {$p} p WHERE p.type = 'provider' AND p.status = 'pending'
			AND NOT EXISTS (SELECT 1 FROM {$legacy_t} g WHERE g.object_type = 'party' AND g.new_id = p.id) ORDER BY p.created_at DESC LIMIT 8",
			ARRAY_A
		); // phpcs:ignore
		$docs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'documents' ) . " WHERE status = 'pending'" ); // phpcs:ignore

		// Zones that still need a shape, where providers sell.
		$no_shape = $wpdb->get_results(
			"SELECT a.id airport_id, COALESCE(a.iata, a.name) airport, COUNT(*) n FROM {$z} z JOIN {$a} a ON a.id = z.airport_id
			WHERE z.status = 'active' AND (z.polygon IS NULL OR z.polygon = '' OR z.polygon = '[]')
			GROUP BY a.id ORDER BY n DESC LIMIT 10",
			ARRAY_A
		); // phpcs:ignore

		$import = get_option( 'atc_last_import' );
		$held   = array();
		if ( is_array( $import ) ) {
			$held = array_values( array_filter( (array) ( $import['issues'] ?? array() ), static fn( $i ) => false !== strpos( $i, 'left out' ) ) );
		}

		return array(
			'now'       => gmdate( 'Y-m-d H:i' ) . ' UTC',
			'month'     => self::money( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
			'all'       => self::money( null ),
			'legacy'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $legacy_t . " WHERE object_type = 'booking'" ), // phpcs:ignore
			'upcoming'  => $trips( $upcoming ),
			'no_provider' => $trips( $no_prov ),
			'recent'    => $recent,
			'by_airport' => array_map( static fn( $r ) => array( 'airport' => $r['airport'], 'name' => $r['name'], 'bookings' => (int) $r['bookings'], 'sales' => (int) $r['sales'] ), $by_airport ),
			'providers' => array(
				'live'      => (int) ( $status['live']->n ?? 0 ),
				'pending'   => (int) ( $status['pending']->n ?? 0 ),
				'suspended' => (int) ( $status['suspended']->n ?? 0 ),
				'portal'    => $portal,
			),
			'todo'      => array(
				'new_signups' => $new_signups,
				'proposed'    => ATC_Prices::proposed_zones(),
				'documents'   => $docs,
				'no_shape'    => $no_shape,
				'held_prices' => array_slice( $held, 0, 60 ),
				'import_at'   => is_array( $import ) ? $import['at'] : null,
			),
		);
	}
}
