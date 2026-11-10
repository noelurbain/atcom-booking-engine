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
		add_filter( 'show_admin_bar', static fn( $show ) => current_user_can( 'atc_provider_portal' ) && ! current_user_can( 'edit_posts' ) ? false : $show );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^portal/?$', 'index.php?atc_portal=home', 'top' );
		add_rewrite_rule( '^portal/(.+?)/?$', 'index.php?atc_portal=$matches[1]', 'top' );
	}

	/** Providers land in the portal after signing in, never in wp-admin. */
	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && $user->has_cap( 'atc_provider_portal' ) && ! $user->has_cap( 'edit_posts' ) ) {
			return home_url( '/portal/' );
		}
		return $to;
	}

	/** A wrong password on the portal sign-in page stays in the portal. */
	public static function login_failed(): void {
		$ref = wp_get_referer();
		if ( $ref && false !== strpos( $ref, '/portal/login' ) ) {
			wp_safe_redirect( home_url( '/portal/login/?failed=1' ) );
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
			self::signup_page();
			exit;
		}
		if ( 'login' === $view ) {
			if ( is_user_logged_in() ) {
				wp_safe_redirect( home_url( '/portal/' ) );
				exit;
			}
			self::login_page();
			exit;
		}
		if ( 'logout' === $view ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'atc_logout' ) ) {
				wp_logout();
			}
			wp_safe_redirect( home_url( '/portal/login/' ) );
			exit;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/portal/login/' ) );
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
			<svg width="34" height="34" viewBox="0 0 40 40" aria-hidden="true"><path d="M3 17 L38 3 L28 38 L21 23 Z" fill="#FBA53E"></path></svg>
			<span><strong><?php echo esc_html( $subtitle ); ?></strong><small>Airport Transportation</small></span>
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

	/* ---------- Sign-up ---------- */

	private static function signup_page(): void {
		if ( is_user_logged_in() && ATC_Partner::current_party_id() ) {
			wp_safe_redirect( home_url( '/portal/' ) );
			exit;
		}
		$errors = null;
		$in     = array();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$in = wp_unslash( $_POST ); // phpcs:ignore -- verified below.
			if ( ! isset( $in['_atc'] ) || ! wp_verify_nonce( (string) $in['_atc'], 'atc_signup' ) ) {
				$errors = new WP_Error( 'nonce', 'The form expired. Please try again.' );
			} elseif ( ! empty( $in['website_url'] ) ) {
				$errors = new WP_Error( 'bot', 'Something went wrong. Please try again.' );
			} elseif ( ! ATC_Partner::rate_ok( 'signup' ) ) {
				$errors = new WP_Error( 'rate', 'Too many attempts. Try again in an hour.' );
			} elseif ( is_user_logged_in() ) {
				$errors = new WP_Error( 'logged', 'You are signed in with another account. Sign out first.' );
			} else {
				$result = ATC_Partner::signup( $in );
				if ( is_wp_error( $result ) ) {
					$errors = $result;
				} else {
					wp_set_current_user( $result );
					wp_set_auth_cookie( $result, true );
					wp_safe_redirect( home_url( '/portal/?welcome=1' ) );
					exit;
				}
			}
		}

		$airports = ATC_Catalog::airports();
		$val      = static fn( $k ) => esc_attr( isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '' );
		$err      = static function ( $k ) use ( $errors ) {
			if ( $errors && $errors->get_error_message( $k ) ) {
				echo '<small class="err">' . esc_html( $errors->get_error_message( $k ) ) . '</small>';
			}
		};

		self::frame_open( 'Become a provider' );
		?>
<main class="atc-wrap atc-narrow">
	<div class="atc-head"><div><h1>Sell your transfers with us</h1><p class="atc-muted">Create your provider account. It takes two minutes; you finish the setup inside the portal.</p></div></div>
	<?php if ( $errors && ( $errors->get_error_message( 'nonce' ) || $errors->get_error_message( 'bot' ) || $errors->get_error_message( 'rate' ) || $errors->get_error_message( 'logged' ) || $errors->get_error_message( 'existing_user_login' ) ) ) : ?>
		<p class="chip red"><?php echo esc_html( $errors->get_error_message() ); ?></p>
	<?php endif; ?>
	<form method="post" class="card pad" novalidate>
		<?php wp_nonce_field( 'atc_signup', '_atc' ); ?>
		<div class="grid2">
			<label class="field"><span>Company name travelers will see</span><input type="text" name="company" required value="<?php echo $val( 'company' ); // phpcs:ignore ?>" autocomplete="organization"><?php $err( 'company' ); ?></label>
			<label class="field"><span>Your name</span><input type="text" name="contact" required value="<?php echo $val( 'contact' ); // phpcs:ignore ?>" autocomplete="name"><?php $err( 'contact' ); ?></label>
			<label class="field"><span>Email (you sign in with it)</span><input type="email" name="email" required value="<?php echo $val( 'email' ); // phpcs:ignore ?>" autocomplete="email"><?php $err( 'email' ); ?></label>
			<label class="field"><span>Mobile with WhatsApp, with country code</span><input type="tel" name="phone" required placeholder="+52 998 …" value="<?php echo $val( 'phone' ); // phpcs:ignore ?>" autocomplete="tel"><?php $err( 'phone' ); ?></label>
			<label class="field"><span>Airport you serve</span><select name="airport_id">
				<?php foreach ( $airports as $a ) : ?>
					<option value="<?php echo (int) $a['id']; ?>" <?php selected( (int) ( $in['airport_id'] ?? 0 ), (int) $a['id'] ); ?>><?php echo esc_html( $a['city'] . ' (' . $a['iata'] . ')' ); ?></option>
				<?php endforeach; ?>
			</select><?php $err( 'airport_id' ); ?></label>
			<label class="field"><span>Password (at least 10 characters)</span><input type="password" name="password" required minlength="10" autocomplete="new-password"><?php $err( 'password' ); ?></label>
		</div>
		<label class="field"><span>Tell us about your company: fleet, areas you cover, years in business (optional)</span><textarea name="about" rows="4" maxlength="2000"><?php echo esc_textarea( isset( $in['about'] ) ? sanitize_textarea_field( (string) $in['about'] ) : '' ); ?></textarea></label>
		<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
		<label class="tick"><input type="checkbox" name="accept" value="1" <?php checked( ! empty( $in['accept'] ) ); ?>> I accept the <a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>" target="_blank" rel="noopener">terms of use</a> and the <a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>" target="_blank" rel="noopener">privacy policy</a>. The provider agreement comes later, in the portal.</label>
		<?php $err( 'accept' ); ?>
		<div class="atc-actions"><button class="btn dark" type="submit">Create my provider account</button><a href="<?php echo esc_url( home_url( '/portal/login/' ) ); ?>">I already have an account</a></div>
	</form>
</main>
		<?php
		self::frame_close();
	}

	private static function login_page(): void {
		self::frame_open( 'Sign in' );
		echo '<main class="atc-wrap atc-narrow"><div class="atc-head"><div><h1>Sign in</h1><p class="atc-muted">Provider portal</p></div></div><section class="card pad atc-login">';
		if ( isset( $_GET['failed'] ) ) {
			echo '<p class="chip red">Email or password is not right.</p>';
		}
		wp_login_form(
			array(
				'redirect'       => home_url( '/portal/' ),
				'label_username' => 'Email',
				'label_log_in'   => 'Sign in',
				'remember'       => true,
			)
		);
		echo '<p><a href="' . esc_url( wp_lostpassword_url( home_url( '/portal/' ) ) ) . '">Forgot your password?</a> · <a href="' . esc_url( home_url( '/portal/signup/' ) ) . '">Create a provider account</a></p></section></main>';
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
		self::frame_close( '<script>window.ATC = ' . wp_json_encode( $config ) . ';</script><script src="' . esc_url( $js ) . '"></script>' );
	}
}
