<?php
/**
 * Affiliates: 8% of each completed booking (rate per affiliate in Control), 30-day click tracking with
 * ?ref=CODE (last click wins), 2% to the affiliate who referred them (?invite=CODE on the sign-up page),
 * paid monthly with the providers. Commission lines live in the same ledger:
 * pending at payment → earned when the trip is completed → paid in a payout; cancelled with the booking.
 *
 * Booking for a client: an affiliate or our team signed in books on the site as usual; the booking is
 * credited to that affiliate (or marked as made by our team), and the pay page can email the client the
 * payment link instead of paying on the spot.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Affiliates {

	const COOKIE = 'atc_ref';
	const INVITE = 'atc_invite';
	const DAYS   = 30;

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'track' ), 5 );
		// The theme's application form posts here; we save the application, then let the theme send its email.
		add_action( 'admin_post_nopriv_atc_form', array( __CLASS__, 'theme_application' ), 0 );
		add_action( 'admin_post_atc_form', array( __CLASS__, 'theme_application' ), 0 );
	}

	/* ---------- Codes and lookups ---------- */

	public static function new_code(): string {
		global $wpdb;
		do {
			$code = 'AFF-' . strtoupper( wp_generate_password( 6, false, false ) );
			$code = strtr( $code, array( 'O' => 'Q', '0' => '8', 'I' => 'J', '1' => '7', 'L' => 'M' ) );
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'parties' ) . ' WHERE ref_code = %s', $code ) ) );
		return $code;
	}

	/** A live affiliate by its code (case does not matter). */
	public static function by_code( string $code ): ?array {
		global $wpdb;
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $code ) );
		if ( '' === $code ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'parties' ) . " WHERE type = 'affiliate' AND status = 'live' AND ref_code = %s", $code ), ARRAY_A );
		return $row ?: null;
	}

	public static function party_id_for_user( int $user_id ): ?int {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT pu.party_id FROM ' . self::t( 'party_users' ) . ' pu JOIN ' . self::t( 'parties' ) . " p ON p.id = pu.party_id WHERE pu.user_id = %d AND p.type = 'affiliate' AND p.status IN ('live','paused') ORDER BY pu.party_id LIMIT 1", $user_id ) );
		return $id ? (int) $id : null;
	}

	public static function current_id(): ?int {
		return is_user_logged_in() && current_user_can( 'atc_affiliate_portal' ) ? self::party_id_for_user( get_current_user_id() ) : null;
	}

	public static function rate_bp( array $party ): int {
		return null === $party['affiliate_bp'] || '' === $party['affiliate_bp'] ? (int) ATC_Settings::get( 'affiliate_bp' ) : (int) $party['affiliate_bp'];
	}

	public static function link( string $code, string $url = '' ): string {
		return add_query_arg( 'ref', $code, '' !== $url ? $url : home_url( '/' ) );
	}

	/* ---------- Tracking ---------- */

	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|headless/i', $ua );
	}

	private static function set_cookie( string $name, string $value ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( $name, $value, array( 'expires' => time() + self::DAYS * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ $name ] = $value;
	}

	/** ?ref=CODE on any page: remember the affiliate for 30 days (last click wins) and count the click. */
	public static function track(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- public tracking links.
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! empty( $_GET['invite'] ) ) {
			$a = self::by_code( sanitize_text_field( wp_unslash( $_GET['invite'] ) ) );
			if ( $a ) {
				self::set_cookie( self::INVITE, $a['ref_code'] );
			}
		}
		if ( empty( $_GET['ref'] ) || self::is_bot() ) {
			return;
		}
		$a = self::by_code( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
		// phpcs:enable
		if ( ! $a ) {
			return;
		}
		self::set_cookie( self::COOKIE, $a['ref_code'] );
		if ( ! ATC_Partner::rate_ok( 'click_' . $a['id'], 60 ) ) {
			return;
		}
		global $wpdb;
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', PHP_URL_PATH );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::t( 'clicks' ) . ' (party_id, day, landing, clicks) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', (int) $a['id'], gmdate( 'Y-m-d' ), mb_substr( $path ?: '/', 0, 190 ) ) ); // phpcs:ignore
	}

	/**
	 * A new booking: who gets the credit. A signed-in affiliate booking for a client first, then the
	 * 30-day cookie. Our team booking for a client is marked as such (no affiliate).
	 */
	public static function attach( int $booking_id ): void {
		global $wpdb;
		$snap    = array();
		$channel = 'web';
		$aff     = null;
		$self_id = self::current_id();
		if ( $self_id ) {
			$aff     = ATC_Catalog::party( $self_id );
			$channel = 'affiliate';
			$snap['booked_by'] = array( 'user' => get_current_user_id(), 'as' => 'affiliate' );
		} elseif ( is_user_logged_in() && current_user_can( 'atc_manage' ) ) {
			$channel = 'admin';
			$snap['booked_by'] = array( 'user' => get_current_user_id(), 'as' => 'team', 'name' => wp_get_current_user()->display_name );
		} elseif ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$aff = self::by_code( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
			if ( $aff ) {
				$channel = 'affiliate';
			}
		}
		$set = array( 'channel' => $channel );
		if ( $aff && 'live' === $aff['status'] ) {
			$set['affiliate_id'] = (int) $aff['id'];
			$snap['affiliate']   = array( 'code' => $aff['ref_code'], 'bp' => self::rate_bp( $aff ) );
			$parent              = $aff['parent_id'] ? ATC_Catalog::party( (int) $aff['parent_id'] ) : null;
			if ( $parent && 'affiliate' === $parent['type'] && 'live' === $parent['status'] ) {
				$set['referrer_id']        = (int) $parent['id'];
				$snap['affiliate']['ref_bp'] = (int) ATC_Settings::get( 'referral_bp' );
			}
		}
		$wpdb->update( self::t( 'bookings' ), $set, array( 'id' => $booking_id ) );
		if ( $snap ) {
			$b = ATC_Checkout::find( '', $booking_id );
			ATC_Checkout::snapshot( $booking_id, array_merge( $b['snap'], $snap ) );
		}
	}

	/** Commission on what the traveler paid (minus refunds, plus extra payments), per the rates at booking. */
	public static function shares( array $b, int $paid ): array {
		$a = (array) ( $b['snap']['affiliate'] ?? array() );
		if ( empty( $b['affiliate_id'] ) || ! $a || $paid <= 0 ) {
			return array( 'affiliate' => 0, 'referrer' => 0 );
		}
		// The affiliate's own discount code comes out of its commission (rate on the full price, minus the discount).
		$own = (array) ( $b['snap']['coupon'] ?? array() );
		$off = 'affiliate' === ( $own['owner'] ?? '' ) && (int) ( $own['party_id'] ?? 0 ) === (int) $b['affiliate_id'] ? (int) ( $own['discount'] ?? 0 ) : 0;
		return array(
			'affiliate' => max( 0, intdiv( ( $paid + $off ) * (int) ( $a['bp'] ?? 0 ), ATC_Money::BP ) - $off ),
			'referrer'  => empty( $b['referrer_id'] ) ? 0 : intdiv( $paid * (int) ( $a['ref_bp'] ?? 0 ), ATC_Money::BP ),
		);
	}

	/** At payment (ATC_Checkout::mark_paid): the commission lines. Returns what they add up to. */
	public static function on_paid( array $b ): int {
		global $wpdb;
		if ( empty( $b['affiliate_id'] ) ) {
			return 0;
		}
		$aff = ATC_Catalog::party( (int) $b['affiliate_id'] );
		// No commission on your own trips.
		if ( ! $aff || ( '' !== (string) $aff['email'] && 0 === strcasecmp( trim( (string) $aff['email'] ), trim( (string) $b['email'] ) ) && empty( $b['snap']['booked_by'] ) ) ) {
			ATC_Install::log( 'booking', (int) $b['id'], 'affiliate_skipped', array( 'why' => 'self-referral' ) );
			$wpdb->update( self::t( 'bookings' ), array( 'affiliate_id' => null, 'referrer_id' => null ), array( 'id' => (int) $b['id'] ) );
			return 0;
		}
		$s     = self::shares( $b, (int) $b['total_cents'] );
		$month = null;
		foreach ( $b['legs'] as $leg ) {
			$month = $leg['pickup_at'] ? substr( $leg['pickup_at'], 0, 7 ) : $month;
		}
		$out = 0;
		foreach ( array( 'affiliate' => (int) $b['affiliate_id'], 'referrer' => (int) $b['referrer_id'] ) as $role => $pid ) {
			if ( $pid && $s[ $role ] > 0 ) {
				$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'party_id' => $pid, 'role' => $role, 'amount_cents' => $s[ $role ], 'currency' => 'USD', 'status' => 'pending', 'service_month' => $month, 'note' => 'affiliate' === $role ? 'Commission' : 'Referral commission', 'created_at' => self::now(), 'updated_at' => self::now() ) );
				$out += $s[ $role ];
			}
		}
		return $out;
	}

	/** After a refund or an extra payment: commission follows what the traveler paid. Returns the new total. */
	public static function rebalance( array $b, int $paid ): int {
		global $wpdb;
		$l   = self::t( 'ledger' );
		$s   = self::shares( $b, $paid );
		$out = 0;
		foreach ( array( 'affiliate', 'referrer' ) as $role ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, amount_cents, payout_id FROM {$l} WHERE booking_id = %d AND role = %s AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", (int) $b['id'], $role ), ARRAY_A ); // phpcs:ignore
			if ( ! $row ) {
				continue;
			}
			if ( in_array( $row['status'], array( 'pending', 'earned' ), true ) && empty( $row['payout_id'] ) ) {
				$wpdb->update( $l, array( 'amount_cents' => $s[ $role ], 'updated_at' => self::now() ), array( 'id' => (int) $row['id'] ) );
				$out += $s[ $role ];
			} else {
				$out += (int) $row['amount_cents'];
			}
		}
		return $out;
	}

	/* ---------- Applications ---------- */

	/** The theme's /partners/affiliate/ form: save the application as a pending affiliate. */
	public static function theme_application(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the theme checks its own nonce and spam guard before mailing; we only save a pending record.
		if ( 'affiliate' !== ( isset( $_POST['atc_form'] ) ? sanitize_key( wp_unslash( $_POST['atc_form'] ) ) : '' ) || ! empty( $_POST['website_url'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_atcn'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_atcn'] ) ), 'atc_form_affiliate' ) || ! ATC_Partner::rate_ok( 'aff_apply', 5 ) ) {
			return;
		}
		$in = wp_unslash( $_POST );
		// phpcs:enable
		self::apply( (array) $in );
	}

	public static function apply( array $in ) {
		global $wpdb;
		$name  = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$email = strtolower( sanitize_email( (string) ( $in['email'] ?? '' ) ) );
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'atc_apply', 'Name and email are needed.', array( 'status' => 400 ) );
		}
		$pt = self::t( 'parties' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE type = 'affiliate' AND email = %s", $email ) ) ) {
			return new WP_Error( 'atc_apply', 'We already have an application with this email.', array( 'status' => 409 ) );
		}
		$parent = ! empty( $_COOKIE[ self::INVITE ] ) ? self::by_code( sanitize_text_field( wp_unslash( $_COOKIE[ self::INVITE ] ) ) ) : null;
		$site   = esc_url_raw( (string) ( $in['website'] ?? '' ) );
		$about  = array();
		foreach ( array( 'Audience' => 'audience', 'Monthly visitors or followers' => 'visitors' ) as $label => $k ) {
			$v = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$about[] = $label . ': ' . $v;
			}
		}
		$host = $site ? (string) wp_parse_url( $site, PHP_URL_HOST ) : '';
		$wpdb->insert(
			$pt,
			array(
				'type'         => 'affiliate',
				'name'         => mb_substr( $host ? preg_replace( '/^www\./', '', $host ) : $name, 0, 190 ),
				'contact_name' => mb_substr( $name, 0, 190 ),
				'email'        => $email,
				'website'      => mb_substr( $site, 0, 190 ) ?: null,
				'about'        => $about ? implode( "\n", $about ) : null,
				'ref_code'     => self::new_code(),
				'parent_id'    => $parent ? (int) $parent['id'] : null,
				'status'       => 'pending',
				'created_at'   => self::now(),
				'updated_at'   => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			ATC_Install::log( 'party', $id, 'applied', array( 'type' => 'affiliate', 'invited_by' => $parent ? (int) $parent['id'] : 0 ) );
		}
		return $id;
	}

	/* ---------- Our team ---------- */

	public static function list( string $status = '' ): array {
		global $wpdb;
		$pt    = self::t( 'parties' );
		$l     = self::t( 'ledger' );
		$where = in_array( $status, array( 'pending', 'live', 'paused', 'declined' ), true ) ? $wpdb->prepare( ' AND p.status = %s', $status ) : '';
		$rows  = $wpdb->get_results(
			"SELECT p.id, p.name, p.contact_name, p.email, p.website, p.ref_code, p.status, p.affiliate_bp, p.parent_id, p.created_at,
				(SELECT COALESCE(SUM(clicks),0) FROM " . self::t( 'clicks' ) . " c WHERE c.party_id = p.id) clicks,
				(SELECT COUNT(*) FROM " . self::t( 'bookings' ) . " b WHERE b.affiliate_id = p.id AND b.status IN ('confirmed','completed')) bookings,
				(SELECT COALESCE(SUM(amount_cents),0) FROM {$l} x WHERE x.party_id = p.id AND x.role IN ('affiliate','referrer') AND x.status IN ('pending','earned','paid')) earned
			FROM {$pt} p WHERE p.type = 'affiliate'{$where} ORDER BY FIELD(p.status,'pending','live','paused','declined'), p.created_at DESC",
			ARRAY_A
		); // phpcs:ignore
		$def = (int) ATC_Settings::get( 'affiliate_bp' );
		return array_map(
			static fn( $r ) => array(
				'id'       => (int) $r['id'],
				'name'     => $r['name'],
				'contact'  => (string) $r['contact_name'],
				'email'    => (string) $r['email'],
				'website'  => (string) $r['website'],
				'code'     => (string) $r['ref_code'],
				'status'   => $r['status'],
				'rate'     => null === $r['affiliate_bp'] ? $def : (int) $r['affiliate_bp'],
				'custom'   => null !== $r['affiliate_bp'],
				'invited'  => (bool) $r['parent_id'],
				'clicks'   => (int) $r['clicks'],
				'bookings' => (int) $r['bookings'],
				'earned'   => (int) $r['earned'],
				'since'    => substr( (string) $r['created_at'], 0, 10 ),
			),
			$rows
		);
	}

	public static function get( int $id ) {
		$p = ATC_Catalog::party( $id );
		if ( ! $p || 'affiliate' !== $p['type'] ) {
			return new WP_Error( 'atc_not_found', 'Affiliate not found.', array( 'status' => 404 ) );
		}
		$parent = $p['parent_id'] ? ATC_Catalog::party( (int) $p['parent_id'] ) : null;
		return array(
			'id'       => (int) $p['id'],
			'name'     => $p['name'],
			'contact'  => (string) $p['contact_name'],
			'email'    => (string) $p['email'],
			'phone'    => (string) $p['phone'],
			'website'  => (string) $p['website'],
			'about'    => (string) $p['about'],
			'code'     => (string) $p['ref_code'],
			'link'     => $p['ref_code'] ? self::link( $p['ref_code'] ) : '',
			'status'   => $p['status'],
			'rate'     => self::rate_bp( $p ),
			'custom'   => null !== $p['affiliate_bp'],
			'default'  => (int) ATC_Settings::get( 'affiliate_bp' ),
			'parent'   => $parent ? array( 'id' => (int) $parent['id'], 'name' => $parent['name'] ) : null,
			'portal'   => ATC_Partner::portal_state( $id ),
			'payout'   => ATC_Partner::payout_summary( $id ),
			'stats'    => self::stats( $id ),
			'bookings' => self::bookings( $id, 1, true )['rows'],
			'referred' => self::referred( $id ),
			'since'    => substr( (string) $p['created_at'], 0, 10 ),
		);
	}

	/** Our team: status (approve sends the portal invitation), commission rate. */
	public static function save( int $id, array $in ) {
		global $wpdb;
		$p = ATC_Catalog::party( $id );
		if ( ! $p || 'affiliate' !== $p['type'] ) {
			return new WP_Error( 'atc_not_found', 'Affiliate not found.', array( 'status' => 404 ) );
		}
		$set = array( 'updated_at' => self::now() );
		if ( isset( $in['status'] ) && in_array( $in['status'], array( 'pending', 'live', 'paused', 'declined' ), true ) ) {
			$set['status'] = $in['status'];
		}
		if ( array_key_exists( 'rate', $in ) ) {
			$rate = '' === (string) $in['rate'] || null === $in['rate'] ? null : (int) round( 100 * (float) $in['rate'] );
			if ( null !== $rate && ( $rate < 0 || $rate > 3000 ) ) {
				return new WP_Error( 'atc_rate', 'The commission goes from 0% to 30%.', array( 'status' => 400 ) );
			}
			$set['affiliate_bp'] = $rate;
		}
		foreach ( array( 'name' => 190, 'contact_name' => 190, 'phone' => 40 ) as $k => $len ) {
			if ( isset( $in[ $k ] ) ) {
				$set[ $k ] = mb_substr( trim( sanitize_text_field( (string) $in[ $k ] ) ), 0, $len );
			}
		}
		if ( isset( $in['email'] ) ) {
			$e = sanitize_email( (string) $in['email'] );
			if ( ! is_email( $e ) ) {
				return new WP_Error( 'atc_email', 'Enter a valid email.', array( 'status' => 400 ) );
			}
			$set['email'] = strtolower( $e );
		}
		if ( empty( $p['ref_code'] ) ) {
			$set['ref_code'] = self::new_code();
		}
		$wpdb->update( self::t( 'parties' ), $set, array( 'id' => $id ) );
		ATC_Install::log( 'party', $id, 'affiliate_saved', array_diff_key( $set, array( 'updated_at' => 1 ) ) );
		$approved = 'live' === ( $set['status'] ?? '' ) && 'live' !== $p['status'];
		if ( $approved && ! $p['invited_at'] ) {
			$sent = self::invite( $id );
			if ( is_wp_error( $sent ) ) {
				return array_merge( self::get( $id ), array( 'warning' => 'Approved, but the invitation was not sent: ' . $sent->get_error_message() ) );
			}
		}
		return self::get( $id );
	}

	/** The affiliate's sign-in, and an email with a link to choose a password. */
	public static function invite( int $id ) {
		global $wpdb;
		$p     = ATC_Catalog::party( $id );
		$email = sanitize_email( (string) $p['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'atc_invite', 'This affiliate has no valid email.', array( 'status' => 400 ) );
		}
		$uid  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d ORDER BY user_id LIMIT 1', $id ) );
		$user = $uid ? get_userdata( $uid ) : get_user_by( 'email', $email );
		if ( $user && ( $user->has_cap( 'edit_posts' ) || $user->has_cap( 'atc_manage' ) ) ) {
			return new WP_Error( 'atc_invite', 'This email belongs to a team account.', array( 'status' => 400 ) );
		}
		if ( ! $user ) {
			$login = sanitize_user( strtolower( strstr( $email, '@', true ) ), true ) ?: 'affiliate';
			$base  = $login;
			$i     = 2;
			while ( username_exists( $login ) ) {
				$login = $base . $i++;
			}
			$new = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 32, true, true ), 'display_name' => $p['contact_name'] ?: $p['name'], 'role' => 'atc_affiliate' ) );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$user = get_userdata( $new );
		} elseif ( ! $user->has_cap( 'atc_affiliate_portal' ) ) {
			$user->add_role( 'atc_affiliate' );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT party_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d AND user_id = %d', $id, $user->ID ) ) ) {
			$wpdb->insert( self::t( 'party_users' ), array( 'party_id' => $id, 'user_id' => $user->ID, 'role' => 'owner' ) );
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$link    = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $user->user_login ) ), home_url( '/portal/set-password/' ) );
		$name    = $p['contact_name'] ?: $p['name'];
		$rate    = self::rate_bp( $p ) / 100;
		$subject = 'Welcome to the Airport Transportation affiliate program';
		$body    = "Hi {$name},\n\nYour affiliate account is approved. You earn {$rate}% of every completed booking made through your links, for 30 days after each click.\n\nChoose your password here (the link works for 24 hours):\n{$link}\n\nYour link: " . self::link( (string) $p['ref_code'] ) . "\n\nIn your affiliate panel you can make a link to any page, see your clicks, bookings and commission, book for your clients, and add your payout details. Commissions are paid on the " . (int) ATC_Settings::get( 'payout_day' ) . "th of each month once you have at least " . ATC_Book::money( (int) ATC_Settings::get( 'payout_minimum_cents' ) ) . ".\n\nSign in any time at " . home_url( '/login/' ) . "\n\nAirport Transportation";
		$to      = $email;
		$test    = (string) ATC_Settings::get( 'invite_test_to' );
		if ( '' !== $test ) {
			$to      = $test;
			$subject = '[TEST, would go to ' . $email . '] ' . $subject;
		}
		if ( ! wp_mail( $to, $subject, $body, array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) ) ) {
			return new WP_Error( 'atc_invite_mail', 'The email could not be sent.', array( 'status' => 500 ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'parties' ) . ' SET invited_at = %s, invite_count = invite_count + 1 WHERE id = %d', self::now(), $id ) );
		ATC_Install::log( 'party', $id, 'invited', array( 'to' => $to, 'test' => '' !== $test ) );
		return ATC_Partner::portal_state( $id ) + array( 'sent_to' => $to, 'test' => '' !== $test );
	}

	/* ---------- Numbers (Control and the affiliate panel) ---------- */

	public static function stats( int $id, string $since = '' ): array {
		global $wpdb;
		$l      = self::t( 'ledger' );
		$b      = self::t( 'bookings' );
		$since  = $since ?: gmdate( 'Y-m-01' );
		$clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(clicks),0) FROM ' . self::t( 'clicks' ) . ' WHERE party_id = %d AND day >= %s', $id, $since ) );
		$books  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b} WHERE affiliate_id = %d AND status IN ('confirmed','completed') AND created_at >= %s", $id, $since ) ); // phpcs:ignore
		$sales  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_cents),0) FROM {$b} WHERE affiliate_id = %d AND status IN ('confirmed','completed') AND created_at >= %s", $id, $since ) ); // phpcs:ignore
		$money  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COALESCE(SUM(amount_cents),0) amt FROM {$l} WHERE party_id = %d AND role IN ('affiliate','referrer') GROUP BY status", $id ), OBJECT_K ); // phpcs:ignore
		$get    = static fn( $k ) => isset( $money[ $k ] ) ? (int) $money[ $k ]->amt : 0;
		return array(
			'since'      => $since,
			'clicks'     => $clicks,
			'bookings'   => $books,
			'sales'      => $sales,
			'conversion' => $clicks ? round( 100 * $books / $clicks, 1 ) : 0,
			'pending'    => $get( 'pending' ),
			'earned'     => $get( 'earned' ),
			'paid'       => $get( 'paid' ),
		);
	}

	/** Bookings credited to an affiliate: no traveler contacts, first name and initial only. */
	public static function bookings( int $id, int $page = 1, bool $all = false ): array {
		global $wpdb;
		$b    = self::t( 'bookings' );
		$l    = self::t( 'ledger' );
		$per  = $all ? 50 : 25;
		$off  = max( 0, $page - 1 ) * $per;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.number, b.status, b.total_cents, b.first_name, b.last_name, b.created_at, b.snapshot, b.referrer_id,
					(SELECT MIN(pickup_at) FROM " . self::t( 'booking_legs' ) . " g WHERE g.booking_id = b.id) pickup,
					(SELECT x.amount_cents FROM {$l} x WHERE x.booking_id = b.id AND x.party_id = %d AND x.role IN ('affiliate','referrer') ORDER BY x.id DESC LIMIT 1) commission,
					(SELECT x.status FROM {$l} x WHERE x.booking_id = b.id AND x.party_id = %d AND x.role IN ('affiliate','referrer') ORDER BY x.id DESC LIMIT 1) cstatus,
					(b.affiliate_id = %d) own
				FROM {$b} b WHERE (b.affiliate_id = %d OR b.referrer_id = %d) AND b.status <> 'held' ORDER BY b.created_at DESC LIMIT %d OFFSET %d",
				$id,
				$id,
				$id,
				$id,
				$id,
				$per,
				$off
			),
			ARRAY_A
		); // phpcs:ignore
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b} WHERE (affiliate_id = %d OR referrer_id = %d) AND status <> 'held'", $id, $id ) ); // phpcs:ignore
		$label = array( 'pending' => 'Pending until the trip', 'earned' => 'Earned', 'paid' => 'Paid', 'cancelled' => 'Cancelled' );
		$out   = array();
		foreach ( $rows as $r ) {
			$snap  = json_decode( (string) $r['snapshot'], true ) ?: array();
			$out[] = array(
				'number'     => $r['number'],
				'booked'     => substr( (string) $r['created_at'], 0, 10 ),
				'trip'       => $r['pickup'] ? substr( (string) $r['pickup'], 0, 10 ) : '',
				'where'      => trim( ( $snap['iata'] ?? '' ) . ' · ' . ( $snap['to'] ?? '' ), ' ·' ),
				'traveler'   => trim( $r['first_name'] . ' ' . mb_substr( (string) $r['last_name'], 0, 1 ) . ( $r['last_name'] ? '.' : '' ) ),
				'status'     => $r['status'],
				'total'      => (int) $r['total_cents'],
				'commission' => null === $r['commission'] ? 0 : (int) $r['commission'],
				'cstatus'    => $label[ (string) $r['cstatus'] ] ?? '',
				'referral'   => ! (int) $r['own'],
				'for_client' => ! empty( $snap['booked_by'] ),
			);
		}
		return array( 'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per ) ) );
	}

	/** Affiliates who signed up with this affiliate's invitation link. */
	public static function referred( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, status, created_at FROM ' . self::t( 'parties' ) . " WHERE type = 'affiliate' AND parent_id = %d ORDER BY created_at DESC", $id ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'since' => substr( (string) $r['created_at'], 0, 10 ) ), $rows );
	}

	/** The affiliate panel's home. */
	public static function me( int $id ): array {
		$p   = ATC_Catalog::party( $id );
		$day = (int) ATC_Settings::get( 'payout_day' );
		return array(
			'id'       => $id,
			'name'     => $p['name'],
			'contact'  => (string) $p['contact_name'],
			'status'   => $p['status'],
			'code'     => (string) $p['ref_code'],
			'link'     => self::link( (string) $p['ref_code'] ),
			'invite'   => add_query_arg( 'invite', $p['ref_code'], home_url( '/partners/affiliate/' ) ),
			'rate'     => self::rate_bp( $p ),
			'ref_rate' => (int) ATC_Settings::get( 'referral_bp' ),
			'days'     => self::DAYS,
			'month'    => self::stats( $id ),
			'all'      => self::stats( $id, '2000-01-01' ),
			'payout'   => ATC_Partner::payout_summary( $id ),
			'minimum'  => (int) ATC_Settings::get( 'payout_minimum_cents' ),
			'pay_day'  => $day,
			'book_url' => home_url( '/' ),
			'home'     => home_url( '/' ),
			'referred' => self::referred( $id ),
			'payouts'  => self::payouts( $id ),
			'support'  => ATC_Settings::get( 'from_email' ),
		);
	}

	public static function payouts( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, period, amount_cents, status, reference, paid_at FROM ' . self::t( 'payouts' ) . ' WHERE party_id = %d ORDER BY period DESC LIMIT 24', $id ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'period' => $r['period'], 'amount' => (int) $r['amount_cents'], 'status' => $r['status'], 'reference' => (string) $r['reference'], 'paid_at' => $r['paid_at'] ? substr( $r['paid_at'], 0, 10 ) : '' ), $rows );
	}

	/** Clicks per day for the last 30 days, and the pages people landed on. */
	public static function clicks( int $id ): array {
		global $wpdb;
		$c     = self::t( 'clicks' );
		$from  = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$days  = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(clicks) n FROM {$c} WHERE party_id = %d AND day >= %s GROUP BY day", $id, $from ), OBJECT_K ); // phpcs:ignore
		$pages = $wpdb->get_results( $wpdb->prepare( "SELECT landing, SUM(clicks) n FROM {$c} WHERE party_id = %d AND day >= %s GROUP BY landing ORDER BY n DESC LIMIT 10", $id, $from ), ARRAY_A ); // phpcs:ignore
		$out   = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$d     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = array( 'day' => $d, 'clicks' => isset( $days[ $d ] ) ? (int) $days[ $d ]->n : 0 );
		}
		return array( 'days' => $out, 'pages' => array_map( static fn( $r ) => array( 'page' => $r['landing'], 'clicks' => (int) $r['n'] ), $pages ) );
	}

	/** A tracked link to any page of our site. */
	public static function make_link( int $id, string $url ) {
		$p    = ATC_Catalog::party( $id );
		$url  = esc_url_raw( trim( $url ) );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || ( '' !== $host && preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home ) ) ) {
			return new WP_Error( 'atc_link', 'Paste the address of a page on ' . $home . '.', array( 'status' => 400 ) );
		}
		if ( '' === $host ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		return array( 'link' => self::link( (string) $p['ref_code'], remove_query_arg( 'ref', $url ) ) );
	}

	/* ---------- Booking for a client: send the payment link ---------- */

	public static function can_send_link( array $b ): bool {
		if ( 'held' !== $b['status'] || ! is_user_logged_in() ) {
			return false;
		}
		$by = (array) ( $b['snap']['booked_by'] ?? array() );
		return current_user_can( 'atc_manage' ) || ( (int) ( $by['user'] ?? 0 ) === get_current_user_id() );
	}

	public static function send_link( string $number, string $key ) {
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b || ! self::can_send_link( $b ) ) {
			return new WP_Error( 'atc_forbidden', 'Only the person who made this booking can send its payment link.', array( 'status' => 403 ) );
		}
		if ( empty( $b['snap']['details'] ) ) {
			return new WP_Error( 'atc_details', 'Fill in the trip details first.', array( 'status' => 409 ) );
		}
		ATC_Mail::payment_link( $b, ATC_Checkout::pay_url( $b['number'], $key ) );
		$b['snap']['link_sent'] = self::now();
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
		ATC_Install::log( 'booking', (int) $b['id'], 'payment_link_sent', array( 'to' => $b['email'] ) );
		return array( 'sent' => true, 'to' => $b['email'] );
	}
}
