<?php
/**
 * REST API: /wp-json/atc/v1. Every website, panel and the app talk to the engine through it.
 * Milestone 1 ships the health check; later milestones add search, bookings and the Crisp MCP tools.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_REST {

	const NS = 'atc/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => rest_ensure_response( array( 'ok' => true, 'version' => ATC_VERSION ) ),
			)
		);

		// Airport search for the provider sign-up form: our airports first, Google for the rest.
		register_rest_route(
			self::NS,
			'/airports/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'airport_search' ),
			)
		);

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => current_user_can( 'atc_manage' ),
				'callback'            => array( __CLASS__, 'status' ),
			)
		);

		// Checkout, for travelers (no account): the booking number and its private key open it.
		register_rest_route(
			self::NS,
			'/checkout/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => self::out( ATC_Checkout::start( (array) $r->get_json_params() ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/checkout/details',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$p = (array) $r->get_json_params();
					if ( ! ATC_Partner::rate_ok( 'checkout_details', 60 ) ) {
						return new WP_Error( 'atc_slow', 'Too many attempts. Please wait a few minutes.', array( 'status' => 429 ) );
					}
					return self::out( ATC_Checkout::details_save( (string) ( $p['number'] ?? '' ), (string) ( $p['key'] ?? '' ), $p ) );
				},
			)
		);
		self::register_manage();
	}

	public static function register_manage(): void {
		register_rest_route(
			self::NS,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => self::out( ATC_Checkout::webhook( (string) $r->get_body(), (string) $r->get_header( 'stripe-signature' ) ) ),
			)
		);
		$open = static function ( string $bucket, callable $fn ) {
			return static function ( WP_REST_Request $r ) use ( $bucket, $fn ) {
				if ( ! ATC_Partner::rate_ok( $bucket, 30 ) ) {
					return new WP_Error( 'atc_slow', 'Too many attempts. Please wait a few minutes.', array( 'status' => 429 ) );
				}
				$p = (array) $r->get_json_params();
				return self::out( $fn( $p, (string) ( $p['number'] ?? '' ), (string) ( $p['key'] ?? '' ) ) );
			};
		};
		$routes = array(
			'/booking/update' => $open( 'booking_update', static fn( $p, $n, $k ) => ATC_Manage::update( $n, $k, $p ) ),
			'/booking/cancel' => $open( 'booking_cancel', static fn( $p, $n, $k ) => ATC_Manage::cancel( $n, $k ) ),
			'/booking/review' => $open( 'booking_review', static fn( $p, $n, $k ) => ATC_Manage::review( $n, $k, (int) ( $p['rating'] ?? 0 ), (string) ( $p['comment'] ?? '' ) ) ),
			'/booking/find'   => $open( 'booking_find', static fn( $p, $n, $k ) => ATC_Manage::lookup( $n, (string) ( $p['email'] ?? '' ) ) ),
		);
		foreach ( $routes as $path => $cb ) {
			register_rest_route( self::NS, $path, array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => $cb ) );
		}
	}

	private static function out( $res ) {
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function airport_search( WP_REST_Request $r ): WP_REST_Response {
		$q    = mb_substr( trim( sanitize_text_field( (string) $r['q'] ) ), 0, 80 );
		$out  = array();
		$seen = array();
		$resp = array( 'results' => array(), 'google' => 'no' );
		if ( mb_strlen( $q ) < 2 ) {
			return rest_ensure_response( $resp );
		}
		$local = static function ( array $a ) use ( &$out, &$seen ): void {
			if ( isset( $seen[ (int) $a['id'] ] ) ) {
				return;
			}
			$seen[ (int) $a['id'] ] = true;
			$out[]                  = array(
				'id'    => (int) $a['id'],
				'label' => ATC_Catalog::airport_label( $a ),
				'iata'  => $a['iata'] ?? null,
				'sub'   => ! empty( $a['iata'] ) ? $a['name'] : trim( $a['city'] . ', ' . $a['country'], ', ' ),
			);
		};
		foreach ( ATC_Catalog::search_airports( $q ) as $a ) {
			$local( $a );
		}
		// Google only when we have nothing, or the visitor asks for more. Limited per visitor: each search costs money.
		// Travelers (local=1) only ever pick airports we serve.
		if ( empty( $r['local'] ) && ( ! $out || ! empty( $r['more'] ) ) ) {
			$resp['google'] = 'failed';
			if ( ATC_Partner::rate_ok( 'airports', 40 ) ) {
				[ $list, $err ] = ATC_Geo::search_airports( $q );
				if ( null === $err ) {
					$resp['google'] = 'yes';
					foreach ( $list as $g ) {
						$mine = ATC_Catalog::airport_matching( $g['google_place_id'], $g['lat'], $g['lng'] );
						if ( $mine ) {
							$local( $mine );
							continue;
						}
						$out[] = array(
							'place_id' => $g['google_place_id'],
							'label'    => $g['name'],
							'sub'      => trim( $g['city'] . ', ' . ( $g['country_name'] ?: $g['country'] ), ', ' ),
							'new'      => true,
						);
					}
				}
			}
		}
		$resp['results'] = $out;
		$res             = rest_ensure_response( $resp );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function status(): WP_REST_Response {
		return rest_ensure_response( self::status_data() );
	}

	public static function status_data(): array {
		global $wpdb;
		$missing = ATC_Schema::missing();
		$mode    = ATC_Settings::get( 'stripe_mode' );
		return array(
			'version'        => ATC_VERSION,
			'db_version'     => get_option( 'atc_db_version' ),
			'tables_total'   => count( ATC_Schema::tables() ),
			'tables_missing' => $missing,
			'airports'       => $missing ? null : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ATC_Schema::table( 'airports' ) ),
			'vehicle_types'  => $missing ? null : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ATC_Schema::table( 'vehicle_types' ) ),
			'stripe'         => array(
				'mode'                => $mode,
				'test_secret_set'     => ATC_Settings::has_secret( 'test' ),
				'live_secret_set'     => ATC_Settings::has_secret( 'live' ),
				'publishable_set'     => '' !== (string) ATC_Settings::get( 'live' === $mode ? 'stripe_live_publishable' : 'stripe_test_publishable' ),
				'webhook_secret_set'  => '' !== ATC_Settings::webhook_secret(),
			),
			'maps'           => array(
				'browser_key_set' => '' !== ATC_Settings::maps_browser_key(),
				'server_key_set'  => '' !== ATC_Settings::maps_server_key(),
			),
			'rules'          => array(
				'provider_commission' => ATC_Settings::get( 'provider_commission_bp' ) / 100 . '%',
				'affiliate'           => ATC_Settings::get( 'affiliate_bp' ) / 100 . '%',
				'referral'            => ATC_Settings::get( 'referral_bp' ) / 100 . '%',
				'example_100_usd'     => ATC_Money::split( 10000, (int) ATC_Settings::get( 'provider_commission_bp' ) ),
			),
		);
	}
}
