<?php
/**
 * Change the trip itself from Manage booking: passengers, vehicle, address and extras. Allowed until the
 * free-cancellation time before the first pickup, like a cancellation.
 *
 * The new price is worked out on the server from the provider's price list (like at checkout). The traveler
 * sees the new total and the difference first. A higher price is paid on Stripe's own page and the change is
 * saved once the payment arrives; a lower price is saved at once and the difference refunded to the card.
 *
 * Pending change kept in the snapshot under 'rebook': id, q, service, extras, rides, provider, extras_total,
 * extras_provider, total, diff, session, status (open/paid/done), created.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Rebook {

	const MIN_CHARGE = 100; // Below USD 1 we don't ask for the difference.

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/** Every ride still open, nothing owed or charged on the side, paid here. */
	public static function can( array $b ): bool {
		if ( 'confirmed' !== $b['status'] || ! empty( $b['snap']['legacy'] ) || empty( $b['stripe_payment_intent'] ) || empty( $b['legs'] ) ) {
			return false;
		}
		foreach ( $b['legs'] as $l ) {
			if ( ! ATC_Manage::open_leg( $l ) ) {
				return false;
			}
		}
		foreach ( ATC_Charges::all( $b ) as $c ) {
			if ( 'open' === $c['status'] || ( 'paid' === $c['status'] && 'rebook' !== ( $c['kind'] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** The search as the booking stands now: real pickup times, current party and place. */
	private static function base_query( array $b ): array {
		$q   = ATC_Search::parse( (array) ( $b['snap']['search'] ?? array() ) );
		$tz  = (string) ( $b['snap']['timezone'] ?? 'UTC' );
		$arr = ATC_Checkout::leg( $b, 'arrival' );
		$dep = ATC_Checkout::leg( $b, 'departure' );
		$first = $arr ?: $dep;
		if ( $first && $first['pickup_at'] ) {
			$q['date'] = ATC_Search::local( $first['pickup_at'], $tz, 'Y-m-d' );
			$q['time'] = ATC_Search::local( $first['pickup_at'], $tz, 'H:i' );
		}
		if ( $arr && $dep && $dep['pickup_at'] ) {
			$q['return_date'] = ATC_Search::local( $dep['pickup_at'], $tz, 'Y-m-d' );
		}
		$q['airport'] = (string) ( $b['snap']['iata'] ?? $q['airport'] );
		return $q;
	}

	/** Current place of the booking (where the traveler is dropped off or picked up). */
	private static function place( array $b ): string {
		$arr = ATC_Checkout::leg( $b, 'arrival' );
		$dep = ATC_Checkout::leg( $b, 'departure' );
		return (string) ( $arr ? $arr['to_place'] : ( $dep['from_place'] ?? '' ) );
	}

	/** The price of a vehicle for this search (rides only), from the provider's list. */
	private static function ride_price( int $service_id, int $zone_id, string $trip ): ?array {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT retail_cents, provider_cents FROM ' . self::t( 'prices' ) . ' WHERE service_id = %d AND zone_id = %d AND direction = %s AND valid_from IS NULL', $service_id, $zone_id, 'round_trip' === $trip ? 'round_trip' : 'one_way' ), ARRAY_A );
		return $p ? array( 'retail' => (int) $p['retail_cents'], 'provider' => (int) $p['provider_cents'] ) : null;
	}

	/**
	 * What the change would cost. $in: adults, children, to (empty = same place), service (0 = keep the
	 * current vehicle if still offered), extras {extra_id: qty} (missing = keep the current ones).
	 *
	 * @return array|WP_Error
	 */
	public static function quote( string $number, string $key, array $in, bool $keep = false ) {
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		if ( ! self::can( $b ) ) {
			return new WP_Error( 'atc_closed', 'This booking can no longer be changed online. Contact us and we will help.', array( 'status' => 409 ) );
		}
		$q             = self::base_query( $b );
		$q['adults']   = max( 1, min( 60, (int) ( $in['adults'] ?? $b['adults'] ) ) );
		$q['children'] = max( 0, min( 40, (int) ( $in['children'] ?? $b['children'] ) ) );
		$to            = trim( sanitize_text_field( (string) ( $in['to'] ?? '' ) ) );
		$moved         = '' !== $to && 0 !== strcasecmp( $to, self::place( $b ) );
		if ( $moved ) {
			$q['to']  = mb_substr( $to, 0, 255 );
			$q['lat'] = null;
			$q['lng'] = null;
			$q['pid'] = '';
		}
		$res = ATC_Search::run( $q );
		if ( empty( $res['destination'] ) ) {
			return new WP_Error( 'atc_place', 'We could not find that address. Type the hotel name or the full address.', array( 'status' => 400 ) );
		}
		if ( ! $res['offers'] ) {
			$why = array(
				'coverage' => 'We don’t cover that address from this airport yet.',
				'party'    => 'No vehicle here takes that many passengers' . ( ! empty( $res['largest'] ) ? ' (the largest takes ' . (int) $res['largest'] . ')' : '' ) . '. Contact us for a second vehicle.',
				'notice'   => 'It is too close to pickup to change this online.',
			);
			return new WP_Error( 'atc_none', $why[ $res['reason'] ] ?? 'No vehicle is available for that change. Contact us and we will help.', array( 'status' => 409 ) );
		}

		$current = (int) ( $b['legs'][0]['service_id'] ?? 0 );
		$offers  = array();
		foreach ( $res['offers'] as $o ) {
			$zone = (int) ( $o['zone_id'] ?: $res['destination']['zone_id'] );
			$p    = self::ride_price( (int) $o['service_id'], $zone, $q['trip'] );
			if ( ! $p ) {
				continue;
			}
			$offers[ (int) $o['service_id'] ] = $o + array( 'zone' => $zone, 'ride' => $p['retail'], 'ride_provider' => $p['provider'] );
		}
		if ( ! $offers ) {
			return new WP_Error( 'atc_none', 'No vehicle is available for that change. Contact us and we will help.', array( 'status' => 409 ) );
		}
		// The vehicle asked for; else the one booked; else the cheapest that fits from the same provider; else the cheapest.
		$want = (int) ( $in['service'] ?? 0 );
		$pid  = (int) ( $b['legs'][0]['party_id'] ?? 0 );
		$same = 0;
		foreach ( $offers as $id => $x ) {
			if ( (int) $x['party_id'] === $pid && ( ! $same || $x['ride'] < $offers[ $same ]['ride'] ) ) {
				$same = $id;
			}
		}
		$sid = isset( $offers[ $want ] ) ? $want : ( isset( $offers[ $current ] ) ? $current : ( $same ?: (int) array_key_first( $offers ) ) );
		$o    = $offers[ $sid ];

		// Extras of that provider: what the traveler asked, or what they had (same provider only).
		$rides  = 'round_trip' === $q['trip'] ? 2 : 1;
		$had    = array();
		foreach ( ATC_Extras::chosen( (int) $b['id'] ) as $x ) {
			$had[ $x['extra_id'] ] = $x['qty'];
		}
		$asked  = isset( $in['extras'] ) && is_array( $in['extras'] ) ? array_map( 'intval', $in['extras'] ) : null;
		$bp     = (int) ATC_Settings::get( 'provider_commission_bp' );
		$xs     = array();
		$xr     = 0;
		$xp     = 0;
		$picked = array();
		foreach ( ATC_Extras::offered( (int) $o['party_id'] ) as $x ) {
			$qty = null !== $asked ? (int) ( $asked[ $x['id'] ] ?? $asked[ (string) $x['id'] ] ?? 0 ) : (int) ( $had[ $x['id'] ] ?? 0 );
			$qty = min( $x['max_qty'], max( 0, $qty ) );
			$times = 'per_ride' === $x['unit'] ? $qty * $rides : ( 'per_booking' === $x['unit'] ? min( 1, $qty ) : $qty );
			$xs[]  = $x + array( 'qty' => $qty );
			if ( $qty ) {
				$picked[ $x['id'] ] = $qty;
				$xr += $x['price'] * $times;
				$xp += ATC_Money::provider_from_retail( $x['price'], $bp ) * $times;
			}
		}

		$retail   = $o['ride'] + $xr;
		$discount = min( (int) $b['discount_cents'], $retail );
		$total    = $retail - $discount;
		$paid     = (int) $b['total_cents'] - (int) ( $b['snap']['refunded'] ?? 0 );
		$diff     = $total - $paid;
		if ( $diff > 0 && $diff < self::MIN_CHARGE ) {
			$diff  = 0;
			$total = $paid;
		}
		$list = array();
		foreach ( $offers as $x ) {
			$list[] = array(
				'service_id' => (int) $x['service_id'],
				'name'       => $x['name'],
				'provider'   => $x['provider'],
				'rating'     => $x['rating'],
				'max_passengers' => $x['max_passengers'],
				'max_bags'   => $x['max_bags'],
				'ride'       => $x['ride'],
				'current'    => (int) $x['service_id'] === $current,
			);
		}
		$out = array(
			'place'      => (string) $res['destination']['label'],
			'moved'      => $moved,
			'adults'     => $q['adults'],
			'children'   => $q['children'],
			'offers'     => $list,
			'current_provider' => (string) ( ATC_Catalog::party( $pid )['name'] ?? '' ),
			'service'    => $sid,
			'extras'     => array_map( static fn( $x ) => array( 'id' => $x['id'], 'name' => $x['name'], 'description' => $x['description'], 'unit' => $x['unit'], 'price' => $x['price'], 'max_qty' => $x['max_qty'], 'qty' => $x['qty'] ), $xs ),
			'rides'      => $o['ride'],
			'extras_total' => $xr,
			'discount'   => $discount,
			'total'      => $total,
			'paid'       => $paid,
			'diff'       => $diff,
		);
		if ( $keep ) {
			$q['to']  = (string) $res['destination']['label'];
			$q['lat'] = $res['destination']['lat'];
			$q['lng'] = $res['destination']['lng'];
			$q['pid'] = (string) $res['destination']['pid'];
			$out['_plan'] = array(
				'q'          => $q,
				'service'    => $sid,
				'party'      => (int) $o['party_id'],
				'zone'       => $o['zone'],
				'vehicle'    => $o['name'],
				'slug'       => $o['slug'],
				'provider'   => $o['provider'],
				'zone_name'  => (string) $res['destination']['zone'],
				'ride'       => $o['ride'],
				'ride_provider' => $o['ride_provider'],
				'extras'     => $picked,
				'total'      => $total,
				'retail'     => $retail,
				'discount'   => $discount,
				'diff'       => $diff,
			);
		}
		return $out;
	}

	/**
	 * Confirm the change shown in the quote. Lower or same price: saved now (difference refunded).
	 * Higher price: returns {pay_url} to Stripe; saved once paid.
	 *
	 * @return array|WP_Error
	 */
	public static function apply( string $number, string $key, array $in ) {
		$qt = self::quote( $number, $key, $in, true );
		if ( is_wp_error( $qt ) ) {
			return $qt;
		}
		$expected = (int) ( $in['expect'] ?? PHP_INT_MIN );
		if ( PHP_INT_MIN !== $expected && $expected !== (int) $qt['diff'] ) {
			return new WP_Error( 'atc_price', 'The price changed while you were looking. Check the new amount and confirm again.', array( 'status' => 409 ) );
		}
		$b    = ATC_Checkout::load( $number, $key );
		$plan = $qt['_plan'];
		$plan['id']      = 'r' . time();
		$plan['created'] = self::now();
		// A payment page opened earlier for another change must not be paid any more.
		$prev = (array) ( $b['snap']['rebook'] ?? array() );
		if ( 'open' === ( $prev['status'] ?? '' ) && ! empty( $prev['session'] ) ) {
			if ( self::sync( $b, (string) $prev['id'] ) ) {
				return new WP_Error( 'atc_paid', 'Your earlier change was just paid and saved. Refresh the page to see it.', array( 'status' => 409 ) );
			}
			ATC_Checkout::stripe( 'POST', 'checkout/sessions/' . rawurlencode( $prev['session'] ) . '/expire' );
			$b = ATC_Checkout::load( $number, $key );
		}
		if ( $plan['diff'] > 0 ) {
			$plan['status']       = 'open';
			$b['snap']['rebook'] = $plan;
			ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
			$url = self::checkout_url( $b, $key );
			return is_wp_error( $url ) ? $url : array( 'pay_url' => $url, 'diff' => $plan['diff'] );
		}
		$changes = self::save( $b, $plan );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$refund = 0;
		if ( $plan['diff'] < 0 ) {
			$refund = self::refund( (int) $b['id'], -$plan['diff'] );
		}
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		if ( $refund ) {
			$changes[] = 'Refund to your card: ' . ATC_Book::money( $refund );
		}
		self::notify( $b, $changes, $plan );
		return array( 'changed' => $changes, 'refund' => $refund );
	}

	/** Stripe's own page for the difference. */
	private static function checkout_url( array $b, string $key ) {
		$plan = (array) ( $b['snap']['rebook'] ?? array() );
		$back = ATC_Checkout::reservation_url( $b['number'], $key );
		$meta = array( 'kind' => 'rebook', 'booking' => $b['number'], 'booking_id' => (string) (int) $b['id'], 'rebook' => (string) $plan['id'] );
		$s    = ATC_Checkout::stripe(
			'POST',
			'checkout/sessions',
			array(
				'mode'                => 'payment',
				'customer_email'      => $b['email'],
				'client_reference_id' => $b['number'],
				'line_items'          => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) $plan['diff'],
							'product_data' => array( 'name' => 'Change to booking ' . $b['number'] . ' · ' . $plan['vehicle'] ),
						),
					),
				),
				'metadata'            => $meta,
				'payment_intent_data' => array( 'metadata' => $meta, 'description' => 'Trip change ' . $b['number'], 'receipt_email' => $b['email'] ),
				'expires_at'          => time() + 3600,
				'success_url'         => add_query_arg( array( 'rebook_back' => $plan['id'] ), $back ),
				'cancel_url'          => add_query_arg( array( 'rebook_cancel' => 1 ), $back ) . '#manage',
			)
		);
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$b['snap']['rebook']['session'] = (string) $s['id'];
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
		return (string) $s['url'];
	}

	/** Back from Stripe: paid? Then save the change (once). */
	public static function sync( array $b, string $id ): bool {
		$plan = (array) ( $b['snap']['rebook'] ?? array() );
		if ( ( $plan['id'] ?? '' ) !== $id || 'open' !== ( $plan['status'] ?? '' ) || empty( $plan['session'] ) ) {
			return 'done' === ( $plan['status'] ?? '' ) && ( $plan['id'] ?? '' ) === $id;
		}
		$s = ATC_Checkout::stripe( 'GET', 'checkout/sessions/' . rawurlencode( $plan['session'] ) );
		if ( is_wp_error( $s ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) {
			return false;
		}
		return self::mark_paid( $b, $id, (string) ( $s['payment_intent'] ?? '' ), (int) ( $s['amount_total'] ?? 0 ) );
	}

	/** The difference is paid (return page or webhook). Safe to call twice. */
	public static function mark_paid( array $b, string $id, string $pi, int $amount ): bool {
		global $wpdb;
		$t    = self::t( 'bookings' );
		$b    = ATC_Checkout::find( '', (int) $b['id'] );
		$plan = (array) ( $b['snap']['rebook'] ?? array() );
		if ( ! $b || ( $plan['id'] ?? '' ) !== $id || 'open' !== ( $plan['status'] ?? '' ) || $amount < (int) $plan['diff'] ) {
			return false;
		}
		// Claim it first, so the webhook and the return page never save it twice.
		$plan['status']        = 'paid';
		$plan['pi']            = $pi;
		$b['snap']['rebook']  = $plan;
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET snapshot = %s WHERE id = %d AND snapshot = %s", wp_json_encode( $b['snap'] ), (int) $b['id'], (string) $b['snapshot'] ) ); // phpcs:ignore
		if ( ! $claimed ) {
			return false;
		}
		// The payment is kept as a paid charge, so a later cancellation refunds it too.
		$b['snap']['charges'][] = array( 'id' => 'c' . ( count( ATC_Charges::all( $b ) ) + 1 ), 'kind' => 'rebook', 'amount' => (int) $plan['diff'], 'reason' => 'Trip change', 'leg' => 0, 'status' => 'paid', 'session' => (string) ( $plan['session'] ?? '' ), 'pi' => $pi, 'created' => $plan['created'], 'by' => 0, 'paid_at' => self::now() );
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
		$b       = ATC_Checkout::find( '', (int) $b['id'] );
		$changes = self::save( $b, $plan, true );
		if ( is_wp_error( $changes ) ) {
			ATC_Mail::team_note( 'Trip change paid but not saved: ' . $b['number'], 'The traveler paid ' . ATC_Book::money( (int) $plan['diff'] ) . ' for a change we could not save (' . $changes->get_error_message() . '). Make the change by hand or refund it.', $b, wp_json_encode( $plan ) );
			return true;
		}
		$changes[] = 'Paid: ' . ATC_Book::money( (int) $plan['diff'] );
		self::notify( ATC_Checkout::find( '', (int) $b['id'] ), $changes, $plan );
		return true;
	}

	/**
	 * Write the change: rides updated in place (provider, price, place), extras, totals and money lines.
	 *
	 * @return array|WP_Error the list of changes, in words.
	 */
	private static function save( array $b, array $plan, bool $paid = false ) {
		global $wpdb;
		$l       = self::t( 'ledger' );
		$legs    = $b['legs'];
		$n       = count( $legs );
		$old_pid = (int) ( $legs[0]['party_id'] ?? 0 );
		$new_pid = (int) $plan['party'];
		$changes = array();
		$q       = $plan['q'];
		$place   = (string) $q['to'];
		$snap    = $b['snap'];

		if ( (int) $b['adults'] !== (int) $q['adults'] || (int) $b['children'] !== (int) $q['children'] ) {
			$changes[] = 'Passengers: ' . (int) $q['adults'] . ' adult' . ( 1 === (int) $q['adults'] ? '' : 's' ) . ( $q['children'] ? ', ' . (int) $q['children'] . ' child' . ( 1 === (int) $q['children'] ? '' : 'ren' ) : '' );
		}
		if ( (int) ( $legs[0]['service_id'] ?? 0 ) !== (int) $plan['service'] ) {
			$changes[] = 'Vehicle: ' . $plan['vehicle'] . ( $old_pid !== $new_pid ? ' (new provider: ' . $plan['provider'] . ')' : '' );
		}
		if ( 0 !== strcasecmp( $place, self::place( $b ) ) ) {
			$changes[] = 'Address: ' . $place;
		}

		foreach ( $legs as $i => $leg ) {
			$last = $i === $n - 1;
			$set  = array(
				'service_id'     => (int) $plan['service'],
				'party_id'       => $new_pid,
				'zone_id'        => (int) $plan['zone'] ?: null,
				'retail_cents'   => $last ? $plan['ride'] - intdiv( $plan['ride'], $n ) * ( $n - 1 ) : intdiv( $plan['ride'], $n ),
				'provider_cents' => $last ? $plan['ride_provider'] - intdiv( $plan['ride_provider'], $n ) * ( $n - 1 ) : intdiv( $plan['ride_provider'], $n ),
			);
			if ( 'arrival' === $leg['direction'] ) {
				$set['to_place'] = mb_substr( $place, 0, 255 );
			} else {
				$set['from_place'] = mb_substr( $place, 0, 255 );
			}
			if ( $old_pid !== $new_pid ) {
				$set += array( 'status' => 'new', 'acknowledged_at' => null, 'driver_id' => null, 'vehicle_id' => null );
			}
			$wpdb->update( self::t( 'booking_legs' ), $set, array( 'id' => (int) $leg['id'] ) );
		}

		// Extras, priced again from the provider's offer.
		$before = ATC_Extras::lines( (int) $b['id'] );
		ATC_Extras::save_choice( ATC_Checkout::find( '', (int) $b['id'] ), (array) $plan['extras'] );
		$after = ATC_Extras::lines( (int) $b['id'] );
		if ( wp_list_pluck( $before, 'label' ) !== wp_list_pluck( $after, 'label' ) ) {
			$changes[] = 'Extras: ' . ( $after ? implode( ', ', wp_list_pluck( $after, 'label' ) ) : 'none' );
		}
		$xt = ATC_Extras::totals( (int) $b['id'] );

		// Booking: party, prices, snapshot.
		// total_cents = all the traveler has been charged (refunds are kept apart in the snapshot), so a higher
		// price adds the difference just paid and a lower one leaves it: the difference goes out as a refund.
		$retail = (int) $plan['ride'] + $xt['retail'];
		$disc   = min( (int) $b['discount_cents'], $retail );
		$wpdb->update(
			self::t( 'bookings' ),
			array( 'adults' => (int) $q['adults'], 'children' => (int) $q['children'], 'retail_cents' => $retail, 'discount_cents' => $disc, 'total_cents' => (int) $b['total_cents'] + max( 0, (int) $plan['diff'] ), 'updated_at' => self::now() ),
			array( 'id' => (int) $b['id'] )
		);
		$fresh                 = ATC_Checkout::find( '', (int) $b['id'] );
		$snap                  = $fresh['snap'];
		$snap['search']        = $q;
		$snap['to']            = $place;
		$snap['zone']          = (string) $plan['zone_name'];
		$snap['vehicle']       = (string) $plan['vehicle'];
		$snap['slug']          = (string) $plan['slug'];
		$snap['provider']      = (string) $plan['provider'];
		if ( isset( $snap['details'] ) ) {
			if ( isset( $snap['details']['dropoff'] ) || ATC_Checkout::leg( $fresh, 'arrival' ) ) {
				$snap['details']['dropoff'] = $place;
			}
			if ( isset( $snap['details']['return_from'] ) ) {
				$snap['details']['return_from'] = $place;
			}
		}
		if ( ! empty( $snap['rebook'] ) ) {
			$snap['rebook']['status'] = 'done';
		}
		$snap['rebooks'][] = array( 'at' => self::now(), 'diff' => (int) $plan['diff'], 'changes' => $changes, 'from_party' => $old_pid );
		ATC_Checkout::snapshot( (int) $b['id'], $snap );

		// Money lines: the pending provider lines are replaced by the new ones.
		$wpdb->query( $wpdb->prepare( "UPDATE {$l} SET status = 'cancelled', note = 'Trip changed by the traveler', updated_at = %s WHERE booking_id = %d AND role = 'provider' AND status = 'pending'", self::now(), (int) $b['id'] ) ); // phpcs:ignore
		foreach ( $fresh['legs'] as $leg ) {
			$wpdb->insert( $l, array( 'booking_id' => (int) $b['id'], 'leg_id' => (int) $leg['id'], 'party_id' => $new_pid, 'role' => 'provider', 'amount_cents' => (int) $leg['provider_cents'], 'currency' => 'USD', 'status' => 'pending', 'service_month' => $leg['pickup_at'] ? substr( $leg['pickup_at'], 0, 7 ) : null, 'created_at' => self::now(), 'updated_at' => self::now() ) );
		}
		ATC_Extras::on_paid( $fresh );
		ATC_Ops::rebalance_platform( (int) $b['id'] );
		ATC_Install::log( 'booking', (int) $b['id'], 'trip_changed_by_traveler', array( 'changes' => $changes, 'diff' => (int) $plan['diff'], 'from_party' => $old_pid, 'to_party' => $new_pid ) );
		return $changes ?: array( 'Trip details saved' );
	}

	/** Lower price: send the difference back, newest payment first. Returns what was refunded. */
	private static function refund( int $booking_id, int $amount ): int {
		global $wpdb;
		$b = ATC_Checkout::find( '', $booking_id );
		if ( ! isset( $b['snap']['pi_refunded'] ) ) {
			$b['snap']['pi_refunded'] = array( $b['stripe_payment_intent'] => (int) ( $b['snap']['refunded'] ?? 0 ) );
		}
		$need = $amount;
		$sent = 0;
		foreach ( ATC_Charges::refund_sources( $b ) as $pi => $room ) {
			if ( $need <= 0 ) {
				break;
			}
			$r = ATC_Checkout::stripe( 'POST', 'refunds', array( 'payment_intent' => $pi, 'amount' => min( $need, $room ), 'reason' => 'requested_by_customer', 'metadata' => array( 'booking' => $b['number'], 'kind' => 'rebook' ) ) );
			if ( is_wp_error( $r ) ) {
				break;
			}
			$got                             = (int) $r['amount'];
			$b['snap']['pi_refunded'][ $pi ] = (int) ( $b['snap']['pi_refunded'][ $pi ] ?? 0 ) + $got;
			$b['snap']['refunded']           = (int) ( $b['snap']['refunded'] ?? 0 ) + $got;
			$need -= $got;
			$sent += $got;
			$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => $booking_id, 'party_id' => null, 'role' => 'refund', 'amount_cents' => -$got, 'currency' => 'USD', 'status' => 'paid', 'note' => 'Trip changed by the traveler: lower price', 'created_at' => self::now(), 'updated_at' => self::now() ) );
		}
		ATC_Checkout::snapshot( $booking_id, $b['snap'] );
		ATC_Ops::rebalance_platform( $booking_id );
		if ( $need > 0 ) {
			ATC_Mail::team_note( 'Refund to send by hand: ' . $b['number'], 'A traveler changed their trip to a lower price. Stripe did not refund ' . ATC_Book::money( $need ) . ' of the difference. Refund it from Control.', $b, 'Trip change' );
		}
		ATC_Install::log( 'booking', $booking_id, 'refunded', array( 'amount' => $sent, 'reason' => 'Trip change, lower price' ) );
		return $sent;
	}

	/** Traveler and provider get the changes; a provider who lost the ride is told; our team sees it. */
	private static function notify( array $b, array $changes, array $plan ): void {
		$last = (array) end( $b['snap']['rebooks'] );
		$from = (int) ( $last['from_party'] ?? 0 );
		ATC_Mail::booking_changed( $b, $changes );
		if ( $from && $from !== (int) $plan['party'] ) {
			ATC_Mail::provider_removed( $b, $from );
			ATC_Mail::provider_new( $b );
		}
		ATC_Mail::team_note( 'Trip changed by the traveler: ' . $b['number'], 'The traveler changed the trip in Manage booking.' . ( $plan['diff'] ? ' Price difference: ' . ATC_Book::money( (int) $plan['diff'] ) . '.' : '' ), $b, implode( "\n", $changes ) );
	}
}
