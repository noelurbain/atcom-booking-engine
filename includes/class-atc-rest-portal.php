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
		$airport = ATC_Catalog::airport( (int) ( $party['home_airport_id'] ?: 0 ) );
		if ( ! $airport ) {
			$all     = ATC_Catalog::airports();
			$airport = $all ? $all[0] : null;
		}
		$zones = $airport ? array_values( array_filter( ATC_Catalog::zones( (int) $airport['id'] ), static fn( $z ) => ! empty( $z['polygon'] ) ) ) : array();
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
		);
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
