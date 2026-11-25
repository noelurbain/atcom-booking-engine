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

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
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

	private static function button( string $item, array $b, string $session ) {
		$note = '';
		if ( 'resend' === $item ) {
			$res  = ATC_Ops::resend( $b['number'] );
			$note = is_wp_error( $res ) ? 'Could not resend: ' . $res->get_error_message() : 'Confirmation for ' . $b['number'] . ' resent to ' . $b['email'] . '.';
		} elseif ( 'manage' === $item ) {
			if ( ! empty( $b['snap']['legacy'] ) ) {
				$note = $b['number'] . ' comes from the old site: it has no manage link.';
			} else {
				ATC_Mail::manage_link( $b );
				$note = 'Manage link for ' . $b['number'] . ' emailed to ' . $b['email'] . '.';
				ATC_Install::log( 'booking', (int) $b['id'], 'manage_link_sent', array( 'via' => 'crisp' ) );
			}
		}
		if ( '' !== $note && '' !== $session ) {
			ATC_Crisp::say( $session, $note, 'note' ); // Private note: only the team sees it.
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
