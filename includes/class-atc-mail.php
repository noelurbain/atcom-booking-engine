<?php
/**
 * Emails about bookings: confirmation, new booking for the provider (and a copy for our team), changes,
 * cancellations, the "finish your booking" reminder, the day-before reminder and the review request.
 *
 * While the setting "invite_test_to" is filled in (test site), every email meant for a provider goes there
 * instead, like provider invitations. Travelers get theirs at the email they typed.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Mail {

	public static function init(): void {
		add_action( 'atc_booking_confirmed', array( __CLASS__, 'booking_confirmed' ) );
		add_action( 'atc_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'atc_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'atc_hourly' );
		}
	}

	/* ---------- Sending ---------- */

	private static function from(): string {
		return 'Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>';
	}

	public static function send( string $to, string $subject, string $html, array $opts = array() ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from() );
		if ( ! empty( $opts['reply_to'] ) && is_email( $opts['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $opts['reply_to'];
		}
		$ok = wp_mail( $to, $subject, $html, $headers );
		ATC_Install::log( 'mail', (int) ( $opts['booking_id'] ?? 0 ), $ok ? 'sent' : 'failed', array( 'kind' => $opts['kind'] ?? '', 'to' => self::mask( $to ) ) );
		return $ok;
	}

	/** Provider emails go to the test inbox while it is set. */
	private static function provider_to( array $party ): string {
		$test = trim( (string) ATC_Settings::get( 'invite_test_to' ) );
		return '' !== $test ? $test : (string) ( $party['email'] ?? '' );
	}

	private static function mask( string $email ): string {
		$p = explode( '@', $email );
		return substr( $p[0], 0, 2 ) . '…@' . ( $p[1] ?? '' );
	}

	/* ---------- Layout ---------- */

	private static function e( $t ): string {
		return esc_html( (string) $t );
	}

	/** The email frame: logo line, title, body, an optional button, footer. */
	public static function wrap( string $title, string $body, string $button = '', string $url = '', string $pre = '' ): string {
		$btn = $button && $url ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px"><tr><td style="background:#FBA53E;border-radius:999px"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 26px;font-weight:800;font-size:15px;color:#15171C;text-decoration:none">' . self::e( $button ) . '</a></td></tr></table>' : '';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . self::e( $title ) . '</title></head>'
			. '<body style="margin:0;background:#F7F5F1;font-family:Manrope,Segoe UI,Helvetica,Arial,sans-serif;color:#15171C">'
			. '<span style="display:none;max-height:0;overflow:hidden">' . self::e( $pre ) . '</span>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F5F1"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
			. '<tr><td style="padding:4px 4px 16px;font-size:20px;font-weight:800"><span style="color:#FBA53E">✈</span> Airport Transportation</td></tr>'
			. '<tr><td style="background:#FFFFFF;border:1px solid #ECE8E1;border-radius:18px;padding:28px">'
			. '<h1 style="margin:0 0 14px;font-size:24px;line-height:1.2">' . self::e( $title ) . '</h1>'
			. $body . $btn
			. '</td></tr>'
			. '<tr><td style="padding:18px 6px;font-size:12px;line-height:1.5;color:#6D6D71">Airport Transportation · ' . self::e( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '<br>You get this email because of a booking made with this address.</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	private static function p( string $html ): string {
		return '<p style="margin:0 0 12px;font-size:15px;line-height:1.55">' . $html . '</p>';
	}

	/** Two-column details table. Values are escaped here unless marked raw. */
	private static function table( array $rows ): string {
		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;border-top:1px solid #ECE8E1">';
		foreach ( $rows as $r ) {
			if ( null === $r || '' === (string) $r[1] ) {
				continue;
			}
			$val  = ! empty( $r[2] ) ? $r[1] : self::e( $r[1] );
			$out .= '<tr><td style="padding:10px 12px 10px 0;border-bottom:1px solid #ECE8E1;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#6D6D71;vertical-align:top;width:34%">' . self::e( $r[0] ) . '</td><td style="padding:10px 0;border-bottom:1px solid #ECE8E1;font-size:14px;line-height:1.5">' . $val . '</td></tr>';
		}
		return $out . '</table>';
	}

	private static function box( string $html, string $bg = '#FFF4E5', string $border = '#FAD7A8' ): string {
		return '<div style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:14px;padding:14px 16px;margin:14px 0;font-size:14px;line-height:1.5">' . $html . '</div>';
	}

	/* ---------- Booking facts ---------- */

	public static function local( ?string $utc, string $tz, string $format = 'D j M Y, H:i' ): string {
		if ( ! $utc ) {
			return '';
		}
		try {
			return wp_date( $format, strtotime( $utc . ' UTC' ), new DateTimeZone( $tz ?: 'UTC' ) );
		} catch ( Exception $e ) {
			return $utc;
		}
	}

	private static function pax( array $b ): string {
		$a = (int) $b['adults'];
		$c = (int) $b['children'];
		return $a . ( 1 === $a ? ' adult' : ' adults' ) . ( $c ? ', ' . $c . ( 1 === $c ? ' child' : ' children' ) : '' );
	}

	/** The rows every booking email shares. $for_provider adds what the driver needs. */
	public static function trip_rows( array $b, bool $for_provider = false ): array {
		$s   = $b['snap'];
		$tz  = (string) ( $s['timezone'] ?? 'UTC' );
		$d   = (array) ( $s['details'] ?? array() );
		$arr = $b['legs'][0] ?? array();
		$ret = $b['legs'][1] ?? array();
		$rows = array(
			array( 'Reservation', $b['number'] ),
			array( 'Arrival', self::local( $arr['flight_time'] ?? $arr['pickup_at'] ?? null, $tz ) . ( ! empty( $arr['flight_number'] ) ? ' · ' . trim( $arr['flight_airline'] . ' ' . $arr['flight_number'] ) : ' · flight not given yet' ) ),
			array( 'From', $arr['from_place'] ?? '' ),
			array( 'To', ( $arr['to_place'] ?? '' ) . ( ! empty( $d['hotel_name'] ) ? ' (hotel booking under ' . $d['hotel_name'] . ')' : '' ) ),
		);
		if ( $ret ) {
			$rows[] = array( 'Return pickup', self::local( $ret['pickup_at'], $tz ) . ' at ' . $ret['from_place'] . ( ! empty( $ret['flight_number'] ) ? ' · flight ' . trim( $ret['flight_airline'] . ' ' . $ret['flight_number'] ) . ( $ret['flight_time'] ? ' at ' . self::local( $ret['flight_time'], $tz, 'H:i' ) : '' ) : '' ) );
		}
		$rows[] = array( 'Vehicle', ( $s['vehicle'] ?? '' ) . ', private' );
		$rows[] = array( 'Passengers', self::pax( $b ) . ' · ' . (int) ( $arr['suitcases'] ?? 0 ) . ' suitcases, ' . (int) ( $arr['carry_ons'] ?? 0 ) . ' carry-ons' );
		if ( $for_provider ) {
			$rows[] = array( 'Lead traveler', $b['first_name'] . ' ' . $b['last_name'] );
			$rows[] = array( 'Mobile', $b['phone'] . ( ! empty( $s['whatsapp'] ) ? ' (WhatsApp)' : '' ) );
		}
		if ( ! empty( $d['notes'] ) ) {
			$rows[] = array( 'Notes for the driver', $d['notes'] );
		}
		return $rows;
	}

	private static function meeting_html( array $b ): string {
		$s   = $b['snap'];
		$aid = (int) ( $s['airport_id'] ?? 0 );
		if ( ! $aid && ! empty( $s['iata'] ) ) {
			$a   = ATC_Search::find_airport( (string) $s['iata'] );
			$aid = $a ? (int) $a['id'] : 0;
		}
		$m   = ATC_Meeting::for_booking( $aid, (int) ( $b['legs'][0]['party_id'] ?? 0 ) );
		$out = '<h2 style="margin:22px 0 8px;font-size:17px">Meeting your driver at ' . self::e( ( $s['iata'] ?? '' ) ?: ( $s['airport'] ?? '' ) ) . '</h2>';
		foreach ( $m['sets'] as $set ) {
			if ( '' !== $set['terminal'] ) {
				$out .= '<p style="margin:10px 0 4px;font-weight:800;color:#9A5605">' . self::e( $set['terminal'] ) . '</p>';
			}
			$out .= '<ol style="margin:0 0 8px;padding-left:20px;font-size:14px;line-height:1.5">';
			foreach ( $set['steps'] as $st ) {
				$out .= '<li style="margin:0 0 6px"><strong>' . self::e( $st['title'] ) . '</strong>' . ( '' !== $st['text'] ? '<br>' . self::e( $st['text'] ) : '' ) . '</li>';
			}
			$out .= '</ol>';
		}
		return $out;
	}

	private static function provider_html( array $b ): string {
		$p = ATC_Checkout::provider( $b );
		if ( ! $p ) {
			return '';
		}
		$lines = '<strong>' . self::e( $p['name'] ) . '</strong> operates your transfer.';
		if ( ! empty( $p['phone'] ) ) {
			$digits = preg_replace( '/\D/', '', (string) $p['phone'] );
			$lines .= '<br>Phone: <a href="tel:+' . esc_attr( $digits ) . '" style="color:#9A5605">' . self::e( $p['phone'] ) . '</a> · <a href="https://wa.me/' . esc_attr( $digits ) . '" style="color:#9A5605">WhatsApp</a>';
		}
		$lines .= '<br><span style="color:#6D6D71">Contact them on the day of your transfer. To change your flight, hotel or time, use Manage booking so the change reaches them in writing.</span>';
		return self::box( $lines, '#FFFFFF', '#ECE8E1' );
	}

	private static function free_until( array $b ): string {
		$first = $b['legs'][0]['pickup_at'] ?? null;
		if ( ! $first ) {
			return '';
		}
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $first . ' UTC' ) - (int) ATC_Settings::get( 'free_cancel_hours' ) * HOUR_IN_SECONDS );
		return strtotime( $until . ' UTC' ) > time() ? self::local( $until, (string) ( $b['snap']['timezone'] ?? 'UTC' ) ) : '';
	}

	private static function team( string $subject, string $html ): void {
		self::send( (string) ATC_Settings::get( 'from_email' ), $subject, $html, array( 'kind' => 'team' ) );
	}

	private static function provider_party( array $b ): ?array {
		$pid = (int) ( $b['legs'][0]['party_id'] ?? 0 );
		return $pid ? ATC_Catalog::party( $pid ) : null;
	}

	/* ---------- The emails ---------- */

	/** Paid: the traveler's confirmation, the provider's new booking, a copy for us. */
	public static function booking_confirmed( int $booking_id ): void {
		$b = ATC_Checkout::find( '', $booking_id );
		if ( ! $b ) {
			return;
		}
		$key  = ATC_Checkout::key_of( $b );
		$b    = ATC_Checkout::find( '', $booking_id );
		$url  = ATC_Checkout::reservation_url( $b['number'], $key );
		$s    = $b['snap'];
		$free = self::free_until( $b );
		$card = ! empty( $s['card']['last4'] ) ? ' · ' . ucfirst( (string) $s['card']['brand'] ) . ' •••• ' . $s['card']['last4'] : '';

		$body = self::p( 'Hi ' . self::e( $b['first_name'] ) . ', your private transfer is booked. Keep this email: it has everything you need on the day.' )
			. self::table( array_merge( self::trip_rows( $b ), array( array( 'Total paid', ATC_Book::money( (int) $b['total_cents'] ) . $card ) ) ) )
			. self::box( '<span style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#5A3303">Service code</span><br><span style="font-size:28px;font-weight:800;letter-spacing:.08em;font-family:Menlo,Consolas,monospace">' . self::e( $b['service_code'] ) . '</span><br>Give it to your driver when the ride is done.', '#FBA53E', '#FBA53E' )
			. self::meeting_html( $b )
			. self::provider_html( $b )
			. ( $free ? self::box( '<strong style="color:#1E6B3A">Free cancellation</strong> until ' . self::e( $free ) . ' (local time), in Manage booking.', '#E8F4EC', '#CFE6D7' ) : '' );
		self::send( $b['email'], 'Booking confirmed: ' . $b['number'] . ' · ' . ( $s['iata'] ?? '' ) . ' ' . self::local( $b['legs'][0]['pickup_at'] ?? null, (string) ( $s['timezone'] ?? 'UTC' ), 'D j M' ), self::wrap( 'You’re booked.', $body, 'View or change your booking', $url, 'Reservation ' . $b['number'] . ' is confirmed.' ), array( 'booking_id' => $booking_id, 'kind' => 'confirmation' ) );

		self::provider_new( $b );
		self::team( 'New booking ' . $b['number'] . ' · ' . ( $s['iata'] ?? '' ) . ' · ' . ATC_Book::money( (int) $b['total_cents'] ), self::wrap( 'New booking ' . $b['number'], self::p( self::e( $s['provider'] ?? '' ) . ' · ' . self::e( ATC_Book::money( (int) $b['total_cents'] ) ) ) . self::table( self::trip_rows( $b, true ) ), 'Open in Control', home_url( '/control/bookings/' . $b['number'] . '/' ) ) );
	}

	private static function provider_amount( array $b ): int {
		$sum = 0;
		foreach ( $b['legs'] as $l ) {
			$sum += (int) $l['provider_cents'];
		}
		return $sum;
	}

	public static function provider_new( array $b ): void {
		$p = self::provider_party( $b );
		if ( ! $p ) {
			return;
		}
		$s    = $b['snap'];
		$body = self::p( 'Hi ' . self::e( $p['contact_name'] ?: $p['name'] ) . ', you have a new booking.' )
			. self::table( array_merge( self::trip_rows( $b, true ), array( array( 'You get paid', ATC_Book::money( self::provider_amount( $b ) ) ) ) ) )
			. self::box( 'The traveler gives your driver the <strong>service code</strong> at the end of the ride. You will enter it to mark the trip done.' )
			. self::p( '<span style="color:#6D6D71">If anything here can’t work for you, reply to this email right away.</span>' );
		self::send( self::provider_to( $p ), 'New booking ' . $b['number'] . ' · ' . ( $s['iata'] ?? '' ) . ' · ' . self::local( $b['legs'][0]['pickup_at'] ?? null, (string) ( $s['timezone'] ?? 'UTC' ), 'D j M, H:i' ), self::wrap( 'New booking ' . $b['number'], $body, 'Open the portal', home_url( '/portal/' ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'provider_new', 'reply_to' => ATC_Settings::get( 'from_email' ) ) );
	}

	/** The traveler changed something: both sides get the new details. */
	public static function booking_changed( array $b, array $changes ): void {
		$key  = ATC_Checkout::key_of( $b );
		$list = '<ul style="margin:0 0 12px;padding-left:20px;font-size:14px;line-height:1.5">';
		foreach ( $changes as $c ) {
			$list .= '<li>' . self::e( $c ) . '</li>';
		}
		$list .= '</ul>';
		self::send( $b['email'], 'Booking updated: ' . $b['number'], self::wrap( 'Your booking is updated', self::p( 'We saved your changes and told your provider:' ) . $list . self::table( self::trip_rows( $b ) ), 'View your booking', ATC_Checkout::reservation_url( $b['number'], $key ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'changed' ) );
		$p = self::provider_party( $b );
		if ( $p ) {
			self::send( self::provider_to( $p ), 'Booking changed: ' . $b['number'], self::wrap( 'Booking ' . $b['number'] . ' changed', self::p( 'The traveler changed:' ) . $list . self::table( self::trip_rows( $b, true ) ), 'Open the portal', home_url( '/portal/' ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'provider_changed' ) );
		}
	}

	public static function booking_cancelled( array $b, int $refund_cents ): void {
		$body = self::p( 'Reservation <strong>' . self::e( $b['number'] ) . '</strong> is cancelled.' )
			. ( $refund_cents ? self::box( '<strong style="color:#1E6B3A">Refund: ' . self::e( ATC_Book::money( $refund_cents ) ) . '</strong> to the card you paid with. Banks usually show it within 5 to 10 days.', '#E8F4EC', '#CFE6D7' ) : '' )
			. self::table( self::trip_rows( $b ) );
		self::send( $b['email'], 'Booking cancelled: ' . $b['number'], self::wrap( 'Your booking is cancelled', $body, 'Book again', home_url( '/' ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'cancelled' ) );
		$p = self::provider_party( $b );
		if ( $p ) {
			self::send( self::provider_to( $p ), 'Booking cancelled: ' . $b['number'], self::wrap( 'Booking ' . $b['number'] . ' is cancelled', self::p( 'The traveler cancelled. Please don’t send a driver.' ) . self::table( self::trip_rows( $b, true ) ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'provider_cancelled' ) );
		}
		self::team( 'Cancelled ' . $b['number'], self::wrap( 'Cancelled ' . $b['number'], self::p( 'Refund ' . self::e( ATC_Book::money( $refund_cents ) ) ) . self::table( self::trip_rows( $b, true ) ) ) );
	}

	/** Manage booking: the link to a booking, sent to the email on it. */
	public static function manage_link( array $b ): void {
		$key = ATC_Checkout::key_of( $b );
		self::send( $b['email'], 'Your booking ' . $b['number'], self::wrap( 'Your booking ' . $b['number'], self::p( 'Here is the link to see, change or cancel your booking. It works on any device.' ), 'Open my booking', ATC_Checkout::reservation_url( $b['number'], $key ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'manage_link' ) );
	}

	/* ---------- Every hour ---------- */

	public static function hourly(): void {
		global $wpdb;
		$t   = ATC_Schema::table( 'bookings' );
		$l   = ATC_Schema::table( 'booking_legs' );
		$now = time();

		// Finish your booking: started (contact given), not paid, between 1 and 24 hours ago. Once.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'held' AND email <> '' AND created_at BETWEEN %s AND %s LIMIT 50", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ) ) ); // phpcs:ignore
		foreach ( $rows as $id ) {
			$b = ATC_Checkout::find( '', (int) $id );
			if ( ! $b || ! empty( $b['snap']['reminded'] ) ) {
				continue;
			}
			$first = $b['legs'][0]['pickup_at'] ?? null;
			if ( $first && strtotime( $first . ' UTC' ) < $now + DAY_IN_SECONDS ) {
				continue; // Too late to be useful.
			}
			$key                   = ATC_Checkout::key_of( $b );
			$b                     = ATC_Checkout::find( '', (int) $id );
			$b['snap']['reminded'] = gmdate( 'Y-m-d H:i:s' );
			ATC_Checkout::snapshot( (int) $id, $b['snap'] );
			$url = add_query_arg( array( 'b' => $b['number'], 'k' => $key ), home_url( '/checkout/' ) );
			self::send( $b['email'], 'Your transfer isn’t booked yet', self::wrap( 'Your transfer isn’t booked yet', self::p( 'Hi ' . self::e( $b['first_name'] ) . ', you started booking a private transfer but didn’t finish. Your details are saved: pick up where you left off.' ) . self::table( self::trip_rows( $b ) ), 'Finish my booking', $url ), array( 'booking_id' => (int) $id, 'kind' => 'abandoned' ) );
		}

		// The day before: rides picking up in 20 to 28 hours.
		$legs = $wpdb->get_results( $wpdb->prepare( "SELECT l.id, l.booking_id FROM {$l} l JOIN {$t} b ON b.id = l.booking_id WHERE b.status = 'confirmed' AND l.status NOT IN ('cancelled','held') AND l.pickup_at BETWEEN %s AND %s LIMIT 100", gmdate( 'Y-m-d H:i:s', $now + 20 * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now + 28 * HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $legs as $leg ) {
			$b = ATC_Checkout::find( '', (int) $leg['booking_id'] );
			$done = (array) ( $b['snap']['reminded_legs'] ?? array() );
			if ( ! $b || in_array( (int) $leg['id'], $done, true ) ) {
				continue;
			}
			$key                        = ATC_Checkout::key_of( $b );
			$b                          = ATC_Checkout::find( '', (int) $leg['booking_id'] );
			$done[]                     = (int) $leg['id'];
			$b['snap']['reminded_legs'] = $done;
			ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
			$is_ret = (int) ( $b['legs'][1]['id'] ?? 0 ) === (int) $leg['id'];
			$body   = self::p( 'Hi ' . self::e( $b['first_name'] ) . ', your ' . ( $is_ret ? 'ride back to the airport' : 'airport pickup' ) . ' is tomorrow. Check your flight details are right; you can still change them in Manage booking.' )
				. self::table( self::trip_rows( $b ) )
				. ( $is_ret ? '' : self::meeting_html( $b ) )
				. self::provider_html( $b );
			self::send( $b['email'], ( $is_ret ? 'Your ride to the airport is tomorrow' : 'Your airport pickup is tomorrow' ) . ' · ' . $b['number'], self::wrap( $is_ret ? 'Your ride to the airport is tomorrow' : 'Your airport pickup is tomorrow', $body, 'View your booking', ATC_Checkout::reservation_url( $b['number'], $key ) ), array( 'booking_id' => (int) $b['id'], 'kind' => 'reminder' ) );
		}

		// How was it: a day after the last ride.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT b.id FROM {$t} b WHERE b.status = 'confirmed' AND (SELECT MAX(pickup_at) FROM {$l} WHERE booking_id = b.id) BETWEEN %s AND %s LIMIT 50", gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) ); // phpcs:ignore
		foreach ( $rows as $id ) {
			$b = ATC_Checkout::find( '', (int) $id );
			if ( ! $b || ! empty( $b['snap']['review_asked'] ) || ATC_Manage::reviewed( (int) $id ) ) {
				continue;
			}
			$key                       = ATC_Checkout::key_of( $b );
			$b                         = ATC_Checkout::find( '', (int) $id );
			$b['snap']['review_asked'] = gmdate( 'Y-m-d H:i:s' );
			ATC_Checkout::snapshot( (int) $id, $b['snap'] );
			$url   = ATC_Checkout::reservation_url( $b['number'], $key );
			$stars = '';
			for ( $i = 5; $i >= 1; $i-- ) {
				$stars .= '<a href="' . esc_url( add_query_arg( 'rate', $i, $url ) . '#rate' ) . '" style="display:inline-block;margin:0 6px 6px 0;padding:10px 14px;border:1px solid #ECE8E1;border-radius:12px;color:#15171C;text-decoration:none;font-weight:800"><span style="color:#E08A00">' . str_repeat( '★', $i ) . '</span> ' . $i . '</a>';
			}
			self::send( $b['email'], 'How was your transfer with ' . ( $b['snap']['provider'] ?? 'your provider' ) . '?', self::wrap( 'How was your ride?', self::p( 'Hi ' . self::e( $b['first_name'] ) . ', tell other travelers how ' . self::e( $b['snap']['provider'] ?? 'your provider' ) . ' did. One tap is enough.' ) . '<div style="margin:12px 0">' . $stars . '</div>' ), array( 'booking_id' => (int) $id, 'kind' => 'review' ) );
		}
	}
}
