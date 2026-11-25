<?php
/**
 * Crisp plugin, step 2: the Booking panel in the Crisp inbox sidebar.
 *
 * The panel (a Crisp "generic widget", JSON in widget()) reads the booking number kept on the
 * conversation (conversation.meta.data.booking: set by our booking pages and by step 1's auto-reply).
 * Every value comes from POST /wp-json/atc/v1/crisp/action, signed by Crisp with the plugin's secret.
 * Agents can type another number in the panel. Changes, provider swaps, refunds and cancellations
 * open the booking in Control, where those already run with all their checks.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Crisp_Widget {

	const COOKIE = 'atc_crisp';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_booking' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'banner' ) );
		add_action( 'atc_booking_confirmed', array( __CLASS__, 'paid' ), 20 );
	}

	/* ---------- Step 3: a new booking from a chat ---------- */

	/** The chat a team member is booking for (set by the panel's "New booking" button), or ''. */
	public static function session(): string {
		if ( ! is_user_logged_in() || ! current_user_can( 'atc_manage' ) ) {
			return '';
		}
		$s = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) );
		return preg_match( '/^session_[a-f0-9-]{36}$/', $s ) ? $s : '';
	}

	/** /?atc_crisp=<session>: remember the chat for 3 hours, then search as usual. ?atc_crisp=end stops. */
	public static function start_booking(): void {
		if ( ! isset( $_GET['atc_crisp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$s = sanitize_text_field( wp_unslash( $_GET['atc_crisp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( current_user_can( 'atc_manage' ) ) {
			if ( preg_match( '/^session_[a-f0-9-]{36}$/', $s ) ) {
				setcookie( self::COOKIE, $s, array( 'expires' => time() + 3 * HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
				$_COOKIE[ self::COOKIE ] = $s;
				self::who( $s, true );
			} else {
				setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			}
		}
		wp_safe_redirect( remove_query_arg( 'atc_crisp' ) );
		exit;
	}

	/** Who the chat is with (name, email, phone from Crisp), cached for the team banner. */
	public static function who( string $s, bool $fresh = false ): array {
		$key = 'atc_crisp_who_' . md5( $s );
		$w   = $fresh ? false : get_transient( $key );
		if ( false === $w ) {
			$m = ATC_Crisp::api( 'GET', 'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $s ) . '/meta' );
			$w = array(
				'name'  => (string) ( $m['nickname'] ?? '' ),
				'email' => (string) ( $m['email'] ?? '' ),
				'phone' => (string) ( $m['phone'] ?? '' ),
			);
			set_transient( $key, $w, 3 * HOUR_IN_SECONDS );
		}
		return $w;
	}

	public static function banner(): void {
		$s = self::session();
		if ( '' === $s ) {
			return;
		}
		$w    = self::who( $s );
		$name = trim( $w['name'] . ( $w['email'] ? ' · ' . $w['email'] : '' ) . ( $w['phone'] ? ' · ' . $w['phone'] : '' ) );
		echo '<div style="position:fixed;left:12px;bottom:12px;z-index:99999;max-width:min(92vw,520px);background:#111;color:#fff;font:14px/1.4 system-ui,sans-serif;padding:10px 14px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.25)">'
			. '<strong>Booking for a Crisp chat</strong>' . ( $name ? ': ' . esc_html( $name ) : '' )
			. '<br><span style="opacity:.8">Search, pick a ride, fill the traveler\'s details, then "Send the payment link": it goes by email and into the chat.</span> '
			. '<a href="' . esc_url( add_query_arg( 'atc_crisp', 'end', home_url( '/' ) ) ) . '" style="color:#ffb74d">Stop</a></div>';
	}

	/** The payment link, into the chat too. */
	public static function send_link( array $b, string $url ): bool {
		$s = (string) ( $b['snap']['crisp_session'] ?? '' );
		if ( '' === $s || ! ATC_Crisp::ready() ) {
			return false;
		}
		$legs = array();
		foreach ( (array) $b['legs'] as $l ) {
			$legs[] = '• ' . ATC_Mail::local( $l['pickup_at'], (string) ( $b['snap']['timezone'] ?? 'UTC' ), 'D j M, H:i' ) . ': ' . $l['from_place'] . ' → ' . $l['to_place'];
		}
		$text = 'Here is your booking ' . $b['number'] . ' (' . ( $b['snap']['vehicle'] ?? 'private transfer' ) . ', ' . ATC_Book::money( (int) $b['total_cents'] ) . "):\n" . implode( "\n", $legs )
			. "\n\nCheck the details and pay securely by card here: " . $url . "\nYour booking is confirmed as soon as you pay. We have sent the same link to " . $b['email'] . '.';
		ATC_Crisp::say( $s, $text );
		ATC_Crisp::remember( $s, $b );
		return true;
	}

	/** Paid: tell the chat. */
	public static function paid( int $booking_id ): void {
		$b = ATC_Checkout::find( '', $booking_id );
		$s = (string) ( $b['snap']['crisp_session'] ?? '' );
		if ( ! $b || '' === $s || ! ATC_Crisp::ready() ) {
			return;
		}
		ATC_Mail::defer( static function () use ( $b, $s ) {
			ATC_Crisp::say( $s, 'Thank you! Booking ' . $b['number'] . ' is paid and confirmed. Your confirmation is on its way to ' . $b['email'] . '.' );
			ATC_Crisp::remember( $s, ATC_Checkout::find( '', (int) $b['id'] ) ?: $b );
		} );
	}

	public static function routes(): void {
		register_rest_route(
			'atc/v1',
			'/crisp/action',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'action' ),
			)
		);
	}

	public static function action_url(): string {
		return rest_url( 'atc/v1/crisp/action' );
	}

	/** The widget to paste in the Crisp Marketplace (plugin → Settings → Widget). */
	public static function widget(): array {
		$bind = '{conversation.meta.data.booking}';
		$has  = "{conversation.meta.data.booking}";
		$data = static fn( string $id, string $label, string $icon ) => array(
			'id'        => $id,
			'type'      => 'data',
			'condition' => $has,
			'value'     => array(
				'label'     => $label,
				'value'     => '',
				'editable'  => false,
				'immediate' => true,
				'icon'      => $icon,
				'data'      => array( 'booking' => $bind ),
			),
		);
		$hook = static fn( string $id, string $label, string $color ) => array(
			'id'        => $id,
			'type'      => 'button',
			'condition' => $has,
			'value'     => array(
				'type'  => 'hook',
				'label' => $label,
				'color' => $color,
				'data'  => array( 'booking' => $bind ),
			),
		);
		return array(
			'version'  => '1.0',
			'sections' => array(
				array(
					'id'    => 'booking',
					'title' => 'Booking',
					'items' => array(
						array(
							'id'    => 'number',
							'type'  => 'data',
							'value' => array(
								'label'       => 'Booking number',
								'value'       => '',
								'placeholder' => 'Type a number (AT…)',
								'editable'    => true,
								'immediate'   => true,
								'icon'        => 'tag',
								'data'        => array( 'booking' => $bind ),
							),
						),
						$data( 'status', 'Status', 'info' ),
						$data( 'traveler', 'Traveler', 'user_single' ),
						$data( 'arrival', 'Pickup', 'calendar_date' ),
						$data( 'return', 'Return', 'calendar_date' ),
						$data( 'ride', 'Vehicle · provider', 'globe' ),
						$data( 'money', 'Money', 'info' ),
						$hook( 'resend', 'Resend confirmation', 'blue' ),
						$hook( 'manage', 'Email manage link', 'black' ),
						array(
							'id'        => 'control',
							'type'      => 'button',
							'condition' => $has,
							'value'     => array(
								'type'  => 'link',
								'label' => 'Open in Control (change, refund, cancel)',
								'color' => 'green',
								'url'   => home_url( '/control/bookings/' ) . $bind . '/',
							),
						),
					),
				),
				array(
					'id'    => 'new',
					'title' => 'New booking',
					'items' => array(
						array(
							'id'    => 'newbooking',
							'type'  => 'button',
							'value' => array(
								'type'  => 'link',
								'label' => 'New booking for this chat',
								'color' => 'orange',
								'url'   => home_url( '/' ) . '?atc_crisp={conversation.session_id}',
							),
						),
					),
				),
			),
		);
	}

	private static function out( $value ) {
		return rest_ensure_response( array( 'data' => array( 'value' => $value ) ) );
	}

	public static function action( WP_REST_Request $r ) {
		if ( ! ATC_Crisp::signed( $r, true ) ) {
			return new WP_Error( 'atc_crisp', 'Not allowed.', array( 'status' => 403 ) );
		}
		$e       = json_decode( (string) $r->get_body(), true );
		$website = (string) ( $e['origin']['website_id'] ?? '' );
		$session = (string) ( $e['origin']['session_id'] ?? '' );
		if ( '' === $website || $website !== (string) ATC_Settings::get( 'crisp_id' ) ) {
			return new WP_Error( 'atc_crisp', 'Not allowed.', array( 'status' => 403 ) );
		}
		$item    = (string) ( $e['widget']['item_id'] ?? '' );
		$method  = (string) ( $e['action']['method'] ?? '' );
		$payload = (array) ( $e['payload'] ?? array() );
		$number  = strtoupper( trim( (string) ( $payload['data']['booking'] ?? '' ) ) );

		if ( 'number' === $item && 'edit' === $method ) {
			$typed = ATC_Crisp::numbers( (string) ( $payload['value'] ?? '' ) );
			$b     = $typed ? ATC_Checkout::find( $typed[0] ) : null;
			if ( ! $b || '' === $session ) {
				return self::out( $number );
			}
			ATC_Crisp::remember( $session, $b );
			return self::out( $b['number'] );
		}

		$b = '' !== $number ? ATC_Checkout::find( $number ) : null;
		if ( 'number' === $item ) {
			return self::out( $b ? $b['number'] : '' );
		}
		if ( ! $b ) {
			return self::out( '' === $number ? '' : 'Not found' );
		}

		if ( 'button' === ( $e['action']['type'] ?? '' ) ) {
			return self::button( $item, $b, $session );
		}
		return self::out( self::value( $item, $b ) );
	}

	/** A private note for the team (needs a paid Crisp plan; skipped quietly otherwise). */
	private static function note( string $session, string $text ): void {
		if ( '' !== $session && ! get_transient( 'atc_crisp_no_notes' ) ) {
			ATC_Crisp::say( $session, $text, 'note' );
		}
	}

	/** Crisp shows "succeeded" on a 200 and "failed" otherwise (plus a private note on paid Crisp plans). */
	private static function button( string $item, array $b, string $session ) {
		if ( 'resend' === $item ) {
			$res = ATC_Ops::resend( $b['number'] );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'atc_crisp', $res->get_error_message(), array( 'status' => 409 ) );
			}
			self::note( $session, 'Confirmation for ' . $b['number'] . ' resent to ' . $b['email'] . '.' );
		} elseif ( 'manage' === $item ) {
			if ( ! empty( $b['snap']['legacy'] ) || ! in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
				return new WP_Error( 'atc_crisp', 'No manage link for this booking.', array( 'status' => 409 ) );
			}
			ATC_Mail::manage_link( $b );
			ATC_Install::log( 'booking', (int) $b['id'], 'manage_link_sent', array( 'via' => 'crisp', 'session' => $session ) );
			self::note( $session, 'Manage link for ' . $b['number'] . ' emailed to ' . $b['email'] . '.' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	private static function value( string $item, array $b ): string {
		$s  = (array) $b['snap'];
		$tz = (string) ( $s['timezone'] ?? 'UTC' );
		switch ( $item ) {
			case 'status':
				$paid     = in_array( $b['status'], array( 'confirmed', 'completed', 'cancelled' ), true ) && 'paid' === ( $s['payment'] ?? '' );
				$refunded = (int) ( $s['refunded'] ?? 0 );
				return ucfirst( $b['status'] )
					. ( $paid ? ' · paid ' . ATC_Book::money( (int) $b['total_cents'] ) : '' )
					. ( $refunded ? ' · refunded ' . ATC_Book::money( $refunded ) : '' )
					. ( ! empty( $s['legacy'] ) ? ' · old site' : '' );
			case 'traveler':
				$kids = (int) $b['children'];
				return trim( $b['first_name'] . ' ' . $b['last_name'] ) . ' · ' . $b['email'] . ( $b['phone'] ? ' · ' . $b['phone'] : '' )
					. ' · ' . (int) $b['adults'] . ' adult' . ( 1 === (int) $b['adults'] ? '' : 's' ) . ( $kids ? ', ' . $kids . ' child' . ( 1 === $kids ? '' : 'ren' ) : '' );
			case 'arrival':
			case 'return':
				$legs = array_values( array_filter( (array) $b['legs'], static fn( $l ) => 'cancelled' !== $l['status'] || 'cancelled' === $b['status'] ) );
				$l    = 'arrival' === $item ? ( $legs[0] ?? null ) : ( $legs[1] ?? null );
				if ( ! $l ) {
					return 'arrival' === $item ? '' : 'None';
				}
				$flight = ! empty( $l['flight_number'] ) ? ' · ' . trim( ( $l['flight_airline'] ?? '' ) . ' ' . $l['flight_number'] ) : '';
				return ATC_Mail::local( $l['pickup_at'], $tz, 'D j M, H:i' ) . ' · ' . $l['from_place'] . ' → ' . $l['to_place'] . $flight;
			case 'ride':
				$prov = ATC_Checkout::provider_name( $b );
				return ( $s['vehicle'] ?? '' ) . ( $prov ? ' · ' . $prov : ' · no provider yet' );
			case 'money':
				foreach ( ATC_Mail::team_rows( $b ) as $row ) {
					if ( 'Money' === $row[0] ) {
						return $row[1];
					}
				}
				return '';
		}
		return '';
	}
}
