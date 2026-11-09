<?php
/**
 * Roles and capabilities.
 * Our team: administrators and atc_staff get atc_manage. Partners get only their portal.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Roles {

	public static function roles(): array {
		return array(
			'atc_staff'     => array( __( 'Booking staff', 'atcom-booking-engine' ), array( 'read' => true, 'atc_manage' => true, 'atc_refund' => false, 'atc_payouts' => false ) ),
			'atc_provider'  => array( __( 'Transport provider', 'atcom-booking-engine' ), array( 'read' => true, 'atc_provider_portal' => true ) ),
			'atc_affiliate' => array( __( 'Affiliate', 'atcom-booking-engine' ), array( 'read' => true, 'atc_affiliate_portal' => true ) ),
			'atc_agent'     => array( __( 'Travel agent', 'atcom-booking-engine' ), array( 'read' => true, 'atc_affiliate_portal' => true, 'atc_book_for_client' => true ) ),
		);
	}

	public static function add(): void {
		foreach ( self::roles() as $slug => [ $label, $caps ] ) {
			remove_role( $slug );
			add_role( $slug, $label, $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'atc_manage', 'atc_refund', 'atc_payouts', 'atc_settings' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function remove(): void {
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'atc_manage', 'atc_refund', 'atc_payouts', 'atc_settings' ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
