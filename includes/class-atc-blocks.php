<?php
/**
 * Provider unavailability: periods when a provider takes no new bookings, for every vehicle category or one.
 * Times are typed in the provider's main airport time and stored in UTC. Search hides blocked offers.
 */

defined( 'ABSPATH' ) || exit;

class ATC_Blocks {

	private static function t(): string {
		return ATC_Schema::table( 'blocks' );
	}

	/** The time zone a provider types its blocks in: its main airport's. */
	public static function tz( int $party_id ): string {
		$p  = ATC_Catalog::party( $party_id );
		$a  = $p && $p['home_airport_id'] ? ATC_Catalog::airport( (int) $p['home_airport_id'] ) : null;
		$tz = (string) ( $a['timezone'] ?? '' );
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'UTC';
	}

	/** Current and future blocks, soonest first, with local times for display. */
	public static function list( int $party_id ): array {
		global $wpdb;
		$tz    = new DateTimeZone( self::tz( $party_id ) );
		$types = array();
		foreach ( ATC_Catalog::vehicle_types() as $v ) {
			$types[ (int) $v['id'] ] = $v['name'];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE party_id = %d AND ends_at > %s ORDER BY starts_at', $party_id, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore
		return array_map(
			static function ( $r ) use ( $tz, $types ) {
				$s = ( new DateTimeImmutable( $r['starts_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
				$e = ( new DateTimeImmutable( $r['ends_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
				return array(
					'id'       => (int) $r['id'],
					'from'     => $s->format( 'D j M Y, H:i' ),
					'to'       => $e->format( 'D j M Y, H:i' ),
					'category' => $r['vehicle_type_id'] ? ( $types[ (int) $r['vehicle_type_id'] ] ?? 'One category' ) : 'All categories',
					'note'     => (string) $r['note'],
					'now'      => strtotime( $r['starts_at'] . ' UTC' ) <= time(),
				);
			},
			$rows
		);
	}

	/** Add a block from local dates and times (Y-m-d, H:i). */
	public static function add( int $party_id, array $in ) {
		global $wpdb;
		$tz   = new DateTimeZone( self::tz( $party_id ) );
		$date = static fn( $d, $t ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) && preg_match( '/^\d{2}:\d{2}$/', (string) $t ) ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $d . ' ' . $t, $tz ) : false;
		$s    = $date( $in['start_date'] ?? '', $in['start_time'] ?? '00:00' );
		$e    = $date( $in['end_date'] ?? '', $in['end_time'] ?? '23:59' );
		if ( ! $s || ! $e ) {
			return new WP_Error( 'atc_block', 'Pick a start and an end date and time.', array( 'status' => 400 ) );
		}
		if ( $e <= $s ) {
			return new WP_Error( 'atc_block', 'The end must be after the start.', array( 'status' => 400 ) );
		}
		if ( $e->getTimestamp() <= time() ) {
			return new WP_Error( 'atc_block', 'That period has already passed.', array( 'status' => 400 ) );
		}
		if ( $e->getTimestamp() - $s->getTimestamp() > 120 * DAY_IN_SECONDS ) {
			return new WP_Error( 'atc_block', 'A block can last up to 120 days. To stop for longer, untick your vehicle categories instead.', array( 'status' => 400 ) );
		}
		$type = (int) ( $in['vehicle_type_id'] ?? 0 );
		if ( $type && ! in_array( $type, array_map( static fn( $v ) => (int) $v['id'], ATC_Catalog::vehicle_types() ), true ) ) {
			$type = 0;
		}
		$utc = new DateTimeZone( 'UTC' );
		$wpdb->insert(
			self::t(),
			array(
				'party_id'        => $party_id,
				'vehicle_type_id' => $type ?: null,
				'starts_at'       => $s->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'ends_at'         => $e->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'note'            => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 190 ) ?: null,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		ATC_Install::log( 'party', $party_id, 'block_added', array( 'from' => $s->format( 'Y-m-d H:i' ), 'to' => $e->format( 'Y-m-d H:i' ), 'type' => $type ) );
		return self::list( $party_id );
	}

	public static function delete( int $party_id, int $id ) {
		global $wpdb;
		$n = $wpdb->delete( self::t(), array( 'id' => $id, 'party_id' => $party_id ) );
		if ( ! $n ) {
			return new WP_Error( 'atc_not_found', 'Block not found.', array( 'status' => 404 ) );
		}
		ATC_Install::log( 'party', $party_id, 'block_removed', array( 'id' => $id ) );
		return self::list( $party_id );
	}

	/**
	 * Which of these providers are blocked at a UTC time: party_id => list of blocked vehicle type ids
	 * (0 in the list = every category).
	 */
	public static function covering( array $party_ids, string $utc ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $party_ids ) ) ) );
		if ( ! $ids || '' === $utc ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT party_id, vehicle_type_id FROM ' . self::t() . " WHERE party_id IN ({$in}) AND starts_at <= %s AND ends_at > %s", $utc, $utc ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['party_id'] ][] = (int) $r['vehicle_type_id'];
		}
		return $out;
	}

	public static function is_blocked( array $covering, int $party_id, int $type_id ): bool {
		$list = $covering[ $party_id ] ?? array();
		return in_array( 0, $list, true ) || in_array( $type_id, $list, true );
	}
}
