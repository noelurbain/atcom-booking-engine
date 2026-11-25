<?php
/**
 * Spam and abuse guards for the public site:
 * - comments, pingbacks and XML-RPC off everywhere (the site has no comments; bots post to them directly);
 * - sign-in: after 8 failed attempts from one address in an hour, sign-in waits until the hour is over;
 * - "Forgot your password": at most 5 reset emails per address per hour;
 * - the theme's contact form: at most 5 messages per address per hour (more are dropped silently).
 * The sign-up forms and the booking endpoints have their own limits.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Guard {

	const LOGIN_FAILS = 8;

	public static function init(): void {
		// No comments or pings anywhere.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 20 );
		add_action( 'pre_comment_on_post', array( __CLASS__, 'no_comments' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', static fn() => array() );
		add_filter( 'wp_headers', static function ( $h ) {
			unset( $h['X-Pingback'] );
			return $h;
		} );
		add_filter( 'rest_endpoints', static function ( $e ) {
			if ( ! current_user_can( 'moderate_comments' ) ) {
				unset( $e['/wp/v2/comments'], $e['/wp/v2/comments/(?P<id>[\d]+)'] );
			}
			return $e;
		} );

		// Sign-in and password reset.
		add_filter( 'authenticate', array( __CLASS__, 'login_gate' ), 30, 1 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 1 ); // before the portal sends the visitor back to /login
		add_action( 'lostpassword_post', array( __CLASS__, 'lostpassword_gate' ), 10, 1 );

		// Contact form (the theme's handler runs at priority 10).
		add_action( 'admin_post_nopriv_atc_form', array( __CLASS__, 'contact_gate' ), -1 );
		add_action( 'admin_post_atc_form', array( __CLASS__, 'contact_gate' ), -1 );
	}

	public static function no_comments(): void {
		wp_die( 'Comments are closed.', 'Comments are closed', array( 'response' => 403 ) );
	}

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
	}

	private static function fails_key(): string {
		return 'atc_lf_' . md5( self::ip() );
	}

	public static function locked(): bool {
		return (int) get_transient( self::fails_key() ) >= self::LOGIN_FAILS;
	}

	/** Too many failed sign-ins from this address: refuse before checking the password. */
	public static function login_gate( $user ) {
		if ( (int) get_transient( self::fails_key() ) >= self::LOGIN_FAILS ) {
			return new WP_Error( 'atc_locked', 'Too many failed sign-in attempts. Please wait an hour, or reset your password.' );
		}
		return $user;
	}

	public static function login_failed(): void {
		$n = (int) get_transient( self::fails_key() );
		set_transient( self::fails_key(), $n + 1, HOUR_IN_SECONDS );
		if ( self::LOGIN_FAILS === $n + 1 ) {
			ATC_Install::log( 'security', 0, 'login_locked', array( 'ip' => self::ip() ) );
		}
	}

	public static function lostpassword_gate( $errors ): void {
		if ( ! ATC_Partner::rate_ok( 'lostpw', 5 ) && $errors instanceof WP_Error ) {
			$errors->add( 'atc_slow', 'Too many password reset requests. Please try again in an hour.' );
		}
	}

	public static function contact_gate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- only reads the form type; the theme checks the nonce.
		$type = isset( $_POST['atc_form'] ) ? sanitize_key( wp_unslash( $_POST['atc_form'] ) ) : '';
		if ( 'contact' !== $type || ATC_Partner::rate_ok( 'contact', 5 ) ) {
			return;
		}
		$back = remove_query_arg( array( 'sent', 'err' ), wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'sent', '1', $back ) . '#form' );
		exit;
	}
}
