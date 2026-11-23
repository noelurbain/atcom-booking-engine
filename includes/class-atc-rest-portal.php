<?php
/**
 * REST routes for the partner portal: /wp-json/atc/v1/portal/...
 * A provider only ever reads and changes its own company.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_REST_Portal {

	const NS = 'atc/v1';

	public static function can(): bool {
		return null !== ATC_Partner::current_party_id();
	}

	private static function pid(): int {
		return (int) ATC_Partner::current_party_id();
	}

	private static function route( string $path, array $methods ): void {
		$args = array();
		foreach ( $methods as $method => $cb ) {
			$args[] = array(
				'methods'             => $method,
				'callback'            => $cb,
				'permission_callback' => array( __CLASS__, 'can' ),
			);
		}
		register_rest_route( self::NS, '/portal' . $path, $args );
	}

	private static function out( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function body( WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		return is_array( $json ) ? $json : $r->get_body_params();
	}

	/** Everything the portal shows, in one call. */
	public static function me(): array {
		$pid     = self::pid();
		$party   = ATC_Catalog::party( $pid );
		$airport = ATC_Catalog::airport( self::current_airport( $pid ) );
		if ( ! $airport ) {
			$all     = ATC_Catalog::airports();
			$airport = $all ? $all[0] : null;
		}
		$zones = $airport ? array_values( array_filter( ATC_Catalog::zones( (int) $airport['id'], false, $pid ), static fn( $z ) => ! empty( $z['polygon'] ) ) ) : array();
		foreach ( $zones as &$z ) {
			unset( $z['places'] );
		}
		$docs  = ATC_Partner::doc_types();
		$types = array();
		foreach ( $docs as $k => [ $name, $hint, $required ] ) {
			$types[] = array( 'type' => $k, 'name' => $name, 'hint' => $hint, 'required' => $required );
		}
		return array(
			'provider'      => array_intersect_key( $party, array_flip( array( 'id', 'name', 'legal_name', 'contact_name', 'email', 'phone', 'languages', 'website', 'about', 'country', 'min_notice_minutes', 'status', 'agreement_accepted_at', 'agreement_version' ) ) ),
			'airport'       => $airport,
			'airports'      => ATC_Partner::airports_overview( $pid ),
			'tour'          => (object) ATC_Partner::tour( get_current_user_id() ),
			'zones'         => $zones,
			'vehicle_types' => ATC_Catalog::vehicle_types(),
			'services'      => ATC_Catalog::services( $pid ),
			'vehicles'      => ATC_Catalog::vehicles( $pid ),
			'documents'     => ATC_Partner::documents( $pid ),
			'doc_types'     => $types,
			'payout'        => ATC_Partner::payout_summary( $pid ),
			'agreement'     => array( 'version' => ATC_Partner::AGREEMENT_VERSION, 'terms' => ATC_Partner::agreement_text() ),
			'commission_bp' => (int) ( $party['commission_bp'] ?? 0 ) ?: (int) ATC_Settings::get( 'provider_commission_bp' ),
			'steps'         => ATC_Partner::steps( $pid ),
			'account'       => ATC_Partner::account( get_current_user_id() ),
			'prices'        => ATC_Catalog::price_table( $pid ),
			'to_confirm'    => self::to_confirm( $pid ),
		);
	}

	/** The airport the provider is working on in the portal: the one it picked last, else its main airport. */
	private static function current_airport( int $pid ): int {
		$ids  = ATC_Partner::airport_ids( $pid );
		$want = (int) get_user_meta( get_current_user_id(), 'atc_portal_airport', true );
		return in_array( $want, $ids, true ) ? $want : (int) ( $ids[0] ?? 0 );
	}

	private static function to_confirm( int $pid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT l.booking_id) FROM ' . ATC_Schema::table( 'booking_legs' ) . ' l JOIN ' . ATC_Schema::table( 'bookings' ) . " b ON b.id = l.booking_id WHERE l.party_id = %d AND l.status = 'new' AND b.status = 'confirmed'", $pid ) );
	}

	public static function register_routes(): void {
		self::route( '/me', array( 'GET' => static fn() => rest_ensure_response( self::me() ) ) );

		self::route(
			'/company',
			array(
				'PUT' => static function ( WP_REST_Request $r ) {
					$in      = array_intersect_key( self::body( $r ), array_flip( array( 'name', 'legal_name', 'contact_name', 'email', 'phone', 'languages', 'website', 'about', 'min_notice_minutes' ) ) );
					$saved   = ATC_Catalog::save_party( $in, self::pid() );
					return is_wp_error( $saved ) ? $saved : rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/services',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					// Providers offer (draft) or pause a category; we switch it live when we approve them.
					$b['status'] = ( isset( $b['offered'] ) && ! $b['offered'] ) ? 'paused' : 'draft';
					$pid         = self::pid();
					$p           = ATC_Catalog::party( $pid );
					if ( 'live' === $p['status'] && 'draft' === $b['status'] ) {
						$b['status'] = 'live';
					}
					unset( $b['zone_ids'] );
					$res = ATC_Catalog::save_service( $pid, $b );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/vehicles',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$res = ATC_Catalog::save_vehicle( self::pid(), self::body( $r ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);
		self::route(
			'/vehicles/(?P<vid>\d+)',
			array(
				'DELETE' => static function ( WP_REST_Request $r ) {
					ATC_Catalog::archive_vehicle( self::pid(), (int) $r['vid'] );
					return rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/coverage',
			array(
				'PUT' => static function ( WP_REST_Request $r ) {
					$b   = self::body( $r );
					$res = ATC_Partner::save_coverage( self::pid(), (int) ( $b['airport_id'] ?? 0 ), (array) ( $b['zone_ids'] ?? array() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/documents',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$files = $r->get_file_params();
					if ( empty( $files['file'] ) ) {
						return new WP_Error( 'atc_doc_file', 'Choose a file first.', array( 'status' => 400 ) );
					}
					$res = ATC_Partner::upload_document( self::pid(), sanitize_key( (string) $r['type'] ), $files['file'], sanitize_text_field( (string) $r['expires_on'] ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/payout',
			array(
				'PUT' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::save_payout( self::pid(), self::body( $r ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		self::route(
			'/account/password',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Partner::change_password( get_current_user_id(), (string) ( $b['current'] ?? '' ), (string) ( $b['new'] ?? '' ) ) );
				},
			)
		);
		self::route(
			'/account/email',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b   = self::body( $r );
					$res = ATC_Partner::request_email_change( get_current_user_id(), (string) ( $b['email'] ?? '' ), (string) ( $b['current'] ?? '' ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		$airport = static function ( WP_REST_Request $r ) {
			$want = (int) ( self::body( $r )['airport_id'] ?? $r['airport_id'] ?? 0 );
			$ids  = wp_list_pluck( ATC_Prices::airports_for( self::pid() ), 'id' );
			return in_array( $want, $ids, true ) ? $want : self::current_airport( self::pid() );
		};
		// Bookings: the provider's rides.
		self::route( '/bookings', array( 'GET' => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Trips::list( self::pid(), (string) $r['filter'], max( 1, (int) $r['page'] ), ! empty( $r['all'] ) ) ) ) );
		// Airports the provider serves: pick the one to work on, add one, stop serving one.
		self::route(
			'/airport',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$id = (int) ( self::body( $r )['airport_id'] ?? 0 );
					if ( ! in_array( $id, ATC_Partner::airport_ids( self::pid() ), true ) ) {
						return new WP_Error( 'atc_airport', 'Add this airport first.', array( 'status' => 400 ) );
					}
					update_user_meta( get_current_user_id(), 'atc_portal_airport', $id );
					return rest_ensure_response( self::me() );
				},
			)
		);
		self::route(
			'/airports',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::add_airport( self::pid(), self::body( $r ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					update_user_meta( get_current_user_id(), 'atc_portal_airport', (int) $res['airport_id'] );
					return rest_ensure_response( array( 'added' => $res['added'] ) + self::me() );
				},
			)
		);
		self::route(
			'/airports/(?P<id>\\d+)',
			array(
				'DELETE' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::remove_airport( self::pid(), (int) $r['id'] );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					delete_user_meta( get_current_user_id(), 'atc_portal_airport' );
					return rest_ensure_response( self::me() );
				},
			)
		);
		// Which explanations of the guided tour this person has seen.
		self::route(
			'/tour',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return rest_ensure_response( array( 'tour' => (object) ATC_Partner::tour_seen( get_current_user_id(), (string) ( $b['key'] ?? '' ), ! isset( $b['seen'] ) || ! empty( $b['seen'] ) ) ) );
				},
			)
		);
		// Our zones around nearby airports, to copy as the provider's own.
		self::route(
			'/zones/nearby',
			array( 'GET' => static fn() => rest_ensure_response( array( 'zones' => ATC_Partner::nearby_zones( self::pid(), self::current_airport( self::pid() ) ) ) ) )
		);
		self::route(
			'/zones/copy',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::copy_zone( self::pid(), (int) ( self::body( $r )['zone_id'] ?? 0 ), self::current_airport( self::pid() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'zone_id' => $res['id'] ) + self::me() );
				},
			)
		);
		// Zones the provider draws itself (no approval).
		self::route(
			'/zones',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::save_own_zone( self::pid(), self::body( $r ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'zone_id' => $res['id'] ) + self::me() );
				},
			)
		);
		self::route(
			'/zones/suggest',
			array(
				'GET' => static function ( WP_REST_Request $r ) {
					$q = trim( sanitize_text_field( (string) $r['q'] ) );
					$a = ATC_Catalog::airport( self::current_airport( self::pid() ) );
					if ( '' === $q || ! $a ) {
						return new WP_Error( 'atc_suggest', 'Type the name of an area first.', array( 'status' => 400 ) );
					}
					if ( ! ATC_Partner::rate_ok( 'zone_suggest', 60 ) ) {
						return new WP_Error( 'atc_suggest', 'Too many searches. Try again in a few minutes.', array( 'status' => 429 ) );
					}
					[ $list ] = ATC_Geo::shape_suggestions( $q . ( false === stripos( $q, (string) $a['city'] ) ? ', ' . $a['city'] : '' ), $a );
					if ( ! $list ) {
						[ $list ] = ATC_Geo::shape_suggestions( $q, $a );
					}
					$list = array_values( array_filter( $list, static function ( $c ) use ( $a ) {
						$ring = (array) ( $c['polygon'] ?? array() );
						return count( $ring ) >= 3 && ATC_Geo::km( (float) $a['lat'], (float) $a['lng'], array_sum( array_column( $ring, 0 ) ) / count( $ring ), array_sum( array_column( $ring, 1 ) ) / count( $ring ) ) <= 300;
					} ) );
					return rest_ensure_response( array( 'query' => $q, 'candidates' => array_slice( $list, 0, 6 ) ) );
				},
			)
		);
		self::route(
			'/zones/(?P<id>\\d+)',
			array(
				'PUT'    => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::save_own_zone( self::pid(), self::body( $r ), (int) $r['id'] );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
				'DELETE' => static function ( WP_REST_Request $r ) {
					$res = ATC_Partner::delete_own_zone( self::pid(), (int) $r['id'] );
					return is_wp_error( $res ) ? $res : rest_ensure_response( self::me() );
				},
			)
		);

		// The provider's directory profile.
		self::route(
			'/directory',
			array(
				'GET' => static fn() => rest_ensure_response( ATC_Directory::profile( self::pid() ) ),
				'PUT' => static fn( WP_REST_Request $r ) => self::out( ATC_Directory::save( self::pid(), self::body( $r ) ) ),
			)
		);
		self::route( '/directory/check', array( 'POST' => static fn() => self::out( ATC_Directory::check_now( self::pid() ) ) ) );
		self::route(
			'/directory/logo',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$files = $r->get_file_params();
					return empty( $files['file'] ) ? new WP_Error( 'atc_logo', 'Choose an image first.', array( 'status' => 400 ) ) : self::out( ATC_Directory::logo( self::pid(), $files['file'] ) );
				},
			)
		);
		// Extras the provider offers.
		self::route( '/extras', array( 'GET' => static fn() => rest_ensure_response( ATC_Extras::for_provider( self::pid() ) ) ) );
		self::route( '/extras/(?P<id>\\d+)', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Extras::save_offer( self::pid(), (int) $r['id'], self::body( $r ) ) ) ) );

		// Tickets: the provider's own, and reporting a problem on one of its bookings.
		self::route(
			'/tickets',
			array(
				'GET'  => static fn() => rest_ensure_response( ATC_Tickets::for_party( self::pid() ) ),
				'POST' => static function ( WP_REST_Request $r ) {
					$b   = self::body( $r );
					$bk  = '' !== (string) ( $b['booking'] ?? '' ) ? ATC_Checkout::find( (string) $b['booking'] ) : null;
					if ( $bk && ! in_array( self::pid(), array_map( static fn( $l ) => (int) $l['party_id'], $bk['legs'] ), true ) ) {
						return new WP_Error( 'atc_not_found', 'That booking is not yours.', array( 'status' => 404 ) );
					}
					$allowed = array( 'traveler_no_show', 'schedule', 'overcharge', 'lost_item', 'complaint', 'provider', 'other' );
					$id = ATC_Tickets::open( array( 'booking_id' => $bk ? (int) $bk['id'] : 0, 'party_id' => self::pid(), 'type' => in_array( $b['type'] ?? '', $allowed, true ) ? $b['type'] : 'provider', 'subject' => (string) ( $b['subject'] ?? '' ), 'body' => (string) ( $b['body'] ?? '' ), 'by' => 'provider' ) );
					return is_wp_error( $id ) ? $id : rest_ensure_response( ATC_Tickets::get( $id, 'provider' ) );
				},
			)
		);
		self::route(
			'/tickets/(?P<id>\d+)',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => ATC_Tickets::party_of( (int) $r['id'] ) === self::pid() ? self::out( ATC_Tickets::get( (int) $r['id'], 'provider' ) ) : new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) ),
				'POST' => static function ( WP_REST_Request $r ) {
					if ( ATC_Tickets::party_of( (int) $r['id'] ) !== self::pid() ) {
						return new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) );
					}
					$res = ATC_Tickets::reply( (int) $r['id'], 'provider', (string) ( self::body( $r )['body'] ?? '' ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( ATC_Tickets::get( (int) $r['id'], 'provider' ) );
				},
			)
		);
		self::route( '/bookings/(?P<number>[A-Za-z0-9]+)', array( 'GET' => static fn( WP_REST_Request $r ) => self::out( ATC_Trips::detail( self::pid(), (string) $r['number'] ) ) ) );
		self::route( '/bookings/(?P<number>[A-Za-z0-9]+)/ack', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Trips::acknowledge_booking( self::pid(), (string) $r['number'] ) ) ) );
		$leg = static fn( string $action, callable $fn ) => self::route( '/legs/(?P<id>\\d+)/' . $action, array( 'POST' => static fn( WP_REST_Request $r ) => self::out( $fn( (int) $r['id'], self::body( $r ) ) ) ) );
		$leg( 'ack', static fn( $id, $b ) => ATC_Trips::acknowledge( self::pid(), $id ) );
		$leg( 'assign', static fn( $id, $b ) => ATC_Trips::assign( self::pid(), $id, $b ) );
		$leg( 'code', static fn( $id, $b ) => ATC_Trips::complete_with_code( self::pid(), $id, (string) ( $b['code'] ?? '' ) ) );
		$leg( 'complete', static fn( $id, $b ) => ATC_Trips::complete_without_code( self::pid(), $id ) );
		$leg( 'noshow', static fn( $id, $b ) => ATC_Trips::no_show( self::pid(), $id, (string) ( $b['note'] ?? '' ) ) );
		$leg( 'release', static fn( $id, $b ) => ATC_Trips::release( self::pid(), $id, (string) ( $b['reason'] ?? '' ) ) );
		self::route( '/drivers', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Trips::add_driver( self::pid(), self::body( $r ) ) ) ) );

		self::route(
			'/meeting',
			array(
				'GET'  => static function ( WP_REST_Request $r ) use ( $airport ) {
					$aid = $airport( $r );
					return rest_ensure_response( array( 'airports' => ATC_Prices::airports_for( self::pid() ), 'airport_id' => $aid, 'mine' => ATC_Meeting::sets( $aid, self::pid() ), 'ours' => ATC_Meeting::for_booking( $aid, 0 )['sets'] ) );
				},
				'POST' => static function ( WP_REST_Request $r ) use ( $airport ) {
					$res = ATC_Meeting::save( $airport( $r ), self::pid(), (array) ( self::body( $r )['sets'] ?? array() ) );
					return self::out( $res );
				},
			)
		);
		self::route(
			'/prices',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Prices::grid( self::pid(), $airport( $r ) ) ),
				'POST' => static function ( WP_REST_Request $r ) use ( $airport ) {
					$aid = $airport( $r );
					$res = ATC_Prices::save( self::pid(), $aid, (array) ( self::body( $r )['cells'] ?? array() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res + array( 'grid' => ATC_Prices::grid( self::pid(), $aid ) ) );
				},
			)
		);
		self::route(
			'/prices/preview',
			array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Prices::preview( self::pid(), $airport( $r ), (array) ( self::body( $r )['rows'] ?? array() ) ) ) )
		);
		self::route(
			'/prices/apply',
			array(
				'POST' => static function ( WP_REST_Request $r ) use ( $airport ) {
					$b   = self::body( $r );
					$aid = $airport( $r );
					$res = ATC_Prices::apply( self::pid(), $aid, (array) ( $b['lines'] ?? array() ), (array) ( $b['places'] ?? array() ), (array) ( $b['vehicles'] ?? array() ), (string) ( $b['basis'] ?? 'provider' ), (array) ( $b['found'] ?? array() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res + array( 'grid' => ATC_Prices::grid( self::pid(), $aid ) ) );
				},
			)
		);

		self::route(
			'/agreement',
			array(
				'POST' => static function () {
					ATC_Partner::accept_agreement( self::pid() );
					return rest_ensure_response( self::me() );
				},
			)
		);
	}
}
