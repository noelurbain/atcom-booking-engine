<?php
/**
 * Provider self-service: sign-up, setup steps, documents, payout details, agreement.
 * Everything here is scoped to the signed-in user's own company.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Partner {

	const AGREEMENT_VERSION = '2026-11-draft';

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
		$airport = ATC_Catalog::airport( (int) ( $in['airport_id'] ?? 0 ) );
		$about   = mb_substr( sanitize_textarea_field( (string) ( $in['about'] ?? '' ) ), 0, 2000 );
		$fleet   = sanitize_text_field( (string) ( $in['fleet'] ?? '' ) );
		if ( '' !== $fleet ) {
			$about = trim( 'Fleet size: ' . $fleet . "\n" . $about );
		}

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
		if ( ! $airport ) {
			$errors->add( 'airport_id', 'Pick the airport you serve.' );
		}
		if ( empty( $in['accept'] ) ) {
			$errors->add( 'accept', 'Please accept the provider terms and conditions.' );
		}
		if ( $errors->has_errors() ) {
			return $errors;
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
		ATC_Install::log( 'party', $party_id, 'signed_up', array( 'user_id' => $user_id ) );

		self::mail_welcome( $email, $contact, $company );
		self::mail_team( 'New provider sign-up: ' . $company, "Company: {$company}\nContact: {$contact}\nEmail: {$email}\nWhatsApp: {$phone}\nAirport: {$airport['iata']}\n\nAbout:\n{$about}\n\nReview: " . home_url( '/control/providers/' . $party_id . '/' ) );

		return (int) $user_id;
	}

	private static function mail_welcome( string $email, string $name, string $company ): void {
		$subject = 'Welcome to Airport Transportation, ' . $company;
		$body    = "Hi {$name},\n\nThanks for signing up {$company} as a transport provider.\n\nNext, finish your setup in the provider portal: company details, vehicles, the zones you serve, your documents, payout details and the agreement.\n\n" . home_url( '/portal/' ) . "\n\nWe review your documents within two working days. Travelers can book you once every step is green.\n\nAirport Transportation";
		wp_mail( $email, $subject, $body, array( 'From: Airport Transportation <' . ATC_Settings::get( 'from_email' ) . '>' ) );
	}

	public static function mail_team( string $subject, string $body ): void {
		wp_mail( ATC_Settings::get( 'from_email' ), $subject, $body );
	}

	/** Max 10 sign-up attempts per IP per hour. */
	public static function rate_ok( string $bucket ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'atc_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------- Setup steps ---------- */

	public static function steps( int $party_id ): array {
		global $wpdb;
		$p        = ATC_Catalog::party( $party_id );
		$services = ATC_Catalog::services( $party_id );
		$offered  = array_filter( $services, static fn( $s ) => 'paused' !== $s['status'] );
		$vehicles = ATC_Catalog::vehicles( $party_id );
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
			'company'   => array( 'name' => 'Company details' ) + $st( $p['name'] && $p['phone'] && $p['email'] && $p['languages'], 'Done' ),
			'vehicles'  => array( 'name' => 'Vehicles' ) + $st( $offered && $vehicles, 'Done' ),
			'coverage'  => array( 'name' => 'Zones you serve' ) + $st( (bool) $zones, 'Done' ),
			'documents' => array( 'name' => 'Documents' ) + $st( $all_approved, 'Approved', $all_sent ),
			'payout'    => array( 'name' => 'Payout details' ) + $st( ! empty( $raw ), 'Done' ),
			'agreement' => array( 'name' => 'Agreement' ) + $st( ! empty( $p['agreement_accepted_at'] ), 'Accepted' ),
			'approval'  => array( 'name' => 'Our approval' ) + $st( 'live' === $p['status'], 'Live', 'pending' === $p['status'], 'Pending' ),
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

	/** What we show back: never the full account number. */
	public static function payout_summary( int $party_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT payout_method, payout_currency, payout_details FROM ' . self::t( 'parties' ) . ' WHERE id = %d', $party_id ), ARRAY_A );
		$d   = self::decrypt( $row['payout_details'] ?? null );
		return array(
			'method'   => $row['payout_method'] ?? 'manual',
			'currency' => $row['payout_currency'] ?? 'USD',
			'set'      => (bool) $d,
			'holder'   => $d['holder'] ?? '',
			'bank'     => $d['bank'] ?? '',
			'last4'    => $d ? substr( preg_replace( '/\s+/', '', (string) ( $d['account'] ?? '' ) ), -4 ) : '',
			'email'    => $d['email'] ?? '',
		);
	}

	public static function save_payout( int $party_id, array $in ) {
		global $wpdb;
		$method   = in_array( $in['method'] ?? '', array( 'wise', 'bank' ), true ) ? $in['method'] : 'bank';
		$currency = in_array( $in['currency'] ?? '', array( 'MXN', 'USD' ), true ) ? $in['currency'] : 'MXN';
		$holder   = trim( sanitize_text_field( $in['holder'] ?? '' ) );
		$bank     = trim( sanitize_text_field( $in['bank'] ?? '' ) );
		$account  = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in['account'] ?? '' ) );
		$email    = sanitize_email( $in['email'] ?? '' );

		if ( '' === $holder ) {
			return new WP_Error( 'atc_payout', 'Enter the account holder’s name.', array( 'status' => 400 ) );
		}
		if ( 'bank' === $method && 'MXN' === $currency && ! preg_match( '/^\d{18}$/', $account ) ) {
			return new WP_Error( 'atc_payout', 'A CLABE has exactly 18 digits.', array( 'status' => 400 ) );
		}
		if ( 'bank' === $method && strlen( $account ) < 6 ) {
			return new WP_Error( 'atc_payout', 'Enter the account number.', array( 'status' => 400 ) );
		}
		if ( 'wise' === $method && ! is_email( $email ) ) {
			return new WP_Error( 'atc_payout', 'Enter the email of your Wise account.', array( 'status' => 400 ) );
		}
		$wpdb->update(
			self::t( 'parties' ),
			array(
				'payout_method'   => $method,
				'payout_currency' => $currency,
				'payout_details'  => self::encrypt( array( 'holder' => $holder, 'bank' => $bank, 'account' => $account, 'email' => $email ) ),
				'updated_at'      => self::now(),
			),
			array( 'id' => $party_id )
		);
		ATC_Install::log( 'party', $party_id, 'payout_updated', array( 'method' => $method, 'currency' => $currency ) );
		return self::payout_summary( $party_id );
	}

	/* ---------- Agreement ---------- */

	public static function agreement_text(): array {
		$c = (int) ATC_Settings::get( 'provider_commission_bp' ) / 100;
		$d = (int) ATC_Settings::get( 'payout_day' );
		$h = (int) ATC_Settings::get( 'autocomplete_hours' );
		return array(
			'Commission. You set your prices as the Suggested retail price or the Amount you will get paid. Our commission is ' . $c . '% of the Suggested retail price. Example: on a USD 100 sale you get paid USD ' . number_format( 100 - $c, 2 ) . '.',
			'Payment. We collect from the traveler. You are paid on the ' . $d . 'th of each month for every trip completed the month before.',
			'Bookings. Confirm (acknowledge) each booking in the portal as soon as you receive it. If you cannot do a trip, hand it back as early as possible so we can reassign it.',
			'Proof of service. The traveler gives your driver a short service code at pickup. Your driver enters it in the portal. Trips without a code complete on their own ' . $h . ' hours after pickup unless you report a no-show.',
			'Quality. Vehicles, drivers and insurance must match what you declare. Keep your documents up to date; we remind you before they expire.',
			'Travelers. Travelers are our customers. Do not offer them other services or ask them to book directly with you.',
			'Disputes. If a traveler disputes a charge, the payment for that trip is held until the case is closed.',
		);
	}

	public static function accept_agreement( int $party_id ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		$wpdb->update( self::t( 'parties' ), array( 'agreement_accepted_at' => self::now(), 'agreement_version' => self::AGREEMENT_VERSION, 'agreement_ip' => $ip, 'updated_at' => self::now() ), array( 'id' => $party_id ) );
		ATC_Install::log( 'party', $party_id, 'agreement_accepted', array( 'version' => self::AGREEMENT_VERSION ) );
		return array( 'accepted_at' => self::now() );
	}

	/* ---------- Coverage ---------- */

	/** Applies one set of zones to all of the provider's vehicle categories at an airport. */
	public static function save_coverage( int $party_id, int $airport_id, array $zone_ids ) {
		global $wpdb;
		$services = array_filter( ATC_Catalog::services( $party_id ), static fn( $s ) => (int) $s['airport_id'] === $airport_id );
		if ( ! $services ) {
			return new WP_Error( 'atc_coverage', 'Add your vehicle categories first.', array( 'status' => 400 ) );
		}
		$valid = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'zones' ) . " WHERE airport_id = %d AND status = 'active' AND polygon IS NOT NULL", $airport_id ) ) );
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
