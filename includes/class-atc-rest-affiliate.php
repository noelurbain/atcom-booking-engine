<?php
/**
 * REST routes for the affiliate panel: /wp-json/atc/v1/affiliate/...
 * An affiliate only ever reads its own numbers.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_REST_Affiliate {

	const NS = 'atc/v1';

	public static function can(): bool {
		return null !== ATC_Affiliates::current_id();
	}

	private static function aid(): int {
		return (int) ATC_Affiliates::current_id();
	}

	private static function route( string $path, array $methods ): void {
		$args = array();
		foreach ( $methods as $method => $cb ) {
			$args[] = array( 'methods' => $method, 'callback' => $cb, 'permission_callback' => array( __CLASS__, 'can' ) );
		}
		register_rest_route( self::NS, '/affiliate' . $path, $args );
	}

	private static function out( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function body( WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		return is_array( $json ) ? $json : $r->get_body_params();
	}

	public static function register_routes(): void {
		self::route( '/me', array( 'GET' => static fn() => rest_ensure_response( ATC_Affiliates::me( self::aid() ) + array( 'account' => ATC_Partner::account( get_current_user_id() ) ) ) ) );
		self::route( '/bookings', array( 'GET' => static fn( WP_REST_Request $r ) => rest_ensure_response( ATC_Affiliates::bookings( self::aid(), max( 1, (int) $r['page'] ) ) ) ) );
		self::route( '/clicks', array( 'GET' => static fn() => rest_ensure_response( ATC_Affiliates::clicks( self::aid() ) ) ) );
		self::route( '/link', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Affiliates::make_link( self::aid(), (string) ( self::body( $r )['url'] ?? '' ) ) ) ) );
		self::route( '/coupons', array( 'GET' => static fn() => rest_ensure_response( ATC_Coupons::for_party( self::aid() ) ), 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Coupons::save( self::body( $r ), 0, self::aid() ) ) ) );
		self::route( '/coupons/(?P<id>\\d+)', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Coupons::save( self::body( $r ), (int) $r['id'], self::aid() ) ) ) );
		self::route( '/payout', array( 'POST' => static fn( WP_REST_Request $r ) => self::out( ATC_Partner::save_payout( self::aid(), self::body( $r ) ) ) ) );
		self::route( '/payouts/(?P<id>\\d+)/lines', array( 'GET' => static function ( WP_REST_Request $r ) {
			global $wpdb;
			$own = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT party_id FROM ' . ATC_Schema::table( 'payouts' ) . ' WHERE id = %d', (int) $r['id'] ) );
			return $own === self::aid() ? rest_ensure_response( ATC_Payouts::lines( (int) $r['id'] ) ) : new WP_Error( 'atc_not_found', 'Payout not found.', array( 'status' => 404 ) );
		} ) );
		self::route( '/account/password', array( 'POST' => static function ( WP_REST_Request $r ) {
			$b = self::body( $r );
			return self::out( ATC_Partner::change_password( get_current_user_id(), (string) ( $b['current'] ?? '' ), (string) ( $b['new'] ?? '' ) ) );
		} ) );
		self::route( '/account/email', array( 'POST' => static function ( WP_REST_Request $r ) {
			$b = self::body( $r );
			return self::out( ATC_Partner::request_email_change( get_current_user_id(), (string) ( $b['email'] ?? '' ), (string) ( $b['current'] ?? '' ) ) );
		} ) );
	}
}
