<?php
/**
 * Extras: one catalog of ours (child seats, an extra stop, drinks…); each provider switches on the ones it
 * offers and sets its price the same way as transfers (Suggested retail price / Amount you will get paid).
 * The traveler adds them on step 2. They go into the booking total; the provider's share is paid with the
 * ride (one ledger line "Extras" on the last ride), and they show on the booking, emails and reports.
 *
 * Units: per_booking (once), per_ride (once per ride: a child seat on a round trip counts twice),
 * per_item (quantity: 6-packs, flowers).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Extras {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** The starting catalog. Our team can rename, turn off or add more in Control → Extras. */
	public static function seed_catalog(): void {
		global $wpdb;
		$rows = array(
			array( 'infant-seat', 'Infant car seat', 'safety', 'Rear-facing, for babies up to about 1 year (0–13 kg).', 'per_ride', 2, 1000, 10 ),
			array( 'child-seat', 'Child car seat', 'safety', 'For children about 1 to 4 years (9–18 kg).', 'per_ride', 3, 1000, 20 ),
			array( 'booster-seat', 'Booster seat', 'safety', 'For children about 4 to 12 years (15–36 kg).', 'per_ride', 3, 800, 30 ),
			array( 'extra-stop', 'Stop at a supermarket or store', 'route', 'Up to 30 minutes on the way, to buy what you need.', 'per_ride', 1, 2500, 40 ),
			array( 'beer-pack', 'Beers (6-pack)', 'drinks', 'Local beer, cold, waiting in the vehicle. Adults 18+ only.', 'per_item', 4, 1500, 50 ),
			array( 'water-soda', 'Water and soft drinks', 'drinks', 'A cooler with bottled water and sodas for the ride.', 'per_booking', 1, 1000, 60 ),
			array( 'champagne', 'Bottle of sparkling wine', 'drinks', 'Chilled, with glasses. Adults 18+ only.', 'per_item', 2, 4500, 70 ),
			array( 'snacks', 'Snacks', 'food', 'A bag of local snacks for the ride.', 'per_booking', 1, 1000, 80 ),
			array( 'flowers', 'Flower bouquet', 'gifts', 'Fresh flowers, given by the driver at pickup.', 'per_item', 2, 5000, 90 ),
			array( 'celebration', 'Celebration kit', 'gifts', 'Balloons and a card for a birthday, honeymoon or anniversary.', 'per_booking', 1, 4000, 100 ),
			array( 'name-sign', 'Custom name sign', 'service', 'Your name or a message on the driver’s sign.', 'per_booking', 1, 500, 110 ),
		);
		$t = self::t( 'extra_types' );
		foreach ( $rows as [ $slug, $name, $grp, $desc, $unit, $max, $sug, $sort ] ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore
				$wpdb->insert( $t, array( 'slug' => $slug, 'name' => $name, 'grp' => $grp, 'description' => $desc, 'unit' => $unit, 'max_qty' => $max, 'suggested_cents' => $sug, 'sort' => $sort, 'status' => 'active' ) );
			}
		}
	}

	public static function units(): array {
		return array( 'per_booking' => 'per booking', 'per_ride' => 'per ride', 'per_item' => 'each' );
	}

	public static function groups(): array {
		return array( 'safety' => 'Safety and comfort', 'route' => 'Route', 'drinks' => 'Drinks', 'food' => 'Food', 'gifts' => 'Gifts', 'service' => 'Services' );
	}

	public static function catalog( bool $all = false ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'extra_types' ) . ( $all ? '' : " WHERE status = 'active'" ) . ' ORDER BY sort, name', ARRAY_A ); // phpcs:ignore
		return array_map(
			static fn( $r ) => array( 'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'group' => $r['grp'], 'description' => (string) $r['description'], 'unit' => $r['unit'], 'max_qty' => (int) $r['max_qty'], 'suggested' => (int) $r['suggested_cents'], 'sort' => (int) $r['sort'], 'status' => $r['status'] ),
			$rows
		);
	}

	/** Our team: add or change a catalog item. */
	public static function save_type( array $in, int $id = 0 ) {
		global $wpdb;
		$name = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'atc_extra', 'Give the extra a name.', array( 'status' => 400 ) );
		}
		$row = array(
			'name'            => mb_substr( $name, 0, 190 ),
			'grp'             => isset( self::groups()[ $in['group'] ?? '' ] ) ? $in['group'] : 'service',
			'description'     => mb_substr( trim( sanitize_text_field( (string) ( $in['description'] ?? '' ) ) ), 0, 255 ) ?: null,
			'unit'            => isset( self::units()[ $in['unit'] ?? '' ] ) ? $in['unit'] : 'per_booking',
			'max_qty'         => max( 1, min( 10, (int) ( $in['max_qty'] ?? 1 ) ) ),
			'suggested_cents' => max( 0, (int) round( 100 * (float) ( $in['suggested'] ?? 0 ) ) ),
			'sort'            => (int) ( $in['sort'] ?? 999 ),
			'status'          => 'off' === ( $in['status'] ?? '' ) ? 'off' : 'active',
		);
		if ( $id ) {
			$wpdb->update( self::t( 'extra_types' ), $row, array( 'id' => $id ) );
		} else {
			$row['slug'] = sanitize_title( $name ) . '-' . wp_generate_password( 4, false, false );
			$wpdb->insert( self::t( 'extra_types' ), $row );
		}
		return self::catalog( true );
	}

	/* ---------- A provider's offer ---------- */

	/** The catalog with this provider's price and switch for each item. */
	public static function for_provider( int $party_id ): array {
		global $wpdb;
		$mine = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'extras' ) . ' WHERE party_id = %d AND extra_type_id IS NOT NULL', $party_id ), ARRAY_A );
		$by   = array();
		foreach ( $mine as $m ) {
			$by[ (int) $m['extra_type_id'] ] = $m;
		}
		$bp = self::commission_bp( $party_id );
		return array(
			'commission_bp' => $bp,
			'items'         => array_map(
				static function ( $c ) use ( $by ) {
					$m = $by[ $c['id'] ] ?? null;
					return $c + array(
						'on'       => $m && 'active' === $m['status'],
						'retail'   => $m ? (int) $m['retail_cents'] : null,
						'provider' => $m ? (int) $m['provider_cents'] : null,
						'my_max'   => $m ? (int) $m['max_qty'] : $c['max_qty'],
					);
				},
				self::catalog()
			),
		);
	}

	private static function commission_bp( int $party_id ): int {
		$p = ATC_Catalog::party( $party_id );
		return $p && null !== $p['commission_bp'] ? (int) $p['commission_bp'] : (int) ATC_Settings::get( 'provider_commission_bp' );
	}

	/** The provider (or our team for it) switches an extra on or off and sets the price. */
	public static function save_offer( int $party_id, int $type_id, array $in ) {
		global $wpdb;
		$type = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'extra_types' ) . ' WHERE id = %d', $type_id ), ARRAY_A );
		if ( ! $type ) {
			return new WP_Error( 'atc_not_found', 'Extra not found.', array( 'status' => 404 ) );
		}
		$on  = ! empty( $in['on'] );
		$bp  = self::commission_bp( $party_id );
		$ret = isset( $in['retail'] ) && '' !== (string) $in['retail'] ? (int) round( 100 * (float) $in['retail'] ) : null;
		$get = isset( $in['provider'] ) && '' !== (string) $in['provider'] ? (int) round( 100 * (float) $in['provider'] ) : null;
		if ( null === $ret && null !== $get ) {
			$ret = (int) round( $get * ATC_Money::BP / max( 1, ATC_Money::BP - $bp ) );
		}
		if ( $on && ( null === $ret || $ret < 100 || $ret > 100000 ) ) {
			return new WP_Error( 'atc_extra', 'Set a price for ' . $type['name'] . ' (from USD 1).', array( 'status' => 400 ) );
		}
		$ret  = null === $ret ? 0 : $ret;
		$prov = ATC_Money::provider_from_retail( $ret, $bp );
		$t    = self::t( 'extras' );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE party_id = %d AND extra_type_id = %d", $party_id, $type_id ) ); // phpcs:ignore
		$row  = array( 'party_id' => $party_id, 'extra_type_id' => $type_id, 'name' => $type['name'], 'unit' => $type['unit'], 'retail_cents' => $ret, 'provider_cents' => $prov, 'max_qty' => max( 1, min( (int) $type['max_qty'], (int) ( $in['max_qty'] ?? $type['max_qty'] ) ) ), 'status' => $on ? 'active' : 'off' );
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, $row );
		}
		ATC_Install::log( 'party', $party_id, 'extra_saved', array( 'extra' => $type['slug'], 'on' => $on, 'retail' => $ret ) );
		return self::for_provider( $party_id );
	}

	/* ---------- At checkout ---------- */

	/** What the traveler can add for this provider (active catalog items the provider switched on). */
	public static function offered( int $party_id ): array {
		global $wpdb;
		if ( ! $party_id ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT e.id, e.retail_cents, e.max_qty, t.name, t.description, t.unit, t.grp, t.slug FROM ' . self::t( 'extras' ) . ' e JOIN ' . self::t( 'extra_types' ) . " t ON t.id = e.extra_type_id AND t.status = 'active' WHERE e.party_id = %d AND e.status = 'active' AND e.retail_cents > 0 ORDER BY t.sort", $party_id ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'name' => $r['name'], 'description' => (string) $r['description'], 'unit' => $r['unit'], 'group' => $r['grp'], 'slug' => $r['slug'], 'price' => (int) $r['retail_cents'], 'max_qty' => (int) $r['max_qty'] ), $rows );
	}

	public static function chosen( int $booking_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'booking_extras' ) . ' WHERE booking_id = %d ORDER BY id', $booking_id ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'extra_id' => (int) $r['extra_id'], 'name' => $r['name'], 'unit' => $r['unit'], 'qty' => (int) $r['qty'], 'retail' => (int) $r['retail_cents'], 'provider' => (int) $r['provider_cents'] ), $rows );
	}

	/** One line per extra for emails and pages: "Child car seat × 2 (both rides)". */
	public static function lines( int $booking_id ): array {
		$out = array();
		foreach ( self::chosen( $booking_id ) as $x ) {
			$out[] = array( 'label' => $x['name'] . ( $x['qty'] > 1 ? ' × ' . $x['qty'] : '' ) . ( 'per_ride' === $x['unit'] ? ' (each ride)' : '' ), 'retail' => $x['retail'], 'provider' => $x['provider'] );
		}
		return $out;
	}

	public static function totals( int $booking_id ): array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(retail_cents),0) r, COALESCE(SUM(provider_cents),0) p FROM ' . self::t( 'booking_extras' ) . ' WHERE booking_id = %d', $booking_id ), ARRAY_A );
		return array( 'retail' => (int) $r['r'], 'provider' => (int) $r['p'] );
	}

	/**
	 * Step 2 saved: the extras the traveler picked ($picked = [extra_id => qty]). Prices come from the provider's
	 * offer, never from the browser. Rides = 2 on a round trip (per-ride extras count twice).
	 */
	public static function save_choice( array $b, array $picked ): void {
		global $wpdb;
		$t = self::t( 'booking_extras' );
		$wpdb->delete( $t, array( 'booking_id' => (int) $b['id'] ) );
		$pid   = (int) ( $b['legs'][0]['party_id'] ?? 0 );
		$rides = max( 1, count( $b['legs'] ) );
		$bp    = self::commission_bp( $pid );
		foreach ( self::offered( $pid ) as $o ) {
			$qty = min( $o['max_qty'], max( 0, (int) ( $picked[ $o['id'] ] ?? $picked[ (string) $o['id'] ] ?? 0 ) ) );
			if ( ! $qty ) {
				continue;
			}
			$times  = 'per_ride' === $o['unit'] ? $qty * $rides : ( 'per_booking' === $o['unit'] ? 1 : $qty );
			$retail = $o['price'] * $times;
			$wpdb->insert( $t, array( 'booking_id' => (int) $b['id'], 'extra_id' => $o['id'], 'name' => $o['name'], 'unit' => $o['unit'], 'qty' => 'per_booking' === $o['unit'] ? 1 : $qty, 'retail_cents' => $retail, 'provider_cents' => ATC_Money::provider_from_retail( $o['price'], $bp ) * $times ) );
		}
	}

	/** The booking's price = the rides + the extras (minus any discount code, applied after). */
	public static function refresh_totals( int $booking_id ): void {
		global $wpdb;
		$legs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(retail_cents),0) FROM ' . self::t( 'booking_legs' ) . ' WHERE booking_id = %d', $booking_id ) );
		$x    = self::totals( $booking_id );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bookings' ) . ' SET retail_cents = %d, total_cents = %d - discount_cents WHERE id = %d', $legs + $x['retail'], $legs + $x['retail'], $booking_id ) ); // phpcs:ignore
	}

	/** At payment: the provider's share of the extras, paid with the last ride. Returns it. */
	public static function on_paid( array $b ): int {
		global $wpdb;
		$x = self::totals( (int) $b['id'] );
		if ( $x['provider'] <= 0 || empty( $b['legs'] ) ) {
			return 0;
		}
		$last  = end( $b['legs'] );
		$month = $last['pickup_at'] ? substr( $last['pickup_at'], 0, 7 ) : null;
		$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'leg_id' => (int) $last['id'], 'party_id' => (int) $last['party_id'], 'role' => 'provider', 'amount_cents' => $x['provider'], 'currency' => 'USD', 'status' => 'pending', 'service_month' => $month, 'note' => 'Extras', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
		return $x['provider'];
	}
}
