<?php
/**
 * Provider payouts. Every completed ride leaves an 'earned' line in the ledger (the Amount you will get paid).
 * Once a month (the payout day) our team opens the run for the month before: each provider's earned lines up
 * to that month, minus nothing (no-shows wait in "held" until decided), under the minimum rolls over.
 *
 * 1. Review and approve: a payout per provider, its lines tied to it.
 * 2. Pay: the payment list (bank or Wise details) goes to the bank; Stripe Connect comes later.
 * 3. Mark paid with the bank reference: lines become 'paid' and the provider gets a statement by email.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Payouts {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/** 'YYYY-MM', default the month before this one. */
	public static function period( string $p ): string {
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $p ) ? $p : gmdate( 'Y-m', strtotime( 'first day of last month' ) );
	}

	private static function month_label( string $p ): string {
		return wp_date( 'F Y', strtotime( $p . '-15 12:00 UTC' ), new DateTimeZone( 'UTC' ) );
	}

	/** The run for a month: one row per provider who has money earned, held or paid. */
	public static function run( string $period ): array {
		global $wpdb;
		$period = self::period( $period );
		$l      = self::t( 'ledger' );
		$min    = (int) ATC_Settings::get( 'payout_minimum_cents' );
		$day    = (int) ATC_Settings::get( 'payout_day' );

		// Earned and not in a payout yet, for rides up to this month.
		$earned = $wpdb->get_results( $wpdb->prepare( "SELECT party_id, COUNT(*) n, SUM(amount_cents) amt FROM {$l} WHERE role IN ('provider','affiliate','referrer') AND status = 'earned' AND payout_id IS NULL AND party_id > 0 AND (service_month IS NULL OR service_month <= %s) GROUP BY party_id", $period ), OBJECT_K ); // phpcs:ignore
		// No-shows waiting for our decision.
		$held = $wpdb->get_results( $wpdb->prepare( "SELECT x.party_id, SUM(x.amount_cents) amt FROM {$l} x JOIN " . self::t( 'booking_legs' ) . " g ON g.id = x.leg_id WHERE x.role = 'provider' AND ( ( x.status = 'pending' AND g.status = 'no_show' ) OR x.status = 'on_hold' ) AND (x.service_month IS NULL OR x.service_month <= %s) GROUP BY x.party_id", $period ), OBJECT_K ); // phpcs:ignore
		// Payouts already made for this month.
		$made = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payouts' ) . ' WHERE period = %s', $period ), ARRAY_A );
		$by   = array();
		foreach ( $made as $m ) {
			$by[ (int) $m['party_id'] ] = $m;
		}
		$ids  = array_unique( array_merge( array_keys( $earned ), array_keys( $held ), array_keys( $by ) ) );
		$rows = array();
		$tot  = array( 'pay' => 0, 'held' => 0, 'rolled' => 0, 'paid' => 0 );
		foreach ( $ids as $pid ) {
			$pid  = (int) $pid;
			$p    = ATC_Catalog::party( $pid );
			$pay  = ATC_Partner::payout_summary( $pid );
			$amt  = isset( $earned[ $pid ] ) ? (int) $earned[ $pid ]->amt : 0;
			$hold = isset( $held[ $pid ] ) ? (int) $held[ $pid ]->amt : 0;
			$out  = isset( $by[ $pid ] ) ? $by[ $pid ] : null;
			if ( $out ) {
				$state = 'paid' === $out['status'] ? 'Paid' : 'Approved, to pay';
				$tone  = 'paid' === $out['status'] ? 'green' : 'blue';
				$tot[ 'paid' === $out['status'] ? 'paid' : 'pay' ] += (int) $out['amount_cents'];
			} elseif ( ! $amt ) {
				$state = 'Only held';
				$tone  = 'grey';
			} elseif ( $amt < $min ) {
				$state = 'Under ' . ATC_Book::money( $min ) . ', rolls over';
				$tone  = 'grey';
				$tot['rolled'] += $amt;
			} elseif ( ! $pay['set'] ) {
				$state = 'No payout details';
				$tone  = 'red';
				$tot['pay'] += $amt;
			} else {
				$state = 'Ready';
				$tone  = 'orange';
				$tot['pay'] += $amt;
			}
			$tot['held'] += $hold;
			$rows[]       = array(
				'party_id'  => $pid,
				'type'      => (string) ( $p['type'] ?? 'provider' ),
				'name'      => $p['name'] ?? ( '#' . $pid ),
				'country'   => (string) ( $p['country'] ?? '' ),
				'method'    => $pay['set'] ? ucfirst( $pay['method'] ) . ' · ' . $pay['currency'] . ( $pay['last4'] ? ' · ••' . $pay['last4'] : '' ) : '',
				'trips'     => $out ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE payout_id = %d", (int) $out['id'] ) ) : ( isset( $earned[ $pid ] ) ? (int) $earned[ $pid ]->n : 0 ), // phpcs:ignore
				'to_pay'    => $out ? (int) $out['amount_cents'] : $amt,
				'held'      => $hold,
				'status'    => $state,
				'tone'      => $tone,
				'payout_id' => $out ? (int) $out['id'] : 0,
				'reference' => $out ? (string) $out['reference'] : '',
				'paid_at'   => $out && $out['paid_at'] ? substr( $out['paid_at'], 0, 10 ) : '',
			);
		}
		usort( $rows, static fn( $a, $b ) => $b['to_pay'] <=> $a['to_pay'] );
		$ready = count( array_filter( $rows, static fn( $r ) => 'Ready' === $r['status'] ) );
		return array(
			'period'    => $period,
			'label'     => self::month_label( $period ),
			'pay_date'  => wp_date( 'j F Y', strtotime( gmdate( 'Y-m', strtotime( $period . '-01 +1 month' ) ) . '-' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT ) . ' 12:00 UTC' ), new DateTimeZone( 'UTC' ) ),
			'minimum'   => $min,
			'rows'      => $rows,
			'totals'    => $tot,
			'can_approve' => $ready > 0,
			'periods'   => self::periods(),
		);
	}

	/** The last 12 months, newest first. */
	private static function periods(): array {
		$out = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$p     = gmdate( 'Y-m', strtotime( 'first day of -' . $i . ' month' ) );
			$out[] = array( 'value' => $p, 'label' => self::month_label( $p ) );
		}
		return $out;
	}

	/** Approve every provider that is Ready: a payout each, its lines tied to it. */
	public static function approve( string $period ) {
		global $wpdb;
		$period = self::period( $period );
		$l      = self::t( 'ledger' );
		$done   = 0;
		foreach ( self::run( $period )['rows'] as $r ) {
			if ( 'Ready' !== $r['status'] ) {
				continue;
			}
			$pay = ATC_Partner::payout_summary( $r['party_id'] );
			$wpdb->insert( self::t( 'payouts' ), array( 'party_id' => $r['party_id'], 'period' => $period, 'amount_cents' => $r['to_pay'], 'currency' => 'USD', 'method' => $pay['method'], 'status' => 'approved', 'created_at' => self::now() ) );
			$id = (int) $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare( "UPDATE {$l} SET payout_id = %d, updated_at = %s WHERE role IN ('provider','affiliate','referrer') AND status = 'earned' AND payout_id IS NULL AND party_id = %d AND (service_month IS NULL OR service_month <= %s)", $id, self::now(), $r['party_id'], $period ) ); // phpcs:ignore
			// The lines decide the amount, in case something moved since the list was drawn.
			$sum = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_cents),0) FROM {$l} WHERE payout_id = %d", $id ) ); // phpcs:ignore
			$wpdb->update( self::t( 'payouts' ), array( 'amount_cents' => $sum ), array( 'id' => $id ) );
			ATC_Install::log( 'payout', $id, 'approved', array( 'party' => $r['party_id'], 'period' => $period, 'amount' => $sum ) );
			++$done;
		}
		if ( ! $done ) {
			return new WP_Error( 'atc_payout', 'Nobody is ready to be paid for this month.', array( 'status' => 409 ) );
		}
		return self::run( $period );
	}

	public static function mark_paid( int $id, string $reference ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payouts' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $p ) {
			return new WP_Error( 'atc_not_found', 'Payout not found.', array( 'status' => 404 ) );
		}
		if ( 'paid' === $p['status'] ) {
			return self::run( $p['period'] );
		}
		$reference = mb_substr( trim( sanitize_text_field( $reference ) ), 0, 190 );
		if ( '' === $reference ) {
			return new WP_Error( 'atc_payout', 'Type the bank or Wise reference of the transfer.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'payouts' ), array( 'status' => 'paid', 'reference' => $reference, 'paid_at' => self::now() ), array( 'id' => $id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . " SET status = 'paid', updated_at = %s WHERE payout_id = %d", self::now(), $id ) ); // phpcs:ignore
		ATC_Install::log( 'payout', $id, 'paid', array( 'reference' => $reference ) );
		ATC_Mail::payout_statement( (int) $p['party_id'], self::month_label( $p['period'] ), (int) $p['amount_cents'], $reference, self::lines( $id ) );
		return self::run( $p['period'] );
	}

	/** The rides behind a payout. */
	public static function lines( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT x.amount_cents, x.role, b.number, COALESCE(g.pickup_at, (SELECT MAX(pickup_at) FROM ' . self::t( 'booking_legs' ) . ' z WHERE z.booking_id = b.id)) pickup_at, g.direction, g.completed_how FROM ' . self::t( 'ledger' ) . ' x JOIN ' . self::t( 'bookings' ) . ' b ON b.id = x.booking_id LEFT JOIN ' . self::t( 'booking_legs' ) . ' g ON g.id = x.leg_id WHERE x.payout_id = %d ORDER BY pickup_at', $id ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'number'    => $r['number'],
				'date'      => $r['pickup_at'] ? substr( $r['pickup_at'], 0, 10 ) : '',
				'direction' => 'affiliate' === $r['role'] ? 'Commission' : ( 'referrer' === $r['role'] ? 'Referral commission' : ( 'arrival' === $r['direction'] ? 'Arrival' : 'Departure' ) ),
				'how'       => array( 'code' => 'Service code', 'auto' => 'Completed on its own', 'manual' => 'Marked by provider', 'no_show_paid' => 'No-show (accepted)', 'legacy' => 'Old site' )[ $r['completed_how'] ] ?? '',
				'amount'    => (int) $r['amount_cents'],
			),
			$rows
		);
	}

	/** Approved, unpaid payouts with full payout details, for the bank. Admins only. */
	public static function payment_list( string $period ): array {
		global $wpdb;
		$period = self::period( $period );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT o.*, p.name, p.payout_details FROM ' . self::t( 'payouts' ) . ' o JOIN ' . self::t( 'parties' ) . " p ON p.id = o.party_id WHERE o.period = %s AND o.status = 'approved' ORDER BY p.name", $period ), ARRAY_A );
		$out    = array();
		foreach ( $rows as $r ) {
			$d     = ATC_Partner::decrypt( $r['payout_details'] ) ?: array();
			$out[] = array(
				'payout'   => (int) $r['id'],
				'provider' => $r['name'],
				'method'   => $r['method'],
				'holder'   => $d['holder'] ?? '',
				'bank'     => $d['bank'] ?? '',
				'account'  => $d['account'] ?? '',
				'email'    => $d['email'] ?? '',
				'amount'   => number_format( (int) $r['amount_cents'] / 100, 2, '.', '' ),
				'currency' => 'USD',
				'concept'  => 'Airport Transportation ' . $period,
			);
		}
		ATC_Install::log( 'payout', 0, 'list_downloaded', array( 'period' => $period, 'rows' => count( $out ) ) );
		return $out;
	}
}
