<?php
/**
 * The control panel at /control: our team's workspace on the site itself, outside wp-admin.
 * Server renders the frame; assets/control.js draws each screen from the REST API.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Control {

	public static function init(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 35 );
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_control' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	/** The site's logo (light words, for our dark top bars), or the orange mark if the theme has none. */
	public static function logo(): string {
		if ( function_exists( 'atc_logo' ) ) {
			return '<span class="atc-logo-wrap">' . atc_logo( true ) . '</span>';
		}
		return '<svg width="34" height="34" viewBox="0 0 40 40" aria-hidden="true"><path d="M3 17 L38 3 L28 38 L21 23 Z" fill="#FBA53E"></path></svg>';
	}

	/** "Control panel" in the WordPress toolbar, for our team, on every wp-admin and site page. */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'atc_manage' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'atc-control',
				'title' => '<span class="ab-icon dashicons dashicons-car" style="top:2px"></span>Control panel',
				'href'  => home_url( '/control/' ),
			)
		);
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^control/?$', 'index.php?atc_control=home', 'top' );
		add_rewrite_rule( '^control/(.+?)/?$', 'index.php?atc_control=$matches[1]', 'top' );
	}

	/** Flush once after install or upgrade, after every plugin has added its rules. */
	public static function maybe_flush(): void {
		// Also after every plugin update, since new versions can add addresses (/login, /portal…).
		if ( get_option( 'atc_flush_rewrite' ) || get_option( 'atc_rewrite_version' ) !== ATC_VERSION ) {
			delete_option( 'atc_flush_rewrite' );
			update_option( 'atc_rewrite_version', ATC_VERSION, false );
			flush_rewrite_rules( false );
		}
	}

	public static function maybe_render(): void {
		$view = get_query_var( 'atc_control' );
		if ( ! $view ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/control/' );
			wp_safe_redirect( ATC_Portal::login_url( $here ) );
			exit;
		}
		if ( ! current_user_can( 'atc_manage' ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'The control panel is for our team only.', 'atcom-booking-engine' ), 403 );
		}
		header( 'X-Robots-Tag: noindex, nofollow', true );
		self::render();
		exit;
	}

	private static function render(): void {
		$user     = wp_get_current_user();
		$initials = strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) . mb_substr( $user->last_name, 0, 1 ) );
		$config   = array(
			'rest'      => esc_url_raw( rest_url( 'atc/v1/control' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'base'      => esc_url_raw( home_url( '/control/' ) ),
			'mapsKey'   => ATC_Settings::maps_browser_key(),
			'version'   => ATC_VERSION,
		);
		$css = ATC_URL . 'assets/control.css?v=' . rawurlencode( ATC_VERSION );
		$js  = ATC_URL . 'assets/control.js?v=' . rawurlencode( ATC_VERSION );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Control panel · Airport Transportation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
</head>
<body>
<header class="atc-top">
	<div class="atc-wrap atc-top-row">
		<a class="atc-brand" href="<?php echo esc_url( home_url( '/control/' ) ); ?>">
			<?php echo self::logo(); // phpcs:ignore -- built by the theme. ?>
			<span><strong>Control panel</strong><small>All sites</small></span>
		</a>
		<form class="atc-search" role="search" action="<?php echo esc_url( home_url( '/control/providers/' ) ); ?>" method="get">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
			<input type="search" name="q" aria-label="Search providers" placeholder="Search a provider by name or email">
		</form>
		<span class="atc-user"><span class="atc-avatar"><?php echo esc_html( $initials ?: '•' ); ?></span><?php echo esc_html( $user->display_name . ( current_user_can( 'manage_options' ) ? ' · Super admin' : '' ) ); ?></span>
	</div>
</header>
<div class="atc-wrap atc-shell">
	<nav class="atc-sidenav" aria-label="Control menu">
		<a href="<?php echo esc_url( home_url( '/control/' ) ); ?>" data-view="">Dashboard</a>
		<a href="<?php echo esc_url( home_url( '/control/bookings/' ) ); ?>" data-view="bookings">Bookings</a>
		<a href="<?php echo esc_url( home_url( '/control/providers/' ) ); ?>" data-view="providers">Providers</a>
		<span class="off">Prices <i class="soon">soon</i></span>
		<a href="<?php echo esc_url( home_url( '/control/zones/' ) ); ?>" data-view="zones">Zones and hotels</a>
		<a href="<?php echo esc_url( home_url( '/control/meeting/' ) ); ?>" data-view="meeting">Meeting your driver</a>
		<span class="off">Payouts <i class="soon">soon</i></span>
		<span class="off">Tickets <i class="soon">soon</i></span>
		<a href="https://app.crisp.chat/" target="_blank" rel="noopener">Crisp inbox ↗</a>
		<?php if ( current_user_can( 'atc_settings' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=atc-settings' ) ); ?>">Settings</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=atc' ) ); ?>">Status</a>
		<?php if ( current_user_can( 'edit_posts' ) ) : ?>
			<a class="atc-wp" href="<?php echo esc_url( admin_url() ); ?>">WordPress ↗</a>
		<?php endif; ?>
	</nav>
	<main id="atc-app" class="atc-main" aria-live="polite"><p class="atc-muted">Loading…</p></main>
</div>
<div id="atc-toast" role="status" aria-live="polite"></div>
<script>window.ATC = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( ATC_URL . 'assets/prices.js?v=' . rawurlencode( ATC_VERSION ) ); ?>"></script>
<script src="<?php echo esc_url( ATC_URL . 'assets/meeting.js?v=' . rawurlencode( ATC_VERSION ) ); ?>"></script>
<script src="<?php echo esc_url( $js ); ?>"></script>
</body>
</html>
		<?php
	}
}
