<?php
/**
 * Discount codes, typed by the traveler on the payment step.
 *
 * Ours (owner 'platform'): a percent or a fixed amount, out of our share. The provider is paid the same,
 * and an affiliate still earns its rate on what the traveler paid.
 * An affiliate's own (owner 'affiliate'): a percent up to its own rate; the discount comes out of its
 * commission (a 5% code leaves it 3%), and the booking is credited to that affiliate.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Coupons {

	/** Never more than our share would allow: at most this much off a booking. */
	const MAX_PLATFORM_BP = 2000;

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function clean_code( string $code ): string {
		return strtoupper( substr( preg_replace( '/[^A-Za-z0-9\-]/', '', $code ), 0, 30 ) );
	}

	public static function find( string $code ): ?array {
		global $wpdb;
		$code = self::clean_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'coupons' ) . ' WHERE code = %s', $code ), ARRAY_A );
		return $r ?: null;
	}

	/** The discount a code gives on this retail total, or an error a traveler can read. */
	public static function check( array $c, int $retail ) {
		$today = gmdate( 'Y-m-d' );
		if ( 'active' !== $c['status'] ) {
			return new WP_Error( 'atc_coupon', 'This code is not active.', array( 'status' => 400 ) );
		}
		if ( ( $c['valid_from'] && $today < $c['valid_from'] ) || ( $c['valid_to'] && $today > $c['valid_to'] ) ) {
			return new WP_Error( 'atc_coupon', 'This code has expired.', array( 'status' => 400 ) );
		}
		if ( null !== $c['max_uses'] && (int) $c['uses'] >= (int) $c['max_uses'] ) {
			return new WP_Error( 'atc_coupon', 'This code has been used up.', array( 'status' => 400 ) );
		}
		if ( $c['min_cents'] && $retail < (int) $c['min_cents'] ) {
			return new WP_Error( 'atc_coupon', 'This code works on bookings from ' . ATC_Book::money( (int) $c['min_cents'] ) . '.', array( 'status' => 400 ) );
		}
		if ( 'affiliate' === $c['owner'] ) {
			$aff = $c['party_id'] ? ATC_Catalog::party( (int) $c['party_id'] ) : null;
			if ( ! $aff || 'live' !== $aff['status'] ) {
				return new WP_Error( 'atc_coupon', 'This code is not active.', array( 'status' => 400 ) );
			}
		}
		$off = $c['percent_bp'] ? intdiv( $retail * (int) $c['percent_bp'], ATC_Money::BP ) : (int) $c['amount_cents'];
		return max( 0, min( $off, $retail - 100 ) );
	}

	/** The traveler types a code on the payment step (empty code removes it). */
	public static function apply( string $number, string $key, string $code ) {
		global $wpdb;
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b || 'held' !== $b['status'] ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		$retail = (int) $b['retail_cents'];
		if ( '' === trim( $code ) ) {
			self::write( $b, null, 0 );
			self::sync_stripe( (int) $b['id'] );
			return array( 'ok' => true, 'removed' => true, 'total' => $retail, 'retail' => $retail );
		}
		$c = self::find( $code );
		if ( ! $c ) {
			return new WP_Error( 'atc_coupon', 'We don’t know this code. Check the spelling.', array( 'status' => 400 ) );
		}
		$off = self::check( $c, $retail );
		if ( is_wp_error( $off ) ) {
			return $off;
		}
		self::write( $b, $c, $off );
		self::sync_stripe( (int) $b['id'] );
		return array( 'ok' => true, 'code' => $c['code'], 'discount' => $off, 'total' => $retail - $off, 'retail' => $retail );
	}

	/** Puts the code on the booking (or takes it off) and keeps the total in step. */
	private static function write( array $b, ?array $c, int $off ): void {
		global $wpdb;
		$snap = $b['snap'];
		$set  = array( 'coupon_code' => $c ? $c['code'] : null, 'discount_cents' => $off, 'total_cents' => (int) $b['retail_cents'] - $off, 'updated_at' => self::now() );
		if ( $c ) {
			$snap['coupon'] = array( 'code' => $c['code'], 'owner' => $c['owner'], 'party_id' => (int) $c['party_id'], 'discount' => $off );
			// An affiliate's own code credits the booking to that affiliate.
			if ( 'affiliate' === $c['owner'] && $c['party_id'] ) {
				$aff                 = ATC_Catalog::party( (int) $c['party_id'] );
				$set['affiliate_id'] = (int) $aff['id'];
				$set['channel']      = 'affiliate';
				$snap['affiliate']   = array( 'code' => $aff['ref_code'], 'bp' => ATC_Affiliates::rate_bp( $aff ) );
				$parent              = $aff['parent_id'] ? ATC_Catalog::party( (int) $aff['parent_id'] ) : null;
				$set['referrer_id']  = $parent && 'live' === $parent['status'] ? (int) $parent['id'] : null;
				if ( $set['referrer_id'] ) {
					$snap['affiliate']['ref_bp'] = (int) ATC_Settings::get( 'referral_bp' );
				}
			}
		} else {
			unset( $snap['coupon'] );
		}
		$wpdb->update( self::t( 'bookings' ), $set, array( 'id' => (int) $b['id'] ) );
		ATC_Checkout::snapshot( (int) $b['id'], $snap );
	}

	/** The payment form is already open: the Stripe amount follows the new total, card details stay. */
	private static function sync_stripe( int $booking_id ): void {
		$b = ATC_Checkout::find( '', $booking_id );
		if ( $b && ! empty( $b['stripe_payment_intent'] ) ) {
			ATC_Checkout::payment_intent( $b );
		}
	}

	/** After a re-hold re-priced the booking: the same code on the new price (dropped if it no longer applies). */
	public static function reapply( int $booking_id ): void {
		$b = ATC_Checkout::find( '', $booking_id );
		if ( ! $b || empty( $b['coupon_code'] ) ) {
			return;
		}
		$c   = self::find( (string) $b['coupon_code'] );
		$off = $c ? self::check( $c, (int) $b['retail_cents'] ) : new WP_Error( 'x', 'x' );
		self::write( $b, is_wp_error( $off ) ? null : $c, is_wp_error( $off ) ? 0 : $off );
	}

	/** Paid: count the use. */
	public static function used( array $b ): void {
		global $wpdb;
		if ( ! empty( $b['coupon_code'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'coupons' ) . ' SET uses = uses + 1 WHERE code = %s', $b['coupon_code'] ) );
		}
	}

	/* ---------- Our team ---------- */

	public static function list(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT c.*, p.name party FROM ' . self::t( 'coupons' ) . ' c LEFT JOIN ' . self::t( 'parties' ) . ' p ON p.id = c.party_id ORDER BY c.status = \'active\' DESC, c.id DESC LIMIT 500', ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	public static function shape( array $r ): array {
		$today = gmdate( 'Y-m-d' );
		$state = 'active' !== $r['status'] ? 'Off' : ( $r['valid_to'] && $today > $r['valid_to'] ? 'Expired' : ( null !== $r['max_uses'] && (int) $r['uses'] >= (int) $r['max_uses'] ? 'Used up' : ( $r['valid_from'] && $today < $r['valid_from'] ? 'Starts ' . $r['valid_from'] : 'Active' ) ) );
		return array(
			'id'       => (int) $r['id'],
			'code'     => $r['code'],
			'owner'    => $r['owner'],
			'party'    => (string) ( $r['party'] ?? '' ),
			'party_id' => (int) $r['party_id'],
			'percent'  => null === $r['percent_bp'] ? null : (int) $r['percent_bp'] / 100,
			'amount'   => null === $r['amount_cents'] ? null : (int) $r['amount_cents'],
			'min'      => (int) $r['min_cents'],
			'from'     => (string) $r['valid_from'],
			'to'       => (string) $r['valid_to'],
			'max_uses' => null === $r['max_uses'] ? null : (int) $r['max_uses'],
			'uses'     => (int) $r['uses'],
			'note'     => (string) $r['note'],
			'status'   => $r['status'],
			'state'    => $state,
		);
	}

	/**
	 * Create or change a code. Ours: percent (1–20) or amount. An affiliate's: percent up to its rate.
	 * $party_id > 0 means the affiliate is saving its own code from the panel.
	 */
	public static function save( array $in, int $id = 0, int $party_id = 0 ) {
		global $wpdb;
		$t   = self::t( 'coupons' );
		$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore
		if ( $id && ( ! $old || ( $party_id && (int) $old['party_id'] !== $party_id ) ) ) {
			return new WP_Error( 'atc_not_found', 'Code not found.', array( 'status' => 404 ) );
		}
		$code = self::clean_code( (string) ( $in['code'] ?? ( $old['code'] ?? '' ) ) );
		if ( strlen( $code ) < 4 ) {
			return new WP_Error( 'atc_coupon', 'Use at least 4 letters or numbers for the code.', array( 'status' => 400 ) );
		}
		$dupe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE code = %s AND id <> %d", $code, $id ) ); // phpcs:ignore
		if ( $dupe ) {
			return new WP_Error( 'atc_coupon', 'That code is taken. Try another.', array( 'status' => 409 ) );
		}
		$owner = $party_id ? 'affiliate' : ( 'affiliate' === ( $in['owner'] ?? $old['owner'] ?? '' ) ? 'affiliate' : 'platform' );
		$pid   = $party_id ?: ( 'affiliate' === $owner ? (int) ( $in['party_id'] ?? $old['party_id'] ?? 0 ) : 0 );
		$pct   = isset( $in['percent'] ) && '' !== (string) $in['percent'] ? (int) round( 100 * (float) $in['percent'] ) : null;
		$amt   = isset( $in['amount'] ) && '' !== (string) $in['amount'] && ! $pct ? (int) round( 100 * (float) $in['amount'] ) : null;
		if ( 'affiliate' === $owner ) {
			$aff = $pid ? ATC_Catalog::party( $pid ) : null;
			if ( ! $aff || 'affiliate' !== $aff['type'] ) {
				return new WP_Error( 'atc_coupon', 'Choose the affiliate.', array( 'status' => 400 ) );
			}
			$max = ATC_Affiliates::rate_bp( $aff );
			if ( ! $pct || $pct < 100 || $pct > $max ) {
				return new WP_Error( 'atc_coupon', 'The discount goes from 1% to ' . ( $max / 100 ) . '% (your commission).', array( 'status' => 400 ) );
			}
			$amt = null;
		} elseif ( $pct ) {
			if ( $pct < 100 || $pct > self::MAX_PLATFORM_BP ) {
				return new WP_Error( 'atc_coupon', 'Percent codes go from 1% to ' . ( self::MAX_PLATFORM_BP / 100 ) . '%.', array( 'status' => 400 ) );
			}
		} elseif ( ! $amt || $amt < 100 ) {
			return new WP_Error( 'atc_coupon', 'Give a percent or an amount off.', array( 'status' => 400 ) );
		}
		$date = static fn( $v ) => is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
		$row  = array(
			'code'         => $code,
			'owner'        => $owner,
			'party_id'     => $pid ?: null,
			'percent_bp'   => $pct,
			'amount_cents' => $amt,
			'min_cents'    => $party_id ? null : ( isset( $in['min'] ) && '' !== (string) $in['min'] ? (int) round( 100 * (float) $in['min'] ) : null ),
			'valid_from'   => $party_id ? null : $date( $in['from'] ?? null ),
			'valid_to'     => $party_id ? null : $date( $in['to'] ?? null ),
			'max_uses'     => $party_id ? null : ( isset( $in['max_uses'] ) && '' !== (string) $in['max_uses'] ? max( 1, (int) $in['max_uses'] ) : null ),
			'note'         => mb_substr( trim( sanitize_text_field( (string) ( $in['note'] ?? '' ) ) ), 0, 190 ) ?: null,
			'status'       => in_array( $in['status'] ?? '', array( 'active', 'off' ), true ) ? $in['status'] : ( $old['status'] ?? 'active' ),
		);
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			if ( $party_id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE party_id = %d AND status = 'active'", $party_id ) ) >= 5 ) { // phpcs:ignore
				return new WP_Error( 'atc_coupon', 'You can have up to 5 active codes. Turn one off first.', array( 'status' => 409 ) );
			}
			$wpdb->insert( $t, $row + array( 'created_at' => self::now() ) );
			$id = (int) $wpdb->insert_id;
		}
		ATC_Install::log( 'coupon', $id, 'saved', array( 'code' => $code, 'by_affiliate' => (bool) $party_id ) );
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT c.*, p.name party FROM {$t} c LEFT JOIN " . self::t( 'parties' ) . ' p ON p.id = c.party_id WHERE c.id = %d', $id ), ARRAY_A ) ); // phpcs:ignore
	}

	public static function for_party( int $party_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'coupons' ) . ' WHERE party_id = %d ORDER BY id DESC', $party_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}
}
