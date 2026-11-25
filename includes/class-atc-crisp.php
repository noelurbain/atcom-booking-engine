<?php
/**
 * Crisp plugin, step 1: automatic replies to reservation numbers.
 *
 * Crisp sends every visitor message to /wp-json/atc/v1/crisp/hook?t=<our token> (a website web hook, or a signed plugin web hook).
 * When the message has a reservation number, we look it up and answer in the chat:
 * - the chat's email is the booking's email: full details and the link to the booking;
 * - otherwise: only the status, and the booking link goes to the email on the booking (anyone can type a number);
 * - unknown number: we say so and ask for the email used.
 * No number in the message: we stay quiet. Each number gets one answer per conversation per 10 minutes.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Crisp {

	const API = 'https://api.crisp.chat/v1/';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function ready(): bool {
		return '' !== (string) ATC_Settings::get( 'crisp_id' ) && '' !== (string) ATC_Settings::get( 'crisp_plugin_id' ) && '' !== (string) ATC_Settings::get( 'crisp_plugin_key' );
	}

	/** Our part of the web hook address: a random token, made once. */
	public static function hook_token(): string {
		$t = (string) get_option( 'atc_crisp_hook_token', '' );
		if ( '' === $t ) {
			$t = wp_generate_password( 32, false, false );
			update_option( 'atc_crisp_hook_token', $t, false );
		}
		return $t;
	}

	public static function hook_url(): string {
		return add_query_arg( 't', self::hook_token(), rest_url( 'atc/v1/crisp/hook' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'atc/v1',
			'/crisp/hook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'hook' ),
			)
		);
	}

	/** Crisp signs plugin hooks: HMAC-SHA256 of "[timestamp;body]" with the hook's signing secret. */
	private static function signed( WP_REST_Request $r ): bool {
		$secret = (string) ATC_Settings::get( 'crisp_hook_secret' );
		if ( '' === $secret ) {
			return true; // Not set yet: the token in the address still protects the hook.
		}
		$ts  = (string) $r->get_header( 'x_crisp_request_timestamp' );
		$sig = (string) $r->get_header( 'x_crisp_signature' );
		if ( '' === $ts || '' === $sig ) {
			return false;
		}
		$raw = hash_hmac( 'sha256', '[' . $ts . ';' . $r->get_body() . ']', $secret, true );
		return hash_equals( bin2hex( $raw ), strtolower( $sig ) ) || hash_equals( base64_encode( $raw ), $sig ); // phpcs:ignore
	}

	public static function hook( WP_REST_Request $r ) {
		if ( ! hash_equals( self::hook_token(), (string) $r->get_param( 't' ) ) || ! self::signed( $r ) ) {
			return new WP_Error( 'atc_crisp', 'Not allowed.', array( 'status' => 403 ) );
		}
		$e = json_decode( (string) $r->get_body(), true );
		$d = (array) ( $e['data'] ?? array() );
		if ( 'message:send' !== ( $e['event'] ?? '' ) || 'user' !== ( $d['from'] ?? '' ) || 'text' !== ( $d['type'] ?? '' ) || ! self::ready() ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$website = (string) ( $d['website_id'] ?? '' );
		$session = (string) ( $d['session_id'] ?? '' );
		if ( $website !== (string) ATC_Settings::get( 'crisp_id' ) || '' === $session ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$numbers = self::numbers( (string) ( $d['content'] ?? '' ) );
		if ( $numbers && ATC_Settings::get( 'crisp_autoreply' ) ) {
			// Answer after Crisp has its 200 (it retries slow hooks).
			ATC_Mail::defer( static function () use ( $session, $numbers ) {
				foreach ( array_slice( $numbers, 0, 2 ) as $n ) {
					self::answer( $session, $n );
				}
			} );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Reservation numbers in a message: AT followed by 6 to 10 letters or digits (old ones too). */
	public static function numbers( string $text ): array {
		// AT + 6 letters/digits (new site) or 8 (old site), written together; very old ones AT + 6 digits ("AT 100084").
		preg_match_all( '/\bAT(?:([A-Z0-9]{6}|[A-Z0-9]{8})|[\s-]([0-9]{6}))\b/i', $text, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $x ) {
			$out[] = 'AT' . strtoupper( '' !== $x[1] ? $x[1] : $x[2] );
		}
		return array_values( array_unique( $out ) );
	}

	private static function answer( string $session, string $number ): void {
		$key = 'atc_crisp_' . md5( $session . $number );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		$b = ATC_Checkout::find( $number );
		if ( ! $b || 'held' === $b['status'] ) {
			if ( ! preg_match( '/\d/', $number ) ) {
				return; // Probably a word ("ATMOSPHERE"), not a reservation number: stay quiet.
			}
			self::say( $session, 'I could not find a confirmed booking with the number ' . $number . '. Please check it (it starts with AT, followed by letters and numbers), or write the email you booked with and our team will help you.' );
			return;
		}
		$meta  = self::api( 'GET', 'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $session ) . '/meta' );
		$email = is_array( $meta ) ? strtolower( trim( (string) ( $meta['email'] ?? '' ) ) ) : '';
		$own   = '' !== $email && $email === strtolower( trim( (string) $b['email'] ) );
		$text  = $own ? self::details( $b ) : self::short( $b );
		if ( ! $own && in_array( $b['status'], array( 'confirmed', 'completed' ), true ) && empty( $b['snap']['legacy'] ) ) {
			ATC_Mail::manage_link( $b );
		}
		self::say( $session, $text );
		ATC_Install::log( 'booking', (int) $b['id'], 'crisp_autoreply', array( 'session' => $session, 'verified' => $own ) );
	}

	private static function status_words( array $b ): string {
		return array(
			'confirmed' => 'is confirmed',
			'completed' => 'is completed',
			'cancelled' => 'was cancelled',
		)[ $b['status'] ] ?? 'is ' . $b['status'];
	}

	/** Anyone can type a number: status only, no personal details. */
	private static function short( array $b ): string {
		$t = 'Booking ' . $b['number'] . ' ' . self::status_words( $b ) . '.';
		if ( in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			$t .= empty( $b['snap']['legacy'] )
				? ' For your privacy, we have just emailed the link to your booking to the address you booked with: there you can see every detail, change it or cancel it.'
				: ' Our team will confirm the details with you here in a moment.';
		}
		return $t;
	}

	/** The chat's email is the booking's: the trip and the link. */
	private static function details( array $b ): string {
		$s    = (array) $b['snap'];
		$tz   = (string) ( $s['timezone'] ?? 'UTC' );
		$t    = 'Hi ' . $b['first_name'] . ', booking ' . $b['number'] . ' ' . self::status_words( $b ) . '.';
		foreach ( $b['legs'] as $l ) {
			if ( 'cancelled' === $l['status'] && 'cancelled' !== $b['status'] ) {
				continue;
			}
			$t .= "\n• " . ( 'arrival' === $l['direction'] ? 'Arrival pickup' : ( ATC_Checkout::leg( $b, 'arrival' ) ? 'Return pickup' : 'Pickup' ) ) . ': ' . ATC_Mail::local( $l['pickup_at'], $tz, 'D j M Y, H:i' ) . ', from ' . $l['from_place'] . ' to ' . $l['to_place'];
		}
		$prov = ATC_Checkout::provider_name( $b );
		$t   .= "\n• Vehicle: " . ( $s['vehicle'] ?? '' ) . ( $prov ? ', run by ' . $prov : '' );
		if ( 'confirmed' === $b['status'] && empty( $s['legacy'] ) ) {
			$t .= "\n\nSee, change or cancel it here: " . ATC_Checkout::reservation_url( $b['number'], ATC_Checkout::key_of( $b ) );
		}
		return $t;
	}

	private static function say( string $session, string $text ): void {
		self::api(
			'POST',
			'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $session ) . '/message',
			array(
				'type'      => 'text',
				'from'      => 'operator',
				'origin'    => 'chat',
				'content'   => $text,
				'automated' => true,
				'user'      => array( 'nickname' => 'Airport Transportation' ),
			)
		);
	}

	/** @return array|null the response's data, or null. */
	private static function api( string $method, string $path, ?array $body = null ): ?array {
		$res = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( ATC_Settings::get( 'crisp_plugin_id' ) . ':' . ATC_Settings::get( 'crisp_plugin_key' ) ), // phpcs:ignore
					'X-Crisp-Tier'  => 'plugin' === ATC_Settings::get( 'crisp_tier' ) ? 'plugin' : 'website',
					'Content-Type'  => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			ATC_Install::log( 'crisp', 0, 'api_error', array( 'path' => $path, 'code' => is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ), 'body' => is_wp_error( $res ) ? '' : mb_substr( wp_remote_retrieve_body( $res ), 0, 300 ) ) );
			return null;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $j['data'] ?? null ) ? $j['data'] : array();
	}
}
