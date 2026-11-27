<?php
/**
 * The provider's bookings (portal → Bookings): every ride ("leg") the provider operates, confirming they got
 * it, assigning a driver and vehicle, completing it with the traveler's service code, reporting a no-show or
 * handing the ride back to our team.
 *
 * Ride statuses: new (waiting for the provider) → accepted → completed | no_show; cancelled; released.
 * Money: the provider's ledger line stays 'pending' until the ride is completed, then becomes 'earned'
 * (payable in the next payout). A no-show stays 'pending' until our team reviews it.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Trips {

	const ACK_STATUSES = array( 'accepted' );

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function init(): void {
		add_action( 'atc_hourly', array( __CLASS__, 'hourly' ) );
	}

	/* ---------- Reading ---------- */

	private static function local( ?string $utc, string $tz, string $format ): string {
		return ATC_Mail::local( $utc, $tz, $format );
	}

	/** The filters of the list, with their SQL. */
	private static function filters(): array {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$soon = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$ago  = gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS );
		return array(
			'action'   => array( 'To confirm', "l.status = 'new' AND b.status = 'confirmed'" ),
			'next'     => array( 'Next 24 h', "l.status IN ('new','accepted') AND b.status IN ('confirmed','completed') AND l.pickup_at BETWEEN '{$ago}' AND '{$soon}'" ),
			'upcoming' => array( 'Upcoming', "l.status IN ('new','accepted') AND b.status IN ('confirmed','completed') AND l.pickup_at >= '{$now}'" ),
			'past'     => array( 'Past', "l.status NOT IN ('cancelled','released') AND b.status IN ('confirmed','completed') AND l.pickup_at < '{$now}'" ),
			'cancelled' => array( 'Cancelled', "(l.status IN ('cancelled','released') OR b.status = 'cancelled')" ),
		);
	}

	private static function base_sql(): string {
		return 'FROM ' . self::t( 'booking_legs' ) . ' l
			JOIN ' . self::t( 'bookings' ) . ' b ON b.id = l.booking_id
			LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = l.service_id
			LEFT JOIN ' . self::t( 'vehicle_types' ) . ' v ON v.id = s.vehicle_type_id
			LEFT JOIN ' . self::t( 'airports' ) . ' a ON a.id = s.airport_id';
	}

	public static function status_label( string $leg, string $booking ): array {
		if ( 'cancelled' === $booking || 'cancelled' === $leg ) {
			return array( 'grey', 'Cancelled' );
		}
		return array(
			'new'       => array( 'orange', 'To confirm' ),
			'accepted'  => array( 'blue', 'Confirmed' ),
			'completed' => array( 'green', 'Completed' ),
			'no_show'   => array( 'red', 'No-show' ),
			'released'  => array( 'grey', 'Handed back' ),
		)[ $leg ] ?? array( 'grey', ucfirst( $leg ) );
	}

	public static function list( int $pid, string $filter, int $page = 1, bool $all = false ): array {
		global $wpdb;
		$f      = self::filters();
		$filter = isset( $f[ $filter ] ) ? $filter : 'upcoming';
		$counts = array();
		foreach ( $f as $k => [ $label, $cond ] ) {
			$counts[] = array( 'key' => $k, 'label' => $label, 'count' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) ' . self::base_sql() . " WHERE l.party_id = %d AND b.status <> 'held' AND {$cond}", $pid ) ) ); // phpcs:ignore
		}
		$order = in_array( $filter, array( 'past', 'cancelled' ), true ) ? 'DESC' : 'ASC';
		$limit = $all ? 5000 : 50;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.*, b.number, b.status AS bstatus, b.first_name, b.last_name, b.phone, b.adults, b.children, b.snapshot, v.name AS vehicle, a.iata, a.timezone ' . self::base_sql() . " WHERE l.party_id = %d AND b.status <> 'held' AND {$f[ $filter ][1]} ORDER BY l.pickup_at {$order} LIMIT %d OFFSET %d", // phpcs:ignore
				$pid,
				$limit,
				( max( 1, $page ) - 1 ) * $limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = self::row( $r );
		}
		return array( 'filter' => $filter, 'filters' => $counts, 'rows' => $out, 'more' => count( $rows ) === $limit );
	}

	private static function row( array $r ): array {
		$tz        = (string) ( $r['timezone'] ?: 'UTC' );
		$snap      = json_decode( (string) $r['snapshot'], true ) ?: array();
		[ $tone, $label ] = self::status_label( (string) $r['status'], (string) $r['bstatus'] );
		$arrival   = 'arrival' === $r['direction'];
		$name      = trim( $r['first_name'] . ' ' . mb_substr( (string) $r['last_name'], 0, 1 ) . ( $r['last_name'] ? '.' : '' ) );
		return array(
			'leg'       => (int) $r['id'],
			'number'    => $r['number'],
			'day'       => self::local( $r['pickup_at'], $tz, 'D j M' ),
			'time'      => self::local( $r['pickup_at'], $tz, 'H:i' ),
			'date'      => self::local( $r['pickup_at'], $tz, 'Y-m-d' ),
			'direction' => $arrival ? 'Arrival' : 'Departure',
			'flight'    => trim( (string) $r['flight_airline'] . ' ' . (string) $r['flight_number'] ) ?: ( $arrival ? 'Flight not given yet' : '' ),
			'from'      => (string) $r['from_place'],
			'to'        => (string) $r['to_place'],
			'iata'      => (string) $r['iata'],
			'vehicle'   => (string) ( $r['vehicle'] ?: ( $snap['vehicle'] ?? '' ) ),
			'pax'       => (int) $r['adults'] + (int) $r['children'],
			'bags'      => (int) $r['suitcases'],
			'traveler'  => $name,
			'status'    => $label,
			'tone'      => $tone,
			'needs_ack' => 'new' === $r['status'] && 'confirmed' === $r['bstatus'],
			'amount'    => (int) $r['provider_cents'],
		);
	}

	/** One booking, as this provider sees it: their rides only. */
	public static function detail( int $pid, string $number ) {
		global $wpdb;
		$b = ATC_Checkout::find( $number );
		if ( ! $b || 'held' === $b['status'] ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		$mine = array_values( array_filter( $b['legs'], static fn( $l ) => (int) $l['party_id'] === $pid ) );
		if ( ! $mine ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		$snap  = $b['snap'];
		$d     = (array) ( $snap['details'] ?? array() );
		$rides = array();
		foreach ( $mine as $l ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT l.*, b.number, b.status AS bstatus, b.first_name, b.last_name, b.phone, b.adults, b.children, b.snapshot, v.name AS vehicle, a.iata, a.timezone ' . self::base_sql() . ' WHERE l.id = %d', (int) $l['id'] ), ARRAY_A ); // phpcs:ignore
			$tz      = (string) ( $r['timezone'] ?: ( $snap['timezone'] ?? 'UTC' ) );
			$row     = self::row( $r );
			$pickup  = strtotime( $l['pickup_at'] . ' UTC' );
			$row    += array(
				'when'          => self::local( $l['pickup_at'], $tz, 'D j M Y · H:i' ),
				'flight_time'   => $l['flight_time'] ? self::local( $l['flight_time'], $tz, 'H:i' ) : '',
				'carry_ons'     => (int) $l['carry_ons'],
				'retail'        => (int) $l['retail_cents'],
				'leg_status'    => $l['status'],
				'driver_id'     => (int) $l['driver_id'],
				'vehicle_id'    => (int) $l['vehicle_id'],
				'acknowledged'  => $l['acknowledged_at'] ? self::local( $l['acknowledged_at'], $tz, 'D j M, H:i' ) : '',
				'completed'     => $l['completed_at'] ? self::local( $l['completed_at'], $tz, 'D j M, H:i' ) : '',
				'completed_how' => (string) $l['completed_how'],
				'can_code'      => in_array( $l['status'], array( 'new', 'accepted' ), true ) && 'confirmed' === $b['status'] && $pickup - time() < 12 * HOUR_IN_SECONDS,
				'can_noshow'    => in_array( $l['status'], array( 'new', 'accepted' ), true ) && 'confirmed' === $b['status'] && time() > $pickup,
				'can_release'   => in_array( $l['status'], array( 'new', 'accepted' ), true ) && 'confirmed' === $b['status'] && $pickup - time() > 2 * HOUR_IN_SECONDS,
				// Last-minute only: not confirmed yet and too close for "Hand back". No penalty (Noel, 2026-11-27).
				'can_decline'   => 'new' === $l['status'] && 'confirmed' === $b['status'] && $pickup - time() <= 2 * HOUR_IN_SECONDS && $pickup > time(),
			);
			$rides[] = $row;
		}
		$tries = max( 0, (int) ATC_Settings::get( 'service_code_attempts' ) - (int) $b['service_code_attempts'] );
		return array(
			'number'    => $b['number'],
			'status'    => $b['status'],
			'received'  => self::local( $b['created_at'], (string) ( $snap['timezone'] ?? 'UTC' ), 'D j M, H:i' ),
			'rides'     => $rides,
			'tries'     => $tries,
			'traveler'  => array(
				'name'     => trim( $b['first_name'] . ' ' . $b['last_name'] ),
				'phone'    => (string) $b['phone'],
				'whatsapp' => ! empty( $snap['whatsapp'] ),
				'adults'   => (int) $b['adults'],
				'children' => (int) $b['children'],
			),
			'hotel_name' => (string) ( $d['hotel_name'] ?? '' ),
			'notes'      => (string) ( $d['notes'] ?? '' ),
			'extras'     => ATC_Extras::lines( (int) $b['id'] ),
			'drivers'    => self::drivers( $pid ),
			'vehicles'   => ATC_Catalog::vehicles( $pid ),
		);
	}

	public static function drivers( int $pid ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, phone, languages FROM ' . self::t( 'drivers' ) . " WHERE party_id = %d AND status = 'active' ORDER BY name", $pid ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['id'] = (int) $r['id'];
		}
		return $rows;
	}

	public static function add_driver( int $pid, array $in ) {
		global $wpdb;
		$name = mb_substr( trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ), 0, 190 );
		if ( '' === $name ) {
			return new WP_Error( 'atc_driver', 'Type the driver’s name.', array( 'status' => 400 ) );
		}
		$wpdb->insert(
			self::t( 'drivers' ),
			array(
				'party_id'  => $pid,
				'name'      => $name,
				'phone'     => mb_substr( preg_replace( '/[^0-9+ ]/', '', (string) ( $in['phone'] ?? '' ) ), 0, 40 ) ?: null,
				'languages' => mb_substr( sanitize_text_field( (string) ( $in['languages'] ?? '' ) ), 0, 100 ) ?: null,
				'status'    => 'active',
			)
		);
		return array( 'drivers' => self::drivers( $pid ), 'id' => (int) $wpdb->insert_id );
	}

	/* ---------- Actions on a ride ---------- */

	/** The ride, if it is this provider's. */
	private static function mine( int $pid, int $leg_id ) {
		global $wpdb;
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT l.*, b.number, b.status AS bstatus, b.service_code, b.service_code_attempts FROM ' . self::t( 'booking_legs' ) . ' l JOIN ' . self::t( 'bookings' ) . ' b ON b.id = l.booking_id WHERE l.id = %d AND l.party_id = %d', $leg_id, $pid ), ARRAY_A );
		if ( ! $l ) {
			return new WP_Error( 'atc_not_found', 'Ride not found.', array( 'status' => 404 ) );
		}
		if ( 'confirmed' !== $l['bstatus'] && 'completed' !== $l['bstatus'] ) {
			return new WP_Error( 'atc_closed', 'This booking is cancelled.', array( 'status' => 409 ) );
		}
		return $l;
	}

	private static function open( array $l ) {
		return in_array( $l['status'], array( 'new', 'accepted' ), true ) ? true : new WP_Error( 'atc_closed', 'This ride is already closed.', array( 'status' => 409 ) );
	}

	public static function acknowledge( int $pid, int $leg_id ) {
		global $wpdb;
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		if ( 'new' === $l['status'] ) {
			$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'accepted', 'acknowledged_at' => self::now() ), array( 'id' => $leg_id ) );
			ATC_Install::log( 'leg', $leg_id, 'acknowledged', array( 'by' => get_current_user_id() ) );
		}
		return self::detail( $pid, $l['number'] );
	}

	/** Confirm every ride of a booking at once (the list's button). */
	public static function acknowledge_booking( int $pid, string $number ) {
		$b = ATC_Checkout::find( $number );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		foreach ( $b['legs'] as $l ) {
			if ( (int) $l['party_id'] === $pid && 'new' === $l['status'] ) {
				self::acknowledge( $pid, (int) $l['id'] );
			}
		}
		return array( 'ok' => true );
	}

	public static function assign( int $pid, int $leg_id, array $in ) {
		global $wpdb;
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$driver  = (int) ( $in['driver_id'] ?? 0 );
		$vehicle = (int) ( $in['vehicle_id'] ?? 0 );
		if ( $driver && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'drivers' ) . ' WHERE id = %d AND party_id = %d', $driver, $pid ) ) ) {
			return new WP_Error( 'atc_driver', 'Pick one of your drivers.', array( 'status' => 400 ) );
		}
		if ( $vehicle && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'vehicles' ) . ' WHERE id = %d AND party_id = %d', $vehicle, $pid ) ) ) {
			return new WP_Error( 'atc_vehicle', 'Pick one of your vehicles.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'booking_legs' ), array( 'driver_id' => $driver ?: null, 'vehicle_id' => $vehicle ?: null ), array( 'id' => $leg_id ) );
		if ( 'new' === $l['status'] ) {
			$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'accepted', 'acknowledged_at' => self::now() ), array( 'id' => $leg_id ) );
		}
		ATC_Install::log( 'leg', $leg_id, 'assigned', array( 'driver' => $driver, 'vehicle' => $vehicle ) );
		return self::detail( $pid, $l['number'] );
	}

	/** The service code completes the ride. A few wrong tries lock it (our team can unlock). */
	public static function complete_with_code( int $pid, int $leg_id, string $code ) {
		global $wpdb;
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$ok = self::open( $l );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$max = (int) ATC_Settings::get( 'service_code_attempts' );
		if ( (int) $l['service_code_attempts'] >= $max ) {
			return new WP_Error( 'atc_locked', 'Too many wrong codes for this booking. Write to us and we will check it.', array( 'status' => 423 ) );
		}
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
		if ( '' === $code || ! hash_equals( strtoupper( (string) $l['service_code'] ), $code ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bookings' ) . ' SET service_code_attempts = service_code_attempts + 1 WHERE id = %d', (int) $l['booking_id'] ) ); // phpcs:ignore
			$left = max( 0, $max - (int) $l['service_code_attempts'] - 1 );
			ATC_Install::log( 'leg', $leg_id, 'code_wrong', array( 'left' => $left ) );
			return new WP_Error( 'atc_code', $left ? 'That code doesn’t match this booking. ' . $left . ( 1 === $left ? ' try' : ' tries' ) . ' left.' : 'That code doesn’t match, and this booking is now locked. Write to us and we will check it.', array( 'status' => 400, 'left' => $left ) );
		}
		$wpdb->update( self::t( 'bookings' ), array( 'service_code_used_at' => self::now(), 'service_code_used_by' => get_current_user_id() ?: null ), array( 'id' => (int) $l['booking_id'] ) );
		self::close( $l, 'completed', 'code' );
		return self::detail( $pid, $l['number'] );
	}

	public static function complete_without_code( int $pid, int $leg_id ) {
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$ok = self::open( $l );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( strtotime( $l['pickup_at'] . ' UTC' ) > time() ) {
			return new WP_Error( 'atc_early', 'You can mark it completed after the pickup time.', array( 'status' => 409 ) );
		}
		self::close( $l, 'completed', 'manual' );
		return self::detail( $pid, $l['number'] );
	}

	public static function no_show( int $pid, int $leg_id, string $note ) {
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$ok = self::open( $l );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( strtotime( $l['pickup_at'] . ' UTC' ) > time() ) {
			return new WP_Error( 'atc_early', 'You can report a no-show only after the pickup time.', array( 'status' => 409 ) );
		}
		$note = mb_substr( trim( sanitize_textarea_field( $note ) ), 0, 1000 );
		if ( mb_strlen( $note ) < 10 ) {
			return new WP_Error( 'atc_note', 'Tell us what happened: how long the driver waited, where, and whether you reached the traveler.', array( 'status' => 400 ) );
		}
		self::close( $l, 'no_show', 'no_show', $note );
		$b = ATC_Checkout::find( '', (int) $l['booking_id'] );
		ATC_Mail::team_note( 'No-show reported: ' . $b['number'], 'The provider reported a no-show. Check it before the payout.', $b, $note );
		return self::detail( $pid, $l['number'] );
	}

	/** Hand the ride back to our team (more than 2 hours before pickup). */
	public static function release( int $pid, int $leg_id, string $reason ) {
		global $wpdb;
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$ok = self::open( $l );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( strtotime( $l['pickup_at'] . ' UTC' ) - time() < 2 * HOUR_IN_SECONDS ) {
			return new WP_Error( 'atc_late', 'It’s less than 2 hours to pickup: if you haven’t confirmed the ride, use Can’t do it; otherwise send us a WhatsApp message right away.', array( 'status' => 409 ) );
		}
		$reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 1000 );
		$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'released', 'party_id' => 0 ), array( 'id' => $leg_id ) );
		$wpdb->update( self::t( 'ledger' ), array( 'status' => 'cancelled', 'note' => 'Handed back by the provider', 'updated_at' => self::now() ), array( 'leg_id' => $leg_id, 'role' => 'provider', 'status' => 'pending' ) );
		ATC_Install::log( 'leg', $leg_id, 'released', array( 'party' => $pid, 'reason' => $reason ) );
		$b = ATC_Checkout::find( '', (int) $l['booking_id'] );
		ATC_Mail::team_note( 'Ride handed back: ' . $b['number'] . ' · find another provider', 'A provider handed this ride back. Assign another provider.', $b, $reason ?: '(no reason given)' );
		return array( 'released' => true );
	}

	/**
	 * "Can't do it": a last-minute ride (under 2 hours to pickup) that the provider has not confirmed yet goes
	 * straight back to our team, with no penalty. Once confirmed, the provider has to message us instead.
	 */
	public static function decline( int $pid, int $leg_id, string $reason ) {
		global $wpdb;
		$l = self::mine( $pid, $leg_id );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$ok = self::open( $l );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$left = strtotime( $l['pickup_at'] . ' UTC' ) - time();
		if ( 'new' !== $l['status'] ) {
			return new WP_Error( 'atc_confirmed', 'You already confirmed this ride. Message us on WhatsApp right away so we can find another driver.', array( 'status' => 409 ) );
		}
		if ( $left > 2 * HOUR_IN_SECONDS ) {
			return new WP_Error( 'atc_early', 'Pickup is more than 2 hours away: use Hand back instead.', array( 'status' => 409 ) );
		}
		if ( $left <= 0 ) {
			return new WP_Error( 'atc_past', 'The pickup time has passed. Message us on WhatsApp right away.', array( 'status' => 409 ) );
		}
		$reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 1000 );
		$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'released', 'party_id' => 0 ), array( 'id' => $leg_id ) );
		$wpdb->update( self::t( 'ledger' ), array( 'status' => 'cancelled', 'note' => 'Last-minute ride declined by the provider (no penalty)', 'updated_at' => self::now() ), array( 'leg_id' => $leg_id, 'role' => 'provider', 'status' => 'pending' ) );
		ATC_Install::log( 'leg', $leg_id, 'declined_last_minute', array( 'party' => $pid, 'reason' => $reason ) );
		$b   = ATC_Checkout::find( '', (int) $l['booking_id'] );
		$m   = (int) floor( $left / 60 );
		$due = ( $m >= 60 ? intdiv( $m, 60 ) . 'H ' : '' ) . ( $m % 60 ) . 'M';
		ATC_Mail::team_note( '⚡ CAN’T DO IT · PICKUP IN ' . $due . ' · reassign now · ' . $b['number'], 'The provider can’t do this last-minute ride (not confirmed, pickup in under 2 hours). Give it to another provider now, or contact the traveler.', $b, $reason ?: '(no reason given)' );
		return array( 'declined' => true );
	}

	/** Close a ride, move its money, and close the booking when all its rides are done. */
	private static function close( array $l, string $status, string $how, string $note = '' ): void {
		global $wpdb;
		$wpdb->update( self::t( 'booking_legs' ), array( 'status' => $status, 'completed_at' => self::now(), 'completed_how' => $how, 'acknowledged_at' => $l['acknowledged_at'] ?: self::now() ), array( 'id' => (int) $l['id'] ) );
		if ( 'completed' === $status ) {
			$wpdb->update( self::t( 'ledger' ), array( 'status' => 'earned', 'updated_at' => self::now() ), array( 'leg_id' => (int) $l['id'], 'role' => 'provider', 'status' => 'pending' ) );
		}
		ATC_Install::log( 'leg', (int) $l['id'], $status, array( 'how' => $how, 'note' => $note ) );
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'booking_legs' ) . " WHERE booking_id = %d AND status IN ('new','accepted','released')", (int) $l['booking_id'] ) );
		if ( ! $open ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bookings' ) . " SET status = 'completed', updated_at = %s WHERE id = %d AND status = 'confirmed'", self::now(), (int) $l['booking_id'] ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . " SET status = 'earned', updated_at = %s WHERE booking_id = %d AND role IN ('platform','affiliate','referrer') AND status = 'pending'", self::now(), (int) $l['booking_id'] ) ); // phpcs:ignore
		}
	}

	/**
	 * Not confirmed within the confirmation time of the Provider Agreement (2 hours; 30 minutes when the pickup
	 * is less than 24 hours away): remind the provider and tell our team. Runs every 15 minutes.
	 */
	public static function ack_check(): void {
		global $wpdb;
		$l      = self::t( 'booking_legs' );
		$b      = self::t( 'bookings' );
		$now    = time();
		$normal = max( 5, (int) ATC_Settings::get( 'ack_reminder_minutes' ) );
		$urgent = max( 5, (int) ATC_Settings::get( 'ack_urgent_minutes' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT l.id, l.booking_id, l.pickup_at, b.updated_at FROM {$l} l JOIN {$b} b ON b.id = l.booking_id WHERE l.status = 'new' AND b.status = 'confirmed' AND l.party_id > 0 AND b.updated_at < %s AND l.pickup_at > %s LIMIT 200", gmdate( 'Y-m-d H:i:s', $now - min( $normal, $urgent ) * 60 ), gmdate( 'Y-m-d H:i:s', $now ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$since  = strtotime( $r['updated_at'] . ' UTC' );
			$soon   = strtotime( $r['pickup_at'] . ' UTC' ) - $since < DAY_IN_SECONDS;
			if ( $now - $since < ( $soon ? $urgent : $normal ) * 60 ) {
				continue;
			}
			$bk   = ATC_Checkout::find( '', (int) $r['booking_id'] );
			$done = (array) ( $bk['snap']['ack_nudged'] ?? array() );
			if ( in_array( (int) $r['id'], $done, true ) ) {
				continue;
			}
			$done[]                   = (int) $r['id'];
			$bk['snap']['ack_nudged'] = $done;
			ATC_Checkout::snapshot( (int) $bk['id'], $bk['snap'] );
			ATC_Mail::provider_nudge( $bk );
		}
	}

	/* ---------- Every hour ---------- */

	public static function hourly(): void {
		global $wpdb;
		$l   = self::t( 'booking_legs' );
		$b   = self::t( 'bookings' );
		$now = time();

		self::ack_check();

		// No code and no no-show: the ride completes on its own after the set hours.
		$hours = max( 1, (int) ATC_Settings::get( 'autocomplete_hours' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, b.status AS bstatus FROM {$l} l JOIN {$b} b ON b.id = l.booking_id WHERE l.status IN ('new','accepted') AND b.status = 'confirmed' AND b.snapshot NOT LIKE '%%\"legacy\":true%%' AND l.party_id > 0 AND l.pickup_at < %s LIMIT 200", gmdate( 'Y-m-d H:i:s', $now - $hours * HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			self::close( $r, 'completed', 'auto' );
		}

		// Reservations from the old site complete quietly once their rides are past (settled there).
		ATC_Migration::complete_past_legacy();
	}

	/* ---------- CSV ---------- */

	public static function csv( int $pid, string $filter ): string {
		$list = self::list( $pid, $filter, 1, true );
		$fh   = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, array( 'Date', 'Time', 'Reservation', 'Direction', 'Flight', 'From', 'To', 'Vehicle', 'Passengers', 'Suitcases', 'Traveler', 'Status', 'You get paid (USD)' ) );
		foreach ( $list['rows'] as $r ) {
			fputcsv( $fh, array( $r['date'], $r['time'], $r['number'], $r['direction'], $r['flight'], $r['from'], $r['to'], $r['vehicle'], $r['pax'], $r['bags'], $r['traveler'], $r['status'], number_format( $r['amount'] / 100, 2, '.', '' ) ) );
		}
		rewind( $fh );
		return (string) stream_get_contents( $fh );
	}
}
