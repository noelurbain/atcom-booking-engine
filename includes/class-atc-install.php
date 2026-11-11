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
		wp_clear_scheduled_hook( 'atc_hourly' );
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
			if ( version_compare( $from, '9', '<' ) ) {
				// The first Hotel Zone outline (copied from resortstours.com) left out Punta Cancún (Riu, Hyatt Ziva…).
				// Replace it only if nobody has redrawn it since.
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . ATC_Schema::table( 'zones' ) . " SET polygon = %s WHERE slug = 'cancun-hotel-zone' AND polygon = %s", self::HOTEL_ZONE, self::HOTEL_ZONE_OLD ) ); // phpcs:ignore
			}
			if ( version_compare( $from, '10', '<' ) ) {
				self::luxury_above_premium();
			}
			if ( version_compare( $from, '15', '<' ) ) {
				self::market_capacities();
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
			// Usual market capacities (passengers, suitcases); each provider can change theirs.
			array( 'executive-sedan', 'Executive Sedan', 3, 2, 10 ),
			array( 'standard-sedan', 'Standard Sedan', 3, 3, 20 ),
			array( 'standard-suv', 'Standard SUV', 4, 4, 25 ),
			// Luxury is the top SUV class (Escalade, Navigator); Premium sits below it (Suburban, Tahoe, Yukon).
			array( 'premium-suv', 'Premium SUV', 6, 6, 30 ),
			array( 'luxury-suv', 'Luxury SUV', 6, 5, 40 ),
			array( 'minivan', 'Minivan', 6, 6, 50 ),
			array( 'minibus', 'Minibus', 14, 14, 60 ),
			array( 'mini-coach', 'Mini Coach', 25, 25, 70 ),
			array( 'midi-coach', 'Midi Coach', 35, 35, 80 ),
			array( 'coach', 'Coach', 49, 49, 90 ),
			array( 'large-coach', 'Large Coach', 57, 57, 100 ),
			// Limousines: shown to providers under "Other categories".
			array( 'lincoln-stretch-limousine', 'Lincoln Stretch Limousine', 8, 4, 200, 'limousine' ),
			array( 'chrysler-300-stretch-limousine', 'Chrysler 300 Stretch Limousine', 10, 4, 210, 'limousine' ),
			array( 'escalade-stretch-limousine', 'Escalade Stretch Limousine', 14, 6, 220, 'limousine' ),
			array( 'suburban-stretch-limousine', 'Suburban Stretch Limousine', 16, 6, 230, 'limousine' ),
			array( 'sprinter-limousine', 'Sprinter Limousine', 14, 8, 240, 'limousine' ),
			array( 'hummer-h2-stretch-limousine', 'Hummer H2 Stretch Limousine', 18, 6, 250, 'limousine' ),
		);
	}

	/**
	 * Luxury SUV becomes the top SUV class, Premium SUV the one below. The old site had it the other way,
	 * and some providers priced Premium above Luxury (Cancun Airport Transportation among them). For each
	 * provider and airport where Premium costs more than Luxury, their two SUV categories trade places,
	 * prices and all, so each vehicle lands in the right class. Providers who priced Luxury higher, or both
	 * the same, already match and are left alone.
	 */
	private static function luxury_above_premium(): void {
		global $wpdb;
		$t   = ATC_Schema::table( 'vehicle_types' );
		$s   = ATC_Schema::table( 'services' );
		$p   = ATC_Schema::table( 'prices' );
		$lux = (int) $wpdb->get_var( "SELECT id FROM {$t} WHERE slug = 'luxury-suv'" ); // phpcs:ignore
		$pre = (int) $wpdb->get_var( "SELECT id FROM {$t} WHERE slug = 'premium-suv'" ); // phpcs:ignore
		if ( ! $lux || ! $pre ) {
			return;
		}
		$wpdb->update( $t, array( 'sort' => 30 ), array( 'id' => $pre ) );
		$wpdb->update( $t, array( 'sort' => 40 ), array( 'id' => $lux ) );
		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sl.id lux_id, sp.id pre_id, sl.party_id, sl.airport_id,
					(SELECT AVG(retail_cents) FROM {$p} WHERE service_id = sl.id AND direction = 'one_way') lux_avg,
					(SELECT AVG(retail_cents) FROM {$p} WHERE service_id = sp.id AND direction = 'one_way') pre_avg
				FROM {$s} sl JOIN {$s} sp ON sp.party_id = sl.party_id AND sp.airport_id = sl.airport_id AND sp.vehicle_type_id = %d
				WHERE sl.vehicle_type_id = %d",
				$pre,
				$lux
			),
			ARRAY_A
		); // phpcs:ignore
		$swapped = array();
		foreach ( $pairs as $r ) {
			if ( null === $r['lux_avg'] || null === $r['pre_avg'] || (float) $r['pre_avg'] <= (float) $r['lux_avg'] ) {
				continue;
			}
			// Trade categories through a free id so the (provider, airport, category) key never clashes.
			$wpdb->update( $s, array( 'vehicle_type_id' => 0 ), array( 'id' => (int) $r['lux_id'] ) );
			$wpdb->update( $s, array( 'vehicle_type_id' => $lux ), array( 'id' => (int) $r['pre_id'] ) );
			$wpdb->update( $s, array( 'vehicle_type_id' => $pre ), array( 'id' => (int) $r['lux_id'] ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . ATC_Schema::table( 'aliases' ) . " SET target_id = IF(target_id = %d, %d, %d) WHERE party_id = %d AND kind = 'vehicle' AND target_id IN (%d, %d)", $lux, $pre, $lux, (int) $r['party_id'], $lux, $pre ) ); // phpcs:ignore
			$swapped[] = array( (int) $r['party_id'], (int) $r['airport_id'] );
		}
		self::log( 'vehicle_types', $lux, 'luxury_above_premium', array( 'swapped' => $swapped ) );
	}

	/**
	 * The first capacities were placeholders (a sedan for 2). Categories and the services still on the old
	 * placeholder get the usual market numbers; anything a provider or our team changed stays as it is.
	 */
	public static function market_capacities(): void {
		global $wpdb;
		$old   = array(
			'executive-sedan' => array( 2, 2 ), 'standard-sedan' => array( 2, 2 ), 'premium-suv' => array( 5, 5 ), 'luxury-suv' => array( 5, 5 ),
			'minibus' => array( 12, 12 ), 'coach' => array( 45, 45 ), 'large-coach' => array( 55, 55 ),
			'chrysler-300-stretch-limousine' => array( 8, 4 ), 'escalade-stretch-limousine' => array( 12, 6 ), 'suburban-stretch-limousine' => array( 14, 6 ),
			'sprinter-limousine' => array( 12, 8 ), 'hummer-h2-stretch-limousine' => array( 16, 6 ),
		);
		$types = ATC_Schema::table( 'vehicle_types' );
		$svc   = ATC_Schema::table( 'services' );
		foreach ( self::vehicle_type_rows() as $row ) {
			if ( ! isset( $old[ $row[0] ] ) ) {
				continue;
			}
			[ $op, $ob ] = $old[ $row[0] ];
			$id          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types} WHERE slug = %s", $row[0] ) ); // phpcs:ignore
			if ( ! $id ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$types} SET max_passengers = %d, max_bags = %d WHERE id = %d AND max_passengers = %d AND max_bags = %d", $row[2], $row[3], $id, $op, $ob ) ); // phpcs:ignore
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$svc} SET max_passengers = %d, max_bags = %d WHERE vehicle_type_id = %d AND max_passengers = %d AND max_bags = %d", $row[2], $row[3], $id, $op, $ob ) ); // phpcs:ignore
			self::log( 'vehicle_type', $id, 'capacity_updated', array( 'pax' => $row[2], 'bags' => $row[3], 'services' => (int) $n ) );
		}
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
		foreach ( $rows as $row ) {
			[ $slug, $name, $pax, $bags, $sort ] = $row;
			$category                            = $row[5] ?? 'standard';
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types} WHERE slug = %s", $slug ) ) ) {
				$wpdb->insert( $types, array( 'slug' => $slug, 'name' => $name, 'max_passengers' => $pax, 'max_bags' => $bags, 'sort' => $sort, 'category' => $category ) );
			}
		}
	}

	/**
	 * The 23 Cancún zones, by name, as on resortstours.com. Two already have shapes
	 * (Cancun Hotel Zone and Playa Mujeres); the rest get drawn in /control.
	 * Only adds names that are not there yet, so it never duplicates or overwrites.
	 */
	/** Cancún Hotel Zone: the whole strip from the Playa Linda bridge to Punta Nizuc, sea side to mid-lagoon. */
	const HOTEL_ZONE     = '[[21.16,-86.818],[21.163,-86.805],[21.1605,-86.78],[21.154,-86.748],[21.146,-86.73],[21.13,-86.725],[21.105,-86.739],[21.075,-86.751],[21.045,-86.762],[21.023,-86.771],[21.017,-86.785],[21.03,-86.796],[21.055,-86.789],[21.08,-86.783],[21.105,-86.777],[21.125,-86.77],[21.134,-86.78],[21.139,-86.8],[21.15,-86.812]]';
	const HOTEL_ZONE_OLD = '[[21.162055,-86.820107],[21.155651,-86.783715],[21.129394,-86.737023],[21.089678,-86.751443],[21.020472,-86.774789],[20.975598,-86.800881],[20.977522,-86.869546]]';

	public static function seed_cun_zones(): void {
		global $wpdb;
		$airport = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ATC_Schema::table( 'airports' ) . ' WHERE iata = %s', 'CUN' ) );
		if ( ! $airport ) {
			return;
		}
		$zones = ATC_Schema::table( 'zones' );
		$list  = array(
			array( 'Cancun Downtown', null ),
			array( 'Cancun Hotel Zone', self::HOTEL_ZONE ),
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
