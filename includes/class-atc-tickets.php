<?php
/**
 * Tickets: every problem on a booking, in one place for our team (Control → Tickets).
 *
 * Opened by our team, the traveler (Report a problem on the reservation page), the provider (portal),
 * or automatically: a Stripe dispute (chargeback) and a rating of 2 stars or less.
 * Messages have an audience: the traveler, the provider, or internal (our team only).
 *
 * Money holds: while a ticket holds money, the booking's provider and affiliate lines don't go into a
 * payout. Resolving decides: not the provider's fault (released), the provider's fault (not paid; the
 * affiliate commission is reversed too), or partly (the provider gets a set amount). Refunds to the traveler
 * go through Stripe from the same screen.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Tickets {

	const OPEN = array( 'open', 'waiting_traveler', 'waiting_provider', 'waiting_us' );

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function types(): array {
		return array(
			'no_show'         => 'Driver didn’t show up or was late',
			'wrong_vehicle'   => 'Wrong vehicle or service',
			'overcharge'      => 'Asked to pay more',
			'refund'          => 'Refund request',
			'schedule'        => 'Flight or schedule problem',
			'lost_item'       => 'Lost item',
			'complaint'       => 'Complaint',
			'payment_dispute' => 'Payment dispute (chargeback)',
			'traveler_no_show' => 'Traveler didn’t show up',
			'provider'        => 'Provider question',
			'affiliate'       => 'Affiliate question',
			'low_rating'      => 'Low rating',
			'other'           => 'Something else',
		);
	}

	public static function statuses(): array {
		return array(
			'open'             => 'Open',
			'waiting_traveler' => 'Waiting on the traveler',
			'waiting_provider' => 'Waiting on the provider',
			'waiting_us'       => 'Waiting on us',
			'resolved'         => 'Resolved',
			'closed'           => 'Closed',
		);
	}

	/** First reply due: within 48 hours, the same day when the trip is in the next 48 hours or it is urgent. */
	private static function due( bool $urgent ): string {
		return gmdate( 'Y-m-d H:i:s', time() + ( $urgent ? 8 : 48 ) * HOUR_IN_SECONDS );
	}

	private static function soon( ?array $b ): bool {
		if ( ! $b ) {
			return false;
		}
		foreach ( $b['legs'] as $l ) {
			$at = $l['pickup_at'] ? strtotime( $l['pickup_at'] . ' UTC' ) : 0;
			if ( $at && $at > time() - 6 * HOUR_IN_SECONDS && $at < time() + 48 * HOUR_IN_SECONDS && in_array( $l['status'], array( 'new', 'accepted', 'released' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Opening and messages ---------- */

	/**
	 * @param array $in booking (number) | booking_id, type, subject, body, by (team|traveler|provider|affiliate|system),
	 *                  audience of the first message, money (cents), hold (bool), stripe_dispute, urgent.
	 */
	public static function open( array $in ) {
		global $wpdb;
		$b = null;
		if ( ! empty( $in['booking_id'] ) ) {
			$b = ATC_Checkout::find( '', (int) $in['booking_id'] );
		} elseif ( ! empty( $in['booking'] ) ) {
			$b = ATC_Checkout::find( (string) $in['booking'] );
			if ( ! $b ) {
				return new WP_Error( 'atc_not_found', 'No booking with that reference.', array( 'status' => 404 ) );
			}
		}
		$types = self::types();
		$type  = isset( $types[ $in['type'] ?? '' ] ) ? $in['type'] : 'other';
		$body  = trim( sanitize_textarea_field( (string) ( $in['body'] ?? '' ) ) );
		$by    = in_array( $in['by'] ?? '', array( 'team', 'traveler', 'provider', 'affiliate', 'system' ), true ) ? $in['by'] : 'team';
		if ( '' === $body && 'system' !== $by ) {
			return new WP_Error( 'atc_ticket', 'Write what happened.', array( 'status' => 400 ) );
		}
		$subject = mb_substr( trim( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ) ), 0, 255 ) ?: $types[ $type ];
		$party   = isset( $in['party_id'] ) ? (int) $in['party_id'] : (int) ( $b['legs'][0]['party_id'] ?? 0 );
		$urgent  = ! empty( $in['urgent'] ) || self::soon( $b ) || 'payment_dispute' === $type;
		$wpdb->insert(
			self::t( 'tickets' ),
			array(
				'booking_id'     => $b ? (int) $b['id'] : null,
				'party_id'       => $party ?: null,
				'type'           => $type,
				'status'         => 'open',
				'subject'        => $subject,
				'opened_by'      => $by,
				'user_id'        => get_current_user_id() ?: null,
				'urgent'         => $urgent ? 1 : 0,
				'money_cents'    => max( 0, (int) ( $in['money'] ?? 0 ) ),
				'stripe_dispute' => isset( $in['stripe_dispute'] ) ? mb_substr( (string) $in['stripe_dispute'], 0, 100 ) : null,
				'due_at'         => self::due( $urgent ),
				'last_reply_at'  => self::now(),
				'last_reply_by'  => $by,
				'created_at'     => self::now(),
				'updated_at'     => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'atc_ticket', 'The ticket could not be saved.', array( 'status' => 500 ) );
		}
		if ( '' !== $body ) {
			$aud = $in['audience'] ?? ( 'traveler' === $by ? 'traveler' : ( 'provider' === $by ? 'provider' : 'internal' ) );
			self::add_message( $id, $by, $body, $aud, (string) ( $in['author_name'] ?? '' ), false );
		}
		if ( $b ) {
			ATC_Install::log( 'booking', (int) $b['id'], 'ticket_opened', array( 'ticket' => $id, 'type' => $type, 'by' => $by ) );
		}
		if ( ! empty( $in['hold'] ) && $b ) {
			self::hold( $id );
		}
		if ( 'team' !== $by ) {
			ATC_Mail::ticket_team( $id, 'New ticket' );
		}
		return $id;
	}

	private static function add_message( int $ticket_id, string $author, string $body, string $audience, string $name = '', bool $touch = true ): void {
		global $wpdb;
		$audience = in_array( $audience, array( 'traveler', 'provider', 'internal' ), true ) ? $audience : 'internal';
		if ( '' === $name ) {
			$u    = wp_get_current_user();
			$name = 'team' === $author ? ( $u->ID ? $u->display_name : 'Airport Transportation' ) : ( $u->ID ? $u->display_name : '' );
		}
		$wpdb->insert( self::t( 'ticket_messages' ), array( 'ticket_id' => $ticket_id, 'author' => $author, 'user_id' => get_current_user_id() ?: null, 'author_name' => mb_substr( $name, 0, 190 ), 'audience' => $audience, 'body' => mb_substr( $body, 0, 5000 ), 'created_at' => self::now() ) );
		if ( $touch ) {
			$wpdb->update( self::t( 'tickets' ), array( 'last_reply_at' => self::now(), 'last_reply_by' => $author, 'updated_at' => self::now() ), array( 'id' => $ticket_id ) );
		}
	}

	/** A reply. Our team picks who reads it; travelers and providers reply into their own side of the thread. */
	public static function reply( int $id, string $by, string $body, string $audience = 'internal', string $status = '' ) {
		global $wpdb;
		$tk = self::row( $id );
		if ( ! $tk ) {
			return new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) );
		}
		$body = trim( sanitize_textarea_field( $body ) );
		if ( '' === $body ) {
			return new WP_Error( 'atc_ticket', 'Write a message.', array( 'status' => 400 ) );
		}
		if ( 'traveler' === $by || 'provider' === $by ) {
			$audience = $by;
		}
		self::add_message( $id, $by, $body, $audience );
		$set = array();
		if ( 'team' === $by && 'internal' !== $audience && ! $tk['first_reply'] ) {
			$set['due_at'] = null;
		}
		if ( 'team' === $by ) {
			$set['status'] = isset( self::statuses()[ $status ] ) && '' !== $status ? $status : ( 'traveler' === $audience ? 'waiting_traveler' : ( 'provider' === $audience ? 'waiting_provider' : $tk['status'] ) );
		} elseif ( ! in_array( $tk['status'], array( 'closed' ), true ) ) {
			$set['status'] = 'waiting_us';
		}
		if ( $set ) {
			$wpdb->update( self::t( 'tickets' ), $set + array( 'updated_at' => self::now() ), array( 'id' => $id ) );
		}
		if ( 'team' === $by && 'internal' !== $audience ) {
			ATC_Mail::ticket_reply( $id, $audience, $body );
		} elseif ( 'team' !== $by ) {
			ATC_Mail::ticket_team( $id, 'New reply' );
		}
		return true;
	}

	/* ---------- Reading ---------- */

	private static function row( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT t.*, (SELECT COUNT(*) FROM ' . self::t( 'ticket_messages' ) . " m WHERE m.ticket_id = t.id AND m.author = 'team' AND m.audience <> 'internal') first_reply FROM " . self::t( 'tickets' ) . ' t WHERE t.id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	private static function shape( array $r, string $for = 'team' ): array {
		$types = self::types();
		$st    = self::statuses();
		$open  = in_array( $r['status'], self::OPEN, true );
		return array(
			'id'        => (int) $r['id'],
			'ref'       => 'T' . str_pad( (string) $r['id'], 5, '0', STR_PAD_LEFT ),
			'subject'   => $r['subject'],
			'type'      => $r['type'],
			'type_name' => $types[ $r['type'] ] ?? $r['type'],
			'status'    => $r['status'],
			'status_name' => 'team' === $for ? ( $st[ $r['status'] ] ?? $r['status'] ) : ( $open ? ( 'waiting_us' === $r['status'] || 'open' === $r['status'] ? 'With our team' : 'Waiting for your reply' ) : 'Closed' ),
			'open'      => $open,
			'urgent'    => (bool) $r['urgent'],
			'overdue'   => $open && $r['due_at'] && strtotime( $r['due_at'] . ' UTC' ) < time(),
			'booking'   => (string) ( $r['number'] ?? '' ),
			'provider'  => (string) ( $r['provider'] ?? '' ),
			'party_id'  => (int) $r['party_id'],
			'opened_by' => $r['opened_by'],
			'money'     => (int) $r['money_cents'],
			'held'      => ! empty( $r['holds'] ) && '[]' !== $r['holds'] && '{}' !== $r['holds'],
			'outcome'   => (string) $r['outcome'],
			'dispute'   => 'team' === $for ? (string) $r['stripe_dispute'] : '',
			'assigned'  => $r['assigned_to'] ? ( get_userdata( (int) $r['assigned_to'] )->display_name ?? '' ) : '',
			'created'   => substr( (string) $r['created_at'], 0, 16 ),
			'updated'   => substr( (string) ( $r['last_reply_at'] ?: $r['updated_at'] ), 0, 16 ),
			'last_by'   => (string) $r['last_reply_by'],
			'due'       => $r['due_at'] ? substr( (string) $r['due_at'], 0, 16 ) : '',
		);
	}

	private static function select(): string {
		return 'SELECT t.*, b.number, p.name provider FROM ' . self::t( 'tickets' ) . ' t LEFT JOIN ' . self::t( 'bookings' ) . ' b ON b.id = t.booking_id LEFT JOIN ' . self::t( 'parties' ) . ' p ON p.id = t.party_id';
	}

	/** Control: filters status (open = all open ones), type, q (booking or subject), mine. */
	public static function list( array $f ): array {
		global $wpdb;
		$where = array( '1=1' );
		$st    = (string) ( $f['status'] ?? 'open' );
		if ( 'open' === $st ) {
			$where[] = "t.status IN ('open','waiting_traveler','waiting_provider','waiting_us')";
		} elseif ( 'urgent' === $st ) {
			$where[] = "t.status IN ('open','waiting_traveler','waiting_provider','waiting_us') AND (t.urgent = 1 OR t.due_at < UTC_TIMESTAMP())";
		} elseif ( 'holds' === $st ) {
			$where[] = "t.holds IS NOT NULL AND t.holds NOT IN ('','[]','{}')";
		} elseif ( isset( self::statuses()[ $st ] ) ) {
			$where[] = $wpdb->prepare( 't.status = %s', $st );
		}
		if ( ! empty( $f['type'] ) && isset( self::types()[ $f['type'] ] ) ) {
			$where[] = $wpdb->prepare( 't.type = %s', $f['type'] );
		}
		if ( ! empty( $f['mine'] ) ) {
			$where[] = $wpdb->prepare( 't.assigned_to = %d', get_current_user_id() );
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $f['q'] ) ) . '%';
			$where[] = $wpdb->prepare( '(b.number LIKE %s OR t.subject LIKE %s OR p.name LIKE %s)', $like, $like, $like );
		}
		if ( ! empty( $f['booking_id'] ) ) {
			$where[] = $wpdb->prepare( 't.booking_id = %d', (int) $f['booking_id'] );
		}
		$rows   = $wpdb->get_results( self::select() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY t.urgent DESC, t.last_reply_at DESC LIMIT 200', ARRAY_A ); // phpcs:ignore
		$counts = $wpdb->get_row( 'SELECT SUM(status IN (\'open\',\'waiting_traveler\',\'waiting_provider\',\'waiting_us\')) open, SUM(status IN (\'open\',\'waiting_us\')) us, SUM(status IN (\'open\',\'waiting_traveler\',\'waiting_provider\',\'waiting_us\') AND (urgent = 1 OR due_at < UTC_TIMESTAMP())) urgent, SUM(holds IS NOT NULL AND holds NOT IN (\'\',\'[]\',\'{}\')) holds FROM ' . self::t( 'tickets' ), ARRAY_A ); // phpcs:ignore
		return array(
			'tickets' => array_map( static fn( $r ) => self::shape( $r ), $rows ),
			'counts'  => array_map( 'intval', (array) $counts ),
			'types'   => self::types(),
			'statuses' => self::statuses(),
		);
	}

	public static function count_waiting(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'tickets' ) . " WHERE status IN ('open','waiting_us')" ); // phpcs:ignore
	}

	/** One ticket with its thread. $for: team (everything), traveler or provider (their side only). */
	public static function get( int $id, string $for = 'team' ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( self::select() . ' WHERE t.id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r ) {
			return new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) );
		}
		$aud  = 'team' === $for ? '' : $wpdb->prepare( ' AND audience = %s', $for );
		$msgs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'ticket_messages' ) . ' WHERE ticket_id = %d' . $aud . ' ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore
		$who  = array( 'team' => 'Airport Transportation', 'traveler' => 'Traveler', 'provider' => 'Provider', 'affiliate' => 'Affiliate', 'system' => 'Automatic' );
		$out  = self::shape( $r, $for );
		$out['messages'] = array_map(
			static fn( $m ) => array(
				'author'   => $m['author'],
				'name'     => 'team' === $for ? ( $m['author_name'] ?: ( $who[ $m['author'] ] ?? '' ) ) : ( 'team' === $m['author'] ? 'Airport Transportation' : ( $m['author'] === $for ? 'You' : ( $who[ $m['author'] ] ?? '' ) ) ),
				'role'     => $who[ $m['author'] ] ?? $m['author'],
				'audience' => $m['audience'],
				'body'     => $m['body'],
				'when'     => substr( (string) $m['created_at'], 0, 16 ),
			),
			$msgs
		);
		if ( 'team' === $for && $r['booking_id'] ) {
			$b                  = ATC_Checkout::find( '', (int) $r['booking_id'] );
			$out['booking_info'] = $b ? array(
				'number'   => $b['number'],
				'status'   => $b['status'],
				'traveler' => trim( $b['first_name'] . ' ' . $b['last_name'] ),
				'email'    => $b['email'],
				'phone'    => $b['phone'],
				'total'    => (int) $b['total_cents'],
				'refunded' => (int) ( $b['snap']['refunded'] ?? 0 ),
				'pickup'   => $b['legs'][0]['pickup_at'] ?? '',
				'legacy'   => ! empty( $b['snap']['legacy'] ),
				'provider_cents' => array_sum( array_map( static fn( $l ) => (int) $l['provider_cents'], $b['legs'] ) ),
			) : null;
			$out['lines'] = self::lines( (int) $r['booking_id'] );
			$out['staff'] = array_map( static fn( $u ) => array( 'id' => $u->ID, 'name' => $u->display_name ), get_users( array( 'capability' => 'atc_manage', 'fields' => array( 'ID', 'display_name' ) ) ) );
			$out['assigned_id'] = (int) $r['assigned_to'];
		}
		return $out;
	}

	/** The traveler's tickets on a booking (reservation page) or a provider's tickets (portal). */
	public static function for_booking( int $booking_id, string $for ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'tickets' ) . " WHERE booking_id = %d AND (opened_by = %s OR EXISTS (SELECT 1 FROM " . self::t( 'ticket_messages' ) . ' m WHERE m.ticket_id = ' . self::t( 'tickets' ) . '.id AND m.audience = %s)) ORDER BY id DESC', $booking_id, $for, $for ) );
		return array_values( array_filter( array_map( static fn( $id ) => self::get( (int) $id, $for ), $ids ), static fn( $t ) => ! is_wp_error( $t ) ) );
	}

	public static function for_party( int $party_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( self::select() . " WHERE t.party_id = %d AND (t.opened_by = 'provider' OR EXISTS (SELECT 1 FROM " . self::t( 'ticket_messages' ) . " m WHERE m.ticket_id = t.id AND m.audience = 'provider')) ORDER BY t.last_reply_at DESC LIMIT 100", $party_id ), ARRAY_A ); // phpcs:ignore
		return array_map( static fn( $r ) => self::shape( $r, 'provider' ), $rows );
	}

	public static function party_of( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT party_id FROM ' . self::t( 'tickets' ) . ' WHERE id = %d', $id ) );
	}

	public static function booking_of( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT booking_id FROM ' . self::t( 'tickets' ) . ' WHERE id = %d', $id ) );
	}

	/* ---------- Our team ---------- */

	public static function update( int $id, array $in ) {
		global $wpdb;
		$tk = self::row( $id );
		if ( ! $tk ) {
			return new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) );
		}
		$set = array();
		if ( isset( $in['status'] ) && isset( self::statuses()[ $in['status'] ] ) ) {
			$set['status'] = $in['status'];
		}
		if ( array_key_exists( 'assigned_to', $in ) ) {
			$set['assigned_to'] = (int) $in['assigned_to'] ?: null;
		}
		if ( isset( $in['urgent'] ) ) {
			$set['urgent'] = $in['urgent'] ? 1 : 0;
		}
		if ( isset( $in['type'] ) && isset( self::types()[ $in['type'] ] ) ) {
			$set['type'] = $in['type'];
		}
		if ( isset( $in['money'] ) ) {
			$set['money_cents'] = max( 0, (int) round( 100 * (float) $in['money'] ) );
		}
		if ( $set ) {
			$wpdb->update( self::t( 'tickets' ), $set + array( 'updated_at' => self::now() ), array( 'id' => $id ) );
		}
		if ( isset( $in['hold'] ) ) {
			$in['hold'] ? self::hold( $id ) : self::release( $id );
		}
		return self::get( $id );
	}

	/** The booking's money lines, for the ticket screen. */
	private static function lines( int $booking_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT x.id, x.role, x.amount_cents, x.status, x.payout_id, p.name FROM ' . self::t( 'ledger' ) . ' x LEFT JOIN ' . self::t( 'parties' ) . ' p ON p.id = x.party_id WHERE x.booking_id = %d ORDER BY x.id', $booking_id ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'role' => $r['role'], 'name' => (string) $r['name'], 'amount' => (int) $r['amount_cents'], 'status' => $r['status'], 'in_payout' => (bool) $r['payout_id'] ), $rows );
	}

	/** Hold the booking's provider and affiliate money that is not paid yet. */
	public static function hold( int $id ): void {
		global $wpdb;
		$bid = self::booking_of( $id );
		if ( ! $bid ) {
			return;
		}
		$l     = self::t( 'ledger' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$l} WHERE booking_id = %d AND role IN ('provider','affiliate','referrer') AND status IN ('pending','earned') AND payout_id IS NULL", $bid ), ARRAY_A ); // phpcs:ignore
		$holds = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT holds FROM ' . self::t( 'tickets' ) . ' WHERE id = %d', $id ) ), true );
		foreach ( $rows as $r ) {
			$holds[ (string) $r['id'] ] = $r['status'];
			$wpdb->update( $l, array( 'status' => 'on_hold', 'updated_at' => self::now() ), array( 'id' => (int) $r['id'] ) );
		}
		$wpdb->update( self::t( 'tickets' ), array( 'holds' => wp_json_encode( (object) $holds ) ), array( 'id' => $id ) );
		ATC_Install::log( 'booking', $bid, 'money_held', array( 'ticket' => $id, 'lines' => count( $rows ) ) );
	}

	/** Give the held lines back their status (the payout picks them up again). */
	public static function release( int $id, array $except = array() ): void {
		global $wpdb;
		$holds = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT holds FROM ' . self::t( 'tickets' ) . ' WHERE id = %d', $id ) ), true );
		foreach ( $holds as $line => $was ) {
			if ( in_array( (int) $line, $except, true ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . " SET status = %s, updated_at = %s WHERE id = %d AND status = 'on_hold'", $was, self::now(), (int) $line ) ); // phpcs:ignore
		}
		$wpdb->update( self::t( 'tickets' ), array( 'holds' => null ), array( 'id' => $id ) );
		if ( $holds ) {
			ATC_Install::log( 'booking', self::booking_of( $id ), 'money_released', array( 'ticket' => $id ) );
		}
	}

	/**
	 * Close the case.
	 * outcome: no_fault (provider paid), provider_fault (provider and affiliate not paid), partial (provider gets
	 * $provider_cents), no_money (nothing changes). $refund_cents goes back to the traveler through Stripe.
	 */
	public static function resolve( int $id, string $outcome, int $provider_cents, int $refund_cents, string $note, bool $tell_traveler, bool $tell_provider ) {
		global $wpdb;
		$tk = self::row( $id );
		if ( ! $tk ) {
			return new WP_Error( 'atc_not_found', 'Ticket not found.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $outcome, array( 'no_fault', 'provider_fault', 'partial', 'no_money' ), true ) ) {
			return new WP_Error( 'atc_ticket', 'Choose how it ends.', array( 'status' => 400 ) );
		}
		$bid = (int) $tk['booking_id'];
		$b   = $bid ? ATC_Checkout::find( '', $bid ) : null;
		if ( $refund_cents > 0 ) {
			if ( ! $b ) {
				return new WP_Error( 'atc_ticket', 'This ticket has no booking to refund.', array( 'status' => 409 ) );
			}
			$r = ATC_Ops::refund( $b['number'], $refund_cents, false, 'Ticket T' . $id . ( $note ? ': ' . $note : '' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$l = self::t( 'ledger' );
		if ( $bid && in_array( $outcome, array( 'provider_fault', 'partial' ), true ) ) {
			$prov = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount_cents FROM {$l} WHERE booking_id = %d AND role = 'provider' AND status IN ('pending','earned','on_hold') AND payout_id IS NULL ORDER BY id", $bid ), ARRAY_A ); // phpcs:ignore
			if ( 'provider_fault' === $outcome ) {
				foreach ( $prov as $p ) {
					$wpdb->update( $l, array( 'status' => 'cancelled', 'note' => 'Ticket T' . $id . ': provider at fault', 'updated_at' => self::now() ), array( 'id' => (int) $p['id'] ) );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE {$l} SET status = 'cancelled', note = %s, updated_at = %s WHERE booking_id = %d AND role IN ('affiliate','referrer') AND status IN ('pending','earned','on_hold') AND payout_id IS NULL", 'Ticket T' . $id . ': reversed', self::now(), $bid ) ); // phpcs:ignore
			} else {
				// Partly: the provider gets this much in total, spread over its open lines.
				$left = max( 0, $provider_cents );
				$n    = count( $prov );
				foreach ( $prov as $i => $p ) {
					$amt   = $i === $n - 1 ? $left : min( $left, (int) $p['amount_cents'] );
					$left -= $amt;
					$wpdb->update( $l, array( 'amount_cents' => $amt, 'note' => 'Ticket T' . $id . ': partly', 'updated_at' => self::now() ), array( 'id' => (int) $p['id'] ) );
				}
			}
		}
		self::release( $id );
		if ( $bid ) {
			ATC_Ops::rebalance_platform( $bid );
		}
		$wpdb->update( self::t( 'tickets' ), array( 'status' => 'resolved', 'outcome' => $outcome, 'resolved_at' => self::now(), 'due_at' => null, 'updated_at' => self::now() ), array( 'id' => $id ) );
		$labels = array( 'no_fault' => 'Provider not at fault: paid as usual', 'provider_fault' => 'Provider at fault: not paid for this booking', 'partial' => 'Partly: provider gets ' . ATC_Book::money( $provider_cents ), 'no_money' => 'Resolved, no money changes' );
		self::add_message( $id, 'team', 'Resolved. ' . $labels[ $outcome ] . ( $refund_cents ? '. Refunded ' . ATC_Book::money( $refund_cents ) . ' to the traveler.' : '.' ) . ( $note ? "\n" . sanitize_textarea_field( $note ) : '' ), 'internal' );
		if ( $tell_traveler && $b ) {
			self::add_message( $id, 'team', $note ? sanitize_textarea_field( $note ) : 'We have looked into this and closed the case.' . ( $refund_cents ? ' We refunded ' . ATC_Book::money( $refund_cents ) . ' to your card.' : '' ), 'traveler' );
			ATC_Mail::ticket_reply( $id, 'traveler', $note ?: 'We have looked into this and closed the case.' );
		}
		if ( $tell_provider && $tk['party_id'] ) {
			$msg = $labels[ $outcome ] . '.' . ( $note ? "\n" . sanitize_textarea_field( $note ) : '' );
			self::add_message( $id, 'team', $msg, 'provider' );
			ATC_Mail::ticket_reply( $id, 'provider', $msg );
		}
		if ( $b ) {
			ATC_Install::log( 'booking', $bid, 'ticket_resolved', array( 'ticket' => $id, 'outcome' => $outcome, 'refund' => $refund_cents ) );
		}
		return self::get( $id );
	}

	/* ---------- Automatic tickets ---------- */

	/** Stripe: charge.dispute.created / charge.dispute.closed. */
	public static function stripe_dispute( string $event, array $d ): void {
		global $wpdb;
		$pi = (string) ( $d['payment_intent'] ?? '' );
		$b  = $pi ? ATC_Checkout::find( '', 0, $pi ) : null;
		if ( ! $b ) {
			// An extra payment (upgrade) disputed: find it by its PaymentIntent.
			$bid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE snapshot LIKE %s LIMIT 1', '%' . $wpdb->esc_like( $pi ) . '%' ) );
			$b   = $bid ? ATC_Checkout::find( '', $bid ) : null;
		}
		$did      = (string) ( $d['id'] ?? '' );
		$existing = $did ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'tickets' ) . ' WHERE stripe_dispute = %s', $did ) ) : 0;
		$amount   = (int) ( $d['amount'] ?? 0 );
		$reason   = str_replace( '_', ' ', (string) ( $d['reason'] ?? '' ) );
		$by       = $d['evidence_details']['due_by'] ?? null;
		if ( 'charge.dispute.created' === $event && ! $existing ) {
			self::open(
				array(
					'booking_id'     => $b ? (int) $b['id'] : 0,
					'type'           => 'payment_dispute',
					'subject'        => 'Card dispute' . ( $reason ? ': ' . $reason : '' ) . ( $b ? ' · ' . $b['number'] : '' ),
					'body'           => 'The traveler’s bank opened a dispute for ' . ATC_Book::money( $amount ) . ( $reason ? ' (reason: ' . $reason . ')' : '' ) . '.' . ( $by ? ' Evidence is due by ' . gmdate( 'j M Y', (int) $by ) . ' UTC: answer in Stripe with the voucher, the service code and the trip details.' : '' ) . ' The provider’s and affiliate’s money on this booking is on hold.',
					'by'             => 'system',
					'audience'       => 'internal',
					'author_name'    => 'Stripe',
					'money'          => $amount,
					'hold'           => (bool) $b,
					'stripe_dispute' => $did,
					'urgent'         => true,
				)
			);
			return;
		}
		if ( 'charge.dispute.closed' === $event && $existing ) {
			$status = (string) ( $d['status'] ?? '' );
			self::add_message( $existing, 'system', 'Stripe closed the dispute: ' . ( 'won' === $status ? 'won, the money stays with us.' : ( 'lost' === $status ? 'lost, the bank returned ' . ATC_Book::money( $amount ) . ' to the traveler (plus Stripe’s dispute fee).' : $status . '.' ) ) . ' Decide the provider’s pay and resolve the ticket.', 'internal', 'Stripe' );
			$wpdb->update( self::t( 'tickets' ), array( 'status' => 'waiting_us', 'updated_at' => self::now() ), array( 'id' => $existing ) );
			if ( 'lost' === $status && $b ) {
				$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'party_id' => null, 'role' => 'refund', 'amount_cents' => -$amount, 'currency' => 'USD', 'status' => 'paid', 'note' => 'Dispute lost', 'created_at' => self::now(), 'updated_at' => self::now() ) );
				$b['snap']['refunded'] = (int) ( $b['snap']['refunded'] ?? 0 ) + $amount;
				ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
			}
			ATC_Mail::ticket_team( $existing, 'Dispute ' . ( $status ?: 'closed' ) );
		}
	}

	/** A rating of 2 stars or less: our team takes a look. */
	public static function low_rating( array $b, int $rating, string $comment ): void {
		self::open(
			array(
				'booking_id'  => (int) $b['id'],
				'type'        => 'low_rating',
				'subject'     => $rating . '-star rating · ' . $b['number'],
				'body'        => 'The traveler rated the ride ' . $rating . ' of 5.' . ( '' !== trim( $comment ) ? "\n“" . trim( $comment ) . '”' : '' ),
				'by'          => 'system',
				'audience'    => 'internal',
				'author_name' => 'Rating',
			)
		);
	}
}
