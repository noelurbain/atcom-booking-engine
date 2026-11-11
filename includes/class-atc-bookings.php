<?php
/**
 * Bookings for the control panel: the list with filters, and one booking in full.
 * Read-only for now, plus internal notes. Changing a provider, editing a trip, refunds and
 * resending vouchers arrive with checkout, when bookings are taken here.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Bookings {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function local( ?string $utc, string $tz, string $fmt = 'D j M Y, H:i' ): ?string {
		if ( ! $utc ) {
			return null;
		}
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( new DateTimeZone( $tz ?: 'UTC' ) );
			return $d->format( $fmt );
		} catch ( Exception $e ) {
			return $utc . ' UTC';
		}
	}

	/** What the list and the detail call each status. */
	public static function status_label( string $status, array $snap ): array {
		if ( 'cancelled' === $status ) {
			return array( 'grey', 'refunded' === ( $snap['payment'] ?? '' ) ? 'Cancelled, refunded' : 'Cancelled' );
		}
		if ( 'held' === $status ) {
			return array( 'orange', 'Not paid' );
		}
		if ( 'completed' === $status ) {
			return array( 'green', 'Completed' );
		}
		return array( 'green', 'Paid' );
	}

	/** Shared joins: each leg with its airport, zone, vehicle and provider. */
	private static function legs_sql(): string {
		$l = self::t( 'booking_legs' );
		$s = self::t( 'services' );
		$v = self::t( 'vehicle_types' );
		$p = self::t( 'parties' );
		$z = self::t( 'zones' );
		$a = self::t( 'airports' );
		return "FROM {$l} l
			LEFT JOIN {$s} s ON s.id = l.service_id LEFT JOIN {$v} v ON v.id = s.vehicle_type_id
			LEFT JOIN {$p} p ON p.id = l.party_id LEFT JOIN {$z} z ON z.id = l.zone_id
			LEFT JOIN {$a} a ON a.id = COALESCE(s.airport_id, z.airport_id)";
	}

	/**
	 * The list. Filters: status (paid|unpaid|cancelled|upcoming|no_provider), q (reference),
	 * airport (IATA), provider (party id), page.
	 */
	public static function list( array $f ): array {
		global $wpdb;
		$b     = self::t( 'bookings' );
		$where = array( '1=1' );
		$args  = array();
		$now   = current_time( 'mysql', true );
		switch ( (string) ( $f['status'] ?? '' ) ) {
			case 'paid':
				$where[] = "b.status IN ('confirmed','completed')";
				break;
			case 'unpaid':
				$where[] = "b.status = 'held'";
				break;
			case 'cancelled':
				$where[] = "b.status = 'cancelled'";
				break;
			case 'upcoming':
				$where[] = "b.status IN ('confirmed','completed') AND EXISTS (SELECT 1 FROM " . self::t( 'booking_legs' ) . " x WHERE x.booking_id = b.id AND x.status <> 'cancelled' AND x.pickup_at >= %s)";
				$args[]  = $now;
				break;
			case 'no_provider':
				$where[] = "b.status IN ('confirmed','completed') AND EXISTS (SELECT 1 FROM " . self::t( 'booking_legs' ) . " x WHERE x.booking_id = b.id AND x.status <> 'cancelled' AND x.party_id = 0 AND x.pickup_at >= %s)";
				$args[]  = $now;
				break;
		}
		$q = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $f['q'] ?? '' ) ) );
		if ( '' !== $q ) {
			$where[] = 'b.number LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $q ) . '%';
		}
		$provider = (int) ( $f['provider'] ?? 0 );
		if ( $provider ) {
			$where[] = 'EXISTS (SELECT 1 FROM ' . self::t( 'booking_legs' ) . ' x WHERE x.booking_id = b.id AND x.party_id = %d)';
			$args[]  = $provider;
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$b} b WHERE {$sql_where}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore
		$per       = 50;
		$page      = max( 1, (int) ( $f['page'] ?? 1 ) );
		$list_sql  = "SELECT b.id, b.number, b.status, b.channel, b.trip_type, b.adults, b.children, b.total_cents, b.created_at, b.snapshot, b.first_name, b.last_name
			FROM {$b} b WHERE {$sql_where} ORDER BY b.created_at DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore

		$out = array();
		foreach ( $rows as $r ) {
			$snap = json_decode( (string) $r['snapshot'], true ) ?: array();
			$leg  = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT l.direction, l.pickup_at, l.from_place, l.to_place, l.party_id, a.iata, a.name airport, a.timezone, z.name zone, p.name provider, v.name vehicle ' . self::legs_sql() . ' WHERE l.booking_id = %d ORDER BY l.pickup_at, l.id LIMIT 1',
					(int) $r['id']
				),
				ARRAY_A
			); // phpcs:ignore
			$tz   = (string) ( $leg['timezone'] ?? '' ) ?: (string) ( $snap['timezone'] ?? 'UTC' );
			$iata = $leg['iata'] ?? '' ?: ( $snap['iata'] ?? '' );
			if ( $leg && 'arrival' === $leg['direction'] ) {
				$route = $iata . ' → ' . ( $leg['zone'] ?: $leg['to_place'] );
			} elseif ( $leg ) {
				$route = ( $leg['zone'] ?: $leg['from_place'] ) . ' → ' . $iata;
			} else {
				$route = $iata;
			}
			[ $tone, $label ] = self::status_label( $r['status'], $snap );
			$name             = trim( $r['first_name'] . ' ' . mb_substr( (string) $r['last_name'], 0, 1 ) . ( $r['last_name'] ? '.' : '' ) );
			$out[]            = array(
				'number'   => $r['number'],
				'travel'   => $leg ? self::local( $leg['pickup_at'], $tz ) : null,
				'booked'   => self::local( $r['created_at'], 'UTC', 'j M Y' ),
				'route'    => $route,
				'vehicle'  => $leg['vehicle'] ?? null,
				'provider' => $leg['provider'] ?? null,
				'pax'      => (int) $r['adults'] + (int) $r['children'],
				'trip'     => $r['trip_type'],
				'total'    => (int) $r['total_cents'],
				'tone'     => $tone,
				'status'   => $label,
				'traveler' => '' !== $name ? $name : null,
				'legacy'   => ! empty( $snap['legacy'] ),
			);
		}
		return array( 'bookings' => $out, 'total' => $total, 'page' => $page, 'pages' => (int) max( 1, ceil( $total / $per ) ) );
	}

	/** One booking in full. */
	public static function get( string $number ) {
		global $wpdb;
		$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', strtoupper( $number ) ), ARRAY_A );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		$snap = json_decode( (string) $b['snapshot'], true ) ?: array();
		$legs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.*, a.iata, a.name airport, a.timezone, z.name zone, p.name provider, p.phone provider_phone, v.name vehicle ' . self::legs_sql() . ' WHERE l.booking_id = %d ORDER BY l.pickup_at, l.id',
				(int) $b['id']
			),
			ARRAY_A
		); // phpcs:ignore
		$tz      = '';
		$out_leg = array();
		$prov    = 0;
		foreach ( $legs as $l ) {
			$tz        = $tz ?: ( (string) $l['timezone'] ?: (string) ( $snap['timezone'] ?? 'UTC' ) );
			$prov     += (int) $l['provider_cents'];
			$out_leg[] = array(
				'direction' => $l['direction'],
				'pickup'    => self::local( $l['pickup_at'], $tz ),
				'timezone'  => $tz,
				'from'      => $l['from_place'],
				'to'        => $l['to_place'],
				'airport'   => $l['iata'] ?: ( $snap['iata'] ?? '' ),
				'zone'      => $l['zone'],
				'vehicle'   => $l['vehicle'],
				'provider'  => $l['provider'],
				'party_id'  => (int) $l['party_id'],
				'flight'    => trim( $l['flight_airline'] . ' ' . $l['flight_number'] ),
				'bags'      => (int) $l['suitcases'],
				'notes'     => $l['notes'],
				'status'    => $l['status'],
				'retail'    => (int) $l['retail_cents'],
				'paid_out'  => (int) $l['provider_cents'],
			);
		}
		$legacy = ! empty( $snap['legacy'] );
		$paid   = in_array( $b['status'], array( 'confirmed', 'completed' ), true );
		$total  = (int) $b['total_cents'];
		$money  = array( array( 'Traveler paid (Suggested retail price)', $paid ? ( ! empty( $snap['method'] ) ? 'with ' . ucfirst( (string) $snap['method'] ) : 'captured' ) : 'not paid', $paid ? $total : 0 ) );
		if ( $paid ) {
			$money[] = array( 'Provider (Amount you will get paid)', $legacy ? 'old site, ' . ( $total ? round( 100 * $prov / $total ) : 0 ) . '% · settled there' : 'pending until completed', $prov );
			$money[] = array( 'Airport Transportation keeps', $legacy ? 'old site, 30% commission' : '', $total - $prov );
		}
		if ( ! empty( $snap['refunded'] ) ) {
			$money[] = array( 'Refunded to the traveler', '', -(int) $snap['refunded'] );
		}
		$events = array_map(
			static function ( $e ) {
				$u    = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null;
				$data = json_decode( (string) $e['data'], true ) ?: array();
				return array(
					'when' => self::local( $e['created_at'], 'UTC', 'j M Y, H:i' ) . ' UTC',
					'what' => 'note' === $e['action'] ? (string) ( $data['text'] ?? '' ) : ucfirst( str_replace( '_', ' ', $e['action'] ) ),
					'who'  => $u ? $u->display_name : 'system',
					'note' => 'note' === $e['action'],
				);
			},
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'events' ) . " WHERE object_type = 'booking' AND object_id = %d ORDER BY id DESC LIMIT 50", (int) $b['id'] ), ARRAY_A )
		);
		$events[] = array( 'when' => self::local( $b['created_at'], 'UTC', 'j M Y, H:i' ) . ' UTC', 'what' => $legacy ? 'Booked on the old site' : 'Booked', 'who' => $b['channel'], 'note' => false );

		[ $tone, $label ] = self::status_label( $b['status'], $snap );
		return array(
			'number'   => $b['number'],
			'status'   => $label,
			'tone'     => $tone,
			'legacy'   => $legacy,
			'booked'   => self::local( $b['created_at'], 'UTC', 'j M Y, H:i' ) . ' UTC',
			'channel'  => $b['channel'],
			'lang'     => $b['lang'],
			'trip'     => $b['trip_type'],
			'pax'      => array( 'adults' => (int) $b['adults'], 'children' => (int) $b['children'] ),
			'traveler' => array(
				'name'  => trim( $b['first_name'] . ' ' . $b['last_name'] ),
				'email' => $b['email'],
				'phone' => $b['phone'],
				'account' => $b['user_id'] ? 'Has an account' : 'Guest',
			),
			'legs'     => $out_leg,
			'money'    => array_map( static fn( $m ) => array( 'party' => $m[0], 'status' => $m[1], 'amount' => $m[2] ), $money ),
			'payment'  => array(
				'state'    => (string) ( $snap['payment'] ?? ( $paid ? 'paid' : 'unpaid' ) ),
				'method'   => (string) ( $snap['method'] ?? '' ),
				'stripe'   => (string) $b['stripe_payment_intent'],
				'refunded' => (int) ( $snap['refunded'] ?? 0 ),
			),
			'source'   => array(
				'site'      => $legacy ? 'airporttransportation.com (old site)' : (string) ( $snap['site'] ?? '' ),
				'affiliate' => $b['affiliate_id'] ? ( ATC_Catalog::party( (int) $b['affiliate_id'] )['name'] ?? '' ) : '',
				'coupon'    => (string) $b['coupon_code'],
				'area'      => (string) ( $snap['area'] ?? '' ),
			),
			'events'   => $events,
		);
	}

	public static function add_note( string $number, string $text ) {
		global $wpdb;
		$id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', strtoupper( $number ) ) );
		$text = trim( sanitize_textarea_field( $text ) );
		if ( ! $id ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		if ( '' === $text ) {
			return new WP_Error( 'atc_note', 'Write the note first.', array( 'status' => 400 ) );
		}
		ATC_Install::log( 'booking', $id, 'note', array( 'text' => mb_substr( $text, 0, 2000 ) ) );
		return self::get( $number );
	}
}
