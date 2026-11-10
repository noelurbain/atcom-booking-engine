<?php
/**
 * Activation, upgrades and starting data (Cancún airport and the six vehicle categories).
 * Deactivating never deletes data; uninstall.php only does when atc_delete_data_on_uninstall is set.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Install {

	public static function activate(): void {
		self::create_tables();
		ATC_Roles::add();
		if ( false === get_option( ATC_Settings::OPTION ) ) {
			add_option( ATC_Settings::OPTION, ATC_Settings::defaults(), '', false );
		}
		self::seed();
		self::seed_cun_zones();
		update_option( 'atc_db_version', ATC_DB_VERSION, false );
		update_option( 'atc_flush_rewrite', 1, false );
		self::log( 'plugin', 0, 'activated', array( 'version' => ATC_VERSION ) );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'atc_every_15_minutes' );
		wp_clear_scheduled_hook( 'atc_daily' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'atc_db_version' ) !== ATC_DB_VERSION ) {
			$from = (string) get_option( 'atc_db_version', '0' );
			self::create_tables();
			ATC_Roles::add();
			if ( version_compare( $from, '3', '<' ) ) {
				self::seed_cun_zones();
			}
			if ( version_compare( $from, '5', '<' ) ) {
				// Airports a provider adds from Google have no IATA code until our team fills it in.
				global $wpdb;
				$wpdb->query( 'ALTER TABLE ' . ATC_Schema::table( 'airports' ) . ' MODIFY iata char(3) NULL' ); // phpcs:ignore
			}
			self::seed();
			update_option( 'atc_db_version', ATC_DB_VERSION, false );
			update_option( 'atc_flush_rewrite', 1, false );
		}
	}

	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( ATC_Schema::sql() as $statement ) {
			dbDelta( $statement );
		}
	}

	/** The vehicle categories we sell, smallest first (same 11 as the old airporttransportation.com). */
	public static function vehicle_type_rows(): array {
		return array(
			array( 'executive-sedan', 'Executive Sedan', 2, 2, 10 ),
			array( 'standard-sedan', 'Standard Sedan', 2, 2, 20 ),
			array( 'standard-suv', 'Standard SUV', 4, 4, 25 ),
			array( 'luxury-suv', 'Luxury SUV', 5, 5, 30 ),
			array( 'premium-suv', 'Premium SUV', 5, 5, 40 ),
			array( 'minivan', 'Minivan', 6, 6, 50 ),
			array( 'minibus', 'Minibus', 12, 12, 60 ),
			array( 'mini-coach', 'Mini Coach', 25, 25, 70 ),
			array( 'midi-coach', 'Midi Coach', 35, 35, 80 ),
			array( 'coach', 'Coach', 45, 45, 90 ),
			array( 'large-coach', 'Large Coach', 55, 55, 100 ),
		);
	}

	public static function seed(): void {
		global $wpdb;

		$airports = ATC_Schema::table( 'airports' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$airports} WHERE iata = %s", 'CUN' ) ) ) {
			$wpdb->insert(
				$airports,
				array(
					'iata'     => 'CUN',
					'name'     => 'Cancún International Airport',
					'city'     => 'Cancún',
					'country'  => 'MX',
					'timezone' => 'America/Cancun',
					'lat'      => 21.0365,
					'lng'      => -86.8771,
					'status'   => 'active',
				)
			);
		}

		$types = ATC_Schema::table( 'vehicle_types' );
		$rows  = self::vehicle_type_rows();
		foreach ( $rows as [ $slug, $name, $pax, $bags, $sort ] ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types} WHERE slug = %s", $slug ) ) ) {
				$wpdb->insert( $types, array( 'slug' => $slug, 'name' => $name, 'max_passengers' => $pax, 'max_bags' => $bags, 'sort' => $sort ) );
			}
		}
	}

	/**
	 * The 23 Cancún zones, by name, as on resortstours.com. Two already have shapes
	 * (Cancun Hotel Zone and Playa Mujeres); the rest get drawn in /control.
	 * Only adds names that are not there yet, so it never duplicates or overwrites.
	 */
	public static function seed_cun_zones(): void {
		global $wpdb;
		$airport = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ATC_Schema::table( 'airports' ) . ' WHERE iata = %s', 'CUN' ) );
		if ( ! $airport ) {
			return;
		}
		$zones = ATC_Schema::table( 'zones' );
		$list  = array(
			array( 'Cancun Downtown', null ),
			array( 'Cancun Hotel Zone', '[[21.162055,-86.820107],[21.155651,-86.783715],[21.129394,-86.737023],[21.089678,-86.751443],[21.020472,-86.774789],[20.975598,-86.800881],[20.977522,-86.869546]]' ),
			array( 'Puerto Morelos', null ),
			array( 'Playa Mujeres', '[[21.26269,-86.818412],[21.264129,-86.810601],[21.272158,-86.726938],[21.204667,-86.679667],[21.16183,-86.799357],[21.162971,-86.823304],[21.16183,-86.799357],[21.16199,-86.822146],[21.171755,-86.822274],[21.162971,-86.823304]]' ),
			array( 'Isla Mujeres', null ),
			array( 'Playa Paraiso', null ),
			array( 'Playa del Carmen', null ),
			array( 'Costa Mujeres', null ),
			array( 'Cozumel', null ),
			array( 'Puerto Aventuras', null ),
			array( 'Akumal', null ),
			array( 'Tulum', null ),
			array( 'Tulum Hotel Zone', null ),
			array( 'Mahahual', null ),
			array( 'Holbox', null ),
			array( 'Valladolid', null ),
			array( 'Merida', null ),
			array( 'Bacalar', null ),
			array( 'Coba', null ),
			array( 'Sian Kaan', null ),
			array( 'Tulum Arco', null ),
			array( 'Ferry & Playacar', null ),
			array( 'Isla Blanca', null ),
		);
		foreach ( $list as $i => [ $name, $poly ] ) {
			$slug = sanitize_title( $name );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$zones} WHERE airport_id = %d AND (slug = %s OR name = %s)", $airport, $slug, $name ) ) ) {
				continue;
			}
			$wpdb->insert(
				$zones,
				array(
					'airport_id' => (int) $airport,
					'name'       => $name,
					'slug'       => $slug,
					'polygon'    => $poly,
					'sort'       => ( $i + 1 ) * 10,
					'status'     => 'active',
				)
			);
		}
		if ( class_exists( 'ATC_Catalog' ) ) {
			ATC_Catalog::rematch_places( (int) $airport );
		}
	}

	/** Write one line to the audit log. */
	public static function log( string $object_type, int $object_id, string $action, array $data = array() ): void {
		global $wpdb;
		$wpdb->insert(
			ATC_Schema::table( 'events' ),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'action'      => $action,
				'user_id'     => get_current_user_id() ?: null,
				'data'        => $data ? wp_json_encode( $data ) : null,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}
}
