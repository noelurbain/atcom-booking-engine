<?php
/**
 * Import from the old airporttransportation.com: airports, providers, their coverage areas (as our zones),
 * vehicle categories and prices. Safe to run again: everything is matched by the old ids, nothing doubles.
 *
 * Prices keep what travelers paid on the old site; the provider amount follows from today's commission (25%).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Migration {

	/** Plausible one-way price range in USD cents; anything outside is held back. */
	const MIN_CENTS = 1000;
	const MAX_CENTS = 500000;

	const SOURCE = 'atcom-old';

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function legacy_get( string $type, $legacy_id ): ?int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT new_id FROM ' . self::t( 'legacy' ) . ' WHERE source = %s AND object_type = %s AND legacy_id = %s', self::SOURCE, $type, (string) $legacy_id ) );
		return $id ? (int) $id : null;
	}

	private static function legacy_set( string $type, $legacy_id, int $new_id ): void {
		global $wpdb;
		$wpdb->replace( self::t( 'legacy' ), array( 'source' => self::SOURCE, 'object_type' => $type, 'legacy_id' => (string) $legacy_id, 'new_id' => $new_id, 'created_at' => self::now() ) );
	}

	public static function alias_set( int $party_id, string $kind, string $alias, int $target_id ): void {
		global $wpdb;
		$alias = mb_substr( trim( $alias ), 0, 190 );
		if ( '' === $alias ) {
			return;
		}
		$wpdb->replace( self::t( 'aliases' ), array( 'party_id' => $party_id, 'kind' => $kind, 'alias' => $alias, 'target_id' => $target_id, 'created_at' => self::now() ) );
	}

	/**
	 * Runs the import. With $dry_run everything happens inside a transaction that is rolled back,
	 * so the report shows exactly what a real run would do.
	 */
	public static function import( array $data, bool $dry_run = false ): array {
		global $wpdb;
		if ( 'atcom-migration' !== ( $data['format'] ?? '' ) || empty( $data['providers'] ) ) {
			return array( 'ok' => false, 'error' => 'This is not an import file from the old airporttransportation.com.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$r = array(
			'ok' => true, 'dry_run' => $dry_run,
			'airports_new' => 0, 'airports_existing' => 0,
			'providers_new' => 0, 'providers_existing' => 0,
			'zones_new' => 0, 'zones_shape_added' => 0, 'zones_existing' => 0,
			'services' => 0, 'prices' => 0, 'prices_held' => 0,
			'issues' => array(),
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			$airports = self::import_airports( (array) ( $data['airports'] ?? array() ), $r );
			$types    = array();
			foreach ( ATC_Catalog::vehicle_types() as $t ) {
				$types[ $t['slug'] ] = (int) $t['id'];
			}
			$commission = (int) ATC_Settings::get( 'provider_commission_bp' );

			foreach ( $data['providers'] as $p ) {
				$party_id = self::import_provider( $p, $airports, $r );
				if ( ! $party_id ) {
					continue;
				}
				$party = ATC_Catalog::party( $party_id );
				$bp    = null === $party['commission_bp'] ? $commission : (int) $party['commission_bp'];

				foreach ( (array) ( $p['airports'] ?? array() ) as $a ) {
					$airport_id = $airports[ $a['iata'] ] ?? null;
					if ( ! $airport_id ) {
						continue;
					}
					// Areas → our zones.
					$area_zone = array();
					foreach ( (array) $a['areas'] as $area ) {
						$zone_id = self::zone_for_area( $airport_id, $area, $r );
						if ( $zone_id ) {
							$area_zone[ (string) $area['legacy_id'] ] = $zone_id;
							self::alias_set( $party_id, 'zone', (string) $area['name'], $zone_id );
						} else {
							$r['issues'][] = $p['name'] . ' · ' . $a['iata'] . ': area "' . $area['name'] . '" has no shape and no matching zone.';
						}
					}
					// Prices per vehicle category → services.
					$by_vehicle = array();
					foreach ( (array) $a['prices'] as $pr ) {
						if ( ! isset( $types[ $pr['vehicle'] ], $area_zone[ (string) $pr['area'] ] ) ) {
							continue;
						}
						$by_vehicle[ $pr['vehicle'] ][] = $pr + array( 'zone_id' => $area_zone[ (string) $pr['area'] ] );
					}
					foreach ( $by_vehicle as $slug => $list ) {
						$zone_ids = array_values( array_unique( array_map( static fn( $x ) => (int) $x['zone_id'], $list ) ) );
						$svc      = ATC_Catalog::save_service(
							$party_id,
							array(
								'airport_id'         => $airport_id,
								'vehicle_type_id'    => $types[ $slug ],
								'status'             => 'live' === $party['status'] ? 'live' : 'draft',
								'min_notice_minutes' => null === $party['min_notice_minutes'] ? 720 : (int) $party['min_notice_minutes'],
								'zone_ids'           => $zone_ids,
							)
						);
						if ( is_wp_error( $svc ) ) {
							$r['issues'][] = $p['name'] . ': ' . $svc->get_error_message();
							continue;
						}
						++$r['services'];
						foreach ( $list as $pr ) {
							$one = (int) round( (float) $pr['one_way'] * 100 );
							// The old site took USD only; a few providers typed yen, rupiah or per-km rates.
							// Those would sell at absurd prices, so they are held back for the provider to re-enter.
							if ( $one > 0 && ( $one < self::MIN_CENTS || $one > self::MAX_CENTS ) ) {
								$r['issues'][]   = $p['name'] . ' · ' . $a['iata'] . ': ' . $slug . ' at USD ' . number_format( $one / 100, 2 ) . ' left out, it looks like another currency or a typo. They re-enter it.';
								++$r['prices_held'];
								continue;
							}
							if ( $one > 0 ) {
								self::set_price( (int) $svc['id'], (int) $pr['zone_id'], 'one_way', $one, $bp );
								++$r['prices'];
							}
							if ( null !== $pr['round_trip'] && (float) $pr['round_trip'] > 0 ) {
								self::set_price( (int) $svc['id'], (int) $pr['zone_id'], 'round_trip', (int) round( (float) $pr['round_trip'] * 100 ), $bp );
								++$r['prices'];
							}
						}
					}
				}
			}
			foreach ( $airports as $airport_id ) {
				ATC_Catalog::rematch_places( (int) $airport_id );
			}
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}

		if ( $dry_run ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$wpdb->query( 'COMMIT' );
			ATC_Install::log( 'migration', 0, 'imported', array_diff_key( $r, array( 'issues' => 1 ) ) );
		}
		$r['issues'] = array_slice( $r['issues'], 0, 200 );
		return $r;
	}

	private static function import_airports( array $list, array &$r ): array {
		global $wpdb;
		$map = array();
		foreach ( $list as $a ) {
			$iata = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $a['iata'] ), 0, 3 ) );
			if ( 3 !== strlen( $iata ) ) {
				continue;
			}
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'airports' ) . ' WHERE iata = %s', $iata ) );
			if ( $id ) {
				++$r['airports_existing'];
			} else {
				$wpdb->insert(
					self::t( 'airports' ),
					array(
						'iata'     => $iata,
						'name'     => mb_substr( sanitize_text_field( $a['name'] ?? $iata ), 0, 190 ),
						'city'     => mb_substr( sanitize_text_field( $a['city'] ?? '' ), 0, 100 ),
						'country'  => strtoupper( substr( (string) ( $a['country'] ?? '' ), 0, 2 ) ),
						'timezone' => sanitize_text_field( $a['timezone'] ?? 'UTC' ) ?: 'UTC',
						'currency' => strtoupper( substr( (string) ( $a['currency'] ?? 'USD' ), 0, 3 ) ) ?: 'USD',
						'lat'      => isset( $a['lat'] ) ? (float) $a['lat'] : null,
						'lng'      => isset( $a['lng'] ) ? (float) $a['lng'] : null,
						'status'   => 'active',
					)
				);
				$id = (int) $wpdb->insert_id;
				++$r['airports_new'];
			}
			$map[ $iata ] = $id;
		}
		return $map;
	}

	private static function import_provider( array $p, array $airports, array &$r ): ?int {
		global $wpdb;
		$name = trim( sanitize_text_field( $p['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$id = self::legacy_get( 'party', $p['legacy_id'] );
		if ( ! $id && ! empty( $p['email'] ) ) {
			// Someone who already signed up on the new site with the same email: link instead of doubling.
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'parties' ) . " WHERE type = 'provider' AND email = %s ORDER BY id LIMIT 1", sanitize_email( $p['email'] ) ) ) ?: null;
		}
		if ( ! $id ) {
			// Same company name as someone who signed up here with another email (Cancun Airport Transportation).
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'parties' ) . " WHERE type = 'provider' AND LOWER(name) = LOWER(%s) ORDER BY id LIMIT 1", $name ) ) ?: null;
			if ( $id ) {
				$r['issues'][] = $name . ': linked to the account already here with the same name; its details were kept, prices and areas added.';
			}
		}
		if ( $id ) {
			self::legacy_set( 'party', $p['legacy_id'], $id );
			++$r['providers_existing'];
			return $id;
		}

		$status = 'pending';
		if ( 'suspended' === ( $p['old_status'] ?? '' ) ) {
			$status = 'suspended';
		} elseif ( 'active' === ( $p['old_status'] ?? '' ) && ! empty( $p['ready'] ) ) {
			$status = 'live';
		}
		$first   = $p['airports'][0]['iata'] ?? '';
		$airport = $first && isset( $airports[ $first ] ) ? ATC_Catalog::airport( $airports[ $first ] ) : null;
		$website = trim( (string) ( $p['website'] ?? '' ) );
		if ( '' !== $website && ! preg_match( '#^https?://#i', $website ) ) {
			$website = 'https://' . $website;
		}
		$wpdb->insert(
			self::t( 'parties' ),
			array(
				'type'               => 'provider',
				'name'               => mb_substr( $name, 0, 190 ),
				'legal_name'         => mb_substr( sanitize_text_field( $p['legal_name'] ?? '' ), 0, 190 ) ?: null,
				'contact_name'       => mb_substr( sanitize_text_field( $p['contact_name'] ?? '' ), 0, 190 ) ?: null,
				'email'              => sanitize_email( $p['email'] ?? '' ) ?: null,
				'phone'              => mb_substr( preg_replace( '/[^0-9+ ()\-]/', '', (string) ( $p['phone'] ?? '' ) ), 0, 40 ) ?: null,
				'website'            => $website ? mb_substr( esc_url_raw( $website ), 0, 190 ) : null,
				'about'              => mb_substr( sanitize_textarea_field( $p['about'] ?? '' ), 0, 2000 ) ?: null,
				'country'            => strtoupper( substr( (string) ( $p['country'] ?: ( $airport['country'] ?? '' ) ), 0, 2 ) ) ?: null,
				'home_airport_id'    => $airport ? (int) $airport['id'] : null,
				'min_notice_minutes' => ! empty( $p['lead_hours'] ) ? (int) $p['lead_hours'] * 60 : 720,
				'payout_currency'    => 'USD',
				'status'             => $status,
				'created_at'         => self::now(),
				'updated_at'         => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		self::legacy_set( 'party', $p['legacy_id'], $id );
		ATC_Install::log( 'party', $id, 'migrated', array( 'legacy_id' => $p['legacy_id'] ) );
		++$r['providers_new'];
		return $id;
	}

	/** Finds or creates our zone for a provider's old area. Hand-drawn shapes on the new site are never replaced. */
	private static function zone_for_area( int $airport_id, array $area, array &$r ): ?int {
		global $wpdb;
		$existing = self::legacy_get( 'area', $area['legacy_id'] );
		if ( $existing && ATC_Catalog::zone( $existing ) ) {
			return $existing;
		}
		$name = trim( sanitize_text_field( $area['zone_name'] ?: $area['name'] ) );
		if ( '' === $name ) {
			return null;
		}
		$slug = sanitize_title( $name );
		$poly = ATC_Geo::clean_polygon( $area['polygon'] ?? null );
		$z    = $wpdb->get_row( $wpdb->prepare( 'SELECT id, polygon, status FROM ' . self::t( 'zones' ) . ' WHERE airport_id = %d AND (slug = %s OR name = %s) ORDER BY status = %s DESC LIMIT 1', $airport_id, $slug, $name, 'active' ), ARRAY_A );
		if ( $z ) {
			$zid = (int) $z['id'];
			if ( empty( $z['polygon'] ) && $poly ) {
				$wpdb->update( self::t( 'zones' ), array( 'polygon' => wp_json_encode( $poly ), 'source' => 'migrated', 'status' => 'active' ), array( 'id' => $zid ) );
				++$r['zones_shape_added'];
			} else {
				++$r['zones_existing'];
			}
		} else {
			if ( ! $poly ) {
				return null;
			}
			$sort = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort),0)+10 FROM ' . self::t( 'zones' ) . ' WHERE airport_id = %d', $airport_id ) );
			$wpdb->insert( self::t( 'zones' ), array( 'airport_id' => $airport_id, 'name' => mb_substr( $name, 0, 190 ), 'slug' => $slug ?: 'zone-' . $area['legacy_id'], 'polygon' => wp_json_encode( $poly ), 'source' => 'migrated', 'sort' => $sort, 'status' => 'active' ) );
			$zid = (int) $wpdb->insert_id;
			++$r['zones_new'];
		}
		self::legacy_set( 'area', $area['legacy_id'], $zid );
		return $zid;
	}

	/** One price: the Suggested retail price as given, the Amount you will get paid from the commission. */
	public static function set_price( int $service_id, int $zone_id, string $direction, int $retail_cents, int $commission_bp ): void {
		global $wpdb;
		$t    = self::t( 'prices' );
		$data = array(
			'retail_cents'   => $retail_cents,
			'provider_cents' => ATC_Money::provider_from_retail( $retail_cents, $commission_bp ),
			'currency'       => 'USD',
			'updated_by'     => get_current_user_id() ?: null,
			'updated_at'     => self::now(),
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE service_id = %d AND zone_id = %d AND direction = %s AND valid_from IS NULL", $service_id, $zone_id, $direction ) );
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, $data + array( 'service_id' => $service_id, 'zone_id' => $zone_id, 'direction' => $direction ) );
		}
	}
}
