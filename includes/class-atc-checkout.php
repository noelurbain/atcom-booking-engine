<?php
/**
 * Checkout: the booking is created as soon as the traveler's contact details are in (so an abandoned
 * booking can still get its reminder), completed with flight and trip details, held for 15 minutes
 * while they pay with Stripe, and confirmed when Stripe says the payment succeeded.
 *
 * Prices are always worked out again on the server from the provider's price list; nothing the browser
 * sends about money is trusted. Travelers without an account reach their booking with its number and a
 * private key (only its hash is stored).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Checkout {

	const HOLD_MINUTES = 15;

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/* ---------- The offer, checked again ---------- */

	/**
	 * The offer the traveler chose, still valid for this search, with the provider's amount.
	 *
	 * @return array|WP_Error offer + search result (airport, destination, pickup) + provider_cents.
	 */
	public static function offer( array $q, int $service_id ) {
		global $wpdb;
		$res = ATC_Search::run( $q );
		foreach ( $res['offers'] as $o ) {
			if ( $o['service_id'] === $service_id ) {
				$dir   = 'round_trip' === $q['trip'] ? 'round_trip' : 'one_way';
				$price = $wpdb->get_row( $wpdb->prepare( 'SELECT retail_cents, provider_cents FROM ' . self::t( 'prices' ) . ' WHERE service_id = %d AND zone_id = %d AND direction = %s AND valid_from IS NULL', $service_id, (int) $res['destination']['zone_id'], $dir ), ARRAY_A );
				if ( ! $price ) {
					break;
				}
				$o['retail']   = (int) $price['retail_cents'];
				$o['provider'] = (int) $price['provider_cents'];
				return array( 'offer' => $o, 'search' => $res );
			}
		}
		return new WP_Error( 'atc_offer_gone', 'That vehicle is no longer available for this trip. Please choose again.', array( 'status' => 409 ) );
	}

	/* ---------- Access ---------- */

	private static function key_hash( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/** A booking by number and private key, or null. Our team can open any booking. */
	public static function load( string $number, string $key ): ?array {
		global $wpdb;
		$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $number ) ) ), ARRAY_A );
		if ( ! $b ) {
			return null;
		}
		$b['snap'] = json_decode( (string) $b['snapshot'], true ) ?: array();
		$ok        = '' !== $key && ! empty( $b['snap']['key_hash'] ) && hash_equals( (string) $b['snap']['key_hash'], self::key_hash( $key ) );
		if ( ! $ok && ! current_user_can( 'atc_manage' ) && ! ( $b['user_id'] && (int) $b['user_id'] === get_current_user_id() ) ) {
			return null;
		}
		$b['legs'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'booking_legs' ) . ' WHERE booking_id = %d ORDER BY id', (int) $b['id'] ), ARRAY_A );
		return $b;
	}

	private static function save_snapshot( int $id, array $snap ): void {
		global $wpdb;
		$wpdb->update( self::t( 'bookings' ), array( 'snapshot' => wp_json_encode( $snap ), 'updated_at' => self::now() ), array( 'id' => $id ) );
	}

	/* ---------- Clean input ---------- */

	private static function contact( array $in ) {
		$first = trim( sanitize_text_field( (string) ( $in['first_name'] ?? '' ) ) );
		$last  = trim( sanitize_text_field( (string) ( $in['last_name'] ?? '' ) ) );
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$code  = preg_replace( '/[^0-9+]/', '', (string) ( $in['phone_code'] ?? '' ) );
		$phone = preg_replace( '/[^0-9]/', '', (string) ( $in['phone'] ?? '' ) );
		if ( '' === $first || '' === $last ) {
			return new WP_Error( 'atc_contact', 'Please type the lead traveler’s first and last name.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'atc_contact', 'Please check the email: your confirmation goes there.', array( 'status' => 400 ) );
		}
		if ( strlen( $phone ) < 6 ) {
			return new WP_Error( 'atc_contact', 'Please type a mobile number your driver can reach on arrival day.', array( 'status' => 400 ) );
		}
		return array(
			'first_name' => mb_substr( $first, 0, 100 ),
			'last_name'  => mb_substr( $last, 0, 100 ),
			'email'      => mb_substr( $email, 0, 190 ),
			'phone'      => mb_substr( ( '' !== $code ? ( '+' === $code[0] ? $code : '+' . $code ) . ' ' : '' ) . $phone, 0, 40 ),
			'whatsapp'   => ! empty( $in['whatsapp'] ),
		);
	}

	private static function utc( string $date, string $time, string $tz ): ?string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
			return null;
		}
		try {
			$d = new DateTime( $date . ' ' . $time, new DateTimeZone( $tz ?: 'UTC' ) );
			$d->setTimezone( new DateTimeZone( 'UTC' ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/* ---------- Start: contact details in, booking created ---------- */

	/**
	 * Create the booking (status held, not yet payable) from the search, the chosen vehicle and the contact.
	 *
	 * @return array|WP_Error {number, key}
	 */
	public static function start( array $in ) {
		global $wpdb;
		if ( ! ATC_Partner::rate_ok( 'checkout_start', 30 ) ) {
			return new WP_Error( 'atc_slow', 'Too many attempts. Please wait a few minutes.', array( 'status' => 429 ) );
		}
		$q   = ATC_Search::parse( (array) ( $in['search'] ?? array() ) );
		$got = self::offer( $q, (int) ( $in['service'] ?? 0 ) );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$c = self::contact( $in );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$o      = $got['offer'];
		$s      = $got['search'];
		$key    = wp_generate_password( 24, false );
		$number = ATC_Codes::booking_number();
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', $number ) ) ) {
			$number = ATC_Codes::booking_number();
		}
		$snap = array(
			'key_hash' => self::key_hash( $key ),
			'search'   => $q,
			'site'     => wp_parse_url( home_url(), PHP_URL_HOST ),
			'iata'     => $s['airport']['iata'],
			'timezone' => $s['airport']['timezone'],
			'airport'  => $s['airport']['name'],
			'to'       => $s['destination']['label'],
			'zone'     => $s['destination']['zone'],
			'vehicle'  => $o['name'],
			'slug'     => $o['slug'],
			'provider' => $o['provider'],
			'whatsapp' => $c['whatsapp'],
			'step'     => 'contact',
		);
		$total = (int) $o['retail'];
		$wpdb->insert(
			self::t( 'bookings' ),
			array(
				'number'       => $number,
				'status'       => 'held',
				'channel'      => 'web',
				'trip_type'    => 'round_trip' === $q['trip'] ? 'round_trip' : 'one_way',
				'user_id'      => get_current_user_id() ?: null,
				'first_name'   => $c['first_name'],
				'last_name'    => $c['last_name'],
				'email'        => $c['email'],
				'phone'        => $c['phone'],
				'adults'       => (int) $q['adults'],
				'children'     => (int) $q['children'],
				'lang'         => 'en',
				'currency'     => 'USD',
				'retail_cents' => $total,
				'total_cents'  => $total,
				'snapshot'     => wp_json_encode( $snap ),
				'service_code' => ATC_Codes::service_code( (int) ATC_Settings::get( 'service_code_length' ) ),
				'created_at'   => self::now(),
				'updated_at'   => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'atc_booking', 'We could not save your booking. Please try again.', array( 'status' => 500 ) );
		}
		self::write_legs( $id, $o, $s, $q, array() );
		ATC_Install::log( 'booking', $id, 'started', array( 'service' => $o['service_id'], 'total' => $total ) );
		return array( 'number' => $number, 'key' => $key );
	}

	/** (Re)write the trip legs: arrival, and the return for a round trip. Money split half and half. */
	private static function write_legs( int $booking_id, array $o, array $s, array $q, array $d ): void {
		global $wpdb;
		$t   = self::t( 'booking_legs' );
		$tz  = (string) $s['airport']['timezone'];
		$air = (string) $s['airport']['name'];
		$to  = (string) ( $d['dropoff'] ?? $s['destination']['label'] );
		$wpdb->delete( $t, array( 'booking_id' => $booking_id ) );
		$legs = array(
			array(
				'direction'      => 'arrival',
				'pickup_at'      => self::utc( (string) ( $d['landing_date'] ?? $q['date'] ), (string) ( $d['landing_time'] ?? ( $q['time'] ?: ATC_Search::DEFAULT_TIME ) ), $tz ),
				'flight_airline' => $d['airline'] ?? null,
				'flight_number'  => $d['flight'] ?? null,
				'flight_time'    => self::utc( (string) ( $d['landing_date'] ?? $q['date'] ), (string) ( $d['landing_time'] ?? $q['time'] ), $tz ),
				'from_place'     => $air,
				'to_place'       => $to,
				'suitcases'      => (int) ( $d['suitcases'] ?? 0 ),
				'carry_ons'      => (int) ( $d['carry_ons'] ?? 0 ),
				'notes'          => $d['notes'] ?? null,
			),
		);
		if ( 'round_trip' === $q['trip'] ) {
			$legs[] = array(
				'direction'      => 'departure',
				'pickup_at'      => self::utc( (string) ( $d['return_date'] ?? $q['return_date'] ), (string) ( $d['return_pickup'] ?? '' ), $tz ),
				'flight_airline' => $d['return_airline'] ?? null,
				'flight_number'  => $d['return_flight'] ?? null,
				'flight_time'    => self::utc( (string) ( $d['return_date'] ?? $q['return_date'] ), (string) ( $d['return_flight_time'] ?? '' ), $tz ),
				'from_place'     => (string) ( $d['return_from'] ?? $to ),
				'to_place'       => $air,
				'suitcases'      => (int) ( $d['suitcases'] ?? 0 ),
				'carry_ons'      => (int) ( $d['carry_ons'] ?? 0 ),
				'notes'          => $d['notes'] ?? null,
			);
		}
		$n = count( $legs );
		foreach ( $legs as $i => $l ) {
			$last = $i === $n - 1;
			$wpdb->insert(
				$t,
				$l + array(
					'booking_id'     => $booking_id,
					'service_id'     => (int) $o['service_id'],
					'party_id'       => (int) $o['party_id'],
					'zone_id'        => (int) $s['destination']['zone_id'] ?: null,
					'retail_cents'   => $last ? $o['retail'] - intdiv( $o['retail'], $n ) * ( $n - 1 ) : intdiv( $o['retail'], $n ),
					'provider_cents' => $last ? $o['provider'] - intdiv( $o['provider'], $n ) * ( $n - 1 ) : intdiv( $o['provider'], $n ),
					'status'         => 'held',
				)
			);
		}
	}

	/* ---------- Details: flight and trip, then the payment ---------- */

	private static function details( array $in, array $q ) {
		$s   = static fn( $k ) => trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
		$no  = ! empty( $in['no_flight'] );
		$d   = array(
			'no_flight'    => $no,
			'airline'      => $no ? null : mb_substr( $s( 'airline' ), 0, 100 ),
			'flight'       => $no ? null : mb_substr( strtoupper( preg_replace( '/\s+/', '', $s( 'flight' ) ) ), 0, 20 ),
			'landing_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'landing_date' ) ) ? $s( 'landing_date' ) : $q['date'],
			'landing_time' => preg_match( '/^\d{1,2}:\d{2}$/', $s( 'landing_time' ) ) ? $s( 'landing_time' ) : $q['time'],
			'dropoff'      => mb_substr( $s( 'dropoff' ), 0, 255 ),
			'hotel_name'   => mb_substr( $s( 'hotel_name' ), 0, 100 ),
			'suitcases'    => max( 0, min( 60, (int) ( $in['suitcases'] ?? 0 ) ) ),
			'carry_ons'    => max( 0, min( 60, (int) ( $in['carry_ons'] ?? 0 ) ) ),
			'notes'        => mb_substr( trim( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ) ), 0, 1000 ),
			'domestic'     => ! empty( $in['domestic'] ),
		);
		if ( ! $no && ( '' === (string) $d['flight'] ) ) {
			return new WP_Error( 'atc_flight', 'Type your flight number, or tick “I don’t have my flight yet”.', array( 'status' => 400 ) );
		}
		if ( '' === (string) $d['landing_time'] ) {
			return new WP_Error( 'atc_flight', 'Type your landing time so your driver is there on time.', array( 'status' => 400 ) );
		}
		if ( 'round_trip' === $q['trip'] ) {
			$d['return_date']        = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'return_date' ) ) ? $s( 'return_date' ) : $q['return_date'];
			$d['return_airline']     = mb_substr( $s( 'return_airline' ), 0, 100 ) ?: null;
			$d['return_flight']      = mb_substr( strtoupper( preg_replace( '/\s+/', '', $s( 'return_flight' ) ) ), 0, 20 ) ?: null;
			$d['return_flight_time'] = preg_match( '/^\d{1,2}:\d{2}$/', $s( 'return_flight_time' ) ) ? $s( 'return_flight_time' ) : '';
			$d['return_pickup']      = preg_match( '/^\d{1,2}:\d{2}$/', $s( 'return_pickup' ) ) ? $s( 'return_pickup' ) : '';
			$d['return_from']        = mb_substr( $s( 'return_from' ), 0, 255 );
			if ( '' === (string) $d['return_date'] || '' === $d['return_pickup'] ) {
				return new WP_Error( 'atc_return', 'Pick the return date and the hotel pickup time.', array( 'status' => 400 ) );
			}
			if ( strtotime( $d['return_date'] ) < strtotime( $d['landing_date'] ) ) {
				return new WP_Error( 'atc_return', 'The return date is before your arrival.', array( 'status' => 400 ) );
			}
		}
		if ( '' === $d['dropoff'] ) {
			unset( $d['dropoff'] );
		}
		return $d;
	}

	/**
	 * Save the trip details, check the price again, hold the booking for 15 minutes and prepare the payment.
	 *
	 * @return array|WP_Error {pay_url}
	 */
	public static function details_save( string $number, string $key, array $in ) {
		global $wpdb;
		$b = self::load( $number, $key );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'We could not find that booking. Please start again.', array( 'status' => 404 ) );
		}
		if ( 'held' !== $b['status'] ) {
			return new WP_Error( 'atc_done', 'This booking is already paid.', array( 'status' => 409 ) );
		}
		$snap = $b['snap'];
		$q    = ATC_Search::parse( (array) $snap['search'] );
		$c    = self::contact( $in );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$d = self::details( $in, $q );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		// The landing time the traveler typed is the real pickup time: check notice and price against it.
		$q['date'] = $d['landing_date'];
		$q['time'] = $d['landing_time'];
		if ( 'round_trip' === $q['trip'] ) {
			$q['return_date'] = $d['return_date'];
		}
		$service = (int) ( $b['legs'][0]['service_id'] ?? 0 );
		$got     = self::offer( $q, $service );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$o = $got['offer'];
		self::write_legs( (int) $b['id'], $o, $got['search'], $q, $d );
		$snap['search']   = $q;
		$snap['details']  = $d;
		$snap['whatsapp'] = $c['whatsapp'];
		$snap['step']     = 'payment';
		$wpdb->update(
			self::t( 'bookings' ),
			array(
				'first_name'      => $c['first_name'],
				'last_name'       => $c['last_name'],
				'email'           => $c['email'],
				'phone'           => $c['phone'],
				'retail_cents'    => (int) $o['retail'],
				'total_cents'     => (int) $o['retail'],
				'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * self::HOLD_MINUTES ),
				'snapshot'        => wp_json_encode( $snap ),
				'updated_at'      => self::now(),
			),
			array( 'id' => (int) $b['id'] )
		);
		ATC_Install::log( 'booking', (int) $b['id'], 'details_saved' );
		return array( 'pay_url' => self::pay_url( $b['number'], $key ) );
	}

	/**
	 * Hold the booking again when the 15 minutes ran out on the payment page: check the vehicle is still
	 * offered and take today's price for it.
	 *
	 * @return array|WP_Error the booking, loaded again.
	 */
	public static function rehold( array $b, string $key ) {
		global $wpdb;
		$q   = ATC_Search::parse( (array) ( $b['snap']['search'] ?? array() ) );
		$got = self::offer( $q, (int) ( $b['legs'][0]['service_id'] ?? 0 ) );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$o = $got['offer'];
		if ( (int) $o['retail'] !== (int) $b['total_cents'] ) {
			self::write_legs( (int) $b['id'], $o, $got['search'], $q, (array) ( $b['snap']['details'] ?? array() ) );
		}
		$wpdb->update(
			self::t( 'bookings' ),
			array( 'retail_cents' => (int) $o['retail'], 'total_cents' => (int) $o['retail'], 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * self::HOLD_MINUTES ), 'updated_at' => self::now() ),
			array( 'id' => (int) $b['id'] )
		);
		return self::load( $b['number'], $key );
	}

	/** Seconds left on the hold (0 when it ran out). */
	public static function hold_left( array $b ): int {
		return empty( $b['hold_expires_at'] ) ? 0 : max( 0, strtotime( $b['hold_expires_at'] . ' UTC' ) - time() );
	}

	/** The provider of a booking, for the confirmation card. */
	public static function provider( array $b ): ?array {
		global $wpdb;
		$pid = (int) ( $b['legs'][0]['party_id'] ?? 0 );
		return $pid ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, phone, email, rating_avg, rating_count FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $pid ), ARRAY_A ) : null;
	}

	public static function pay_url( string $number, string $key ): string {
		return add_query_arg( array( 'b' => $number, 'k' => $key ), home_url( '/checkout/pay/' ) );
	}

	public static function reservation_url( string $number, string $key ): string {
		return add_query_arg( array( 'k' => $key ), home_url( '/reservation/' . $number . '/' ) );
	}

	/* ---------- Stripe ---------- */

	public static function stripe( string $method, string $path, array $body = array() ) {
		$secret = ATC_Settings::stripe_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'atc_stripe', 'Payments are not set up yet.', array( 'status' => 503 ) );
		}
		$res = wp_remote_request(
			'https://api.stripe.com/v1/' . ltrim( $path, '/' ),
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $secret,
					'Stripe-Version' => '2024-06-20',
				),
				'body'    => $body ? $body : null,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'atc_stripe', 'We could not reach the payment service. Please try again.', array( 'status' => 502 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) >= 400 ) {
			ATC_Install::log( 'stripe', 0, 'error', array( 'path' => $path, 'error' => $data['error']['message'] ?? 'unknown' ) );
			return new WP_Error( 'atc_stripe', $data['error']['message'] ?? 'The payment service refused the request.', array( 'status' => 502 ) );
		}
		return $data;
	}

	/**
	 * The PaymentIntent for a held booking: created once, its amount kept in step with the booking.
	 *
	 * @return array|WP_Error Stripe PaymentIntent.
	 */
	public static function payment_intent( array $b ) {
		global $wpdb;
		$amount = (int) $b['total_cents'];
		$body   = array(
			'amount'      => $amount,
			'currency'    => 'usd',
			'description' => 'Airport transfer ' . $b['number'] . ' · ' . ( $b['snap']['vehicle'] ?? '' ),
			'receipt_email' => $b['email'],
			'metadata'    => array( 'booking' => $b['number'], 'booking_id' => (int) $b['id'], 'site' => (string) ( $b['snap']['site'] ?? '' ) ),
		);
		if ( ! empty( $b['stripe_payment_intent'] ) ) {
			$pi = self::stripe( 'GET', 'payment_intents/' . rawurlencode( $b['stripe_payment_intent'] ) );
			if ( ! is_wp_error( $pi ) && in_array( $pi['status'], array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
				if ( (int) $pi['amount'] !== $amount ) {
					$pi = self::stripe( 'POST', 'payment_intents/' . rawurlencode( $pi['id'] ), array( 'amount' => $amount ) );
				}
				return $pi;
			}
			if ( ! is_wp_error( $pi ) && in_array( $pi['status'], array( 'succeeded', 'processing' ), true ) ) {
				return $pi;
			}
		}
		$body['automatic_payment_methods'] = array( 'enabled' => 'true' );
		$pi = self::stripe( 'POST', 'payment_intents', $body );
		if ( ! is_wp_error( $pi ) ) {
			$wpdb->update( self::t( 'bookings' ), array( 'stripe_payment_intent' => $pi['id'] ), array( 'id' => (int) $b['id'] ) );
		}
		return $pi;
	}

	/** Back from Stripe (or the webhook later): if the payment succeeded, confirm the booking once. */
	public static function sync_payment( array $b ): string {
		if ( 'held' !== $b['status'] || empty( $b['stripe_payment_intent'] ) ) {
			return $b['status'];
		}
		$pi = self::stripe( 'GET', 'payment_intents/' . rawurlencode( $b['stripe_payment_intent'] ) . '?expand[]=latest_charge' );
		if ( is_wp_error( $pi ) ) {
			return 'held';
		}
		if ( 'succeeded' === $pi['status'] && (int) $pi['amount_received'] >= (int) $b['total_cents'] ) {
			self::mark_paid( $b, $pi );
			return 'confirmed';
		}
		return 'processing' === $pi['status'] ? 'processing' : 'held';
	}

	/**
	 * Confirm a paid booking: status, legs waiting for the provider, the money lines (provider pending until
	 * the trip is completed, our share), and the card shown on the confirmation. Safe to call twice.
	 */
	public static function mark_paid( array $b, array $pi ): void {
		global $wpdb;
		$t = self::t( 'bookings' );
		// Only the first caller moves held → confirmed.
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'confirmed', hold_expires_at = NULL, updated_at = %s WHERE id = %d AND status = 'held'", self::now(), (int) $b['id'] ) ); // phpcs:ignore
		if ( ! $done ) {
			return;
		}
		$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'new' ), array( 'booking_id' => (int) $b['id'] ) );
		$card = $pi['latest_charge']['payment_method_details']['card'] ?? null;
		$snap = $b['snap'];
		unset( $snap['step'] );
		$snap['payment'] = 'paid';
		$snap['method']  = $card ? 'card' : (string) ( $pi['payment_method_types'][0] ?? 'card' );
		$snap['card']    = $card ? array( 'brand' => $card['brand'] ?? '', 'last4' => $card['last4'] ?? '' ) : null;
		$snap['paid_at'] = self::now();
		self::save_snapshot( (int) $b['id'], $snap );

		$l     = self::t( 'ledger' );
		$month = null;
		$prov  = 0;
		foreach ( $b['legs'] as $leg ) {
			$month = $leg['pickup_at'] ? substr( $leg['pickup_at'], 0, 7 ) : null;
			$prov += (int) $leg['provider_cents'];
			$wpdb->insert( $l, array( 'booking_id' => (int) $b['id'], 'leg_id' => (int) $leg['id'], 'party_id' => (int) $leg['party_id'], 'role' => 'provider', 'amount_cents' => (int) $leg['provider_cents'], 'currency' => 'USD', 'status' => 'pending', 'service_month' => $month, 'created_at' => self::now(), 'updated_at' => self::now() ) );
		}
		$wpdb->insert( $l, array( 'booking_id' => (int) $b['id'], 'party_id' => null, 'role' => 'platform', 'amount_cents' => (int) $b['total_cents'] - $prov, 'currency' => 'USD', 'status' => 'pending', 'service_month' => $month, 'note' => 'Before Stripe fees', 'created_at' => self::now(), 'updated_at' => self::now() ) );
		ATC_Install::log( 'booking', (int) $b['id'], 'paid', array( 'amount' => (int) $pi['amount_received'], 'payment_intent' => $pi['id'] ) );
		do_action( 'atc_booking_confirmed', (int) $b['id'] );
	}
}
