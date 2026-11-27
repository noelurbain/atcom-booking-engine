<?php
/**
 * Provider self-service: sign-up, setup steps, documents, payout details, agreement.
 * Everything here is scoped to the signed-in user's own company.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Partner {

	const AGREEMENT_VERSION = '2026-11-25';

	/** Documents every provider sends before going live. */
	public static function doc_types(): array {
		return array(
			'permit'       => array( 'Operating license or permit', 'The permit that lets you carry paying passengers (for example your SCT federal permit or a state concession).', true ),
			'registration' => array( 'Transport registration', 'Your company’s registration with the transport ministry or authority.', false ),
			'insurance'    => array( 'Liability insurance certificate', 'Company liability cover for passengers, not only cover on each vehicle.', true ),
		);
	}

	private static function t( string $name ): string {
		return ATC_Schema::table( $name );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/* ---------- Who is signed in ---------- */

	public static function party_id_for_user( int $user_id ): ?int {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT pu.party_id FROM ' . self::t( 'party_users' ) . ' pu JOIN ' . self::t( 'parties' ) . " p ON p.id = pu.party_id WHERE pu.user_id = %d AND p.type = 'provider' AND p.status <> 'archived' ORDER BY pu.party_id LIMIT 1", $user_id ) );
		return $id ? (int) $id : null;
	}

	public static function current_party_id(): ?int {
		return current_user_can( 'atc_provider_portal' ) ? self::party_id_for_user( get_current_user_id() ) : null;
	}

	/* ---------- Sign-up ---------- */

	/**
	 * Creates the WordPress account, the provider record and links them.
	 *
	 * @return int|WP_Error user id
	 */
	public static function signup( array $in ) {
		global $wpdb;

		$company = trim( sanitize_text_field( $in['company'] ?? '' ) );
		$contact = trim( sanitize_text_field( $in['contact'] ?? '' ) );
		$email   = sanitize_email( $in['email'] ?? '' );
		$phone   = preg_replace( '/[^0-9+ ()\-]/', '', (string) ( $in['phone'] ?? '' ) );
		$pass    = (string) ( $in['password'] ?? '' );
		$place   = sanitize_text_field( (string) ( $in['airport_place_id'] ?? '' ) );
		$airport = ATC_Catalog::airport( (int) ( $in['airport_id'] ?? 0 ) );
		if ( $airport && 'active' !== $airport['status'] ) {
			$airport = null;
		}
		// Typed but not picked from the list (no JavaScript): accept it when it names exactly one of our airports.
		if ( ! $airport && '' === $place && '' !== trim( (string) ( $in['airport_q'] ?? '' ) ) ) {
			$match = ATC_Catalog::search_airports( sanitize_text_field( (string) $in['airport_q'] ), 2 );
			if ( 1 === count( $match ) ) {
				$airport = ATC_Catalog::airport( (int) $match[0]['id'] );
			}
		}
		$about   = mb_substr( sanitize_textarea_field( (string) ( $in['about'] ?? '' ) ), 0, 2000 );
		$fleet   = sanitize_text_field( (string) ( $in['fleet'] ?? '' ) );

		$errors = new WP_Error();
		if ( '' === $company ) {
			$errors->add( 'company', 'Enter your company name.' );
		}
		if ( '' === $contact ) {
			$errors->add( 'contact', 'Enter your name.' );
		}
		if ( ! is_email( $email ) ) {
			$errors->add( 'email', 'Enter a valid email address.' );
		} elseif ( email_exists( $email ) ) {
			$errors->add( 'email', 'An account with this email already exists. Sign in instead.' );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 8 ) {
			$errors->add( 'phone', 'Enter a mobile number with WhatsApp, with country code.' );
		}
		if ( strlen( $pass ) < 10 ) {
			$errors->add( 'password', 'Use a password of at least 10 characters.' );
		}
		if ( ! $airport && '' === $place ) {
			$errors->add( 'airport_id', 'Search for your main airport and pick it from the list.' );
		}
		$found = ATC_Directory::contact_in( $about );
		if ( '' !== $found ) {
			$errors->add( 'about', 'This text contains ' . $found . '. It appears on your public directory profile, so leave out phone numbers, emails and websites: travelers book through us.' );
		}
		if ( empty( $in['accept'] ) ) {
			$errors->add( 'accept', 'Please tick the box to accept the Provider Agreement.' );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// An airport we do not have yet is added from Google, once everything else on the form is right.
		$new_airport = false;
		if ( ! $airport ) {
			$airport = ATC_Catalog::airport_from_google( $place );
			if ( is_wp_error( $airport ) ) {
				$errors->add( 'airport_id', $airport->get_error_message() );
				return $errors;
			}
			$new_airport = ! empty( $airport['is_new'] );
		}

		$login = sanitize_user( strtolower( strstr( $email, '@', true ) ), true ) ?: 'provider';
		$base  = $login;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}

		$parts   = preg_split( '/\s+/', $contact, 2 );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => $contact,
				'first_name'   => $parts[0] ?? '',
				'last_name'    => $parts[1] ?? '',
				'role'         => 'atc_provider',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$wpdb->insert(
			self::t( 'parties' ),
			array(
				'type'            => 'provider',
				'name'            => mb_substr( $company, 0, 190 ),
				'contact_name'    => mb_substr( $contact, 0, 190 ),
				'about'           => '' === $about ? null : $about,
				'fleet'           => '' === $fleet ? null : wp_json_encode( array( 'size' => mb_substr( $fleet, 0, 20 ), 'updated_at' => self::now() ) ),
				'email'           => $email,
				'phone'           => mb_substr( $phone, 0, 40 ),
				'country'         => $airport['country'],
				'home_airport_id' => (int) $airport['id'],
				'payout_currency' => 'MX' === $airport['country'] ? 'MXN' : 'USD',
				'status'          => 'pending',
				'created_at'      => self::now(),
				'updated_at'      => self::now(),
			)
		);
		$party_id = (int) $wpdb->insert_id;
		$wpdb->insert( self::t( 'party_users' ), array( 'party_id' => $party_id, 'user_id' => $user_id, 'role' => 'owner' ) );
		update_user_meta( $user_id, 'atc_password_set', time() );
		ATC_Install::log( 'party', $party_id, 'signed_up', array( 'user_id' => $user_id ) );
		// Ticking the box on the sign-up form is the provider's signature on the agreement.
		self::accept_agreement( $party_id );
		ATC_Directory::$claiming = (int) ( $in['claim'] ?? 0 );
		do_action( 'atc_provider_signed_up', $party_id );
		ATC_Directory::$claiming = 0;

		ATC_Mail::defer( static fn() => self::mail_welcome( $email, $contact, $company ) );
		ATC_Mail::defer( static fn() => self::mail_team( 'New provider sign-up: ' . $company, "Company: {$company}\nContact: {$contact}\nEmail: {$email}\nWhatsApp: {$phone}\nMain airport: " . ATC_Catalog::airport_label( $airport ) . ( $new_airport ? "\n\nNEW AIRPORT, added from Google: {$airport['name']} ({$airport['city']}, {$airport['country']}, time zone {$airport['timezone']}). It has no IATA code and no zones yet." : '' ) . ( '' !== $fleet ? "\nFleet size: {$fleet}" : '' ) . "\n\nAbout:\n{$about}\n\nReview: " . home_url( '/control/providers/' . $party_id . '/' ) ) );

		return (int) $user_id;
	}

	private static function mail_welcome( string $email, string $name, string $company ): void {
		$subject = 'Welcome to Airport Transportation, ' . $company;
		$body    = "Hi {$name},\n\nThanks for signing up {$company} as a transport provider.\n\nNext, finish your setup in the provider portal, step by step: company details, vehicles, the zones you serve and your prices. You go live as soon as those are done.\n\n" . home_url( '/portal/' ) . "\n\nThen add your payout details and upload your documents (permit and insurance).\n\nAirport Transportation";
		wp_mail( $email, $subject, $body, array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
	}

	public static function mail_team( string $subject, string $body ): void {
		wp_mail( ATC_Settings::get( 'from_email' ), $subject, $body );
	}

	/** Max attempts per IP per hour (10 for sign-ups). */
	public static function rate_ok( string $bucket, int $max = 10 ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'atc_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------- Invitations (providers brought over from the old site) ---------- */

	/** not_invited | invited | active, plus dates, for the control panel. */
	public static function portal_state( int $party_id ): array {
		global $wpdb;
		$p   = $wpdb->get_row( $wpdb->prepare( 'SELECT invited_at, invite_count FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $party_id ), ARRAY_A );
		$uid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d ORDER BY user_id LIMIT 1', $party_id ) );
		$set = $uid ? get_user_meta( $uid, 'atc_password_set', true ) : '';
		return array(
			'state'        => $set ? 'active' : ( ! empty( $p['invited_at'] ) ? 'invited' : 'not_invited' ),
			'invited_at'   => $p['invited_at'] ?? null,
			'invite_count' => (int) ( $p['invite_count'] ?? 0 ),
			'active_since' => $set ? gmdate( 'Y-m-d', (int) $set ) : null,
		);
	}

	/** When the provider last opened their portal (UTC date and time), or null. */
	public static function last_seen( int $party_id ): ?string {
		global $wpdb;
		$uid  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d ORDER BY user_id LIMIT 1', $party_id ) );
		$seen = $uid ? (int) get_user_meta( $uid, 'atc_last_seen', true ) : 0;
		return $seen ? gmdate( 'Y-m-d H:i', $seen ) : null;
	}

	/** Every provider's onboarding at a glance, for the control panel. */
	public static function onboarding(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT p.id, p.name, p.email, p.country, p.status, p.invited_at, p.invite_count, p.created_at, p.fleet, a.iata FROM ' . self::t( 'parties' ) . ' p LEFT JOIN ' . self::t( 'airports' ) . " a ON a.id = p.home_airport_id WHERE p.type = 'provider' AND p.status <> 'archived' ORDER BY p.name", ARRAY_A ); // phpcs:ignore
		$out = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['id'];
			$state = self::portal_state( $id );
			$uid   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d ORDER BY user_id LIMIT 1', $id ) );
			$seen  = $uid ? (int) get_user_meta( $uid, 'atc_last_seen', true ) : 0;
			$steps = array();
			foreach ( self::steps( $id ) as $k => $st ) {
				$steps[ $k ] = array( 'state' => $st['state'], 'label' => $st['label'] );
			}
			$f     = self::fleet_shape( $r['fleet'] ? (array) json_decode( $r['fleet'], true ) : array() );
			$out[] = array(
				'id'        => $id,
				'name'      => $r['name'],
				'email'     => (string) $r['email'],
				'country'   => (string) $r['country'],
				'airport'   => (string) $r['iata'],
				'status'    => $r['status'],
				'group'     => self::invite_group( $r + array( 'id' => $id ) ),
				'portal'    => $state,
				'last_seen' => $seen ? gmdate( 'Y-m-d H:i', $seen ) : null,
				'steps'     => $steps,
				'fleet'     => $f['total'],
			);
		}
		return $out;
	}

	/* ---------- Fleet inventory (our team only, never shown on the directory) ---------- */

	/**
	 * How many vehicles the provider owns in each of our categories, across all their airports.
	 * Optional: it never blocks a setup step. Used by our team to plan large groups and events (DMC needs).
	 */
	public static function fleet( int $party_id ): array {
		global $wpdb;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT fleet FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $party_id ) );
		return self::fleet_shape( '' === $raw ? array() : (array) json_decode( $raw, true ) );
	}

	private static function fleet_shape( array $f ): array {
		$counts = array();
		foreach ( (array) ( $f['counts'] ?? array() ) as $type => $n ) {
			if ( (int) $n > 0 ) {
				$counts[ (string) (int) $type ] = (int) $n;
			}
		}
		return array(
			'counts'     => (object) $counts,
			'total'      => array_sum( $counts ),
			'drivers'    => isset( $f['drivers'] ) && '' !== $f['drivers'] ? (int) $f['drivers'] : null,
			'size'       => (string) ( $f['size'] ?? '' ),   // the range picked on the sign-up form
			'notes'      => (string) ( $f['notes'] ?? '' ),  // our team's notes, staff only
			'updated_at' => (string) ( $f['updated_at'] ?? '' ),
		);
	}

	/** Save the counts. $staff lets our team keep private notes too. */
	public static function save_fleet( int $party_id, array $in, bool $staff = false ) {
		global $wpdb;
		$cur    = self::fleet( $party_id );
		$types  = array_map( static fn( $t ) => (string) (int) $t['id'], ATC_Catalog::vehicle_types() );
		$counts = array();
		foreach ( (array) ( $in['counts'] ?? array() ) as $type => $n ) {
			$type = (string) (int) $type;
			$n    = (int) $n;
			if ( in_array( $type, $types, true ) && $n > 0 ) {
				$counts[ $type ] = min( $n, 9999 );
			}
		}
		$drivers = $in['drivers'] ?? null;
		$data    = array(
			'counts'     => $counts,
			'drivers'    => ( null === $drivers || '' === $drivers ) ? null : max( 0, min( 9999, (int) $drivers ) ),
			'size'       => $cur['size'],
			'notes'      => $staff && isset( $in['notes'] ) ? mb_substr( sanitize_textarea_field( (string) $in['notes'] ), 0, 2000 ) : $cur['notes'],
			'updated_at' => self::now(),
		);
		$wpdb->update( self::t( 'parties' ), array( 'fleet' => wp_json_encode( $data ), 'updated_at' => self::now() ), array( 'id' => $party_id ) );
		ATC_Install::log( 'party', $party_id, 'fleet_saved', array( 'total' => array_sum( $counts ), 'by' => $staff ? 'staff' : 'provider' ) );
		return self::fleet( $party_id );
	}

	/** One row per provider, for the "Fleet inventory" download in the control panel. */
	public static function fleet_csv(): string {
		global $wpdb;
		$types = ATC_Catalog::vehicle_types();
		$rows  = $wpdb->get_results( 'SELECT p.id, p.name, p.country, p.status, p.fleet, a.iata, a.city FROM ' . self::t( 'parties' ) . ' p LEFT JOIN ' . self::t( 'airports' ) . " a ON a.id = p.home_airport_id WHERE p.type = 'provider' AND p.status <> 'archived' ORDER BY p.name", ARRAY_A ); // phpcs:ignore
		$head  = array_merge( array( 'Provider', 'Status', 'Country', 'Main airport', 'City' ), array_map( static fn( $t ) => $t['name'], $types ), array( 'Total vehicles', 'Drivers', 'Size at sign-up', 'Updated', 'Notes' ) );
		$out   = fopen( 'php://temp', 'r+' );
		fputcsv( $out, $head );
		foreach ( $rows as $r ) {
			$f      = self::fleet_shape( $r['fleet'] ? (array) json_decode( $r['fleet'], true ) : array() );
			$counts = (array) $f['counts'];
			$line   = array( $r['name'], $r['status'], $r['country'], (string) $r['iata'], (string) $r['city'] );
			foreach ( $types as $t ) {
				$line[] = $counts[ (string) (int) $t['id'] ] ?? '';
			}
			array_push( $line, $f['total'] ?: '', $f['drivers'] ?? '', $f['size'], substr( $f['updated_at'], 0, 10 ), $f['notes'] );
			fputcsv( $out, $line );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return (string) $csv;
	}

	/** One time (DB 25): the sign-up form used to put "Fleet size: …" at the top of the about text. Move it. */
	public static function fleet_from_about(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, about, fleet FROM ' . self::t( 'parties' ) . " WHERE about LIKE 'Fleet size:%'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			if ( ! preg_match( '/^Fleet size:\s*(.*)$/m', (string) $r['about'], $m ) ) {
				continue;
			}
			$f         = $r['fleet'] ? (array) json_decode( $r['fleet'], true ) : array();
			$f['size'] = mb_substr( trim( $m[1] ), 0, 20 );
			$about     = trim( preg_replace( '/^Fleet size:.*\n?/m', '', (string) $r['about'] ) );
			$wpdb->update( self::t( 'parties' ), array( 'fleet' => wp_json_encode( $f ), 'about' => '' === $about ? null : $about ), array( 'id' => (int) $r['id'] ) );
		}
	}

	/**
	 * Which invitation a provider gets: 'live' (has prices, gets bookings), 'applied' (applied on the old
	 * site and never got an answer) or 'former' (in the old provider list, no active prices).
	 */
	public static function invite_group( array $p ): string {
		global $wpdb;
		if ( 'live' === $p['status'] ) {
			return 'live';
		}
		$applied = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'events' ) . " WHERE object_type = 'party' AND object_id = %d AND action = 'imported_application'", (int) $p['id'] ) );
		return $applied ? 'applied' : 'former';
	}

	/** Subject, title, body (HTML) and button of each invitation. */
	private static function invite_mail( string $group, array $p, string $link ): array {
		$e       = static fn( $t ) => esc_html( (string) $t );
		$name    = $p['contact_name'] ?: $p['name'];
		$company = $p['name'];
		$pid     = class_exists( 'ATC_Directory' ) ? ATC_Directory::post_id( (int) $p['id'] ) : 0;
		$profile = $pid ? (string) get_permalink( $pid ) : '';
		$prof    = $profile ? '<a href="' . esc_url( $profile ) . '">' . $e( $profile ) . '</a>' : '';
		$li      = static fn( array $items ) => '<ol style="padding-left:20px;margin:0 0 16px">' . implode( '', array_map( static fn( $i ) => '<li style="margin:0 0 6px">' . $i . '</li>', $items ) ) . '</ol>';
		$pw      = 'Choose your password with the button below (the link works for 7 days).';
		$backlink = 'If you add a link to us from your website, we show your website on your profile too, and you get a link back from us. Your portal shows the exact code to copy.';
		$p_      = static fn( $t ) => '<p style="margin:0 0 14px">' . $t . '</p>';
		if ( 'live' === $group ) {
			return array(
				'subject' => 'Your Airport Transportation provider account is ready: please check your details',
				'title'   => 'Your provider account is ready',
				'body'    => $p_( 'Hi ' . $e( $name ) . ',' )
					. $p_( 'We have moved Airport Transportation to a new platform, and ' . $e( $company ) . ' is already in it: your company details, vehicles, zones and prices were brought over from the previous system.' )
					. $p_( 'Please take 10 minutes to check everything is right before new bookings come in:' )
					. $li( array( $pw, 'Check your company details, vehicles, zones and prices.', 'Accept the new provider agreement and add your payout details. Our commission is now 25% (it was 30%), and your prices for travelers stay the same.', 'Upload your documents (insurance, permits).' ) )
					. ( $prof ? $p_( 'You also have a free public profile in our directory: ' . $prof . '. Check it and add your logo, photos and a short description. ' . $backlink ) : '' ),
			);
		}
		if ( 'applied' === $group ) {
			return array(
				'subject' => 'Your application to Airport Transportation: sorry for the wait',
				'title'   => 'Your provider account is ready',
				'body'    => $p_( 'Hi ' . $e( $name ) . ',' )
					. $p_( 'You applied to become a transportation provider with Airport Transportation, and we are sorry we did not get back to you. We have been building a new platform, and it is now ready for you.' )
					. $p_( $e( $company ) . ' already has an account. To start receiving bookings:' )
					. $li( array( $pw, 'Add your vehicles, the zones you cover and your prices per vehicle.', 'Accept the provider agreement, add your payout details and upload your documents.' ) )
					. $p_( 'Once those steps are done, your listing goes live and travelers can book you. It is free to join; we take a 25% commission only on the bookings we send you.' )
					. ( $prof ? $p_( 'You also get a free public profile in our directory: ' . $prof . '. ' . $backlink ) : '' ),
			);
		}
		return array(
			'subject' => 'Airport Transportation has a new provider platform: add your prices to get bookings',
			'title'   => 'Add your prices to get bookings',
			'body'    => $p_( 'Hi ' . $e( $name ) . ',' )
				. $p_( $e( $company ) . ' is a provider partner of Airport Transportation, but your account had no active prices, so travelers could not book you. Our new platform makes this much easier.' )
				. $li( array( $pw, 'Check your company details, then add your vehicles, zones and prices.', 'Accept the new provider agreement (our commission is now 25%), add your payout details and upload your documents.' ) )
				. ( $prof ? $p_( 'Your free directory profile: ' . $prof . '. ' . $backlink ) : '' ),
		);
	}

	/** Creates (or finds) the provider's sign-in and emails a link to choose a password. */
	public static function invite( int $party_id ) {
		global $wpdb;
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p ) {
			return new WP_Error( 'atc_not_found', 'Provider not found.', array( 'status' => 404 ) );
		}
		$email = sanitize_email( (string) $p['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'atc_invite', $p['name'] . ' has no valid email. Add one first.', array( 'status' => 400 ) );
		}
		$uid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d ORDER BY user_id LIMIT 1', $party_id ) );
		$user = $uid ? get_userdata( $uid ) : get_user_by( 'email', $email );
		if ( $user && ( $user->has_cap( 'edit_posts' ) || $user->has_cap( 'atc_manage' ) ) ) {
			return new WP_Error( 'atc_invite', 'This email belongs to a team account. Give the provider its own email.', array( 'status' => 400 ) );
		}
		if ( ! $user ) {
			$login = sanitize_user( strtolower( strstr( $email, '@', true ) ), true ) ?: 'provider';
			$base  = $login;
			$i     = 2;
			while ( username_exists( $login ) ) {
				$login = $base . $i++;
			}
			$new = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => $p['contact_name'] ?: $p['name'],
					'role'         => 'atc_provider',
				)
			);
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$user = get_userdata( $new );
		} elseif ( ! $user->has_cap( 'atc_provider_portal' ) ) {
			$user->add_role( 'atc_provider' );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT party_id FROM ' . self::t( 'party_users' ) . ' WHERE party_id = %d AND user_id = %d', $party_id, $user->ID ) ) ) {
			$wpdb->insert( self::t( 'party_users' ), array( 'party_id' => $party_id, 'user_id' => $user->ID, 'role' => 'owner' ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$link  = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $user->user_login ) ), home_url( '/portal/set-password/' ) );
		$group = self::invite_group( $p );
		$m     = self::invite_mail( $group, $p, $link );
		$to    = $email;
		$test  = (string) ATC_Settings::get( 'invite_test_to' );
		$subj  = $m['subject'];
		if ( '' !== $test ) {
			$to   = $test;
			$subj = '[TEST, would go to ' . $email . '] ' . $subj;
		}
		$html = ATC_Mail::wrap( $m['title'], $m['body'] . '<p style="margin:0 0 14px">Sign in any time at <a href="' . esc_url( home_url( '/login/' ) ) . '">' . esc_html( home_url( '/login/' ) ) . '</a></p>', 'Choose my password', $link );
		$sent = ATC_Mail::send( $to, $subj, $html, array( 'kind' => 'provider_invite_' . $group ) );
		if ( ! $sent ) {
			return new WP_Error( 'atc_invite_mail', 'The email could not be sent. Check the mail settings (FluentSMTP).', array( 'status' => 500 ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'parties' ) . ' SET invited_at = %s, invite_count = invite_count + 1 WHERE id = %d', self::now(), $party_id ) );
		ATC_Install::log( 'party', $party_id, 'invited', array( 'to' => $to, 'test' => '' !== $test, 'group' => $group ) );
		return self::portal_state( $party_id ) + array( 'sent_to' => $to, 'test' => '' !== $test );
	}

	/* ---------- Account: sign-in email and password ---------- */

	public static function account( int $user_id ): array {
		$u       = get_userdata( $user_id );
		$pending = get_user_meta( $user_id, 'atc_pending_email', true );
		return array(
			'email'         => $u ? $u->user_email : '',
			'pending_email' => is_array( $pending ) && $pending['expires'] > time() ? $pending['email'] : '',
		);
	}

	public static function change_password( int $user_id, string $current, string $new ) {
		$u = get_userdata( $user_id );
		if ( ! $u || ! wp_check_password( $current, $u->user_pass, $user_id ) ) {
			return new WP_Error( 'atc_pass', 'Your current password is not right.', array( 'status' => 400 ) );
		}
		if ( strlen( $new ) < 10 ) {
			return new WP_Error( 'atc_pass', 'Use a new password of at least 10 characters.', array( 'status' => 400 ) );
		}
		$res = wp_update_user( array( 'ID' => $user_id, 'user_pass' => $new ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		ATC_Install::log( 'user', $user_id, 'password_changed' );
		return array( 'ok' => true );
	}

	/** Starts an email change: nothing changes until the link sent to the new address is clicked. */
	public static function request_email_change( int $user_id, string $email, string $current ) {
		$u     = get_userdata( $user_id );
		$email = sanitize_email( $email );
		if ( ! $u || ! wp_check_password( $current, $u->user_pass, $user_id ) ) {
			return new WP_Error( 'atc_email', 'Your current password is not right.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'atc_email', 'Enter a valid email address.', array( 'status' => 400 ) );
		}
		if ( strtolower( $email ) === strtolower( $u->user_email ) ) {
			return new WP_Error( 'atc_email', 'That is already your sign-in email.', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'atc_email', 'Another account already uses this email.', array( 'status' => 400 ) );
		}
		$token = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, 'atc_pending_email', array( 'email' => $email, 'hash' => wp_hash( $token ), 'expires' => time() + DAY_IN_SECONDS ) );
		$link = add_query_arg( 'token', $token, home_url( '/portal/confirm-email/' ) );
		wp_mail( $email, 'Confirm your new sign-in email', "Hi {$u->display_name},\n\nClick this link within 24 hours to use this address to sign in to the Airport Transportation provider portal:\n\n{$link}\n\nIf you did not ask for this, ignore this email.", array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
		ATC_Install::log( 'user', $user_id, 'email_change_requested' );
		return self::account( $user_id );
	}

	public static function confirm_email_change( int $user_id, string $token ) {
		$p = get_user_meta( $user_id, 'atc_pending_email', true );
		if ( ! is_array( $p ) || $p['expires'] < time() || ! hash_equals( (string) $p['hash'], wp_hash( $token ) ) ) {
			return new WP_Error( 'atc_email', 'This link has expired or was already used. Ask for a new one under Account.' );
		}
		if ( email_exists( $p['email'] ) ) {
			return new WP_Error( 'atc_email', 'Another account already uses this email.' );
		}
		global $wpdb;
		$u   = get_userdata( $user_id );
		$old = $u->user_email;
		$res = wp_update_user( array( 'ID' => $user_id, 'user_email' => $p['email'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		delete_user_meta( $user_id, 'atc_pending_email' );
		// Keep the booking email in step when it was the same address.
		$party = self::party_id_for_user( $user_id );
		if ( $party ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'parties' ) . ' SET email = %s WHERE id = %d AND email = %s', $p['email'], $party, $old ) );
		}
		wp_mail( $old, 'Your sign-in email was changed', "The sign-in email of your Airport Transportation provider account is now {$p['email']}.\n\nIf this wasn't you, write to " . ATC_Settings::get( 'from_email' ) . ' right away.', array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
		ATC_Install::log( 'user', $user_id, 'email_changed' );
		return true;
	}

	/* ---------- Setup steps ---------- */

	public static function steps( int $party_id ): array {
		global $wpdb;
		$p        = ATC_Catalog::party( $party_id );
		$services = ATC_Catalog::services( $party_id );
		$offered  = array_filter( $services, static fn( $s ) => 'paused' !== $s['status'] );
		$zones    = array();
		foreach ( $offered as $s ) {
			$zones = array_merge( $zones, $s['zone_ids'] );
		}
		$docs     = self::documents( $party_id );
		$required = array_keys( array_filter( self::doc_types(), static fn( $d ) => $d[2] ) );
		$sent     = array();
		$approved = array();
		foreach ( $docs as $d ) {
			if ( 'rejected' !== $d['status'] ) {
				$sent[ $d['type'] ] = true;
			}
			if ( 'approved' === $d['status'] ) {
				$approved[ $d['type'] ] = true;
			}
		}
		$all_sent     = ! array_diff( $required, array_keys( $sent ) );
		$all_approved = ! array_diff( $required, array_keys( $approved ) );
		$raw          = $wpdb->get_var( $wpdb->prepare( 'SELECT payout_details FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $party_id ) );

		$st = static fn( bool $done, string $label_done, bool $waiting = false, string $label_wait = 'In review' ) => array( 'state' => $done ? 'done' : ( $waiting ? 'waiting' : 'todo' ), 'label' => $done ? $label_done : ( $waiting ? $label_wait : 'To do' ) );

		return array(
			'company'   => array( 'name' => 'Company details' ) + $st( $p['name'] && $p['phone'] && $p['email'], 'Done' ),
			'vehicles'  => array( 'name' => 'Vehicles' ) + $st( (bool) $offered, 'Done' ),
			'coverage'  => array( 'name' => 'Zones you serve' ) + $st( (bool) $zones, 'Done' ),
			'prices'    => array( 'name' => 'Prices' ) + ( function () use ( $party_id, $st ) {
				$mp = self::missing_prices( $party_id );
				$ok = $mp['total'] > 0 && 0 === $mp['missing'];
				$out = $st( $ok, 'Done' );
				if ( ! $ok && $mp['missing'] ) {
					$out['label'] = $mp['missing'] . ' missing';
				}
				return $out + array( 'missing' => $mp['missing'], 'total' => $mp['total'], 'missing_list' => $mp['list'] );
			} )(),
			'documents' => array( 'name' => 'Documents' ) + $st( $all_approved, 'Approved', $all_sent ),
			'payout'    => array( 'name' => 'Payout details' ) + $st( ! empty( $raw ), 'Done' ),
			'directory' => array( 'name' => 'Directory profile' ) + $st( ATC_Directory::step_done( $party_id ), 'Done' ),
			'agreement' => array( 'name' => 'Agreement' ) + ( function () use ( $p, $st ) {
				$out = $st( self::agreement_current( $p ), 'Accepted' );
				if ( ! empty( $p['agreement_accepted_at'] ) && 'done' !== $out['state'] ) {
					$out['label'] = 'New version';
				}
				return $out;
			} )(),
			'approval'  => array( 'name' => 'Live on the site' ) + $st( 'live' === $p['status'], 'Live', false ),
		);
	}

	/* ---------- Documents ---------- */

	public static function documents( int $party_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, original_name, mime, size, expires_on, status, note, created_at, reviewed_at FROM ' . self::t( 'documents' ) . ' WHERE party_id = %d ORDER BY created_at DESC', $party_id ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['id']  = (int) $r['id'];
			$r['url'] = self::file_url( (int) $r['id'] );
		}
		return $rows;
	}

	public static function file_url( int $doc_id ): string {
		return wp_nonce_url( home_url( '/portal/file/' . $doc_id . '/' ), 'atc_file_' . $doc_id );
	}

	private static function private_dir(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'atc-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** Saves an uploaded PDF or photo. $file is one entry of $_FILES. */
	public static function upload_document( int $party_id, string $type, array $file, string $expires_on = '' ) {
		global $wpdb;
		if ( ! isset( self::doc_types()[ $type ] ) ) {
			return new WP_Error( 'atc_doc_type', 'Unknown document.', array( 'status' => 400 ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) ) {
			return new WP_Error( 'atc_doc_file', 'The file did not arrive. Try again.', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > 10 * MB_IN_BYTES ) {
			return new WP_Error( 'atc_doc_size', 'Files up to 10 MB, please.', array( 'status' => 400 ) );
		}
		$allowed = array( 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic' );
		$mime    = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file['tmp_name'] ) : (string) ( $file['type'] ?? '' );
		if ( ! isset( $allowed[ $mime ] ) ) {
			return new WP_Error( 'atc_doc_mime', 'Upload a PDF or a photo (JPG, PNG, WEBP).', array( 'status' => 400 ) );
		}
		$name = $party_id . '-' . $type . '-' . wp_generate_password( 24, false, false ) . '.' . $allowed[ $mime ];
		$dest = self::private_dir() . '/' . $name;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'atc_doc_move', 'Could not save the file.', array( 'status' => 500 ) );
		}
		$expires = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_on ) ? $expires_on : null;
		$wpdb->insert(
			self::t( 'documents' ),
			array(
				'party_id'      => $party_id,
				'type'          => $type,
				'file'          => $name,
				'original_name' => mb_substr( sanitize_file_name( (string) $file['name'] ), 0, 190 ),
				'mime'          => $mime,
				'size'          => (int) $file['size'],
				'expires_on'    => $expires,
				'status'        => 'pending',
				'created_at'    => self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		ATC_Install::log( 'document', $id, 'uploaded', array( 'party_id' => $party_id, 'type' => $type ) );
		$p = ATC_Catalog::party( $party_id );
		self::mail_team( 'Document to review: ' . ( $p['name'] ?? '' ), self::doc_types()[ $type ][0] . ' uploaded by ' . ( $p['name'] ?? '' ) . ".\n\nReview: " . home_url( '/control/providers/' . $party_id . '/' ) );
		return array( 'id' => $id );
	}

	public static function document( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'documents' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Streams a document to its owner or to our team. */
	public static function serve_file( int $id ): void {
		$doc = self::document( $id );
		$ok  = $doc && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'atc_file_' . $id )
			&& ( current_user_can( 'atc_manage' ) || self::current_party_id() === (int) $doc['party_id'] );
		$path = $doc ? self::private_dir() . '/' . basename( $doc['file'] ) : '';
		if ( ! $ok || ! is_file( $path ) ) {
			status_header( 404 );
			wp_die( 'File not found.', 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . $doc['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', $doc['original_name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	public static function review_document( int $id, string $status, string $note = '' ) {
		global $wpdb;
		$doc = self::document( $id );
		if ( ! $doc ) {
			return new WP_Error( 'atc_not_found', 'Document not found.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
			return new WP_Error( 'atc_doc_status', 'Unknown status.', array( 'status' => 400 ) );
		}
		$wpdb->update(
			self::t( 'documents' ),
			array( 'status' => $status, 'note' => mb_substr( sanitize_text_field( $note ), 0, 255 ), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => self::now() ),
			array( 'id' => $id )
		);
		ATC_Install::log( 'document', $id, 'reviewed', array( 'status' => $status ) );
		if ( 'rejected' === $status ) {
			$p = ATC_Catalog::party( (int) $doc['party_id'] );
			if ( $p && $p['email'] ) {
				wp_mail( $p['email'], 'Please send a new ' . strtolower( self::doc_types()[ $doc['type'] ][0] ?? 'document' ), "We couldn't accept the document you sent" . ( $note ? ': ' . $note : '.' ) . "\n\nUpload a new one here: " . home_url( '/portal/documents/' ), array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
			}
		}
		return array( 'id' => $id );
	}

	/* ---------- Payout details (encrypted at rest) ---------- */

	private static function key(): string {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash( 'sha256', $salt . '|atc-payout', true );
	}

	public static function encrypt( array $data ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( wp_json_encode( $data ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'v1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore
	}

	public static function decrypt( ?string $blob ): ?array {
		if ( ! $blob || 0 !== strpos( $blob, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $blob, 3 ), true ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$pt = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		$d  = false === $pt ? null : json_decode( $pt, true );
		return is_array( $d ) ? $d : null;
	}

	/** Countries whose banks use an IBAN (Europe, the UK and a few more). */
	const IBAN_COUNTRIES = 'AD AE AL AT AZ BA BE BG BH BR CH CR CY CZ DE DK DO EE EG ES FI FO FR GB GE GI GL GR GT HR HU IE IL IQ IS IT JO KW KZ LB LC LI LT LU LV MC MD ME MK MR MT MU NL NO PK PL PS PT QA RO RS SA SC SE SI SK SM ST SV TL TN TR UA VA VG XK';

	/** Which bank details a country uses: clabe (Mexico), aba (USA), iban, or swift (account number and SWIFT/BIC). */
	public static function bank_scheme( string $country ): string {
		$c = strtoupper( $country );
		if ( 'MX' === $c ) {
			return 'clabe';
		}
		if ( 'US' === $c ) {
			return 'aba';
		}
		return in_array( $c, explode( ' ', self::IBAN_COUNTRIES ), true ) ? 'iban' : 'swift';
	}

	private static function iban_ok( string $iban ): bool {
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return false;
		}
		$r = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$n = '';
		foreach ( str_split( $r ) as $ch ) {
			$n .= ctype_alpha( $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
		}
		$mod = 0;
		foreach ( str_split( $n, 7 ) as $part ) {
			$mod = (int) ( ( $mod . $part ) % 97 );
		}
		return 1 === $mod;
	}

	/** What we show back: never the full account number. */
	public static function payout_summary( int $party_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT payout_method, payout_currency, payout_details, country FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $party_id ), ARRAY_A );
		$d   = self::decrypt( $row['payout_details'] ?? null );
		$cc  = strtoupper( (string) ( $d['country'] ?? ( $row['country'] ?? '' ) ) );
		return array(
			'method'   => $row['payout_method'] ?? 'manual',
			'currency' => $row['payout_currency'] ?? 'USD',
			'set'      => (bool) $d,
			'holder'   => $d['holder'] ?? '',
			'bank'     => $d['bank'] ?? '',
			'last4'    => $d ? substr( preg_replace( '/\s+/', '', (string) ( $d['account'] ?? '' ) ), -4 ) : '',
			'email'    => $d['email'] ?? '',
			'swift'    => $d['swift'] ?? '',
			'routing'  => $d['routing'] ?? '',
			'country'  => $cc,
			'scheme'   => self::bank_scheme( $cc ),
			'iban_countries' => self::IBAN_COUNTRIES,
		);
	}

	public static function save_payout( int $party_id, array $in ) {
		global $wpdb;
		$p        = ATC_Catalog::party( $party_id );
		$method   = in_array( $in['method'] ?? '', array( 'wise', 'bank', 'paypal' ), true ) ? $in['method'] : 'bank';
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['currency'] ?? '' ) ) );
		$currency = 3 === strlen( $currency ) ? $currency : 'USD';
		$country  = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['country'] ?? '' ) ) );
		$country  = 2 === strlen( $country ) ? $country : strtoupper( (string) ( $p['country'] ?? '' ) );
		$holder   = trim( sanitize_text_field( $in['holder'] ?? '' ) );
		$bank     = trim( sanitize_text_field( $in['bank'] ?? '' ) );
		$account  = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in['account'] ?? '' ) ) );
		$swift    = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in['swift'] ?? '' ) ) );
		$routing  = preg_replace( '/\D/', '', (string) ( $in['routing'] ?? '' ) );
		$email    = sanitize_email( $in['email'] ?? '' );
		$scheme   = self::bank_scheme( $country );

		if ( '' === $holder ) {
			return new WP_Error( 'atc_payout', 'Enter the account holder’s name.', array( 'status' => 400 ) );
		}
		if ( 'bank' === $method ) {
			if ( 'clabe' === $scheme && ! preg_match( '/^\d{18}$/', $account ) ) {
				return new WP_Error( 'atc_payout', 'A CLABE has exactly 18 digits.', array( 'status' => 400 ) );
			}
			if ( 'iban' === $scheme && ! self::iban_ok( $account ) ) {
				return new WP_Error( 'atc_payout', 'That IBAN does not look right. Copy it from your bank statement or app.', array( 'status' => 400 ) );
			}
			if ( 'aba' === $scheme && ( ! preg_match( '/^\d{4,17}$/', $account ) || ! preg_match( '/^\d{9}$/', $routing ) ) ) {
				return new WP_Error( 'atc_payout', 'Enter the account number and the 9-digit routing number (ABA).', array( 'status' => 400 ) );
			}
			if ( 'swift' === $scheme && strlen( $account ) < 4 ) {
				return new WP_Error( 'atc_payout', 'Enter the account number.', array( 'status' => 400 ) );
			}
			// Mexico: the CLABE is enough inside Mexico (SPEI, Wise); an international wire also needs the SWIFT, so it is asked but optional.
			if ( ( in_array( $scheme, array( 'iban', 'swift' ), true ) || ( 'clabe' === $scheme && '' !== $swift ) ) && ! preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swift ) ) {
				return new WP_Error( 'atc_payout', 'Enter the SWIFT/BIC code of your bank (8 or 11 letters and numbers).', array( 'status' => 400 ) );
			}
			if ( '' === $bank ) {
				return new WP_Error( 'atc_payout', 'Enter the name of your bank.', array( 'status' => 400 ) );
			}
		}
		if ( in_array( $method, array( 'wise', 'paypal' ), true ) && ! is_email( $email ) ) {
			return new WP_Error( 'atc_payout', 'Enter the email of your ' . ( 'paypal' === $method ? 'PayPal' : 'Wise' ) . ' account.', array( 'status' => 400 ) );
		}
		$details = 'bank' === $method
			? array( 'holder' => $holder, 'bank' => $bank, 'account' => $account, 'swift' => $swift, 'routing' => $routing, 'country' => $country, 'email' => '' )
			: array( 'holder' => $holder, 'email' => $email, 'country' => $country, 'bank' => '', 'account' => '' );
		$wpdb->update(
			self::t( 'parties' ),
			array(
				'payout_method'   => $method,
				'payout_currency' => $currency,
				'payout_details'  => self::encrypt( $details ),
				'updated_at'      => self::now(),
			),
			array( 'id' => $party_id )
		);
		ATC_Install::log( 'party', $party_id, 'payout_updated', array( 'method' => $method, 'currency' => $currency, 'country' => $country ) );
		return self::payout_summary( $party_id );
	}

	/* ---------- Agreement ---------- */

	/**
	 * The Provider Agreement: the key points first (what most people read), then the full text, which is what
	 * binds. Shown on the public page before sign-up, beside the sign-up checkbox, and in the portal.
	 */
	public static function agreement(): array {
		$c  = (int) ATC_Settings::get( 'provider_commission_bp' ) / 100;
		$d  = (int) ATC_Settings::get( 'payout_day' );
		$h  = (int) ATC_Settings::get( 'autocomplete_hours' );
		$fc = (int) ATC_Settings::get( 'free_cancel_hours' );
		$k  = rtrim( rtrim( number_format( $c, 2 ), '0' ), '.' );
		$net = number_format( 100 - $c, 2 );
		return array(
			'version' => self::AGREEMENT_VERSION,
			'updated' => 'November 25, 2026',
			'key'     => array(
				'You set your own prices. We keep ' . $k . '% of the price the traveler pays; the rest is yours (on a USD 100 trip you get USD ' . $net . ').',
				'We collect from travelers. You are paid on the ' . $d . 'th of each month for the trips you completed the month before.',
				'Confirm every booking within 2 hours (30 minutes when pickup is less than 24 hours away), or we may give it to another provider.',
				'Track the flight, wait from the real landing time, and meet travelers as your meeting instructions say. Charge nothing extra on the spot.',
				'A trip you miss or do badly is paid less or not at all, and a missed trip can cost you up to 150% of its price (table in section 12).',
				'Travelers who book through us are our customers: never ask them to book with you directly, during this agreement or 12 months after.',
				'You are an independent company, responsible for your drivers, vehicles, licenses and insurance, and for anything that happens during the trip.',
				'Disputed or charged-back trips are held until the case is closed. You can leave at any time with 30 days\' notice; trips already booked must still be done.',
			),
			'sections' => array(
				array( '1. Definitions', array(
					'"We", "us" and "our" mean Airport Transportation LLC, a Wyoming (USA) company, which runs AirportTransportation.com, its booking engine and the sites, apps and partners that sell through it (together, the "Platform").',
					'"You" means the transport company that signs up as a provider, and "Provider Personnel" means your employees, drivers and anyone else you use to do a trip.',
					'"Traveler" means the person who books, or travels on, a trip. "Booking" means one reservation made through the Platform for one or more trips ("Trips").',
					'"Listing" means what you declare in the provider portal (the "Portal"): airports, zones, vehicle categories, passengers and bags, prices, extras, free waiting time, minimum notice and meeting instructions.',
					'"Suggested retail price" is the price the traveler pays. "Amount you will get paid" is that price less our commission. "Pickup time" is the time in the Booking, in the local time of the pickup place.',
				) ),
				array( '2. Accepting this agreement', array(
					'You accept this agreement by ticking the box on the sign-up form, or by accepting it in the Portal. That acceptance is your electronic signature and has the same effect as a signature on paper. We record the date, time, IP address, account and the version you accepted, and we email you a copy.',
					'The person who accepts confirms that they are of legal age and authorized to bind your company. Adding vehicles, zones or prices, or doing a Trip, also confirms your acceptance.',
					'The key points at the top of this agreement are a summary. If they differ from the full text, the full text applies.',
				) ),
				array( '3. Our role', array(
					'We run a marketplace. We show your Listing to travelers, take Bookings and payments, and pass each Booking to you. You, not us, provide the transport: the contract of carriage is between you and the traveler, and you are the carrier.',
					'We collect payment from travelers in our name, as your limited agent for that purpose only. Once a traveler has paid us, the traveler has paid you, and your claim for the Trip is against us under this agreement, not against the traveler.',
					'This agreement is non-exclusive: you may sell your services elsewhere, and we may work with other providers in the same places. Nothing in it makes you our employee, partner, franchisee or agent for any other purpose.',
				) ),
				array( '4. Joining and going live', array(
					'Your Listing goes live once your company details, vehicles, zones and prices are complete and this agreement is accepted. Being live does not mean we have checked your documents.',
					'We may ask at any time for your operating license or permit, registration, insurance policies, vehicle and driver documents, and proof of identity. You must send them within 7 days of our request. If you do not, or if they are not valid, we may pause your Listing until they are.',
					'We may refuse, pause or remove a Listing at our discretion, for example after complaints, wrong information, or a risk to travelers or to us.',
				) ),
				array( '5. Your Listing', array(
					'Your Listing must be true, complete and up to date. The vehicle you send must be of the category you listed, in similar condition, and carry at least the passengers and bags you declared.',
					'Your prices are per vehicle, not per person, and include everything the traveler needs for the Trip: driver, fuel, tolls, parking and airport access fees, taxes, and the free waiting time you declared. The only extras you may charge are those the traveler booked through the Platform.',
					'Changes you make to your Listing apply to new Bookings only. Bookings already made keep the terms, vehicle and price they were made with.',
				) ),
				array( '6. Prices, commission and extras', array(
					'You set your prices in the Portal, as the Suggested retail price or as the Amount you will get paid; the Portal always shows both. Our commission is ' . $k . '% of the Suggested retail price, extras included. Example: on a USD 100 trip you get USD ' . $net . '.',
					'Travelers pay the Suggested retail price with no booking fee added. Discounts, coupons and affiliate or agency commissions that we offer are paid from our commission and never reduce what you get, unless you agree to a promotion in writing.',
					'You may not show travelers a lower price for the same Trip to win them away from the Platform (see section 15).',
					'We may change our commission rate for new Bookings with 30 days\' notice by email and in the Portal.',
				) ),
				array( '7. Bookings and confirmation', array(
					'A Booking that fits your Listing and your minimum notice is made with you as soon as the traveler pays. You receive it in the Portal and by email.',
					'You must confirm (acknowledge) each Booking in the Portal within 2 hours of receiving it, or within 30 minutes when the pickup is less than 24 hours away. If you do not, we may give the Booking to another provider, and you lose it with no payment.',
					'If you cannot do a Trip, hand it back in the Portal at once. Handing back more than 48 hours before pickup costs nothing; later hand-backs count as a missed Trip under section 12 unless we find another provider at the same price.',
					'Travelers can change or cancel online until ' . $fc . ' hours before pickup, and changes reach you in the Portal and by email. Changes made later go through us and need your confirmation.',
				) ),
				array( '8. Doing the Trip', array(
					'Track the flight. For airport pickups, the free waiting time counts from the actual landing time, not the time in the Booking. A delayed flight is not a no-show.',
					'Meet the traveler as your meeting instructions say (place, sign with the traveler\'s name, contact by phone or WhatsApp), and be reachable on the numbers you gave us from 24 hours before until the end of the Trip.',
					'Carry only the passengers in the Booking. Do not share the vehicle with other travelers, make stops that were not booked, or swap in a taxi or another company without our written consent.',
					'Provide booked extras (child seats, stops, drinks and the like) as booked. Child seats must meet the safety rules of the country.',
					'Your vehicles must be clean, safe, in good working order and legally registered for paid passenger transport. Your drivers must be licensed for the vehicle, rested, sober, courteous and dressed neatly, and must drive safely and lawfully.',
					'Never ask the traveler for money, tips, or payment for anything the Booking includes.',
				) ),
				array( '9. Proof of service and no-shows', array(
					'The traveler gives your driver a short service code at pickup, and your driver enters it in the Portal. A Trip without a code is treated as done ' . $h . ' hours after pickup unless you report a problem before then.',
					'If the traveler does not appear, report a no-show in the Portal within ' . $h . ' hours of pickup, after waiting at least your free waiting time from landing and trying to call or message the traveler. Keep proof (for example a photo at the meeting point and your call log); we may ask for it.',
					'A no-show you report correctly is paid as a completed Trip. If the traveler cancels within ' . $fc . ' hours of pickup, the Trip is also paid as completed.',
				) ),
				array( '10. Licenses, drivers and insurance', array(
					'You hold, and keep valid for as long as you are on the Platform, every license, permit, registration and approval that the law requires to carry paying passengers in each place you serve.',
					'Every driver has the right license for the vehicle, is not suspended, has no conviction for violent, sexual or serious driving offences, and has been checked by you before driving for the Platform.',
					'You keep, with a reputable insurer: passenger and third-party liability insurance covering injury, death and damage to property; insurance for the vehicles; and any other insurance the law requires (workers\' compensation, for example). In Mexico, cover for occupants\' medical expenses must be at least what federal and state rules require. We may ask for the policies at any time.',
				) ),
				array( '11. Incidents and complaints', array(
					'Tell us at once, through the Portal or by email, of any accident, injury, death, damage, lost property, police involvement, or serious complaint during a Trip, and give us the details we ask for.',
					'Answer our questions about a Booking or a complaint within 48 hours. We may share your answer with the traveler.',
				) ),
				array( '12. Service levels and deductions', array(
					'When a Trip is not done as booked, the Amount you get paid for it is reduced as follows. We decide each case on the evidence (service code, messages, flight data, traveler reports and your proof), and you can reply before we decide.',
					'Driver no-show, or hand-back less than 48 hours before pickup with no replacement: no payment, plus the extra cost of a replacement or of the traveler\'s own transport, up to 150% of the Trip price.',
					'Driver more than 45 minutes late, or the traveler missed a flight because of you (with proof): no payment, plus the traveler\'s documented extra costs, up to 150% of the Trip price.',
					'Driver 20 to 45 minutes late; a shared ride, taxi or other company instead of the private vehicle booked; or a smaller or lower category vehicle: 50% of the payment withheld.',
					'Driver up to 20 minutes late; wrong drop-off; no meet and greet as described; dirty vehicle; route not as booked; rude or unprofessional driver: 20% of the payment withheld.',
					'A booked extra not provided: the price of that extra refunded to the traveler, plus 10% of the Trip payment withheld.',
					'Repeated problems may also lead us to pause or remove your Listing.',
				) ),
				array( '13. Payment', array(
					'We pay you on the ' . $d . 'th of each month for every Trip completed in the previous month, less deductions under section 12 and any amounts you owe us. Your monthly statement is in the Portal.',
					'We pay to the payout details you give in the Portal, in USD or in the local currency we offer for your country. Bank, transfer and currency-conversion fees on your side are yours. We hold your payments until you give valid payout details; held amounts are paid in the next payout after you do.',
					'If a traveler disputes a charge or files a chargeback, we hold the payment for that Trip until the case is closed. If the case is lost because the Trip was not done as booked, the Trip is not paid, and amounts already paid for it may be taken from your later payments or billed to you.',
					'We may set off any amount you owe us (deductions, chargebacks, overpayments) against any amount we owe you.',
					'You are responsible for your own taxes and, where the law requires, for issuing the traveler a receipt or invoice for the transport. Where the law of your country requires it, we may withhold taxes from your payments and give you the receipts.',
				) ),
				array( '14. Reviews, ratings and your directory profile', array(
					'Travelers may rate and review each Trip. We publish reviews as written, except reviews that break the law or our review rules, and we are not responsible for their content. Do not offer travelers anything for a review, or post reviews about yourself or other providers.',
					'We may publish a directory profile of your company with your name, logo, description, airports and vehicles. Your website link appears there when your website links to ours, which we check automatically.',
				) ),
				array( '15. Travelers are our customers', array(
					'Do not ask travelers who booked through the Platform to book with you directly, give them your own booking contacts, cards or links, or offer them other services, during this agreement and for 12 months after it ends.',
					'If you take a Booking off the Platform in breach of this section, you owe us the commission we would have earned on it, and we may end this agreement at once.',
				) ),
				array( '16. Personal data and confidentiality', array(
					'We give you travelers\' personal data (names, phone numbers, flights, addresses) only to do the Booking. Use it only for that Booking, keep it secure, share it only with the Provider Personnel who need it, do not use it for marketing, and delete it within 90 days after the Trip unless the law requires you to keep it.',
					'Tell us within 24 hours of any loss or misuse of travelers\' data. You follow the data protection laws that apply to you (for example Mexico\'s LFPDPPP, the GDPR in Europe, and US state laws).',
					'We use your company and contact data to run the Platform, as our privacy policy describes, and may share it with travelers and sales partners to make Bookings work.',
					'Each party keeps the other\'s non-public business information (prices, commission, statements, volumes) confidential, during this agreement and for 2 years after it.',
				) ),
				array( '17. Our brand and the Platform', array(
					'The Platform, its software, content, data and our brand belong to us. You get a limited right to use the Portal for this agreement, and to use our name and logo on signs, vehicles and documents only to serve Bookings, as we allow.',
					'You allow us to use your company name, logo, photos and descriptions to show and promote your services on the Platform and in our marketing.',
				) ),
				array( '18. Liability and indemnity', array(
					'You are responsible for the Trips and everything that happens during them: the vehicles, Provider Personnel, safety of travelers and their belongings, and compliance with the law.',
					'You will defend and indemnify us, our staff and partners against any claim, loss, fine or cost (including reasonable legal fees) that arises from your services, from Provider Personnel, or from a breach of this agreement by you.',
					'We provide the Platform "as is". We are not liable for indirect or consequential losses, lost profits, or lost business. Our total liability to you under this agreement in any 12 months is limited to the commission we earned on your Trips in that period. Nothing in this agreement limits liability that the law does not allow to be limited.',
				) ),
				array( '19. Suspension and ending', array(
					'This agreement runs until it is ended. You can end it at any time with 30 days\' notice in the Portal or by email; we can end it with 30 days\' notice.',
					'We may pause your Listing or end this agreement at once if you seriously or repeatedly breach it, put travelers at risk, act dishonestly, lose a required license or insurance, or harm our reputation.',
					'When it ends, you must still do every Trip already booked unless we take them back, and we pay you for those Trips as above. Sections 12, 13, 15, 16, 18 and 21 continue after it ends.',
				) ),
				array( '20. Changes to this agreement', array(
					'We may update this agreement. We tell you by email and in the Portal at least 15 days before a change applies, or at once if the law requires it. The new version applies to Bookings made after it takes effect. If you do not accept it, you may end this agreement before it takes effect; we may pause your Listing until you accept.',
				) ),
				array( '21. General', array(
					'Notices to you go to the email address in your account and appear in the Portal; notices to us go to the support address shown in the Portal.',
					'You may not assign this agreement without our written consent. We may assign it to a company in our group or to a buyer of our business.',
					'If part of this agreement is invalid, the rest stays in force and the invalid part is read as close to its purpose as the law allows. Not enforcing a right does not waive it.',
					'This agreement, together with the Portal rules and policies it refers to, is the whole agreement between us on this subject and replaces earlier provider terms. If it is translated, the English version prevails.',
					'This agreement is governed by the laws of the State of Wyoming, USA. The courts of Wyoming have jurisdiction, but we may also bring a claim in the courts of the place where you are established.',
				) ),
			),
		);
	}

	/** The key points as a list (the portal and older screens). */
	public static function agreement_text(): array {
		return self::agreement()['key'];
	}

	/** The full agreement as HTML, for the public page ([atc_provider_agreement]) and the e-mail copy. */
	public static function agreement_html(): string {
		$a   = self::agreement();
		$out = '<div class="atc-agreement"><p><em>Airport Transportation Provider Agreement · version ' . esc_html( $a['version'] ) . ' · last updated ' . esc_html( $a['updated'] ) . '</em></p>';
		$out .= '<div class="atc-keypoints" style="background:#F7F5F1;border:1px solid #ECE8E1;border-radius:14px;padding:18px 22px;margin:0 0 28px"><h2 style="margin-top:0">The key points</h2><ul>';
		foreach ( $a['key'] as $k ) {
			$out .= '<li>' . esc_html( $k ) . '</li>';
		}
		$out .= '</ul><p><small>These points summarize the agreement below. The full text is what applies.</small></p></div><h2>The full agreement</h2>';
		foreach ( $a['sections'] as [ $t, $paras ] ) {
			$out .= '<h3>' . esc_html( $t ) . '</h3>';
			foreach ( $paras as $para ) {
				$out .= '<p>' . esc_html( $para ) . '</p>';
			}
		}
		return $out . '</div>';
	}

	public static function agreement_current( ?array $p ): bool {
		return $p && ! empty( $p['agreement_accepted_at'] ) && self::AGREEMENT_VERSION === (string) $p['agreement_version'];
	}

	/**
	 * A pending provider goes live on its own once it can take bookings: company, vehicles, zones and prices
	 * done and the agreement accepted. Documents, payout details and the directory profile do not hold it back.
	 */
	public static function maybe_go_live( int $party_id ): bool {
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p || 'provider' !== $p['type'] || 'pending' !== $p['status'] || ! ATC_Settings::get( 'auto_live' ) ) {
			return false;
		}
		$s = self::steps( $party_id );
		foreach ( array( 'company', 'vehicles', 'coverage', 'prices', 'agreement' ) as $k ) {
			if ( 'done' !== $s[ $k ]['state'] ) {
				return false;
			}
		}
		$res = ATC_Catalog::save_party( array( 'status' => 'live' ), $party_id );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		ATC_Install::log( 'party', $party_id, 'went_live_automatically', array() );
		ATC_Mail::defer( static function () use ( $p, $party_id ) {
			self::mail_team( 'Provider live: ' . $p['name'], $p['name'] . " finished its setup and is now live (automatic).\n\nDocuments and payout details may still be missing. Check: " . home_url( '/control/providers/' . $party_id . '/' ) );
			ATC_Mail::provider_live( $p );
		} );
		return true;
	}

	public static function accept_agreement( int $party_id ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		$wpdb->update( self::t( 'parties' ), array( 'agreement_accepted_at' => self::now(), 'agreement_version' => self::AGREEMENT_VERSION, 'agreement_ip' => $ip, 'updated_at' => self::now() ), array( 'id' => $party_id ) );
		ATC_Install::log( 'party', $party_id, 'agreement_accepted', array( 'version' => self::AGREEMENT_VERSION ) );
		$p = ATC_Catalog::party( $party_id );
		if ( $p ) {
			ATC_Mail::defer( static fn() => ATC_Mail::agreement_copy( $p ) );
		}
		return array( 'accepted_at' => self::now() );
	}

	/* ---------- Airports a provider serves ---------- */

	const AIRPORTS_MAX = 12;

	/** Airport ids the provider works at: its main airport, the ones it added, and any with a vehicle category. */
	public static function airport_ids( int $party_id ): array {
		global $wpdb;
		$p   = ATC_Catalog::party( $party_id );
		$ids = array();
		if ( $p && ! empty( $p['home_airport_id'] ) ) {
			$ids[] = (int) $p['home_airport_id'];
		}
		foreach ( self::extra_airports( $p ) as $id ) {
			$ids[] = $id;
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT airport_id FROM ' . self::t( 'services' ) . " WHERE party_id = %d AND status <> 'paused'", $party_id ) ) as $id ) {
			$ids[] = (int) $id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function extra_airports( ?array $p ): array {
		return $p ? array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $p['extra_airports'] ?? '' ) ) ) ) ) : array();
	}

	/** Each airport with how far its setup is: vehicle categories, zones, prices. */
	public static function airports_overview( int $party_id ): array {
		$p        = ATC_Catalog::party( $party_id );
		$services = ATC_Catalog::services( $party_id );
		$out      = array();
		foreach ( self::airport_ids( $party_id ) as $id ) {
			$a = ATC_Catalog::airport( $id );
			if ( ! $a ) {
				continue;
			}
			$here  = array_filter( $services, static fn( $s ) => (int) $s['airport_id'] === $id && 'paused' !== $s['status'] );
			$zones = array();
			foreach ( $here as $s ) {
				$zones = array_merge( $zones, array_map( 'intval', $s['zone_ids'] ) );
			}
			$mp    = self::missing_prices( $party_id, $id );
			$out[] = array(
				'id'       => $id,
				'label'    => ATC_Catalog::airport_label( $a ),
				'name'     => $a['name'],
				'iata'     => $a['iata'],
				'city'     => $a['city'],
				'home'     => $p && (int) $p['home_airport_id'] === $id,
				'vehicles' => count( $here ),
				'zones'    => count( array_unique( $zones ) ),
				'prices'   => array( 'total' => $mp['total'], 'missing' => $mp['missing'] ),
			);
		}
		return $out;
	}

	/** Adds an airport the provider also serves: one of ours, or one found on Google (place_id). */
	public static function add_airport( int $party_id, array $in ) {
		global $wpdb;
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p ) {
			return new WP_Error( 'atc_airport', 'Provider not found.', array( 'status' => 404 ) );
		}
		$airport = ! empty( $in['airport_id'] ) ? ATC_Catalog::airport( (int) $in['airport_id'] ) : null;
		if ( $airport && 'active' !== $airport['status'] ) {
			$airport = null;
		}
		$place = sanitize_text_field( (string) ( $in['place_id'] ?? '' ) );
		if ( ! $airport && '' !== $place ) {
			if ( ! self::rate_ok( 'add_airport', 10 ) ) {
				return new WP_Error( 'atc_airport', 'Too many new airports. Try again in a few minutes.', array( 'status' => 429 ) );
			}
			$airport = ATC_Catalog::airport_from_google( $place );
			if ( is_wp_error( $airport ) ) {
				return $airport;
			}
		}
		if ( ! $airport ) {
			return new WP_Error( 'atc_airport', 'Search for the airport and pick it from the list.', array( 'status' => 400 ) );
		}
		$id  = (int) $airport['id'];
		$ids = self::airport_ids( $party_id );
		if ( in_array( $id, $ids, true ) ) {
			return array( 'airport_id' => $id, 'added' => false );
		}
		if ( count( $ids ) >= self::AIRPORTS_MAX ) {
			return new WP_Error( 'atc_airport', 'You serve ' . self::AIRPORTS_MAX . ' airports already. Write to us to add more.', array( 'status' => 400 ) );
		}
		$extra   = self::extra_airports( $p );
		$extra[] = $id;
		$wpdb->update( self::t( 'parties' ), array( 'extra_airports' => implode( ',', array_unique( $extra ) ) ), array( 'id' => $party_id ) );
		if ( empty( $p['home_airport_id'] ) ) {
			$wpdb->update( self::t( 'parties' ), array( 'home_airport_id' => $id ), array( 'id' => $party_id ) );
		}
		return array( 'airport_id' => $id, 'added' => true );
	}

	/** Stops serving an airport (not the main one): its categories are paused, zones and prices are kept. */
	public static function remove_airport( int $party_id, int $airport_id ) {
		global $wpdb;
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p ) {
			return new WP_Error( 'atc_airport', 'Provider not found.', array( 'status' => 404 ) );
		}
		if ( (int) $p['home_airport_id'] === $airport_id ) {
			return new WP_Error( 'atc_airport', 'This is your main airport. Write to us if you want to change it.', array( 'status' => 400 ) );
		}
		$extra = array_values( array_diff( self::extra_airports( $p ), array( $airport_id ) ) );
		$wpdb->update( self::t( 'parties' ), array( 'extra_airports' => $extra ? implode( ',', $extra ) : null ), array( 'id' => $party_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'services' ) . " SET status = 'paused' WHERE party_id = %d AND airport_id = %d", $party_id, $airport_id ) );
		return true;
	}

	/**
	 * Zones we drew around OTHER airports close to this one (for example Tulum and Playa del Carmen,
	 * drawn for Cancún, seen from Tulum airport). A provider copies one as its own zone at its airport.
	 */
	public static function nearby_zones( int $party_id, int $airport_id, float $km = 100 ): array {
		global $wpdb;
		$a = ATC_Catalog::airport( $airport_id );
		if ( ! $a || null === $a['lat'] ) {
			return array();
		}
		$mine  = array_map( 'strtolower', $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active' AND (party_id IS NULL OR party_id = %d)", $airport_id, $party_id ) ) );
		$near  = array();
		foreach ( ATC_Catalog::airports() as $o ) {
			if ( (int) $o['id'] !== $airport_id && null !== $o['lat'] && ATC_Geo::km( (float) $a['lat'], (float) $a['lng'], (float) $o['lat'], (float) $o['lng'] ) <= $km * 2 ) {
				$near[ (int) $o['id'] ] = $o;
			}
		}
		$out = array();
		foreach ( $near as $oid => $o ) {
			foreach ( ATC_Catalog::zones( $oid ) as $z ) {
				$ring = (array) ( $z['polygon'] ?? array() );
				if ( count( $ring ) < 3 || in_array( strtolower( (string) $z['name'] ), $mine, true ) ) {
					continue;
				}
				$clat = array_sum( array_column( $ring, 0 ) ) / count( $ring );
				$clng = array_sum( array_column( $ring, 1 ) ) / count( $ring );
				$d    = ATC_Geo::km( (float) $a['lat'], (float) $a['lng'], $clat, $clng );
				if ( $d > $km ) {
					continue;
				}
				$out[] = array( 'id' => (int) $z['id'], 'name' => $z['name'], 'polygon' => $ring, 'km' => (int) round( $d ), 'from' => ATC_Catalog::airport_label( $o ) );
			}
		}
		usort( $out, static fn( $x, $y ) => $x['km'] <=> $y['km'] );
		return array_slice( $out, 0, 60 );
	}

	/** Copies one of our zones from a nearby airport as the provider's own zone at its airport. */
	public static function copy_zone( int $party_id, int $zone_id, int $airport_id ) {
		foreach ( self::nearby_zones( $party_id, $airport_id ) as $z ) {
			if ( $z['id'] === $zone_id ) {
				return self::save_own_zone( $party_id, array( 'name' => $z['name'], 'polygon' => $z['polygon'], 'airport_id' => $airport_id ) );
			}
		}
		return new WP_Error( 'atc_zone', 'That zone is not near this airport.', array( 'status' => 400 ) );
	}

	/* ---------- Guided tour: which explanations a portal user has seen ---------- */

	public static function tour( int $user_id ): array {
		$t = get_user_meta( $user_id, 'atc_tour', true );
		return is_array( $t ) ? $t : array();
	}

	public static function tour_seen( int $user_id, string $key, bool $seen = true ): array {
		$t   = self::tour( $user_id );
		$key = sanitize_key( $key );
		if ( 'reset' === $key ) {
			$t = array();
		} elseif ( '' !== $key ) {
			if ( $seen ) {
				$t[ $key ] = 1;
			} else {
				unset( $t[ $key ] );
			}
		}
		update_user_meta( $user_id, 'atc_tour', array_slice( $t, 0, 40, true ) );
		return $t;
	}

	/* ---------- Zones a provider draws for itself ---------- */

	const OWN_ZONES_MAX = 80;

	/** The provider's main airport, where it draws. */
	private static function home_airport( int $party_id ): ?array {
		$p = ATC_Catalog::party( $party_id );
		return $p && $p['home_airport_id'] ? ATC_Catalog::airport( (int) $p['home_airport_id'] ) : null;
	}

	/**
	 * Creates or changes one of the provider's own zones. No approval: it is live for the provider at once,
	 * and a new zone is served by all of its vehicle categories at that airport (it still needs prices).
	 */
	public static function save_own_zone( int $party_id, array $in, ?int $id = null ) {
		global $wpdb;
		$t    = self::t( 'zones' );
		$zone = null;
		if ( $id ) {
			$zone = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND party_id = %d AND status = 'active'", $id, $party_id ), ARRAY_A );
			if ( ! $zone ) {
				return new WP_Error( 'atc_zone', 'That zone is not yours or no longer exists.', array( 'status' => 404 ) );
			}
			$airport = ATC_Catalog::airport( (int) $zone['airport_id'] );
		} else {
			$airport = ! empty( $in['airport_id'] ) ? ATC_Catalog::airport( (int) $in['airport_id'] ) : self::home_airport( $party_id );
			if ( $airport && ! in_array( (int) $airport['id'], self::airport_ids( $party_id ), true ) ) {
				return new WP_Error( 'atc_zone', 'Add this airport to your airports first.', array( 'status' => 400 ) );
			}
			if ( ! $airport ) {
				return new WP_Error( 'atc_zone', 'Set your main airport first.', array( 'status' => 400 ) );
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE party_id = %d AND airport_id = %d AND status = 'active'", $party_id, (int) $airport['id'] ) );
			if ( $count >= self::OWN_ZONES_MAX ) {
				return new WP_Error( 'atc_zone', 'You have ' . self::OWN_ZONES_MAX . ' zones. Join or delete some before drawing more.', array( 'status' => 400 ) );
			}
		}
		$data = array();
		if ( ! $id || isset( $in['name'] ) ) {
			$name = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
			if ( '' === $name || mb_strlen( $name ) > 100 ) {
				return new WP_Error( 'atc_zone_name', 'Give the zone a name travelers know, like "Hotel Zone" or "Playa del Carmen" (up to 100 characters).', array( 'status' => 400 ) );
			}
			$dupe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE party_id = %d AND airport_id = %d AND status = 'active' AND name = %s AND id <> %d", $party_id, (int) $airport['id'], $name, (int) $id ) );
			if ( $dupe ) {
				return new WP_Error( 'atc_zone_name', 'You already have a zone called ' . $name . '.', array( 'status' => 400 ) );
			}
			$data['name'] = $name;
		}
		if ( ! $id || array_key_exists( 'polygon', $in ) ) {
			$poly = ATC_Geo::clean_polygon( $in['polygon'] ?? null );
			if ( ! $poly ) {
				return new WP_Error( 'atc_zone_polygon', 'Draw the zone with at least 3 points on the map.', array( 'status' => 400 ) );
			}
			$area = ATC_Geo::area_km2( $poly );
			if ( $area < 0.01 ) {
				return new WP_Error( 'atc_zone_polygon', 'That zone is too small. Draw around the whole area.', array( 'status' => 400 ) );
			}
			if ( $area > 30000 ) {
				return new WP_Error( 'atc_zone_polygon', 'That zone is too big. Draw smaller zones, each with its own price.', array( 'status' => 400 ) );
			}
			$clat = array_sum( array_column( $poly, 0 ) ) / count( $poly );
			$clng = array_sum( array_column( $poly, 1 ) ) / count( $poly );
			if ( null !== $airport['lat'] && ATC_Geo::km( (float) $airport['lat'], (float) $airport['lng'], $clat, $clng ) > 400 ) {
				return new WP_Error( 'atc_zone_polygon', 'That zone is more than 400 km from ' . ATC_Catalog::airport_label( $airport ) . '.', array( 'status' => 400 ) );
			}
			$data['polygon'] = wp_json_encode( $poly );
		}
		if ( $id ) {
			if ( $data ) {
				$wpdb->update( $t, $data, array( 'id' => $id ) );
				ATC_Install::log( 'zone', $id, 'updated', array( 'party_id' => $party_id, 'name' => $data['name'] ?? $zone['name'], 'reshaped' => isset( $data['polygon'] ) ) );
			}
			return array( 'id' => $id );
		}
		$base = 'p' . $party_id . '-' . ( sanitize_title( $data['name'] ) ?: 'zone' );
		$slug = $base;
		for ( $i = 2; $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE airport_id = %d AND slug = %s", (int) $airport['id'], $slug ) ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$sort = 1000 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE party_id = %d", $party_id ) );
		$wpdb->insert( $t, $data + array( 'airport_id' => (int) $airport['id'], 'party_id' => $party_id, 'slug' => $slug, 'source' => 'provider', 'sort' => $sort, 'status' => 'active' ) );
		$id = (int) $wpdb->insert_id;
		foreach ( ATC_Catalog::services( $party_id ) as $s ) {
			if ( (int) $s['airport_id'] === (int) $airport['id'] && 'paused' !== $s['status'] ) {
				$wpdb->replace( self::t( 'service_zones' ), array( 'service_id' => (int) $s['id'], 'zone_id' => $id ) );
			}
		}
		ATC_Install::log( 'zone', $id, 'drawn', array( 'party_id' => $party_id, 'name' => $data['name'] ) );
		return array( 'id' => $id );
	}

	/** Deletes one of the provider's own zones: it stops being served; its prices stay in the history. */
	public static function delete_own_zone( int $party_id, int $id ) {
		global $wpdb;
		$t = self::t( 'zones' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d AND party_id = %d AND status = 'active'", $id, $party_id ) ) ) {
			return new WP_Error( 'atc_zone', 'That zone is not yours or no longer exists.', array( 'status' => 404 ) );
		}
		$wpdb->update( $t, array( 'status' => 'archived' ), array( 'id' => $id ) );
		$wpdb->delete( self::t( 'service_zones' ), array( 'zone_id' => $id ) );
		ATC_Install::log( 'zone', $id, 'archived', array( 'party_id' => $party_id ) );
		return array( 'id' => $id );
	}

	/** Prices still missing: one-way, for each vehicle category the provider sells × each zone it serves. */
	public static function missing_prices( int $party_id, int $airport_id = 0 ): array {
		global $wpdb;
		$only = $airport_id ? $wpdb->prepare( ' AND s.airport_id = %d', $airport_id ) : '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT z.name zone, v.name vehicle FROM ' . self::t( 'services' ) . ' s
				JOIN ' . self::t( 'service_zones' ) . ' sz ON sz.service_id = s.id
				JOIN ' . self::t( 'zones' ) . " z ON z.id = sz.zone_id AND z.status = 'active'
				JOIN " . self::t( 'vehicle_types' ) . ' v ON v.id = s.vehicle_type_id
				LEFT JOIN ' . self::t( 'prices' ) . " pr ON pr.service_id = s.id AND pr.zone_id = sz.zone_id AND pr.direction = 'one_way' AND pr.valid_from IS NULL AND pr.retail_cents > 0
				WHERE s.party_id = %d AND s.status <> 'paused' AND pr.service_id IS NULL{$only}
				ORDER BY z.sort, z.name, v.sort",
				$party_id
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'services' ) . ' s JOIN ' . self::t( 'service_zones' ) . ' sz ON sz.service_id = s.id JOIN ' . self::t( 'zones' ) . " z ON z.id = sz.zone_id AND z.status = 'active' WHERE s.party_id = %d AND s.status <> 'paused'{$only}", $party_id ) );
		return array( 'total' => $total, 'missing' => count( $rows ), 'list' => array_slice( $rows, 0, 12 ) );
	}

	/* ---------- Coverage ---------- */

	/** Applies one set of zones to all of the provider's vehicle categories at an airport. */
	public static function save_coverage( int $party_id, int $airport_id, array $zone_ids ) {
		global $wpdb;
		$services = array_filter( ATC_Catalog::services( $party_id ), static fn( $s ) => (int) $s['airport_id'] === $airport_id );
		if ( ! $services ) {
			return new WP_Error( 'atc_coverage', 'Add your vehicle categories first.', array( 'status' => 400 ) );
		}
		$valid = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active' AND polygon IS NOT NULL AND (party_id IS NULL OR party_id = %d)", $airport_id, $party_id ) ) );
		$want  = array_values( array_intersect( array_map( 'intval', $zone_ids ), $valid ) );
		$sz    = self::t( 'service_zones' );
		foreach ( $services as $s ) {
			$wpdb->delete( $sz, array( 'service_id' => (int) $s['id'] ) );
			foreach ( $want as $zid ) {
				$wpdb->insert( $sz, array( 'service_id' => (int) $s['id'], 'zone_id' => $zid ) );
			}
		}
		ATC_Install::log( 'party', $party_id, 'coverage_updated', array( 'zones' => count( $want ) ) );
		return array( 'zone_ids' => $want );
	}
}
