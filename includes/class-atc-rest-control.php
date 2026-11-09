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
				'GET'  => static fn() => rest_ensure_response( ATC_Catalog::parties( 'provider' ) ),
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
						)
					);
				},
				'PUT' => static fn( WP_REST_Request $r ) => self::out( ATC_Catalog::save_party( self::body( $r ), (int) $r['id'] ) ),
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
