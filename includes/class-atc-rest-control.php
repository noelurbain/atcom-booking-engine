<?php
/**
 * REST routes for the control panel: /wp-json/atc/v1/control/...
 * Staff only (atc_manage). The panel sends the WordPress REST nonce with every request.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_REST_Control {

	const NS = 'atc/v1';

	public static function can(): bool {
		return current_user_can( 'atc_manage' );
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
		register_rest_route( self::NS, '/control' . $path, $args );
	}

	/** Wrap a result: WP_Error passes through, everything else becomes a 200 response. */
	private static function out( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function body( WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		return is_array( $json ) ? $json : $r->get_body_params();
	}

	public static function register_routes(): void {
		self::route(
			'/bootstrap',
			array(
				'GET' => static fn() => rest_ensure_response(
					array(
						'airports'      => ATC_Catalog::airports(),
						'vehicle_types' => ATC_Catalog::vehicle_types(),
						'commission_bp' => (int) ATC_Settings::get( 'provider_commission_bp' ),
					)
				),
			)
		);

		self::route(
			'/bookings',
			array( 'GET' => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Bookings::list( array( 'status' => $r['status'], 'q' => $r['q'], 'provider' => $r['provider'], 'page' => $r['page'] ) ) ) )
		);
		self::route(
			'/bookings/(?P<number>[A-Za-z0-9]+)',
			array( 'GET' => static fn( WP_REST_Request $r ) => self::out( ATC_Bookings::get( (string) $r['number'] ) ) )
		);
		self::route(
			'/bookings/(?P<number>[A-Za-z0-9]+)/notes',
			array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Bookings::add_note( (string) $r['number'], (string) ( self::body( $r )['text'] ?? '' ) ) ) )
		);
		self::route(
			'/demand/(?P<id>\\d+)',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					global $wpdb;
					$wpdb->update( ATC_Schema::table( 'demand' ), array( 'status' => 'ignored' ), array( 'id' => (int) $r['id'] ) );
					return rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);
		self::route( '/dashboard', array( 'GET' => static fn() => rest_ensure_response( ATC_Dashboard::data() ) ) );

		self::route(
			'/zones',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Catalog::zones( (int) $r['airport_id'] ) ),
				'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_zone( self::body( $r ) ) ),
			)
		);
		self::route(
			'/zones/(?P<id>\d+)',
			array(
				'PUT'    => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_zone( self::body( $r ), (int) $r['id'] ) ),
				'DELETE' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::archive_zone( (int) $r['id'] ) ),
			)
		);

		self::route(
			'/zones/(?P<id>\d+)/suggest',
			array(
				'GET' => static function ( WP_REST_Request $r ) {
					$zone = ATC_Catalog::zone( (int) $r['id'] );
					if ( ! $zone ) {
						return new WP_Error( 'atc_not_found', 'Zone not found.', array( 'status' => 404 ) );
					}
					$name    = trim( (string) ( $r['q'] ?? '' ) ) ?: $zone['name'];
					$airport = ATC_Catalog::airport( (int) $zone['airport_id'] );
					[ $list, $errors ] = ATC_Geo::shape_suggestions( sanitize_text_field( $name ), $airport );
					return rest_ensure_response( array( 'query' => $name, 'candidates' => $list, 'errors' => $errors ) );
				},
			)
		);
		self::route(
			'/zones/(?P<id>\d+)/tiles',
			array(
				'GET' => static function ( WP_REST_Request $r ) {
					$tiles = ATC_Catalog::zone_tiles( (int) $r['id'] );
					return is_wp_error( $tiles ) ? $tiles : rest_ensure_response( array( 'count' => count( $tiles ) ) );
				},
			)
		);
		self::route(
			'/zones/(?P<id>\d+)/import-tile',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Catalog::import_zone_tile( (int) $r['id'], (int) ( $b['index'] ?? 0 ) ) );
				},
			)
		);
		self::route(
			'/zone-at',
			array(
				'GET' => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Catalog::zone_at( (int) $r['airport_id'], (float) $r['lat'], (float) $r['lng'], sanitize_text_field( (string) $r['place_id'] ) ) ),
			)
		);
		self::route(
			'/places',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => rest_ensure_response(
					ATC_Catalog::places(
						(int) $r['airport_id'],
						array(
							'zone_id'   => (int) $r['zone_id'],
							'unmatched' => (bool) $r['unmatched'],
							'search'    => (string) $r['search'],
						)
					)
				),
				'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_place( self::body( $r ) ) ),
			)
		);
		self::route(
			'/places/import',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return rest_ensure_response( ATC_Catalog::import_places( (int) ( $b['airport_id'] ?? 0 ), (array) ( $b['lines'] ?? array() ) ) );
				},
			)
		);
		self::route(
			'/places/(?P<id>\d+)',
			array(
				'PUT'    => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Catalog::set_place_zone( (int) $r['id'], $b['zone_id'] ?? null ) );
				},
				'DELETE' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::archive_place( (int) $r['id'] ) ),
			)
		);

		self::route(
			'/providers',
			array(
				'GET'  => static function () {
					$rows = ATC_Catalog::parties( 'provider' );
					foreach ( $rows as &$row ) {
						$row['portal'] = ATC_Partner::portal_state( (int) $row['id'] );
					}
					return rest_ensure_response( array( 'providers' => $rows, 'invite_test_to' => (string) ATC_Settings::get( 'invite_test_to' ) ) );
				},
				'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_party( self::body( $r ) ) ),
			)
		);
		self::route(
			'/providers/(?P<id>\d+)',
			array(
				'GET' => static function ( WP_REST_Request $r ) {
					$id    = (int) $r['id'];
					$party = ATC_Catalog::party( $id );
					if ( ! $party ) {
						return new WP_Error( 'atc_not_found', 'Provider not found.', array( 'status' => 404 ) );
					}
					return rest_ensure_response(
						array(
							'provider' => $party,
							'services' => ATC_Catalog::services( $id ),
							'vehicles' => ATC_Catalog::vehicles( $id ),
							'documents' => ATC_Partner::documents( $id ),
							'doc_types' => ATC_Partner::doc_types(),
							'steps'     => ATC_Partner::steps( $id ),
							'payout'    => ATC_Partner::payout_summary( $id ),
							'portal'    => ATC_Partner::portal_state( $id ),
							'prices'    => ATC_Catalog::price_table( $id ),
						)
					);
				},
				'PUT' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_party( self::body( $r ), (int) $r['id'] ) ),
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/invite',
			array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Partner::invite( (int) $r['id'] ) ) ),
		);
		self::route(
			'/providers/invite-all',
			array(
				'POST' => static function () {
					global $wpdb;
					$ids  = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . ATC_Schema::table( 'parties' ) . " WHERE type = 'provider' AND invited_at IS NULL AND status <> 'archived' AND email IS NOT NULL AND email <> '' ORDER BY id" ) );
					$sent = 0;
					$fail = array();
					foreach ( array_slice( $ids, 0, 40 ) as $id ) {
						if ( 'active' === ATC_Partner::portal_state( $id )['state'] ) {
							continue;
						}
						$res = ATC_Partner::invite( $id );
						if ( is_wp_error( $res ) ) {
							$fail[] = ATC_Catalog::party( $id )['name'] . ': ' . $res->get_error_message();
						} else {
							++$sent;
						}
					}
					return rest_ensure_response( array( 'sent' => $sent, 'failed' => $fail, 'remaining' => max( 0, count( $ids ) - 40 ), 'test_to' => (string) ATC_Settings::get( 'invite_test_to' ) ) );
				},
			)
		);
		self::route(
			'/import',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$files = $r->get_file_params();
					if ( empty( $files['file']['tmp_name'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
						return new WP_Error( 'atc_import', 'Choose the import file first.', array( 'status' => 400 ) );
					}
					if ( $files['file']['size'] > 20 * MB_IN_BYTES ) {
						return new WP_Error( 'atc_import', 'The file is too large.', array( 'status' => 400 ) );
					}
					$data = json_decode( (string) file_get_contents( $files['file']['tmp_name'] ), true ); // phpcs:ignore
					if ( ! is_array( $data ) ) {
						return new WP_Error( 'atc_import', 'The file could not be read.', array( 'status' => 400 ) );
					}
					return rest_ensure_response( ATC_Migration::import( $data, '1' === (string) $r['dry_run'] ) );
				},
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/prices',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Prices::grid( (int) $r['id'], (int) $r['airport_id'] ?: (int) ( ATC_Prices::airports_for( (int) $r['id'] )[0]['id'] ?? 0 ) ) ),
				'POST' => static function ( WP_REST_Request $r ) {
					$b   = self::body( $r );
					$aid = (int) ( $b['airport_id'] ?? 0 );
					$res = ATC_Prices::save( (int) $r['id'], $aid, (array) ( $b['cells'] ?? array() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res + array( 'grid' => ATC_Prices::grid( (int) $r['id'], $aid ) ) );
				},
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/prices/preview',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Prices::preview( (int) $r['id'], (int) ( $b['airport_id'] ?? 0 ), (array) ( $b['rows'] ?? array() ) ) );
				},
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/prices/apply',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b   = self::body( $r );
					$aid = (int) ( $b['airport_id'] ?? 0 );
					$res = ATC_Prices::apply( (int) $r['id'], $aid, (array) ( $b['lines'] ?? array() ), (array) ( $b['places'] ?? array() ), (array) ( $b['vehicles'] ?? array() ), (string) ( $b['basis'] ?? 'retail' ), (array) ( $b['found'] ?? array() ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res + array( 'grid' => ATC_Prices::grid( (int) $r['id'], $aid ) ) );
				},
			)
		);
		self::route( '/zones/proposed', array( 'GET' => static fn() => rest_ensure_response( ATC_Prices::proposed_zones() ) ) );
		self::route(
			'/zones/(?P<id>\d+)/decide',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Prices::decide_zone( (int) $r['id'], ! empty( $b['approve'] ) ) );
				},
			)
		);
		self::route(
			'/documents/(?P<id>\d+)/review',
			array(
				'POST' => static function ( WP_REST_Request $r ) {
					$b = self::body( $r );
					return self::out( ATC_Partner::review_document( (int) $r['id'], (string) ( $b['status'] ?? '' ), (string) ( $b['note'] ?? '' ) ) );
				},
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/services',
			array(
				'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_service( (int) $r['id'], self::body( $r ) ) ),
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/vehicles',
			array(
				'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_vehicle( (int) $r['id'], self::body( $r ) ) ),
			)
		);
		self::route(
			'/providers/(?P<id>\d+)/vehicles/(?P<vid>\d+)',
			array(
				'PUT'    => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_vehicle( (int) $r['id'], self::body( $r ), (int) $r['vid'] ) ),
				'DELETE' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::archive_vehicle( (int) $r['id'], (int) $r['vid'] ) ),
			)
		);
	}
}
