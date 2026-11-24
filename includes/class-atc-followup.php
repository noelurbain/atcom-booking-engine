<?php
/**
 * Follow-ups that keep providers in line once they are live without our approval:
 * - documents: reminders until the required documents are sent (day 3, 7, 14, then every 14 days),
 *   an optional pause after a set number of days, and a reminder 30 days before a document expires;
 * - bookings: the confirmation window of the Provider Agreement (2 hours, 30 minutes when pickup is
 *   less than 24 hours away), checked every 15 minutes.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Followup {

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'atc_quarter', array( 'ATC_Trips', 'ack_check' ) );
		add_action( 'atc_hourly', array( __CLASS__, 'daily' ) );
	}

	public static function schedules( array $s ): array {
		$s['atc_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes' );
		return $s;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'atc_quarter' ) ) {
			wp_schedule_event( time() + 120, 'atc_15min', 'atc_quarter' );
		}
	}

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** Runs from the hourly job, once a day. */
	public static function daily(): void {
		$today = gmdate( 'Y-m-d' );
		if ( get_option( 'atc_followup_day' ) === $today ) {
			return;
		}
		update_option( 'atc_followup_day', $today, false );
		self::documents();
		self::expiring();
	}

	/** Required document types a provider has not sent (or had rejected). */
	public static function missing_docs( int $party_id ): array {
		$sent = array();
		foreach ( ATC_Partner::documents( $party_id ) as $d ) {
			if ( 'rejected' !== $d['status'] ) {
				$sent[ $d['type'] ] = true;
			}
		}
		$out = array();
		foreach ( ATC_Partner::doc_types() as $k => [ $name, , $required ] ) {
			if ( $required && empty( $sent[ $k ] ) ) {
				$out[ $k ] = $name;
			}
		}
		return $out;
	}

	private static function documents(): void {
		global $wpdb;
		$pause_after = (int) ATC_Settings::get( 'docs_pause_days' );
		$ids         = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . self::t( 'parties' ) . " WHERE type = 'provider' AND status = 'live'" ) );
		foreach ( $ids as $pid ) {
			$missing = self::missing_docs( $pid );
			$key     = 'atc_docs_follow_' . $pid;
			if ( ! $missing ) {
				delete_option( $key );
				continue;
			}
			$st = get_option( $key );
			if ( ! is_array( $st ) ) {
				$st = array( 'since' => gmdate( 'Y-m-d' ), 'sent' => array() );
				update_option( $key, $st, false );
				continue;
			}
			$days = (int) floor( ( time() - strtotime( $st['since'] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
			$p    = ATC_Catalog::party( $pid );
			if ( ! $p ) {
				continue;
			}
			if ( $pause_after > 0 && $days >= $pause_after ) {
				ATC_Catalog::save_party( array( 'status' => 'paused' ), $pid );
				ATC_Install::log( 'party', $pid, 'paused_missing_documents', array( 'days' => $days ) );
				ATC_Mail::provider_docs( $p, array_values( $missing ), 0, true );
				ATC_Partner::mail_team( 'Provider paused (no documents): ' . $p['name'], $p['name'] . ' was paused after ' . $days . " days without its documents.\n\n" . home_url( '/control/providers/' . $pid . '/' ) );
				delete_option( $key );
				continue;
			}
			// Day 3, 7, 14, then every 14 days.
			$due = in_array( $days, array( 3, 7, 14 ), true ) || ( $days > 14 && 0 === $days % 14 );
			if ( $due && ! in_array( $days, (array) $st['sent'], true ) ) {
				$st['sent'][] = $days;
				update_option( $key, $st, false );
				ATC_Mail::provider_docs( $p, array_values( $missing ), $pause_after > 0 ? max( 0, $pause_after - $days ) : 0, false );
				ATC_Install::log( 'party', $pid, 'documents_reminder', array( 'days' => $days ) );
			}
		}
	}

	/** A document that expires in 30 days: one reminder. */
	private static function expiring(): void {
		global $wpdb;
		$day  = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.id, d.party_id, d.type, d.expires_on FROM ' . self::t( 'documents' ) . " d JOIN " . self::t( 'parties' ) . " p ON p.id = d.party_id WHERE d.expires_on = %s AND d.status <> 'rejected' AND p.status IN ('live','pending')", $day ), ARRAY_A );
		$types = ATC_Partner::doc_types();
		foreach ( $rows as $r ) {
			$p = ATC_Catalog::party( (int) $r['party_id'] );
			if ( $p ) {
				ATC_Mail::provider_doc_expiring( $p, $types[ $r['type'] ][0] ?? $r['type'], (string) $r['expires_on'] );
			}
		}
	}
}
