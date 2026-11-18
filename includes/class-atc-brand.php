<?php
/**
 * Our name everywhere, not WordPress's: sign-in goes to /login, the password pages that stay on
 * wp-login.php carry our logo and colors, and the usual "made with WordPress" traces are taken out of
 * pages, headers and emails. (Files still live under /wp-content/: that path can't be hidden safely.)
 *
 * Escape hatch: wp-login.php?native=1 shows WordPress's own sign-in form, in case /login ever breaks.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Brand {

	public static function init(): void {
		add_action( 'login_init', array( __CLASS__, 'to_login_page' ), 1 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_style' ) );
		add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_logo' ) );
		add_filter( 'login_display_language_dropdown', '__return_false' );
		add_filter( 'login_title', static fn( $title ) => str_replace( array( '&lsaquo;', '— WordPress', '&#8212; WordPress', 'WordPress' ), array( '·', '', '', 'Airport Transportation' ), (string) $title ) );

		// Traces in every page's <head> and in the response headers.
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_headers', static function ( $h ) {
			unset( $h['X-Pingback'] );
			return $h;
		} );
		add_filter( 'style_loader_src', array( __CLASS__, 'no_wp_version' ), 20 );
		add_filter( 'script_loader_src', array( __CLASS__, 'no_wp_version' ), 20 );
		add_action( 'wp_enqueue_scripts', static fn() => wp_dequeue_script( 'wp-embed' ), 100 );

		// Who writes the site: no author pages, no user list from the API for visitors.
		add_action( 'template_redirect', array( __CLASS__, 'no_author_pages' ), 1 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_users_endpoint' ) );

		// Emails WordPress itself sends (password reset…): from Airport Transportation.
		add_filter( 'wp_mail_from_name', static fn( $name ) => 'WordPress' === $name ? 'Airport Transportation' : $name );
		add_filter( 'retrieve_password_title', static fn() => 'Reset your Airport Transportation password' );
	}

	/** Plain visits to wp-login.php (and wp-admin when signed out) go to /login. Sign-in posts and password pages stay. */
	public static function to_login_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- only reads where to go.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		// "Check your email" and "password changed" messages stay here, on our branded version of the page.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || 'login' !== $action || ! empty( $_GET['native'] ) || ! empty( $_GET['interim-login'] ) || ! empty( $_GET['checkemail'] ) || ! empty( $_GET['password'] ) ) {
			return;
		}
		$next = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		// phpcs:enable
		wp_safe_redirect( ATC_Portal::login_url( $next ) );
		exit;
	}

	public static function login_logo(): string {
		$logo = function_exists( 'atc_logo' ) ? atc_logo( false ) : '<strong>Airport Transportation</strong>';
		return '<span class="atc-login-logo">' . $logo . '</span><span class="screen-reader-text">Airport Transportation</span>';
	}

	public static function login_style(): void {
		?>
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
		<style>
			body.login{background:#F6F4EF;font-family:Manrope,system-ui,sans-serif;color:#151515}
			.login h1 a{background:none!important;width:auto!important;height:auto!important;text-indent:0!important;overflow:visible!important;margin:0 auto 18px!important;display:flex;justify-content:center}
			.login h1 a:focus{box-shadow:none}
			.atc-login-logo{display:inline-flex;align-items:center;gap:10px;font-size:22px;font-weight:800;color:#151515}
			.atc-login-logo svg,.atc-login-logo img{height:44px;width:auto}
			.login form{border:0;border-radius:18px;box-shadow:0 6px 30px rgba(0,0,0,.06);padding:26px 24px}
			.login label{font-weight:600;font-size:14px}
			.login input[type=text],.login input[type=password],.login input[type=email]{border-radius:12px;border-color:#DDD8CE;font-size:16px;padding:6px 12px}
			.login input:focus{border-color:#151515;box-shadow:0 0 0 1px #151515}
			.wp-core-ui .button-primary{background:#151515;border-color:#151515;border-radius:999px;font-weight:700;padding:0 20px;min-height:40px}
			.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:#000;border-color:#000}
			.login #nav a,.login #backtoblog a,.login .privacy-policy-link{color:#555;font-size:13px}
			.login .message,.login .notice,.login #login_error{border-radius:12px;border-left-color:#F5A33A}
			.login #backtoblog a::before{content:''}
			.language-switcher{display:none}
		</style>
		<?php
	}

	public static function no_wp_version( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	public static function no_author_pages(): void {
		if ( is_author() || ( isset( $_GET['author'] ) && ! is_admin() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function hide_users_endpoint( array $endpoints ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}
}
