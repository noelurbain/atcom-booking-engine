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

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => current_user_can( 'atc_manage' ),
				'callback'            => array( __CLASS__, 'status' ),
			)
		);
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
			'rules'          => array(
				'provider_commission' => ATC_Settings::get( 'provider_commission_bp' ) / 100 . '%',
				'affiliate'           => ATC_Settings::get( 'affiliate_bp' ) / 100 . '%',
				'referral'            => ATC_Settings::get( 'referral_bp' ) / 100 . '%',
				'example_100_usd'     => ATC_Money::split( 10000, (int) ATC_Settings::get( 'provider_commission_bp' ) ),
			),
		);
	}
}
