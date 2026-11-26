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
 * A complaint (refund, no-show, dispute… in English, Spanish, French, Portuguese or Italian) or a booking from the
 * old site never gets an automatic status: the chat is tagged "complaint" and handed to our team with one reply.
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
	public static function signed( WP_REST_Request $r, bool $strict = false ): bool {
		$secret = (string) ATC_Settings::get( 'crisp_hook_secret' );
		if ( '' === $secret ) {
			return ! $strict; // Not set yet: the token in the address still protects the hook.
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
		$content   = (string) ( $d['content'] ?? '' );
		$numbers   = self::numbers( $content );
		$complaint = self::is_complaint( $content );
		if ( ( $numbers || $complaint ) && ATC_Settings::get( 'crisp_autoreply' ) ) {
			// Answer after Crisp has its 200 (it retries slow hooks).
			ATC_Mail::defer( static function () use ( $session, $numbers, $complaint ) {
				if ( $complaint ) {
					self::hand_over( $session, $numbers );
					return;
				}
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

	/** Words of a traveler who is unhappy or wants money back: no automatic status, a person answers. */
	public static function is_complaint( string $text ): bool {
		$t = mb_strtolower( remove_accents( $text ) );
		$words = array(
			// English
			'complain', 'refund', 'money back', 'chargeback', 'charge back', 'dispute', 'no show', 'no-show', 'did not show', "didn't show", 'didnt show', 'never showed', 'never came', 'never arrived', 'nobody came', 'no one came', 'no driver', 'driver did not', "driver didn't", 'scam', 'fraud', 'lawyer', 'unacceptable', 'terrible', 'worst', 'compensation', 'reimburse', 'stranded', 'left us', 'missed my flight', 'missed our flight',
			// Spanish
			'queja', 'reclamo', 'reclamacion', 'reembolso', 'devolucion', 'devuelvan', 'mi dinero', 'nadie llego', 'no llego', 'no vino', 'nunca llego', 'estafa', 'fraude', 'denuncia', 'profeco', 'abogado', 'pesimo', 'indignad',
			// French
			'plainte', 'remboursement', 'rembourser', 'arnaque', 'personne n\'est venu', 'pas venu', 'litige', 'inadmissible',
			// Portuguese
			'reclamacao', 'reembolso', 'ninguem veio', 'nao apareceu', 'golpe',
			// Italian
			'reclamo', 'rimborso', 'truffa', 'nessuno e venuto', 'non e venuto',
		);
		foreach ( $words as $w ) {
			if ( false !== strpos( $t, $w ) ) {
				return true;
			}
		}
		return false;
	}

	/** A complaint: one reply per conversation, the chat tagged for our team, the booking kept on it. */
	private static function hand_over( string $session, array $numbers ): void {
		$key = 'atc_crisp_ho_' . md5( $session );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		$b = $numbers ? ATC_Checkout::find( $numbers[0] ) : null;
		self::say( $session, "Thanks for telling us, and sorry about this. I'm passing your message to our team now: a person will answer you here, within 2 business days and usually much sooner." . ( $b ? '' : ' Please add your booking number (it starts with AT) if you have it.' ) );
		$path = 'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $session ) . '/meta';
		$meta = self::api( 'GET', $path );
		$segs = is_array( $meta['segments'] ?? null ) ? $meta['segments'] : array();
		if ( ! in_array( 'complaint', $segs, true ) ) {
			$segs[] = 'complaint';
			self::api( 'PATCH', $path, array( 'segments' => array_values( $segs ) ) );
		}
		if ( $b ) {
			self::remember( $session, $b );
			ATC_Install::log( 'booking', (int) $b['id'], 'crisp_complaint', array( 'session' => $session ) );
		}
	}

	private static function answer( string $session, string $number ): void {
		$key = 'atc_crisp_' . md5( $session . $number );
		if ( get_transient( $key ) || get_transient( 'atc_crisp_ho_' . md5( $session ) ) ) {
			return; // Already answered, or handed to our team after a complaint: no more automatic replies.
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
		if ( ! empty( $b['snap']['legacy'] ) ) {
			// Booked on the old site: its status here may be out of date, so a person answers.
			self::say( $session, 'Thanks! Booking ' . $b['number'] . ' was made on our previous system, so a person from our team will check it and answer you here shortly.' );
			self::remember( $session, $b );
			ATC_Install::log( 'booking', (int) $b['id'], 'crisp_autoreply', array( 'session' => $session, 'legacy' => true ) );
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
		self::remember( $session, $b );
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

	public static function say( string $session, string $text, string $type = 'text' ): void {
		self::api(
			'POST',
			'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $session ) . '/message',
			array(
				'type'      => $type,
				'from'      => 'operator',
				'origin'    => 'chat',
				'content'   => $text,
				'automated' => true,
				'user'      => array( 'nickname' => 'Airport Transportation' ),
			)
		);
	}

	/** @return array|null the response's data, or null. */
	public static function api( string $method, string $path, ?array $body = null ): ?array {
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
		if ( ! is_wp_error( $res ) && 402 === wp_remote_retrieve_response_code( $res ) && 'note' === ( $body['type'] ?? '' ) ) {
			set_transient( 'atc_crisp_no_notes', 1, DAY_IN_SECONDS ); // Free Crisp plan: no private notes. Try again tomorrow.
			return null;
		}
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			ATC_Install::log( 'crisp', 0, 'api_error', array( 'path' => $path, 'code' => is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ), 'body' => is_wp_error( $res ) ? '' : mb_substr( wp_remote_retrieve_body( $res ), 0, 300 ) ) );
			return null;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $j['data'] ?? null ) ? $j['data'] : array();
	}

	/** Keep the booking on the conversation, so the sidebar panel shows it. */
	public static function remember( string $session, array $b ): bool {
		$path = 'website/' . ATC_Settings::get( 'crisp_id' ) . '/conversation/' . rawurlencode( $session ) . '/meta';
		$meta = self::api( 'GET', $path );
		if ( null === $meta ) {
			return false;
		}
		$data = is_array( $meta['data'] ?? null ) ? $meta['data'] : array();
		$data['booking'] = $b['number'];
		$data['status']  = $b['status'];
		$data['control'] = home_url( '/control/bookings/' . $b['number'] . '/' );
		return null !== self::api( 'PATCH', $path, array( 'data' => $data ) );
	}
}
