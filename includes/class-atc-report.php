<?php
/**
 * The money report: every paid booking in a day, week or month with all the fees in it (modelled on CAT's
 * daily sheet): price, discount, extra payments, what the traveler paid, Stripe's fee, affiliate commission,
 * refunds, what the provider gets, and what we keep. Cancelled, refunded and no-show bookings apart.
 * Old-site bookings are left out (settled there).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Report {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** period day|week|month, a date inside it, basis trip|booked. */
	public static function window( array $in ): array {
		$period = in_array( $in['period'] ?? '', array( 'day', 'week', 'month', 'custom' ), true ) ? $in['period'] : 'day';
		$date   = isset( $in['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['date'] ) ? $in['date'] : gmdate( 'Y-m-d' );
		$ts     = strtotime( $date . ' 12:00 UTC' );
		if ( 'custom' === $period ) {
			[ $from, $to ] = ATC_Insights::range( $in );
		} elseif ( 'week' === $period ) {
			$from = gmdate( 'Y-m-d', strtotime( 'monday this week', $ts ) );
			$to   = gmdate( 'Y-m-d', strtotime( $from . ' +6 days' ) );
		} elseif ( 'month' === $period ) {
			$from = gmdate( 'Y-m-01', $ts );
			$to   = gmdate( 'Y-m-t', $ts );
		} else {
			$from = $date;
			$to   = $date;
		}
		return array( $period, $from, $to, 'booked' === ( $in['basis'] ?? '' ) ? 'booked' : 'trip' );
	}

	public static function build( array $in ): array {
		global $wpdb;
		[ $period, $from, $to, $basis ] = self::window( $in );
		$bk = self::t( 'bookings' );
		$lg = self::t( 'booking_legs' );
		$l  = self::t( 'ledger' );
		$pt = self::t( 'parties' );
		// The trip date is the first ride's date in the airport's time zone (close enough in UTC for a day view).
		$date_sql = 'booked' === $basis ? 'b.created_at' : "(SELECT MIN(g.pickup_at) FROM {$lg} g WHERE g.booking_id = b.id)";
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, {$date_sql} AS d,
					(SELECT COALESCE(SUM(amount_cents),0) FROM {$l} x WHERE x.booking_id = b.id AND x.role = 'provider' AND x.status <> 'cancelled') prov,
					(SELECT COALESCE(SUM(amount_cents),0) FROM {$l} x WHERE x.booking_id = b.id AND x.role IN ('affiliate','referrer') AND x.status <> 'cancelled') aff,
					(SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') FROM {$lg} g JOIN {$pt} p ON p.id = g.party_id WHERE g.booking_id = b.id) provider,
					(SELECT GROUP_CONCAT(DISTINCT g.status) FROM {$lg} g WHERE g.booking_id = b.id) leg_status,
					(SELECT name FROM {$pt} a WHERE a.id = b.affiliate_id) affiliate
				FROM {$bk} b
				WHERE b.status IN ('confirmed','completed','cancelled') AND b.stripe_payment_intent IS NOT NULL AND b.snapshot NOT LIKE %s
				HAVING d BETWEEN %s AND %s
				ORDER BY d, b.id",
				'%"legacy":true%',
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		); // phpcs:ignore
		$ok      = array();
		$off     = array();
		$missing = 0;
		foreach ( $rows as $r ) {
			$snap = json_decode( (string) $r['snapshot'], true ) ?: array();
			if ( 'cancelled' === $r['status'] && empty( $snap['paid_at'] ) ) {
				continue; // never paid
			}
			$extras = ATC_Extras::totals( (int) $r['id'] )['retail'];
			foreach ( (array) ( $snap['charges'] ?? array() ) as $c ) {
				$extras += 'paid' === ( $c['status'] ?? '' ) ? (int) $c['amount'] : 0;
			}
			$total  = (int) $r['total_cents'];
			$refund = (int) ( $snap['refunded'] ?? 0 );
			$fee    = isset( $snap['stripe_fee'] ) ? (int) $snap['stripe_fee'] : null;
			if ( null === $fee ) {
				++$missing;
			}
			$prov  = (int) $r['prov'];
			$aff   = (int) $r['aff'];
			$noshow = false !== strpos( (string) $r['leg_status'], 'no_show' );
			$line  = array(
				'date'      => substr( (string) $r['d'], 0, 10 ),
				'time'      => substr( (string) $r['d'], 11, 5 ),
				'number'    => $r['number'],
				'traveler'  => trim( $r['first_name'] . ' ' . $r['last_name'] ),
				'route'     => trim( ( $snap['iata'] ?? '' ) . ' → ' . ( $snap['to'] ?? '' ), ' →' ),
				'vehicle'   => (string) ( $snap['vehicle'] ?? '' ),
				'trip'      => 'round_trip' === $r['trip_type'] ? 'Round trip' : 'One way',
				'provider'  => (string) $r['provider'],
				'channel'   => 'affiliate' === $r['channel'] ? 'Affiliate: ' . $r['affiliate'] : ( 'admin' === $r['channel'] ? 'Our team' : 'Website' ),
				'method'    => ucfirst( (string) ( $snap['method'] ?? 'card' ) ),
				'status'    => 'cancelled' === $r['status'] ? 'Cancelled' : ( $noshow ? 'No-show' : ( 'completed' === $r['status'] ? 'Completed' : 'Confirmed' ) ),
				'price'     => (int) $r['retail_cents'] - $extras,
				'discount'  => (int) $r['discount_cents'],
				'coupon'    => (string) $r['coupon_code'],
				'extras'    => $extras,
				'charged'   => $total,
				'fee'       => $fee,
				'affiliate' => $aff,
				'refunded'  => $refund,
				'provider_cost' => $prov,
				'margin'    => $total - $refund - $prov - $aff - (int) $fee,
			);
			if ( 'cancelled' === $r['status'] || $refund >= $total || $noshow ) {
				$off[] = $line;
			} else {
				$ok[] = $line;
			}
		}
		return array(
			'period'  => $period,
			'basis'   => $basis,
			'from'    => $from,
			'to'      => $to,
			'rows'    => $ok,
			'other'   => $off,
			'totals'  => self::sum( $ok ),
			'other_totals' => self::sum( $off ),
			'all'     => self::sum( array_merge( $ok, $off ) ),
			'by_day'  => self::by_day( array_merge( $ok, $off ) ),
			'fee_missing' => $missing,
		);
	}

	private static function sum( array $rows ): array {
		$keys = array( 'price', 'discount', 'extras', 'charged', 'fee', 'affiliate', 'refunded', 'provider_cost', 'margin' );
		$out  = array_fill_keys( $keys, 0 );
		foreach ( $rows as $r ) {
			foreach ( $keys as $k ) {
				$out[ $k ] += (int) $r[ $k ];
			}
		}
		$out['count'] = count( $rows );
		return $out;
	}

	private static function by_day( array $rows ): array {
		$days = array();
		foreach ( $rows as $r ) {
			$days[ $r['date'] ][] = $r;
		}
		ksort( $days );
		$out = array();
		foreach ( $days as $d => $list ) {
			$out[] = array( 'day' => $d ) + self::sum( $list );
		}
		return $out;
	}

	/** The same report as a CSV file (opens in Excel and Google Sheets). */
	public static function csv( array $in ): string {
		$r  = self::build( $in );
		$fh = fopen( 'php://temp', 'r+' );
		$m  = static fn( $c ) => null === $c ? '' : number_format( $c / 100, 2, '.', '' );
		$hd = array( 'Date', 'Time (UTC)', 'Reservation', 'Traveler', 'Route', 'Vehicle', 'Trip', 'Provider', 'Channel', 'Payment', 'Status', 'Price', 'Discount', 'Code', 'Extras', 'Charged', 'Stripe fee', 'Affiliate commission', 'Refunded', 'Provider gets', 'We keep' );
		fputcsv( $fh, array( 'Airport Transportation · money report · ' . $r['from'] . ( $r['from'] !== $r['to'] ? ' to ' . $r['to'] : '' ) . ' · by ' . ( 'booked' === $r['basis'] ? 'booking date' : 'trip date' ) . ' · USD' ) );
		foreach ( array( 'Bookings' => array( $r['rows'], $r['totals'] ), 'Cancelled, refunded and no-show' => array( $r['other'], $r['other_totals'] ) ) as $title => [ $rows, $tot ] ) {
			fputcsv( $fh, array() );
			fputcsv( $fh, array( $title ) );
			fputcsv( $fh, $hd );
			foreach ( $rows as $x ) {
				fputcsv( $fh, array( $x['date'], $x['time'], $x['number'], $x['traveler'], $x['route'], $x['vehicle'], $x['trip'], $x['provider'], $x['channel'], $x['method'], $x['status'], $m( $x['price'] ), $m( $x['discount'] ), $x['coupon'], $m( $x['extras'] ), $m( $x['charged'] ), $m( $x['fee'] ), $m( $x['affiliate'] ), $m( $x['refunded'] ), $m( $x['provider_cost'] ), $m( $x['margin'] ) ) );
			}
			fputcsv( $fh, array( 'Total', '', $tot['count'] . ' bookings', '', '', '', '', '', '', '', '', $m( $tot['price'] ), $m( $tot['discount'] ), '', $m( $tot['extras'] ), $m( $tot['charged'] ), $m( $tot['fee'] ), $m( $tot['affiliate'] ), $m( $tot['refunded'] ), $m( $tot['provider_cost'] ), $m( $tot['margin'] ) ) );
		}
		rewind( $fh );
		return "\xEF\xBB\xBF" . (string) stream_get_contents( $fh );
	}
}
