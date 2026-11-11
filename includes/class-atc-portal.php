<?php
/**
 * Partner portal at /portal: sign-up, sign-in and the provider's workspace.
 * /provider and /affiliate are marketing pages on the site; the portal lives here.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Portal {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_portal' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_wp_admin' ) );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
		add_action( 'admin_post_nopriv_atc_form', array( __CLASS__, 'theme_signup' ), 1 );
		add_action( 'admin_post_atc_form', array( __CLASS__, 'theme_signup' ), 1 );
		add_filter( 'show_admin_bar', static fn( $show ) => current_user_can( 'atc_provider_portal' ) && ! current_user_can( 'edit_posts' ) ? false : $show );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^login/?$', 'index.php?atc_portal=signin', 'top' );
		add_rewrite_rule( '^portal/?$', 'index.php?atc_portal=home', 'top' );
		add_rewrite_rule( '^portal/(.+?)/?$', 'index.php?atc_portal=$matches[1]', 'top' );
	}

	/** One sign-in page for everyone: /login. */
	public static function login_url( string $next = '' ): string {
		$url = home_url( '/login/' );
		return '' !== $next ? add_query_arg( 'next', rawurlencode( $next ), $url ) : $url;
	}

	/**
	 * Where someone goes after signing in: the page they were going to (if it is on this site
	 * and they may open it), otherwise our team to /control, partners to /portal.
	 */
	public static function destination( WP_User $user, string $next = '' ): string {
		$partner = $user->has_cap( 'atc_provider_portal' ) || $user->has_cap( 'atc_affiliate_portal' );
		$staff   = $user->has_cap( 'atc_manage' );
		if ( '' !== $next ) {
			$next = wp_validate_redirect( $next, '' );
			$path = (string) wp_parse_url( $next, PHP_URL_PATH );
			$ok   = $next && ( $staff || ( $partner && 0 === strpos( $path, '/portal' ) ) || ( ! $partner && false === strpos( $path, '/control' ) ) );
			if ( $ok ) {
				return $next;
			}
		}
		if ( $staff ) {
			return home_url( '/control/' );
		}
		if ( $partner ) {
			return home_url( '/portal/' );
		}
		return home_url( '/' );
	}

	/** Partners never land in wp-admin, even when they sign in at wp-login.php. */
	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && ( $user->has_cap( 'atc_provider_portal' ) || $user->has_cap( 'atc_affiliate_portal' ) ) && ! $user->has_cap( 'edit_posts' ) ) {
			return self::destination( $user, (string) $requested );
		}
		return $to;
	}

	/** A wrong password on /login stays on /login. */
	public static function login_failed(): void {
		$ref = wp_get_referer();
		if ( $ref && ( false !== strpos( $ref, '/login' ) ) && false === strpos( $ref, 'wp-login.php' ) ) {
			$next = '';
			$q    = (string) wp_parse_url( $ref, PHP_URL_QUERY );
			parse_str( $q, $args );
			if ( ! empty( $args['next'] ) ) {
				$next = (string) $args['next'];
			}
			wp_safe_redirect( add_query_arg( 'failed', 1, self::login_url( $next ) ) );
			exit;
		}
	}

	public static function keep_out_of_wp_admin(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'atc_provider_portal' ) || current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/portal/' ) );
		exit;
	}

	public static function maybe_render(): void {
		$view = (string) get_query_var( 'atc_portal' );
		if ( '' === $view ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( preg_match( '#^file/(\d+)$#', $view, $m ) ) {
			ATC_Partner::serve_file( (int) $m[1] );
		}
		if ( 'signup' === $view ) {
			wp_safe_redirect( self::signup_url() );
			exit;
		}
		if ( 'signin' === $view || 'login' === $view ) {
			$next = isset( $_GET['next'] ) ? esc_url_raw( wp_unslash( $_GET['next'] ) ) : ( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );
			if ( is_user_logged_in() ) {
				wp_safe_redirect( self::destination( wp_get_current_user(), $next ) );
				exit;
			}
			if ( 'login' === $view ) {
				wp_safe_redirect( self::login_url( $next ) );
				exit;
			}
			self::login_page( $next );
			exit;
		}
		if ( 'set-password' === $view ) {
			self::set_password_page();
			exit;
		}
		if ( 'logout' === $view ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'atc_logout' ) ) {
				wp_logout();
			}
			wp_safe_redirect( self::login_url() );
			exit;
		}
		if ( ! is_user_logged_in() ) {
			$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/portal/' );
			wp_safe_redirect( self::login_url( $here ) );
			exit;
		}
		if ( 'confirm-email' === $view ) {
			$res = ATC_Partner::confirm_email_change( get_current_user_id(), isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '' );
			self::frame_open( 'Sign-in email' );
			echo '<main class="atc-wrap atc-narrow"><section class="card pad"><h1>' . ( is_wp_error( $res ) ? 'Email not changed' : 'Email changed' ) . '</h1><p>' . esc_html( is_wp_error( $res ) ? $res->get_error_message() : 'From now on, sign in with ' . wp_get_current_user()->user_email . '.' ) . '</p><p><a class="btn dark" href="' . esc_url( home_url( '/portal/account/' ) ) . '">Back to the portal</a></p></section></main>';
			self::frame_close();
			exit;
		}
		if ( ! ATC_Partner::current_party_id() ) {
			self::frame_open( 'Partner portal' );
			echo '<main class="atc-wrap atc-narrow"><section class="card pad"><h1>This account has no company linked</h1>';
			if ( current_user_can( 'atc_manage' ) ) {
				echo '<p>You are signed in as our team. The provider portal is for transport companies; manage providers in the <a href="' . esc_url( home_url( '/control/providers/' ) ) . '">control panel</a>. To try the portal yourself, sign out and sign up with a different email.</p>';
			} else {
				echo '<p>Sign up as a transport provider, or write to us at ' . esc_html( ATC_Settings::get( 'from_email' ) ) . '.</p>';
			}
			echo '<p><a class="btn" href="' . esc_url( wp_nonce_url( home_url( '/portal/logout/' ), 'atc_logout' ) ) . '">Sign out</a></p></section></main>';
			self::frame_close();
			exit;
		}
		self::app();
		exit;
	}

	/* ---------- Shared frame ---------- */

	private static function frame_open( string $title, string $subtitle = 'Partner portal' ): void {
		$css = ATC_URL . 'assets/control.css?v=' . rawurlencode( ATC_VERSION );
		$css2 = ATC_URL . 'assets/portal.css?v=' . rawurlencode( ATC_VERSION );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?> · Airport Transportation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $css2 ); ?>">
</head>
<body>
<header class="atc-top">
	<div class="atc-wrap atc-top-row">
		<a class="atc-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo ATC_Control::logo(); // phpcs:ignore -- built by the theme. ?>
			<span><strong><?php echo esc_html( $subtitle ); ?></strong></span>
		</a>
		<?php if ( is_user_logged_in() ) : ?>
			<a class="atc-user" href="<?php echo esc_url( wp_nonce_url( home_url( '/portal/logout/' ), 'atc_logout' ) ); ?>">Sign out</a>
		<?php endif; ?>
	</div>
</header>
		<?php
	}

	private static function frame_close( string $extra = '' ): void {
		echo $extra; // phpcs:ignore -- built by this class.
		echo "\n</body>\n</html>";
	}

	/* ---------- Sign-up (form lives on the theme's /partners/provider/ page) ---------- */

	public static function signup_url(): string {
		return (string) apply_filters( 'atc_provider_signup_url', home_url( '/partners/provider/' ) ) . '#form';
	}

	/**
	 * Handles the provider form on /partners/provider/. The theme posts every form to admin-post.php
	 * (action atc_form); this runs first and takes over when the provider form carries our fields.
	 */
	public static function theme_signup(): void {
		if ( 'provider' !== ( isset( $_POST['atc_form'] ) ? sanitize_key( wp_unslash( $_POST['atc_form'] ) ) : '' ) || empty( $_POST['atc_signup'] ) ) {
			return;
		}
		$back = remove_query_arg( array( 'sent', 'err', 'atc_su' ), wp_get_referer() ? wp_get_referer() : home_url( '/partners/provider/' ) );
		$back = strtok( $back, '#' );
		$in   = wp_unslash( $_POST ); // phpcs:ignore -- checked and sanitized below.

		$fail = static function ( array $errors, string $general = '' ) use ( $in, $back ) {
			$token = wp_generate_password( 12, false, false );
			$keep  = array_intersect_key( $in, array_flip( array( 'company', 'name', 'email', 'phone', 'airport_id', 'airport_place_id', 'airport_q', 'fleet', 'about', 'accept' ) ) );
			set_transient( 'atc_su_' . $token, array( 'errors' => $errors, 'general' => $general, 'in' => $keep ), 15 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'atc_su', $token, $back ) . '#form' );
			exit;
		};

		if ( ! isset( $in['_atcn'] ) || ! wp_verify_nonce( (string) $in['_atcn'], 'atc_form_provider' ) ) {
			$fail( array(), 'The form expired. Please send it again.' );
		}
		if ( ! empty( $in['website_url'] ) ) {
			wp_safe_redirect( add_query_arg( 'sent', '1', $back ) . '#form' );
			exit;
		}
		if ( function_exists( 'atc_is_spam' ) ) {
			$spam = atc_is_spam( 'provider' );
			if ( 'captcha' === $spam ) {
				$fail( array(), 'Please complete the security check and send again.' );
			} elseif ( $spam ) {
				$fail( array(), 'Something went wrong. Please wait a few seconds and send again.' );
			}
		}
		if ( preg_match_all( '#(https?://|www\.|<a\s)#i', (string) ( $in['about'] ?? '' ) ) > 2 ) {
			$fail( array( 'about' => 'Please remove the links.' ) );
		}
		if ( is_user_logged_in() ) {
			$fail( array(), 'You are signed in with another account. Sign out first, then create the provider account.' );
		}
		if ( ! ATC_Partner::rate_ok( 'signup' ) ) {
			$fail( array(), 'Too many attempts. Try again in an hour.' );
		}

		$in['contact'] = $in['name'] ?? '';
		$result        = ATC_Partner::signup( $in );
		if ( is_wp_error( $result ) ) {
			$errors = array();
			foreach ( $result->get_error_codes() as $code ) {
				$errors[ 'contact' === $code ? 'name' : $code ] = $result->get_error_message( $code );
			}
			$fail( $errors );
		}
		wp_set_current_user( $result );
		wp_set_auth_cookie( $result, true );
		wp_safe_redirect( home_url( '/portal/?welcome=1' ) );
		exit;
	}

	/** The provider form's fields, for the theme's /partners/provider/ page. */
	public static function signup_fields(): string {
		$state = array( 'errors' => array(), 'general' => '', 'in' => array() );
		if ( isset( $_GET['atc_su'] ) ) {
			$saved = get_transient( 'atc_su_' . sanitize_key( wp_unslash( $_GET['atc_su'] ) ) );
			if ( is_array( $saved ) ) {
				$state = array_merge( $state, $saved );
			}
		}
		$in  = $state['in'];
		$v   = static fn( $k ) => esc_attr( isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '' );
		$err = static fn( $k ) => isset( $state['errors'][ $k ] ) ? '<small class="atc-field-err" role="alert">' . esc_html( $state['errors'][ $k ] ) . '</small>' : '';

		$out = '<input type="hidden" name="atc_signup" value="1">';
		if ( $state['general'] || ( $state['errors'] && empty( array_intersect_key( $state['errors'], array_flip( array( 'company', 'name', 'email', 'phone', 'airport_id', 'password', 'accept', 'about' ) ) ) ) ) ) {
			$msg  = $state['general'] ?: implode( ' ', $state['errors'] );
			$out .= '<p class="atc-formnote is-err" role="alert">' . esc_html( $msg ) . '</p>';
		}
		$out .= '<label class="atc-field"><span>Company name travelers will see</span><input type="text" name="company" placeholder="Your company" required autocomplete="organization" value="' . $v( 'company' ) . '">' . $err( 'company' ) . '</label>';
		$out .= '<label class="atc-field"><span>Contact name</span><input type="text" name="name" placeholder="Your name" required autocomplete="name" value="' . $v( 'name' ) . '">' . $err( 'name' ) . '</label>';
		$out .= '<label class="atc-field"><span>Email (you sign in with it)</span><input type="email" name="email" placeholder="you@company.com" required autocomplete="email" value="' . $v( 'email' ) . '">' . $err( 'email' ) . '</label>';
		$out .= '<label class="atc-field"><span>Mobile with WhatsApp, with country code</span><input type="tel" name="phone" placeholder="+52 998 …" required autocomplete="tel" value="' . $v( 'phone' ) . '">' . $err( 'phone' ) . '</label>';
		$out .= self::airport_field( $in, $err( 'airport_id' ) );
		$out .= '<label class="atc-field"><span>Fleet size</span><select name="fleet">';
		foreach ( array( '1–2 vehicles', '3–10 vehicles', '11+ vehicles' ) as $f ) {
			$out .= '<option' . selected( (string) ( $in['fleet'] ?? '' ), $f, false ) . '>' . esc_html( $f ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<label class="atc-field"><span>Tell us about your company: fleet, areas you cover, years in business (optional)</span><textarea name="about" rows="4" maxlength="2000">' . esc_textarea( (string) ( $in['about'] ?? '' ) ) . '</textarea>' . $err( 'about' ) . '</label>';
		$out .= '<label class="atc-field"><span>Password (at least 10 characters)</span><input type="password" name="password" required minlength="10" autocomplete="new-password">' . $err( 'password' ) . '</label>';
		$out .= '<label class="atc-check"><input type="checkbox" name="accept" value="1"' . checked( ! empty( $in['accept'] ), true, false ) . ' required> <span>I accept the <a href="' . esc_url( home_url( '/partners/provider/terms-conditions/' ) ) . '" target="_blank" rel="noopener">provider terms and conditions</a>.</span></label>' . $err( 'accept' );
		return $out;
	}

	/**
	 * Main airport: a search box over our airports, with Google for any airport we do not have yet.
	 * The pick travels in airport_id (ours) or airport_place_id (new, from Google).
	 */
	private static function airport_field( array $in, string $err ): string {
		wp_enqueue_style( 'atc-signup', ATC_URL . 'assets/signup.css', array(), ATC_VERSION );
		wp_enqueue_script( 'atc-signup', ATC_URL . 'assets/signup.js', array(), ATC_VERSION, true );

		$id    = (int) ( $in['airport_id'] ?? 0 );
		$place = preg_match( '/^[A-Za-z0-9_-]{10,190}$/', (string) ( $in['airport_place_id'] ?? '' ) ) ? (string) $in['airport_place_id'] : '';
		$text  = sanitize_text_field( (string) ( $in['airport_q'] ?? '' ) );
		$mine  = $id ? ATC_Catalog::airport( $id ) : null;
		if ( $mine ) {
			$text  = ATC_Catalog::airport_label( $mine );
			$place = '';
		} else {
			$id = 0;
		}

		$out  = '<div class="atc-airport" data-atc-airport data-search="' . esc_url( rest_url( 'atc/v1/airports/search' ) ) . '">';
		$out .= '<label class="atc-field"><span>Main airport you serve</span><input type="text" name="airport_q" placeholder="Search by city, airport name or code" required autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="atc-airport-list" value="' . esc_attr( $text ) . '">' . $err . '</label>';
		$out .= '<input type="hidden" name="airport_id" value="' . ( $id ? $id : '' ) . '"><input type="hidden" name="airport_place_id" value="' . esc_attr( $place ) . '">';
		$out .= '<ul id="atc-airport-list" class="atc-airport-list" role="listbox" aria-label="Airports" hidden></ul>';
		$out .= '<p class="atc-airport-new" role="status"' . ( $place ? '' : ' hidden' ) . '>This airport is new on Airport Transportation. We add it from Google when you create your account, and our team sets up its zones before travelers can book you.</p>';
		$out .= '<small class="atc-airport-hint">Pick the airport where you do most of your transfers. You can add more airports later.</small>';
		$out .= '</div>';
		return $out;
	}

	/** First password for invited providers (and a fresh one for anyone with a valid link). */
	private static function set_password_page(): void {
		$key   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		$login = isset( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( rawurldecode( (string) $_REQUEST['login'] ) ) ) : '';
		$user  = check_password_reset_key( $key, $login );
		$error = '';
		if ( ! is_wp_error( $user ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$p1 = (string) wp_unslash( $_POST['pass1'] ?? '' ); // phpcs:ignore
			$p2 = (string) wp_unslash( $_POST['pass2'] ?? '' ); // phpcs:ignore
			if ( ! isset( $_POST['_atc'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_atc'] ) ), 'atc_setpass_' . $user->ID ) ) {
				$error = 'The form expired. Please try again.';
			} elseif ( strlen( $p1 ) < 10 ) {
				$error = 'Use at least 10 characters.';
			} elseif ( $p1 !== $p2 ) {
				$error = 'The two passwords are not the same.';
			} else {
				remove_action( 'after_password_reset', 'wp_password_change_notification' );
				reset_password( $user, $p1 );
				update_user_meta( $user->ID, 'atc_password_set', time() );
				ATC_Install::log( 'user', $user->ID, 'password_set_from_invite' );
				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );
				wp_safe_redirect( self::destination( $user ) . '?welcome=1' );
				exit;
			}
		}
		self::frame_open( 'Choose your password', 'Welcome' );
		echo '<main class="atc-wrap atc-narrow">';
		if ( is_wp_error( $user ) ) {
			echo '<section class="card pad"><h1>This link has expired</h1><p>Links to choose a password work for 24 hours and only once. <a href="' . esc_url( wp_lostpassword_url( self::login_url() ) ) . '">Get a new link</a> with your email, or write to ' . esc_html( ATC_Settings::get( 'from_email' ) ) . '.</p></section>';
		} else {
			echo '<div class="atc-head"><div><h1>Choose your password</h1><p class="atc-muted">You sign in with ' . esc_html( $user->user_email ) . '.</p></div></div><form method="post" class="card pad">';
			if ( $error ) {
				echo '<p class="chip red">' . esc_html( $error ) . '</p>';
			}
			wp_nonce_field( 'atc_setpass_' . $user->ID, '_atc' );
			echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><input type="hidden" name="login" value="' . esc_attr( $login ) . '">';
			echo '<div class="grid2"><label class="field"><span>New password (at least 10 characters)</span><input type="password" name="pass1" minlength="10" required autocomplete="new-password"></label><label class="field"><span>Type it again</span><input type="password" name="pass2" minlength="10" required autocomplete="new-password"></label></div>';
			echo '<div class="atc-actions"><button class="btn dark" type="submit">Save and open my portal</button></div></form>';
		}
		echo '</main>';
		self::frame_close();
	}

	private static function login_page( string $next = '' ): void {
		self::frame_open( 'Sign in', 'Sign in' );
		echo '<main class="atc-wrap atc-narrow"><div class="atc-head"><div><h1>Sign in</h1><p class="atc-muted">Travelers, providers, affiliates and our team all sign in here. Booked without an account? Use <a href="' . esc_url( home_url( '/manage-booking/' ) ) . '">Manage booking</a> with your reservation number and email.</p></div></div><section class="card pad atc-login">';
		if ( isset( $_GET['failed'] ) ) {
			echo '<p class="chip red">Email or password is not right.</p>';
		}
		wp_login_form(
			array(
				'redirect'       => self::login_url( $next ),
				'label_username' => 'Email',
				'label_log_in'   => 'Sign in',
				'remember'       => true,
			)
		);
		echo '<p><a href="' . esc_url( wp_lostpassword_url( self::login_url() ) ) . '">Forgot your password?</a></p><p class="atc-muted">New here? <a href="' . esc_url( self::signup_url() ) . '">Become a provider</a> · <a href="' . esc_url( home_url( '/partners/affiliate/' ) ) . '">Become an affiliate</a></p></section></main>';
		self::frame_close();
	}

	/* ---------- The provider app ---------- */

	private static function app(): void {
		$config = array(
			'rest'    => esc_url_raw( rest_url( 'atc/v1/portal' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'base'    => esc_url_raw( home_url( '/portal/' ) ),
			'mapsKey' => ATC_Settings::maps_browser_key(),
			'logout'  => esc_url_raw( wp_nonce_url( home_url( '/portal/logout/' ), 'atc_logout' ) ),
			'support' => ATC_Settings::get( 'from_email' ),
		);
		$js = ATC_URL . 'assets/portal.js?v=' . rawurlencode( ATC_VERSION );
		self::frame_open( 'Provider portal', 'Provider portal' );
		echo '<div class="atc-wrap atc-shell"><nav id="atc-side" class="atc-side" aria-label="Provider menu"></nav><main id="atc-app" class="atc-main" aria-live="polite"><p class="atc-muted">Loading…</p></main></div><div id="atc-toast" role="status" aria-live="polite"></div>';
		self::frame_close( '<script>window.ATC = ' . wp_json_encode( $config ) . ';</script><script src="' . esc_url( ATC_URL . 'assets/prices.js?v=' . rawurlencode( ATC_VERSION ) ) . '"></script><script src="' . esc_url( $js ) . '"></script>' );
	}
}
