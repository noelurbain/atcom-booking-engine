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
		if ( 'atcom-migration' !== ( $data['format'] ?? '' ) || ( empty( $data['providers'] ) && (int) ( $data['version'] ?? 1 ) < 2 ) ) {
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
			'bookings_new' => 0, 'bookings_existing' => 0,
			'issues' => array(),
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			$airports = self::import_airports( (array) ( $data['airports'] ?? array() ), $r );
			// Airports already here (a later file only lists the ones it needs).
			foreach ( $wpdb->get_results( 'SELECT id, iata FROM ' . self::t( 'airports' ) . " WHERE iata IS NOT NULL AND iata <> ''", ARRAY_A ) as $row ) {
				$airports[ $row['iata'] ] = $airports[ $row['iata'] ] ?? (int) $row['id'];
			}
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
			foreach ( (array) ( $data['bookings'] ?? array() ) as $b ) {
				self::import_booking( (array) $b, $airports, $r );
			}
			foreach ( (array) ( $data['travelers'] ?? array() ) as $t ) {
				self::import_traveler( (array) $t, $r );
			}
			foreach ( (array) ( $data['provider_applications'] ?? array() ) as $a ) {
				self::import_application( (array) $a, $airports, $r );
			}
			foreach ( (array) ( $data['affiliate_applications'] ?? array() ) as $a ) {
				self::import_affiliate( (array) $a, $r );
			}
			if ( ! empty( $data['complete_past'] ) ) {
				$r['bookings_completed'] = self::complete_past_legacy();
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
			update_option( 'atc_last_import', array( 'at' => self::now(), 'by' => get_current_user_id() ) + $r, false );
		}
		$r['issues'] = array_slice( $r['issues'], 0, 200 );
		return $r;
	}

	/**
	 * One reservation from the old site. Traveler names, emails and phones are not in the file: they stay
	 * on the old site. Money is what happened there (old 30% commission); no ledger lines are written,
	 * because those trips were paid and settled on the old site.
	 */
	private static function import_booking( array $b, array $airports, array &$r ): void {
		global $wpdb;
		$ref = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $b['ref'] ?? '' ) ) );
		if ( '' === $ref ) {
			return;
		}
		if ( self::legacy_get( 'booking', $ref ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', $ref ) ) ) {
			++$r['bookings_existing'];
			return;
		}
		$airport_id = $airports[ $b['iata'] ?? '' ] ?? null;
		if ( ! $airport_id ) {
			$r['issues'][] = 'Booking ' . $ref . ': airport ' . ( $b['iata'] ?? '?' ) . ' not found, left out.';
			return;
		}
		$party_id = $b['provider_legacy'] ? (int) self::legacy_get( 'party', $b['provider_legacy'] ) : 0;
		$type_id  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'vehicle_types' ) . ' WHERE slug = %s', (string) $b['vehicle'] ) );
		$svc_id   = $party_id && $type_id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'services' ) . ' WHERE party_id = %d AND airport_id = %d AND vehicle_type_id = %d', $party_id, $airport_id, $type_id ) ) : 0;

		// Their area name → our zone: the provider's alias first, then any zone of that airport with that name.
		$area    = trim( (string) ( $b['area'] ?? '' ) );
		$zone_id = 0;
		if ( '' !== $area ) {
			$zt      = self::t( 'zones' );
			$zone_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT a.target_id FROM ' . self::t( 'aliases' ) . " a JOIN {$zt} z ON z.id = a.target_id WHERE a.kind = 'zone' AND a.alias = %s AND z.airport_id = %d ORDER BY a.party_id = %d DESC LIMIT 1", $area, $airport_id, $party_id ) );
			if ( ! $zone_id ) {
				$zone_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$zt} WHERE airport_id = %d AND (name = %s OR slug = %s) LIMIT 1", $airport_id, $area, sanitize_title( $area ) ) );
			}
		}

		$paid     = 'paid' === $b['payment'];
		$old      = (string) $b['old_status'];
		$status   = 'cancelled' === $old ? 'cancelled' : ( $paid ? 'confirmed' : 'held' );
		$booked   = self::utc( $b['booked_utc'] ?? '' ) ?: self::now();
		$total    = max( 0, (int) $b['total'] );
		$owed     = max( 0, (int) $b['provider_owed'] );
		$round    = 'round_trip' === $b['trip'] && ! empty( $b['return_utc'] );
		$lang     = preg_match( '/^[a-z]{2}$/', (string) $b['lang'] ) ? $b['lang'] : 'en';
		$channel  = in_array( $b['channel'], array( 'web', 'affiliate', 'admin', 'api' ), true ) ? $b['channel'] : 'web';
		$snapshot = array(
			'legacy'        => true,
			'source'        => 'old airporttransportation.com',
			'payment'       => (string) $b['payment'],
			'method'        => (string) $b['method'],
			'old_status'    => $old,
			'refunded'      => (int) $b['refunded'],
			'provider_owed' => $owed,
			'platform'      => $paid && $owed ? $total - $owed : 0,
			'area'          => $area,
			'iata'          => (string) $b['iata'],
			'timezone'      => (string) $b['timezone'],
			'bags'          => (int) $b['bags'],
		);
		$wpdb->insert(
			self::t( 'bookings' ),
			array(
				'number'          => $ref,
				'status'          => $status,
				'channel'         => $channel,
				'trip_type'       => $round ? 'round_trip' : 'one_way',
				'adults'          => max( 1, (int) $b['pax'] ),
				'children'        => 0,
				'lang'            => $lang,
				'currency'        => 'USD',
				'retail_cents'    => $total,
				'total_cents'     => $total,
				'snapshot'        => wp_json_encode( $snapshot ),
				'service_code'    => (string) wp_rand( 1000, 9999 ),
				'hold_expires_at' => 'held' === $status ? $booked : null,
				'created_at'      => $booked,
				'updated_at'      => self::now(),
			)
		);
		$booking_id = (int) $wpdb->insert_id;
		if ( ! $booking_id ) {
			$r['issues'][] = 'Booking ' . $ref . ': could not be saved.';
			return;
		}
		self::legacy_set( 'booking', $ref, $booking_id );

		$leg_status = 'cancelled' === $status ? 'cancelled' : ( 'held' === $status ? 'held' : ( $party_id ? 'accepted' : 'unassigned' ) );
		$legs       = array( array( $b['direction'], self::utc( $b['pickup_utc'] ?? '' ), (string) $b['from'], (string) $b['to'] ) );
		if ( $round ) {
			$back   = 'arrival' === $b['direction'] ? 'departure' : 'arrival';
			$legs[] = array( $back, self::utc( $b['return_utc'] ), (string) $b['to'], (string) $b['from'] );
		}
		$n = count( $legs );
		foreach ( $legs as $i => [ $dir, $at, $from, $to ] ) {
			$last = $i === $n - 1;
			$wpdb->insert(
				self::t( 'booking_legs' ),
				array(
					'booking_id'     => $booking_id,
					'direction'      => $dir,
					'service_id'     => $svc_id,
					'party_id'       => $party_id,
					'zone_id'        => $zone_id ?: null,
					'pickup_at'      => $at,
					'from_place'     => mb_substr( $from, 0, 255 ),
					'to_place'       => mb_substr( $to, 0, 255 ),
					'suitcases'      => $i ? 0 : (int) $b['bags'],
					'retail_cents'   => $last ? $total - intdiv( $total, $n ) * ( $n - 1 ) : intdiv( $total, $n ),
					'provider_cents' => $last ? $owed - intdiv( $owed, $n ) * ( $n - 1 ) : intdiv( $owed, $n ),
					'status'         => $leg_status,
				)
			);
		}
		++$r['bookings_new'];
		if ( $b['provider_legacy'] && ! $party_id ) {
			$r['issues'][] = 'Booking ' . $ref . ': its provider was not found here, saved without one.';
		}
		if ( '' !== $area && ! $zone_id ) {
			$r['issues'][] = 'Booking ' . $ref . ': area "' . $area . '" has no zone here.';
		}
	}

	/**
	 * Traveler details of an old reservation: name, email, phone, and per ride the flight, notes and luggage.
	 * Only fills what the old site has; never touches a booking made on the new site.
	 */
	private static function import_traveler( array $t, array &$r ): void {
		global $wpdb;
		$ref = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $t['ref'] ?? '' ) ) );
		$b   = $ref ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, snapshot FROM ' . self::t( 'bookings' ) . ' WHERE number = %s', $ref ), ARRAY_A ) : null;
		if ( ! $b ) {
			$r['issues'][] = 'Traveler for ' . ( $ref ?: '?' ) . ': reservation not found here.';
			return;
		}
		$snap = json_decode( (string) $b['snapshot'], true ) ?: array();
		if ( empty( $snap['legacy'] ) ) {
			$r['issues'][] = 'Traveler for ' . $ref . ': booked on the new site, left as it is.';
			return;
		}
		$set = array();
		foreach ( array( 'first_name' => 100, 'last_name' => 100, 'phone' => 40 ) as $k => $len ) {
			$v = trim( sanitize_text_field( (string) ( $t[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$set[ $k ] = mb_substr( $v, 0, $len );
			}
		}
		$email = sanitize_email( (string) ( $t['email'] ?? '' ) );
		if ( is_email( $email ) ) {
			$set['email'] = strtolower( $email );
		}
		if ( $set ) {
			$set['updated_at'] = self::now();
			$wpdb->update( self::t( 'bookings' ), $set, array( 'id' => (int) $b['id'] ) );
			++$r['travelers_updated'];
		}
		foreach ( (array) ( $t['legs'] ?? array() ) as $l ) {
			$dir = in_array( $l['direction'] ?? '', array( 'arrival', 'departure' ), true ) ? $l['direction'] : '';
			if ( '' === $dir ) {
				continue;
			}
			$leg = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'booking_legs' ) . ' WHERE booking_id = %d AND direction = %s ORDER BY id LIMIT 1', (int) $b['id'], $dir ) );
			if ( ! $leg ) {
				continue;
			}
			$u = array();
			foreach ( array( 'flight_airline' => 100, 'flight_number' => 20 ) as $k => $len ) {
				$v = trim( sanitize_text_field( (string) ( $l[ $k ] ?? '' ) ) );
				if ( '' !== $v ) {
					$u[ $k ] = mb_substr( $v, 0, $len );
				}
			}
			if ( $ft = self::utc( $l['flight_time_utc'] ?? '' ) ) {
				$u['flight_time'] = $ft;
			}
			$notes = trim( sanitize_textarea_field( (string) ( $l['notes'] ?? '' ) ) );
			if ( '' !== $notes ) {
				$u['notes'] = mb_substr( $notes, 0, 2000 );
			}
			if ( isset( $l['suitcases'] ) && '' !== (string) $l['suitcases'] ) {
				$u['suitcases'] = max( 0, (int) $l['suitcases'] );
			}
			if ( $at = self::utc( $l['pickup_utc'] ?? '' ) ) {
				$u['pickup_at'] = $at;
			}
			if ( $u ) {
				$wpdb->update( self::t( 'booking_legs' ), $u, array( 'id' => $leg ) );
				++$r['legs_updated'];
			}
		}
	}

	/**
	 * A provider sign-up from the old site that was not approved there yet. It becomes a pending provider
	 * with no portal account: the team invites it from Control like the other old providers.
	 */
	private static function import_application( array $a, array $airports, array &$r ): void {
		global $wpdb;
		$code  = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $a['code'] ?? '' ) ) );
		$name  = trim( sanitize_text_field( (string) ( $a['company'] ?? '' ) ) );
		$email = strtolower( sanitize_email( (string) ( $a['email'] ?? '' ) ) );
		if ( '' === $code || '' === $name ) {
			return;
		}
		$pt       = self::t( 'parties' );
		$existing = self::legacy_get( 'application', $code );
		if ( ! $existing && is_email( $email ) ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE type = 'provider' AND email = %s LIMIT 1", $email ) );
		}
		if ( $existing ) {
			self::legacy_set( 'application', $code, (int) $existing );
			++$r['provider_applications_existing'];
			return;
		}
		$about = array();
		foreach ( array( 'Coverage' => 'location', 'Fleet' => 'fleet', 'Applied on the old site' => 'applied' ) as $label => $k ) {
			$v = trim( sanitize_text_field( (string) ( $a[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$about[] = $label . ': ' . $v;
			}
		}
		$desc = trim( sanitize_textarea_field( (string) ( $a['description'] ?? '' ) ) );
		if ( '' !== $desc ) {
			$about[] = $desc;
		}
		$about[]    = 'Old application ' . $code;
		$airport_id = $airports[ strtoupper( (string) ( $a['iata'] ?? '' ) ) ] ?? null;
		$country    = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $a['country'] ?? '' ) ), 0, 2 ) );
		$wpdb->insert(
			$pt,
			array(
				'type'            => 'provider',
				'name'            => mb_substr( $name, 0, 190 ),
				'contact_name'    => mb_substr( trim( sanitize_text_field( (string) ( $a['contact'] ?? '' ) ) ), 0, 190 ) ?: null,
				'email'           => is_email( $email ) ? $email : null,
				'phone'           => mb_substr( preg_replace( '/[^0-9+ ()\-]/', '', (string) ( $a['phone'] ?? '' ) ), 0, 40 ) ?: null,
				'website'         => mb_substr( esc_url_raw( (string) ( $a['website'] ?? '' ) ), 0, 190 ) ?: null,
				'about'           => mb_substr( implode( "\n", $about ), 0, 4000 ),
				'country'         => 2 === strlen( $country ) ? $country : null,
				'home_airport_id' => $airport_id,
				'payout_currency' => 'MX' === $country ? 'MXN' : 'USD',
				'status'          => 'pending',
				'created_at'      => self::utc( $a['applied_utc'] ?? '' ) ?: self::now(),
				'updated_at'      => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::legacy_set( 'application', $code, $id );
			ATC_Install::log( 'party', $id, 'imported_application', array( 'code' => $code ) );
			++$r['provider_applications_new'];
		}
	}

	/** An affiliate (or affiliate application) from the old site, kept with its old AFF code. */
	private static function import_affiliate( array $a, array &$r ): void {
		global $wpdb;
		$code  = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $a['code'] ?? '' ) ) );
		$name  = trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		$email = strtolower( sanitize_email( (string) ( $a['email'] ?? '' ) ) );
		if ( '' === $code || '' === $name ) {
			return;
		}
		$pt = self::t( 'parties' );
		if ( self::legacy_get( 'affiliate', $code ) || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE ref_code = %s", $code ) ) ) {
			++$r['affiliates_existing'];
			return;
		}
		$status = array( 'approved' => 'live', 'declined' => 'declined' )[ strtolower( (string) ( $a['status'] ?? '' ) ) ] ?? 'pending';
		$about  = array();
		foreach ( array( 'Audience' => 'audience', 'Traffic' => 'traffic', 'Applied on the old site' => 'applied' ) as $label => $k ) {
			$v = trim( sanitize_text_field( (string) ( $a[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$about[] = $label . ': ' . $v;
			}
		}
		$desc = trim( sanitize_textarea_field( (string) ( $a['description'] ?? '' ) ) );
		if ( '' !== $desc ) {
			$about[] = $desc;
		}
		$wpdb->insert(
			$pt,
			array(
				'type'         => 'affiliate',
				'name'         => mb_substr( $name, 0, 190 ),
				'contact_name' => mb_substr( trim( sanitize_text_field( (string) ( $a['contact'] ?? '' ) ) ), 0, 190 ) ?: null,
				'email'        => is_email( $email ) ? $email : null,
				'phone'        => mb_substr( preg_replace( '/[^0-9+ ()\-]/', '', (string) ( $a['phone'] ?? '' ) ), 0, 40 ) ?: null,
				'website'      => mb_substr( esc_url_raw( (string) ( $a['website'] ?? '' ) ), 0, 190 ) ?: null,
				'about'        => $about ? mb_substr( implode( "\n", $about ), 0, 4000 ) : null,
				'ref_code'     => $code,
				'status'       => $status,
				'created_at'   => self::utc( $a['applied_utc'] ?? '' ) ?: self::now(),
				'updated_at'   => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::legacy_set( 'affiliate', $code, $id );
			++$r['affiliates_new'];
		}
	}

	/**
	 * Old paid reservations whose rides are in the past are marked completed. They were run and settled
	 * on the old site, so no ledger lines are written and they never show up in payouts.
	 */
	public static function complete_past_legacy(): int {
		global $wpdb;
		$bt  = self::t( 'bookings' );
		$lt  = self::t( 'booking_legs' );
		$now = self::now();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT b.id FROM {$bt} b WHERE b.status = 'confirmed' AND b.snapshot LIKE %s AND NOT EXISTS (SELECT 1 FROM {$lt} l WHERE l.booking_id = b.id AND l.status NOT IN ('cancelled','released','completed','no_show') AND (l.pickup_at IS NULL OR l.pickup_at >= %s)) AND NOT EXISTS (SELECT 1 FROM " . self::t( 'ledger' ) . " x WHERE x.booking_id = b.id AND x.role = 'provider' AND x.status = 'pending')", '%"legacy":true%', $now ) ); // A ride given to a provider here completes like a new-site ride, so it gets paid.
		foreach ( $ids as $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$lt} SET status = 'completed', completed_how = 'legacy', completed_at = COALESCE(pickup_at, %s) WHERE booking_id = %d AND status NOT IN ('cancelled','released','completed','no_show')", $now, (int) $id ) );
			$wpdb->update( $bt, array( 'status' => 'completed', 'updated_at' => $now ), array( 'id' => (int) $id ) );
		}
		return count( $ids );
	}

	private static function utc( ?string $s ): ?string {
		return $s && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s ) ? $s : null;
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
