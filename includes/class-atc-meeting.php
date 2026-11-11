<?php
/**
 * Meeting your driver: the steps a traveler follows at the airport, shown on the confirmation page (and,
 * later, in the emails). Our team writes a default per airport; each provider can write their own for each
 * airport they serve, which replaces ours on their bookings. Both can be split by terminal.
 *
 * A set is { terminal: '' | 'Terminal 3', steps: [ { title, text }, ... ] }.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Meeting {

	const MAX_SETS  = 6;
	const MAX_STEPS = 6;

	private static function t(): string {
		return ATC_Schema::table( 'meeting' );
	}

	/** Used where nobody has written anything yet. */
	public static function fallback(): array {
		return array(
			array(
				'terminal' => '',
				'steps'    => array(
					array( 'title' => 'Clear immigration and customs', 'text' => 'Collect your bags. Your driver follows your flight, so there’s no rush.' ),
					array( 'title' => 'Walk out of the arrivals hall', 'text' => 'Ignore offers inside the building: your transfer is already paid.' ),
					array( 'title' => 'Look for your name', 'text' => 'Your driver holds a sign with your name.' ),
				),
			),
		);
	}

	/** The sets saved for one airport and one party (0 = ours), in order. */
	public static function sets( int $airport_id, int $party_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT terminal, steps FROM ' . self::t() . ' WHERE airport_id = %d AND party_id = %d ORDER BY sort, id', $airport_id, $party_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$steps = json_decode( (string) $r['steps'], true );
			if ( is_array( $steps ) && $steps ) {
				$out[] = array( 'terminal' => (string) $r['terminal'], 'steps' => $steps );
			}
		}
		return $out;
	}

	/**
	 * What a traveler sees: the provider's own steps for that airport, else ours, else the general ones.
	 *
	 * @return array { sets, source: provider|airport|general }
	 */
	public static function for_booking( int $airport_id, int $party_id ): array {
		if ( $party_id ) {
			$mine = self::sets( $airport_id, $party_id );
			if ( $mine ) {
				return array( 'sets' => $mine, 'source' => 'provider' );
			}
		}
		$ours = $airport_id ? self::sets( $airport_id, 0 ) : array();
		return $ours ? array( 'sets' => $ours, 'source' => 'airport' ) : array( 'sets' => self::fallback(), 'source' => 'general' );
	}

	/** Clean what an editor sent. */
	public static function clean( array $sets ) {
		$out = array();
		foreach ( array_slice( $sets, 0, self::MAX_SETS ) as $set ) {
			$steps = array();
			foreach ( array_slice( (array) ( $set['steps'] ?? array() ), 0, self::MAX_STEPS ) as $st ) {
				$title = mb_substr( trim( sanitize_text_field( (string) ( $st['title'] ?? '' ) ) ), 0, 80 );
				$text  = mb_substr( trim( sanitize_textarea_field( (string) ( $st['text'] ?? '' ) ) ), 0, 400 );
				if ( '' !== $title || '' !== $text ) {
					$steps[] = array( 'title' => $title, 'text' => $text );
				}
			}
			if ( $steps ) {
				$out[] = array( 'terminal' => mb_substr( trim( sanitize_text_field( (string) ( $set['terminal'] ?? '' ) ) ), 0, 60 ), 'steps' => $steps );
			}
		}
		if ( count( $out ) > 1 ) {
			foreach ( $out as $set ) {
				if ( '' === $set['terminal'] ) {
					return new WP_Error( 'atc_meeting', 'With more than one set of steps, give each one its terminal name.', array( 'status' => 400 ) );
				}
			}
		}
		return $out;
	}

	/** Replace the steps of one airport and party. No sets = remove (a provider goes back to ours). */
	public static function save( int $airport_id, int $party_id, array $sets ) {
		global $wpdb;
		if ( ! $airport_id || ! ATC_Catalog::airport( $airport_id ) ) {
			return new WP_Error( 'atc_meeting', 'Pick an airport.', array( 'status' => 400 ) );
		}
		$sets = self::clean( $sets );
		if ( is_wp_error( $sets ) ) {
			return $sets;
		}
		$wpdb->delete( self::t(), array( 'airport_id' => $airport_id, 'party_id' => $party_id ) );
		foreach ( $sets as $i => $set ) {
			$wpdb->insert(
				self::t(),
				array(
					'airport_id' => $airport_id,
					'party_id'   => $party_id,
					'terminal'   => $set['terminal'],
					'sort'       => $i,
					'steps'      => wp_json_encode( $set['steps'] ),
					'updated_by' => get_current_user_id() ?: null,
					'updated_at' => current_time( 'mysql', true ),
				)
			);
		}
		ATC_Install::log( 'meeting', $airport_id, $sets ? 'saved' : 'removed', array( 'party' => $party_id, 'sets' => count( $sets ) ) );
		return self::sets( $airport_id, $party_id );
	}

	/** Providers at an airport who wrote their own steps, for the control panel. */
	public static function providers( int $airport_id ): array {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT party_id FROM ' . self::t() . ' WHERE airport_id = %d AND party_id > 0', $airport_id ) ) );
		$out = array();
		foreach ( $ids as $pid ) {
			$p     = ATC_Catalog::party( $pid );
			$out[] = array( 'party_id' => $pid, 'name' => $p['name'] ?? ( '#' . $pid ), 'sets' => self::sets( $airport_id, $pid ) );
		}
		return $out;
	}
}
