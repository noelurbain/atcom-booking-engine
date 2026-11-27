<?php
/**
 * Browser push notifications (Web Push, RFC 8030/8291/8292) for providers and our team.
 * No outside service: the browser vendors deliver. Keys (VAPID) are made on first use and kept in an option.
 * Sends are queued during the request and go out at shutdown, after the page has been sent.
 */

defined( 'ABSPATH' ) || exit;

class ATC_Push {

	const OPTION = 'atc_vapid';

	private static array $queue = array();

	public static function init(): void {
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_push_file' ) ) );
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 99 );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^atc-sw\.js$', 'index.php?atc_push_file=sw', 'top' );
		add_rewrite_rule( '^atc-app-(portal|control)\.webmanifest$', 'index.php?atc_push_file=manifest-$matches[1]', 'top' );
	}

	private static function t(): string {
		return ATC_Schema::table( 'push_subs' );
	}

	/* ---------- Files: the service worker and the home-screen manifests ---------- */

	public static function serve(): void {
		$f = (string) get_query_var( 'atc_push_file' );
		if ( '' === $f ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'sw' === $f ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
			echo self::worker_js(); // phpcs:ignore -- static script.
			exit;
		}
		$app  = 'manifest-control' === $f ? 'control' : 'portal';
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';
		$m    = array(
			'name'             => 'control' === $app ? 'AT Control' : 'Airport Transportation Partners',
			'short_name'       => 'control' === $app ? 'AT Control' : 'AT Partners',
			'start_url'        => '/' . $app . '/',
			'scope'            => '/' . $app . '/',
			'display'          => 'standalone',
			'background_color' => '#F7F5F1',
			'theme_color'      => '#1B1B1F',
			'icons'            => $icon ? array(
				array( 'src' => get_site_icon_url( 192 ), 'sizes' => '192x192', 'type' => 'image/png' ),
				array( 'src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
			) : array(),
		);
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $m, JSON_UNESCAPED_SLASHES ); // phpcs:ignore
		exit;
	}

	/** Tags for the portal and Control <head>: home-screen app on iPhone, Android and computers. */
	public static function head_tags( string $app ): string {
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 180 ) : '';
		return '<link rel="manifest" href="' . esc_url( home_url( '/atc-app-' . ( 'control' === $app ? 'control' : 'portal' ) . '.webmanifest' ) ) . '">' . "\n"
			. '<meta name="theme-color" content="#1B1B1F">' . "\n"
			. '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-title" content="' . ( 'control' === $app ? 'AT Control' : 'AT Partners' ) . '">' . "\n"
			. ( $icon ? '<link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">' . "\n" : '' );
	}

	private static function worker_js(): string {
		return <<<'JS'
/* Airport Transportation: booking alerts. Push only; no caching, no fetch handling. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { title: 'Airport Transportation', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Airport Transportation', {
    body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, requireInteraction: !!d.urgent,
    icon: d.icon || undefined, badge: d.badge || undefined, data: { url: d.url || '/' }, timestamp: Date.now()
  }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) { return c.navigate(url).then(function (w) { return (w || c).focus(); }).catch(function () { return self.clients.openWindow(url); }); }
    }
    return self.clients.openWindow(url);
  }));
});
JS;
	}

	/* ---------- Keys ---------- */

	public static function b64u( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	public static function unb64u( string $s ): string {
		$s = strtr( $s, '-_', '+/' );
		return (string) base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore
	}

	/** Raw uncompressed P-256 point (65 bytes) from an openssl EC key's details. */
	private static function raw_public( array $details ): string {
		return "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	/** PEM public key from a raw uncompressed P-256 point. */
	private static function pem_from_raw( string $raw ): string {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore
	}

	private static function new_key() {
		return openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
	}

	/** The server's VAPID key pair: array( 'private' => PEM, 'public' => base64url raw point ). */
	public static function vapid(): array {
		$v = get_option( self::OPTION );
		if ( is_array( $v ) && ! empty( $v['private'] ) && ! empty( $v['public'] ) ) {
			return $v;
		}
		$k = self::new_key();
		if ( ! $k ) {
			return array();
		}
		openssl_pkey_export( $k, $pem );
		$v = array( 'private' => $pem, 'public' => self::b64u( self::raw_public( openssl_pkey_get_details( $k ) ) ) );
		add_option( self::OPTION, $v, '', false );
		return $v;
	}

	public static function public_key(): string {
		return (string) ( self::vapid()['public'] ?? '' );
	}

	/* ---------- Subscriptions ---------- */

	public static function subscribe( int $user_id, ?int $party_id, string $role, array $in ) {
		global $wpdb;
		$endpoint = esc_url_raw( (string) ( $in['endpoint'] ?? '' ), array( 'https' ) );
		$p256dh   = (string) ( $in['keys']['p256dh'] ?? '' );
		$auth     = (string) ( $in['keys']['auth'] ?? '' );
		if ( ! $endpoint || 65 !== strlen( self::unb64u( $p256dh ) ) || 16 !== strlen( self::unb64u( $auth ) ) ) {
			return new WP_Error( 'atc_push', 'This browser sent an incomplete subscription. Try again.', array( 'status' => 400 ) );
		}
		$row = array(
			'user_id'    => $user_id,
			'party_id'   => $party_id ?: null,
			'role'       => 'team' === $role ? 'team' : 'provider',
			'endpoint'   => $endpoint,
			'p256dh'     => $p256dh,
			'auth'       => $auth,
			'device'     => mb_substr( sanitize_text_field( (string) ( $in['device'] ?? '' ) ), 0, 120 ),
			'fails'      => 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE endpoint_hash = %s', md5( $endpoint ) ) ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t(), $row + array( 'endpoint_hash' => md5( $endpoint ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			$id = (int) $wpdb->insert_id;
		}
		ATC_Install::log( 'push', $id, 'subscribed', array( 'role' => $row['role'], 'party' => $party_id, 'device' => $row['device'] ) );
		return array( 'id' => $id );
	}

	public static function unsubscribe( int $user_id, string $endpoint ): void {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'endpoint_hash' => md5( $endpoint ), 'user_id' => $user_id ) );
	}

	/** How many devices get alerts, for a provider or for a user. */
	public static function count_for( ?int $party_id, ?int $user_id = null ): int {
		global $wpdb;
		if ( $party_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE role = 'provider' AND party_id = %d", $party_id ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE user_id = %d', (int) $user_id ) ); // phpcs:ignore
	}

	/* ---------- Sending ---------- */

	/**
	 * Queue an alert to every device of a provider. $msg: title, body, url (path or full), tag, urgent.
	 */
	public static function to_party( int $party_id, array $msg ): void {
		global $wpdb;
		if ( ! $party_id ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE role = 'provider' AND party_id = %d", $party_id ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			self::$queue[] = array( $r, $msg );
		}
	}

	/** Queue an alert to every team device (people with access to Control who turned alerts on). */
	public static function to_team( array $msg ): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE role = 'team'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			self::$queue[] = array( $r, $msg );
		}
	}

	/** Queue an alert to one user's devices (the "Send a test" button). */
	public static function to_user( int $user_id, array $msg ): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d', $user_id ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			self::$queue[] = array( $r, $msg );
		}
		return count( $rows );
	}

	/** Sends what was queued, once the page has gone to the visitor. */
	public static function flush(): void {
		if ( ! self::$queue ) {
			return;
		}
		$queue       = self::$queue;
		self::$queue = array();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$seen = array();
		foreach ( $queue as [ $sub, $msg ] ) {
			$key = $sub['id'] . '|' . md5( wp_json_encode( $msg ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			self::send_now( $sub, $msg );
		}
	}

	/** Encrypts and posts one message to one device. Returns the HTTP status (0 on failure before sending). */
	public static function send_now( array $sub, array $msg ): int {
		global $wpdb;
		$url = (string) ( $msg['url'] ?? '/' );
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$icon    = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 192 ) : '';
		$payload = wp_json_encode(
			array(
				'title'  => mb_substr( (string) ( $msg['title'] ?? 'Airport Transportation' ), 0, 120 ),
				'body'   => mb_substr( (string) ( $msg['body'] ?? '' ), 0, 300 ),
				'url'    => $url,
				'tag'    => (string) ( $msg['tag'] ?? '' ),
				'urgent' => ! empty( $msg['urgent'] ),
				'icon'   => $icon,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$body = self::encrypt( $payload, (string) $sub['p256dh'], (string) $sub['auth'] );
		$jwt  = self::vapid_jwt( (string) $sub['endpoint'] );
		if ( null === $body || '' === $jwt ) {
			return 0;
		}
		$res  = wp_remote_post(
			$sub['endpoint'],
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::public_key(),
					'Content-Encoding' => 'aes128gcm',
					'Content-Type'     => 'application/octet-stream',
					'TTL'              => (string) ( ! empty( $msg['urgent'] ) ? 3600 : 86400 ),
					'Urgency'          => 'high',
				),
				'body'    => $body,
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code || 410 === $code ) {
			$wpdb->delete( self::t(), array( 'id' => (int) $sub['id'] ) ); // the browser dropped this subscription
		} elseif ( $code >= 200 && $code < 300 ) {
			$wpdb->update( self::t(), array( 'fails' => 0, 'last_ok_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $sub['id'] ) );
		} else {
			$fails = (int) $sub['fails'] + 1;
			if ( $fails >= 10 ) {
				$wpdb->delete( self::t(), array( 'id' => (int) $sub['id'] ) );
			} else {
				$wpdb->update( self::t(), array( 'fails' => $fails ), array( 'id' => (int) $sub['id'] ) );
			}
		}
		return $code;
	}

	/** RFC 8291 aes128gcm body for one subscription, or null. */
	public static function encrypt( string $payload, string $p256dh_b64u, string $auth_b64u, ?array $fixed = null ): ?string {
		$ua_pub = self::unb64u( $p256dh_b64u );
		$auth   = self::unb64u( $auth_b64u );
		if ( 65 !== strlen( $ua_pub ) || 16 !== strlen( $auth ) ) {
			return null;
		}
		$local = $fixed['key'] ?? self::new_key();
		if ( ! $local ) {
			return null;
		}
		$as_pub = self::raw_public( openssl_pkey_get_details( $local ) );
		$peer   = openssl_pkey_get_public( self::pem_from_raw( $ua_pub ) );
		if ( ! $peer ) {
			return null;
		}
		$ecdh = openssl_pkey_derive( $peer, $local, 32 );
		if ( false === $ecdh ) {
			return null;
		}
		$salt  = $fixed['salt'] ?? random_bytes( 16 );
		$ikm   = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_pub . $as_pub, $auth );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag   = '';
		$ct    = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $ct ) {
			return null;
		}
		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_pub . $ct . $tag;
	}

	/** VAPID JWT (ES256) for the push service that owns $endpoint. */
	public static function vapid_jwt( string $endpoint ): string {
		$v = self::vapid();
		if ( ! $v ) {
			return '';
		}
		$p   = wp_parse_url( $endpoint );
		$aud = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$h   = self::b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$c   = self::b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . ATC_Settings::get( 'from_email' ) ), JSON_UNESCAPED_SLASHES ) );
		$der = '';
		if ( ! openssl_sign( $h . '.' . $c, $der, $v['private'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}
		$raw = self::der_to_raw( $der );
		return '' === $raw ? '' : $h . '.' . $c . '.' . self::b64u( $raw );
	}

	/** ECDSA DER signature to the 64-byte r||s form JWT wants. */
	public static function der_to_raw( string $der ): string {
		$o = 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$o += ord( $der[1] ) & 0x7f;
		}
		$parts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== $der[ $o ] ) {
				return '';
			}
			$len     = ord( $der[ $o + 1 ] );
			$int     = substr( $der, $o + 2, $len );
			$parts[] = str_pad( ltrim( $int, "\0" ), 32, "\0", STR_PAD_LEFT );
			$o      += 2 + $len;
		}
		return $parts[0] . $parts[1];
	}
}
