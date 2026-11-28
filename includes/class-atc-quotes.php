<?php
/**
 * Quote requests: where nothing can be booked online (an airport we don't have yet, an address no
 * provider covers, a zone nobody priced, a group too big for one vehicle), the traveler asks for a
 * price. Providers at that airport and the directory companies in that city get the trip by email
 * with a private link to send their price; our team sends the traveler that price plus our share.
 *
 * @package ATCOM_Booking_Engine
 */

defined( 'ABSPATH' ) || exit;

class ATC_Quotes {

	const NS        = 'atc/v1';
	const MAX_ASKED = 8;

	public static function init(): void {
		add_action( 'init', static fn() => add_rewrite_rule( '^quote/([A-Za-z0-9]+)/?$', 'index.php?atc_quote=$matches[1]', 'top' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_quote' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function t(): string {
		return ATC_Schema::table( 'quotes' );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/* ---------- Routes ---------- */

	public static function routes(): void {
		register_rest_route( self::NS, '/quote', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/quote/(?P<number>[A-Za-z0-9]+)/offer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_offer' ), 'permission_callback' => '__return_true' ) );
		$team = array( 'ATC_Rest_Control', 'can' );
		register_rest_route( self::NS, '/control/quotes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $team ) );
		register_rest_route( self::NS, '/control/quotes/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_send' ), 'permission_callback' => $team ) );
		register_rest_route( self::NS, '/control/quotes/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_status' ), 'permission_callback' => $team ) );
	}

	private static function body( WP_REST_Request $r ): array {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : (array) $r->get_body_params();
	}

	private static function err( string $msg, int $code = 400 ): WP_Error {
		return new WP_Error( 'atc_quote', $msg, array( 'status' => $code ) );
	}

	/* ---------- The traveler asks ---------- */

	public static function rest_create( WP_REST_Request $r ) {
		$in = self::body( $r );
		if ( ! empty( $in['website'] ) ) { // honeypot: people never fill it
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( ! ATC_Partner::rate_ok( 'quote', 6 ) ) {
			return self::err( 'Too many requests. Please try again later or write to us.', 429 );
		}
		$s     = static fn( $k, $max = 190 ) => mb_substr( trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) ), 0, $max );
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::err( 'Enter a valid email so we can send you the price.' );
		}
		$name = $s( 'name', 120 );
		if ( '' === $name ) {
			return self::err( 'Enter your name.' );
		}
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'date' ) ) ? $s( 'date' ) : '';
		if ( '' === $date || $date < gmdate( 'Y-m-d' ) ) {
			return self::err( 'Enter the date of your transfer.' );
		}
		$place = $s( 'place', 255 );
		if ( '' === $place ) {
			return self::err( 'Enter your hotel or address.' );
		}
		// The airport: one of ours, or a Google airport we add now (checked with Google, never trusted from the browser).
		$airport = null;
		$code    = $s( 'airport' );
		if ( 0 === strpos( $code, 'g:' ) ) {
			$pid = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) strtok( substr( $code, 2 ), '~' ) );
			if ( ! ATC_Partner::rate_ok( 'add_airport', 10 ) ) {
				return self::err( 'Too many requests. Please try again later.', 429 );
			}
			$a = ATC_Catalog::airport_from_google( $pid );
			if ( is_wp_error( $a ) ) {
				return $a;
			}
			$airport = ATC_Catalog::airport( (int) $a['id'] );
		} elseif ( '' !== $code ) {
			$airport = ATC_Search::find_airport( $code );
		}
		$label = $airport ? ATC_Catalog::airport_label( $airport ) : $s( 'airport_label' );
		if ( '' === $label ) {
			return self::err( 'Choose your airport.' );
		}
		$ret = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s( 'return_date' ) ) ? $s( 'return_date' ) : null;
		global $wpdb;
		$number = self::new_number();
		$row    = array(
			'number'        => $number,
			'status'        => 'new',
			'airport_id'    => $airport ? (int) $airport['id'] : null,
			'airport_label' => mb_substr( $label, 0, 190 ),
			'reason'        => sanitize_key( (string) ( $in['reason'] ?? '' ) ),
			'dir'           => 'to_airport' === ( $in['dir'] ?? '' ) ? 'to_airport' : 'from_airport',
			'place'         => $place,
			'lat'           => is_numeric( $in['lat'] ?? null ) ? (float) $in['lat'] : null,
			'lng'           => is_numeric( $in['lng'] ?? null ) ? (float) $in['lng'] : null,
			'pickup_date'   => $date,
			'pickup_time'   => preg_match( '/^\d{1,2}:\d{2}$/', $s( 'time' ) ) ? $s( 'time' ) : '',
			'return_date'   => $ret,
			'adults'        => max( 1, min( 99, (int) ( $in['adults'] ?? 1 ) ) ),
			'children'      => max( 0, min( 60, (int) ( $in['children'] ?? 0 ) ) ),
			'bags'          => max( 0, min( 200, (int) ( $in['bags'] ?? 0 ) ) ),
			'flight'        => $s( 'flight', 60 ),
			'name'          => $name,
			'email'         => $email,
			'phone'         => $s( 'phone', 40 ),
			'notes'         => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1500 ),
			'snapshot'      => wp_json_encode( array( 'asked' => array(), 'offers' => array() ) ),
			'created_at'    => self::now(),
			'updated_at'    => self::now(),
		);
		if ( ! $wpdb->insert( self::t(), $row ) ) {
			return self::err( 'We could not save your request. Please write to us.', 500 );
		}
		$q     = self::get_by_number( $number );
		$asked = self::ask_providers( $q );
		self::mail_traveler_received( $q );
		self::mail_team_new( $q, $asked );
		ATC_Install::log( 'quote', (int) $q['id'], 'created', array( 'asked' => count( $asked ) ) );
		return rest_ensure_response( array( 'ok' => true, 'number' => $number ) );
	}

	private static function new_number(): string {
		global $wpdb;
		do {
			$n = 'Q' . strtoupper( wp_generate_password( 7, false, false ) );
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE number = %s', $n ) ) ); // phpcs:ignore
		return $n;
	}

	public static function get_by_number( string $number ): ?array {
		global $wpdb;
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE number = %s', $number ), ARRAY_A ); // phpcs:ignore
		return $q ? self::decode( $q ) : null;
	}

	private static function get( int $id ): ?array {
		global $wpdb;
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $q ? self::decode( $q ) : null;
	}

	private static function decode( array $q ): array {
		$q['snap'] = json_decode( (string) $q['snapshot'], true ) ?: array();
		$q['snap'] += array( 'asked' => array(), 'offers' => array() );
		return $q;
	}

	private static function save_snap( array $q ): void {
		global $wpdb;
		$wpdb->update( self::t(), array( 'snapshot' => wp_json_encode( $q['snap'] ), 'updated_at' => self::now() ), array( 'id' => (int) $q['id'] ) );
	}

	/* ---------- Who we ask ---------- */

	/**
	 * Providers with services at that airport, then unclaimed directory companies in that city
	 * (with an email we found). At most MAX_ASKED.
	 */
	private static function candidates( array $q ): array {
		global $wpdb;
		$out = array();
		if ( $q['airport_id'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT p.id, p.name, p.email FROM ' . ATC_Schema::table( 'parties' ) . ' p JOIN ' . ATC_Schema::table( 'services' ) . " s ON s.party_id = p.id AND s.airport_id = %d AND s.status IN ('live','draft')
					WHERE p.type = 'provider' AND p.status IN ('live','approved','pending') LIMIT 20",
					(int) $q['airport_id']
				),
				ARRAY_A
			); // phpcs:ignore
			foreach ( $rows as $p ) {
				if ( is_email( (string) $p['email'] ) ) {
					$out[ strtolower( $p['email'] ) ] = array( 'email' => $p['email'], 'name' => $p['name'], 'party_id' => (int) $p['id'] );
				}
			}
		}
		$airport = $q['airport_id'] ? ATC_Catalog::airport( (int) $q['airport_id'] ) : null;
		$city    = $airport ? trim( (string) $airport['city'] ) : '';
		if ( '' !== $city && taxonomy_exists( 'atc_place' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'atc_place', 'name' => $city, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$posts = get_posts( array( 'post_type' => 'atc_provider', 'post_status' => 'publish', 'posts_per_page' => 30, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'atc_place', 'terms' => $terms ) ) ) ); // phpcs:ignore
				foreach ( $posts as $pid ) {
					if ( (int) get_post_meta( $pid, '_atc_party_id', true ) ) {
						continue; // owned profiles: their provider is asked above when they serve this airport
					}
					$c = (array) get_post_meta( $pid, '_atc_claim_contact', true );
					foreach ( preg_split( '/[\s,;]+/', (string) ( $c['email'] ?? '' ) ) as $e ) {
						$e = sanitize_email( $e );
						if ( is_email( $e ) && ! isset( $out[ strtolower( $e ) ] ) ) {
							$out[ strtolower( $e ) ] = array( 'email' => $e, 'name' => get_the_title( $pid ), 'post_id' => (int) $pid );
							break;
						}
					}
				}
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_ASKED );
	}

	private static function ask_providers( array $q ): array {
		$asked = array();
		foreach ( self::candidates( $q ) as $c ) {
			if ( self::mail_provider_request( $q, $c ) ) {
				$asked[] = $c + array( 'at' => self::now() );
			}
		}
		$q['snap']['asked'] = $asked;
		self::save_snap( $q );
		if ( $asked ) {
			global $wpdb;
			$wpdb->update( self::t(), array( 'status' => 'asked' ), array( 'id' => (int) $q['id'] ) );
		}
		return $asked;
	}

	/** The provider's private link: only someone who got the email can send a price. */
	private static function key( string $number, string $email ): string {
		return substr( hash_hmac( 'sha256', $number . '|' . strtolower( $email ), wp_salt( 'auth' ) ), 0, 24 );
	}

	private static function offer_url( array $q, string $email ): string {
		return add_query_arg( array( 'e' => rawurlencode( base64_encode( strtolower( $email ) ) ), 'k' => self::key( $q['number'], $email ) ), home_url( '/quote/' . $q['number'] . '/' ) );
	}

	private static function email_from_link( array $q, string $e, string $k ): string {
		$email = sanitize_email( (string) base64_decode( rawurldecode( $e ), true ) );
		return is_email( $email ) && hash_equals( self::key( $q['number'], $email ), $k ) ? $email : '';
	}

	/* ---------- Emails ---------- */

	private static function trip_rows( array $q, bool $for_traveler ): array {
		$to_airport = 'to_airport' === $q['dir'];
		return array(
			array( 'Request', $q['number'] ),
			array( 'Date', date_i18n( 'D j M Y', strtotime( $q['pickup_date'] ) ) . ( $q['pickup_time'] ? ', ' . $q['pickup_time'] : '' ) ),
			array( 'From', $to_airport ? $q['place'] : $q['airport_label'] ),
			array( 'To', $to_airport ? $q['airport_label'] : $q['place'] ),
			array( 'Return', $q['return_date'] ? date_i18n( 'D j M Y', strtotime( $q['return_date'] ) ) : '' ),
			array( 'Passengers', (int) $q['adults'] + (int) $q['children'] . ( (int) $q['children'] ? ' (' . (int) $q['children'] . ' children)' : '' ) ),
			array( 'Luggage', (int) $q['bags'] ? (int) $q['bags'] . ' bags' : '' ),
			array( 'Flight', $q['flight'] ),
			array( 'Notes', (string) $q['notes'] ),
			$for_traveler ? null : array( 'Traveler', '' ),
		);
	}

	private static function mail_traveler_received( array $q ): void {
		$body = ATC_Mail::p( 'Hi ' . esc_html( $q['name'] ) . ', thank you for your request. We are asking the local transport companies we work with and will email you a price within 24 hours, usually sooner. You don’t pay anything now.' )
			. ATC_Mail::table( self::trip_rows( $q, true ) )
			. ATC_Mail::p( 'If something is wrong or you want to add details, just reply to this email.' )
			. ATC_Mail::p( 'Cristian Flores<br>Customer Service, Airport Transportation' );
		ATC_Mail::send( $q['email'], 'We’re finding you a price: ' . $q['airport_label'] . ' (' . $q['number'] . ')', ATC_Mail::wrap( 'We’re finding you a price', $body ), array( 'kind' => 'quote_received' ) );
	}

	private static function mail_team_new( array $q, array $asked ): void {
		$who  = $asked ? implode( ', ', array_map( static fn( $a ) => $a['name'] . ' <' . $a['email'] . '>', $asked ) ) : 'Nobody: no provider or directory company with an email at this airport yet. Find companies and ask them by hand.';
		$rows = array_merge( self::trip_rows( $q, true ), array( array( 'Traveler', $q['name'] . ' · ' . $q['email'] . ( $q['phone'] ? ' · ' . $q['phone'] : '' ) ), array( 'Why', $q['reason'] ?: 'quote' ), array( 'Asked', $who ) ) );
		$body = ATC_Mail::table( $rows );
		ATC_Mail::send( (string) ATC_Settings::get( 'from_email' ), '💬 QUOTE REQUEST · ' . $q['airport_label'] . ' · ' . $q['pickup_date'] . ' · ' . ( count( $asked ) ? count( $asked ) . ' asked' : 'NOBODY TO ASK' ) . ' · ' . $q['number'], ATC_Mail::wrap( 'Quote request ' . $q['number'], $body, 'Open in Control', home_url( '/control/quotes/' ) ), array( 'kind' => 'team' ) );
	}

	private static function mail_provider_request( array $q, array $c ): bool {
		$body = ATC_Mail::p( 'Hello ' . esc_html( $c['name'] ) . ',' )
			. ATC_Mail::p( 'A traveler asked us for a private transfer at <strong>' . esc_html( $q['airport_label'] ) . '</strong>. Can you do it, and at what price?' )
			. ATC_Mail::table( self::trip_rows( $q, true ) )
			. ATC_Mail::p( 'Send your price (the amount you receive, in USD) with the button below. It takes one minute. If you can’t do it, just ignore this email.' )
			. ATC_Mail::p( empty( $c['party_id'] ) ? 'Joining Airport Transportation is free: once you sign up and add your prices, travelers book you directly.' : 'Tip: add prices for this area in your portal so travelers can book you directly next time.' )
			. ATC_Mail::p( 'If you really can’t find the answer you are looking for, you can send a WhatsApp message to +1 786 943 8732.' )
			. ATC_Mail::p( 'Noel<br>Airport Transportation' );
		return ATC_Mail::send( $c['email'], 'Price request: ' . $q['airport_label'] . ' · ' . date_i18n( 'D j M', strtotime( $q['pickup_date'] ) ) . ' · ' . ( (int) $q['adults'] + (int) $q['children'] ) . ' passengers · ' . $q['number'], ATC_Mail::wrap( 'Price request ' . $q['number'], $body, 'Send my price', self::offer_url( $q, $c['email'] ) ), array( 'kind' => 'quote_request' ) );
	}

	/* ---------- The provider answers ---------- */

	public static function rest_offer( WP_REST_Request $r ) {
		$q = self::get_by_number( (string) $r['number'] );
		if ( ! $q ) {
			return self::err( 'Request not found.', 404 );
		}
		$in    = self::body( $r );
		$email = self::email_from_link( $q, (string) ( $in['e'] ?? '' ), (string) ( $in['k'] ?? '' ) );
		if ( '' === $email ) {
			return self::err( 'This link is not valid. Use the button in our email.', 403 );
		}
		if ( in_array( $q['status'], array( 'closed', 'won' ), true ) ) {
			return self::err( 'This request is closed. Thank you anyway.', 409 );
		}
		$amount = round( (float) ( $in['amount'] ?? 0 ), 2 );
		if ( $amount <= 0 || $amount > 20000 ) {
			return self::err( 'Enter your price in USD.' );
		}
		$offer = array(
			'email'   => $email,
			'company' => mb_substr( sanitize_text_field( (string) ( $in['company'] ?? '' ) ), 0, 120 ),
			'vehicle' => mb_substr( sanitize_text_field( (string) ( $in['vehicle'] ?? '' ) ), 0, 80 ),
			'amount'  => $amount,
			'notes'   => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 800 ),
			'at'      => self::now(),
		);
		$q['snap']['offers'][ strtolower( $email ) ] = $offer;
		self::save_snap( $q );
		global $wpdb;
		$wpdb->update( self::t(), array( 'status' => 'priced' ), array( 'id' => (int) $q['id'], 'status' => 'asked' ) );
		$wpdb->update( self::t(), array( 'status' => 'priced' ), array( 'id' => (int) $q['id'], 'status' => 'new' ) );
		$body = ATC_Mail::table(
			array(
				array( 'Request', $q['number'] . ' · ' . $q['airport_label'] . ' → ' . $q['place'] ),
				array( 'Company', $offer['company'] . ' <' . $email . '>' ),
				array( 'Vehicle', $offer['vehicle'] ),
				array( 'They get', 'USD ' . number_format( $amount, 2 ) ),
				array( 'Traveler pays', 'USD ' . number_format( self::retail( $amount ), 2 ) ),
				array( 'Notes', $offer['notes'] ),
			)
		);
		ATC_Mail::send( (string) ATC_Settings::get( 'from_email' ), '💵 PRICE IN · ' . $q['number'] . ' · ' . ( $offer['company'] ?: $email ) . ' · USD ' . number_format( $amount, 2 ), ATC_Mail::wrap( 'New price for ' . $q['number'], $body, 'Send it to the traveler', home_url( '/control/quotes/' ) ), array( 'kind' => 'team' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** What the traveler pays for a provider price: our 25% on top, rounded up to the dollar. */
	public static function retail( float $amount ): float {
		return (float) ceil( $amount / 0.75 );
	}

	/* ---------- Control ---------- */

	/** Requests waiting on us: new, or a price came in that we haven't sent. */
	public static function count_open(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE status IN ('new','priced')" ); // phpcs:ignore
	}

	public static function rest_list( WP_REST_Request $r ) {
		global $wpdb;
		$status = sanitize_key( (string) $r['status'] );
		$where  = 'open' === $status || '' === $status ? "WHERE status IN ('new','asked','priced','quoted')" : ( 'all' === $status ? '' : $wpdb->prepare( 'WHERE status = %s', $status ) );
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " {$where} ORDER BY id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore
		$out    = array();
		foreach ( $rows as $q ) {
			$q     = self::decode( $q );
			$out[] = array(
				'id'       => (int) $q['id'],
				'number'   => $q['number'],
				'status'   => $q['status'],
				'created'  => get_date_from_gmt( $q['created_at'], 'j M, H:i' ),
				'airport'  => $q['airport_label'],
				'new_airport' => $q['airport_id'] && ! ( ATC_Catalog::airport( (int) $q['airport_id'] )['iata'] ?? '' ),
				'trip'     => array_values( array_filter( array_map( static fn( $x ) => $x && '' !== (string) $x[1] ? $x[0] . ': ' . $x[1] : null, self::trip_rows( $q, true ) ) ) ),
				'traveler' => $q['name'] . ' · ' . $q['email'] . ( $q['phone'] ? ' · ' . $q['phone'] : '' ),
				'reason'   => $q['reason'],
				'asked'    => array_map( static fn( $a ) => $a['name'] . ' <' . $a['email'] . '>', (array) $q['snap']['asked'] ),
				'offers'   => array_values( array_map( static fn( $o ) => $o + array( 'retail' => self::retail( (float) $o['amount'] ) ), (array) $q['snap']['offers'] ) ),
				'sent'     => $q['snap']['sent'] ?? null,
			);
		}
		return rest_ensure_response( array( 'quotes' => $out ) );
	}

	public static function rest_send( WP_REST_Request $r ) {
		$q = self::get( (int) $r['id'] );
		if ( ! $q ) {
			return self::err( 'Request not found.', 404 );
		}
		$in    = self::body( $r );
		$offer = $q['snap']['offers'][ strtolower( (string) ( $in['email'] ?? '' ) ) ] ?? null;
		if ( ! $offer ) {
			return self::err( 'Pick one of the prices.' );
		}
		$price = isset( $in['price'] ) && (float) $in['price'] > 0 ? round( (float) $in['price'], 2 ) : self::retail( (float) $offer['amount'] );
		$body  = ATC_Mail::p( 'Hi ' . esc_html( $q['name'] ) . ', good news: we found a local provider for your transfer.' )
			. ATC_Mail::table( array_merge( self::trip_rows( $q, true ), array( array( 'Vehicle', $offer['vehicle'] ), array( 'Price', 'USD ' . number_format( $price, 2 ) . ' in total, for the vehicle' . ( $q['return_date'] ? ', both ways' : '' ) ) ) ) )
			. ATC_Mail::p( 'The price includes the private vehicle and driver, meeting you at arrivals, and flight tracking. To book it, just reply to this email and we will send you the confirmation and a secure payment link.' )
			. ATC_Mail::p( 'Cristian Flores<br>Customer Service, Airport Transportation' );
		if ( ! ATC_Mail::send( $q['email'], 'Your transfer price: ' . $q['airport_label'] . ' (' . $q['number'] . ')', ATC_Mail::wrap( 'Your transfer price', $body ), array( 'kind' => 'quote_sent' ) ) ) {
			return self::err( 'The email could not be sent.', 500 );
		}
		$q['snap']['sent'] = array( 'email' => $offer['email'], 'company' => $offer['company'], 'amount' => (float) $offer['amount'], 'price' => $price, 'at' => self::now() );
		self::save_snap( $q );
		global $wpdb;
		$wpdb->update( self::t(), array( 'status' => 'quoted' ), array( 'id' => (int) $q['id'] ) );
		ATC_Install::log( 'quote', (int) $q['id'], 'sent', array( 'price' => $price ) );
		return self::rest_list( new WP_REST_Request( 'GET' ) );
	}

	public static function rest_status( WP_REST_Request $r ) {
		$status = sanitize_key( (string) ( self::body( $r )['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'won', 'closed', 'asked' ), true ) ) {
			return self::err( 'Unknown status.' );
		}
		global $wpdb;
		$wpdb->update( self::t(), array( 'status' => $status, 'updated_at' => self::now() ), array( 'id' => (int) $r['id'] ) );
		return self::rest_list( new WP_REST_Request( 'GET' ) );
	}

	/* ---------- The quote form on the results page ---------- */

	/** The "Get a price" form, shown when nothing can be booked online. */
	public static function form( array $res ): string {
		$q       = $res['query'];
		$code    = (string) $q['airport'];
		$label   = $res['airport']['label'] ?? ( 0 === strpos( $code, 'g:' ) ? (string) substr( (string) strstr( $code, '~' ), 1 ) : $code );
		$code    = $res['airport'] ? (string) ( $res['airport']['iata'] ?: $res['airport']['id'] ) : $code;
		$new     = 0 === strpos( $code, 'g:' );
		$rest    = esc_url_raw( rest_url( self::NS . '/quote' ) );
		$hidden  = array(
			'airport'       => $code,
			'airport_label' => $label,
			'reason'        => (string) ( $res['reason'] ?? '' ),
			'dir'           => $q['dir'],
			'lat'           => null === $q['lat'] ? '' : $q['lat'],
			'lng'           => null === $q['lng'] ? '' : $q['lng'],
			'return_date'   => $q['return_date'],
		);
		ob_start();
		?>
		<form class="ab-quote" data-ab-quote data-rest="<?php echo esc_attr( $rest ); ?>" novalidate style="margin-top:22px;padding:22px;border:1px solid var(--line,#E5E1D8);border-radius:18px;background:#fff">
			<h2 class="ab-h3" style="margin-top:0"><?php echo $new ? 'New airport: we’ll find you a driver' : 'Get a price by email'; ?></h2>
			<p class="ab-muted" style="margin:0 0 14px"><?php echo esc_html( 'Tell us your trip' . ( '' !== $label ? ' from ' . $label : '' ) . ' and we’ll ask the local transport companies we work with. You get a price by email within 24 hours, and you don’t pay anything now.' ); ?></p>
			<?php foreach ( $hidden as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
				<label class="ab-field"><span>Hotel or address</span><input name="place" required value="<?php echo esc_attr( $q['to'] ); ?>"></label>
				<label class="ab-field"><span>Date</span><input type="date" name="date" required value="<?php echo esc_attr( $q['date'] ); ?>"></label>
				<label class="ab-field"><span>Time (landing or pickup)</span><input type="time" name="time" value="<?php echo esc_attr( $q['time'] ); ?>"></label>
				<label class="ab-field"><span>Flight number</span><input name="flight" placeholder="e.g. UA 57"></label>
				<label class="ab-field"><span>Adults</span><input type="number" name="adults" min="1" max="99" value="<?php echo (int) $q['adults']; ?>"></label>
				<label class="ab-field"><span>Children</span><input type="number" name="children" min="0" max="60" value="<?php echo (int) $q['children']; ?>"></label>
				<label class="ab-field"><span>Suitcases</span><input type="number" name="bags" min="0" max="200" value="<?php echo (int) $q['adults']; ?>"></label>
				<label class="ab-field"><span>Your name</span><input name="name" required autocomplete="name"></label>
				<label class="ab-field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label>
				<label class="ab-field"><span>Phone or WhatsApp (optional)</span><input name="phone" autocomplete="tel"></label>
			</div>
			<label class="ab-field" style="margin-top:12px;display:block"><span>Anything else (return trip, child seats, surfboards…)</span><textarea name="notes" rows="3" style="width:100%"></textarea></label>
			<input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
			<p class="ab-quote-msg" role="status" style="margin:12px 0 0;font-weight:700"></p>
			<div class="ab-actions" style="margin-top:12px"><button type="submit" class="ab-btn is-accent">Get my price</button></div>
		</form>
		<script>
		(function () {
			var f = document.querySelector('[data-ab-quote]'); if (!f || f.dataset.on) return; f.dataset.on = 1;
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = f.querySelector('.ab-quote-msg'), btn = f.querySelector('button[type=submit]'), data = {};
				new FormData(f).forEach(function (v, k) { data[k] = v; });
				if (!data.name || !data.email || !data.date || !data.place) { msg.textContent = 'Please fill in your name, email, date and hotel or address.'; return; }
				btn.disabled = true; msg.textContent = 'Sending…';
				fetch(f.getAttribute('data-rest'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
					.then(function (x) {
						if (!x.ok) { btn.disabled = false; msg.textContent = (x.j && x.j.message) || 'Something went wrong. Please write to us.'; return; }
						f.innerHTML = '<h2 class="ab-h3" style="margin-top:0">Thank you! We’re on it.</h2><p>We’ve emailed you a copy of your request (' + x.j.number + '). You’ll get a price by email within 24 hours.</p>';
					}).catch(function () { btn.disabled = false; msg.textContent = 'Something went wrong. Please try again.'; });
			});
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- The provider's page: /quote/{number}/?e=…&k=… ---------- */

	public static function maybe_render(): void {
		$number = (string) get_query_var( 'atc_quote' );
		if ( '' === $number ) {
			return;
		}
		$q     = self::get_by_number( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $number ) ) );
		$e     = isset( $_GET['e'] ) ? sanitize_text_field( wp_unslash( $_GET['e'] ) ) : ''; // phpcs:ignore
		$k     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore
		$email = $q ? self::email_from_link( $q, $e, $k ) : '';
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		add_filter( 'pre_get_document_title', static fn() => 'Send your price · Airport Transportation' );
		get_header();
		echo '<main class="atc-wrap" style="max-width:760px;margin:40px auto;padding:0 16px">';
		if ( ! $q || '' === $email ) {
			echo '<h1>This link is not valid</h1><p>Please use the button in the email we sent you, or reply to that email.</p>';
		} elseif ( in_array( $q['status'], array( 'closed', 'won' ), true ) ) {
			echo '<h1>This request is closed</h1><p>Thank you anyway. We will send you the next one.</p>';
		} else {
			$mine    = $q['snap']['offers'][ strtolower( $email ) ] ?? null;
			$company = '';
			foreach ( (array) $q['snap']['asked'] as $a ) {
				if ( strtolower( $a['email'] ) === strtolower( $email ) ) {
					$company = (string) $a['name'];
				}
			}
			$types = array_map( static fn( $v ) => $v['name'], ATC_Catalog::vehicle_types() );
			echo '<h1 style="margin-bottom:6px">Price request ' . esc_html( $q['number'] ) . '</h1>';
			echo '<p class="atc-muted" style="margin-top:0">A traveler is looking for a private transfer. Send us your price for the whole vehicle, in USD: the amount you receive.</p>';
			echo ATC_Mail::table( self::trip_rows( $q, true ) ); // phpcs:ignore -- escaped inside.
			?>
			<form data-atc-offer style="display:grid;gap:12px;margin-top:18px">
				<label><strong>Your company</strong><br><input name="company" value="<?php echo esc_attr( $mine['company'] ?? $company ); ?>" style="width:100%;padding:10px"></label>
				<label><strong>Vehicle</strong><br><select name="vehicle" style="width:100%;padding:10px"><?php foreach ( $types as $t ) : ?><option<?php selected( $mine['vehicle'] ?? '', $t ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?></select></label>
				<label><strong>Your price in USD (what you receive<?php echo $q['return_date'] ? ', both ways' : ''; ?>)</strong><br><input name="amount" type="number" min="1" step="0.01" required value="<?php echo esc_attr( (string) ( $mine['amount'] ?? '' ) ); ?>" style="width:100%;padding:10px"></label>
				<label><strong>Notes (optional)</strong><br><textarea name="notes" rows="3" style="width:100%;padding:10px"><?php echo esc_textarea( $mine['notes'] ?? '' ); ?></textarea></label>
				<input type="hidden" name="e" value="<?php echo esc_attr( $e ); ?>"><input type="hidden" name="k" value="<?php echo esc_attr( $k ); ?>">
				<p data-msg role="status" style="font-weight:700;margin:0"><?php echo $mine ? 'We have your price. You can change it here.' : ''; ?></p>
				<button type="submit" style="padding:14px 24px;border:0;border-radius:999px;background:#FBA53E;font-weight:800;cursor:pointer">Send my price</button>
			</form>
			<p class="atc-muted" style="margin-top:22px">Not a partner yet? <a href="<?php echo esc_url( home_url( '/partners/provider/' ) ); ?>">Join Airport Transportation for free</a>, add your prices, and travelers can book you directly.</p>
			<script>
			(function () {
				var f = document.querySelector('[data-atc-offer]'), m = f.querySelector('[data-msg]');
				f.addEventListener('submit', function (ev) {
					ev.preventDefault(); var d = {}; new FormData(f).forEach(function (v, k) { d[k] = v; });
					m.textContent = 'Sending…';
					fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( self::NS . '/quote/' . $q['number'] . '/offer' ) ) ); ?>, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) })
						.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
						.then(function (x) { m.textContent = x.ok ? 'Thank you! We received your price and will tell you if the traveler books.' : ((x.j && x.j.message) || 'Something went wrong.'); })
						.catch(function () { m.textContent = 'Something went wrong. Please reply to our email instead.'; });
				});
			})();
			</script>
			<?php
		}
		echo '</main>';
		get_footer();
		exit;
	}
}
