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
		update_option( 'atc_db_version', ATC_DB_VERSION, false );
		self::log( 'plugin', 0, 'activated', array( 'version' => ATC_VERSION ) );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'atc_every_15_minutes' );
		wp_clear_scheduled_hook( 'atc_daily' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'atc_db_version' ) !== ATC_DB_VERSION ) {
			self::create_tables();
			ATC_Roles::add();
			update_option( 'atc_db_version', ATC_DB_VERSION, false );
		}
	}

	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( ATC_Schema::sql() as $statement ) {
			dbDelta( $statement );
		}
	}

	private static function seed(): void {
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
		$rows  = array(
			array( 'executive-sedan', 'Executive Sedan', 2, 2, 10 ),
			array( 'standard-sedan', 'Standard Sedan', 2, 2, 20 ),
			array( 'luxury-suv', 'Luxury SUV', 5, 5, 30 ),
			array( 'premium-suv', 'Premium SUV', 5, 5, 40 ),
			array( 'minivan', 'Minivan', 6, 6, 50 ),
			array( 'minibus', 'Minibus', 12, 12, 60 ),
		);
		foreach ( $rows as [ $slug, $name, $pax, $bags, $sort ] ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types} WHERE slug = %s", $slug ) ) ) {
				$wpdb->insert( $types, array( 'slug' => $slug, 'name' => $name, 'max_passengers' => $pax, 'max_bags' => $bags, 'sort' => $sort ) );
			}
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
