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
		if ( preg_match( '#^document/(\d+)$#', (string) $view, $m ) ) {
			self::document_preview( (int) $m[1] );
			exit;
		}
		self::render();
		exit;
	}

	/** A provider document shown in the page (PDFs drawn page by page), with its details, for review. */
	private static function document_preview( int $id ): void {
		$doc = ATC_Partner::document( $id );
		if ( ! $doc ) {
			status_header( 404 );
			wp_die( 'Document not found.', 404 );
		}
		$p     = ATC_Catalog::party( (int) $doc['party_id'] );
		$types = ATC_Partner::doc_types();
		$url   = ATC_Partner::file_url( $id );
		$pdf   = 'application/pdf' === $doc['mime'];
		$lib   = ATC_URL . 'assets/vendor/pdfjs/';
		?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Document <?php echo (int) $id; ?> · <?php echo esc_html( $p['name'] ?? '' ); ?></title>
<style>body{margin:0;font:14px/1.4 system-ui,sans-serif;background:#E9E6E0;color:#1B1B1F}.bar{position:sticky;top:0;background:#fff;padding:10px 16px;border-bottom:1px solid #ddd;z-index:2}.bar b{font-size:15px}.pages{display:flex;flex-direction:column;align-items:center;gap:12px;padding:16px}.pages canvas,.pages img{max-width:min(1100px,96vw);height:auto;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.15)}.muted{color:#6D6D71}</style>
</head><body>
<div class="bar"><b><?php echo esc_html( ( $p['name'] ?? '' ) . ( ! empty( $p['legal_name'] ) && $p['legal_name'] !== $p['name'] ? ' (' . $p['legal_name'] . ')' : '' ) ); ?></b> · <?php echo esc_html( $p['country'] ?? '' ); ?> · <?php echo esc_html( $types[ $doc['type'] ][0] ?? $doc['type'] ); ?>
 · expires <?php echo esc_html( $doc['expires_on'] ?: 'not given' ); ?> · <span class="muted"><?php echo esc_html( $doc['original_name'] ); ?> · <?php echo esc_html( $doc['status'] ); ?></span> <span id="np" class="muted"></span></div>
<div class="pages" id="pages"><?php if ( ! $pdf ) : ?><img src="<?php echo esc_url( $url ); ?>" alt=""><?php endif; ?></div>
		<?php if ( $pdf ) : ?>
<script type="module">
import * as lib from '<?php echo esc_url( $lib . 'pdf.min.js' ); ?>';
lib.GlobalWorkerOptions.workerSrc = '<?php echo esc_url( $lib . 'pdf.worker.min.js' ); ?>';
const box = document.getElementById('pages');
try {
  const pdf = await lib.getDocument('<?php echo esc_url_raw( $url ); ?>').promise;
  const n = Math.min(pdf.numPages, 8);
  document.getElementById('np').textContent = '· ' + pdf.numPages + ' page' + (pdf.numPages > 1 ? 's' : '') + (pdf.numPages > n ? ' (first ' + n + ' shown)' : '');
  for (let i = 1; i <= n; i++) {
    const page = await pdf.getPage(i);
    const vp = page.getViewport({ scale: 1.6 });
    const c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height; box.appendChild(c);
    await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
  }
  document.body.dataset.ready = '1';
} catch (e) { box.textContent = 'Could not draw this PDF: ' + e.message; document.body.dataset.ready = '1'; }
</script>
		<?php endif; ?>
</body></html>
		<?php
	}

	private static function render(): void {
		$user     = wp_get_current_user();
		$initials = strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) . mb_substr( $user->last_name, 0, 1 ) );
		$config   = array(
			'rest'      => esc_url_raw( rest_url( 'atc/v1/control' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'base'      => esc_url_raw( home_url( '/control/' ) ),
			'home'      => esc_url_raw( home_url( '/' ) ),
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
<?php echo ATC_Push::head_tags( 'control' ); // phpcs:ignore -- escaped inside. ?>
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
<div class="atc-wrap"><div id="atc-clocks" class="atc-clocks" aria-label="Local times"></div></div>
<div class="atc-wrap atc-shell">
	<nav class="atc-sidenav" aria-label="Control menu">
		<a class="atc-site" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">View the site ↗</a>
		<a href="<?php echo esc_url( home_url( '/control/' ) ); ?>" data-view="">Dashboard</a>
		<a href="<?php echo esc_url( home_url( '/control/bookings/' ) ); ?>" data-view="bookings">Bookings</a>
		<a href="<?php echo esc_url( home_url( '/control/providers/' ) ); ?>" data-view="providers">Providers</a>
		<a href="<?php echo esc_url( home_url( '/control/content/' ) ); ?>" data-view="content">Content</a>
		<a href="<?php echo esc_url( home_url( '/control/affiliates/' ) ); ?>" data-view="affiliates">Affiliates</a>
		<span class="off">Prices <i class="soon">soon</i></span>
		<a href="<?php echo esc_url( home_url( '/control/zones/' ) ); ?>" data-view="zones">Zones and hotels</a>
		<a href="<?php echo esc_url( home_url( '/control/airports/' ) ); ?>" data-view="airports">Airports</a>
		<a href="<?php echo esc_url( home_url( '/control/meeting/' ) ); ?>" data-view="meeting">Meeting your driver</a>
		<a href="<?php echo esc_url( home_url( '/control/payouts/' ) ); ?>" data-view="payouts">Payouts</a>
		<a href="<?php echo esc_url( home_url( '/control/coupons/' ) ); ?>" data-view="coupons">Discount codes</a>
		<a href="<?php echo esc_url( home_url( '/control/extras/' ) ); ?>" data-view="extras">Extras</a>
		<a href="<?php echo esc_url( home_url( '/control/insights/' ) ); ?>" data-view="insights">Searches</a>
		<?php if ( current_user_can( 'atc_payouts' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/control/report/' ) ); ?>" data-view="report">Money report</a>
		<?php endif; ?>
		<?php $waiting = ATC_Tickets::count_waiting(); ?>
		<a href="<?php echo esc_url( home_url( '/control/tickets/' ) ); ?>" data-view="tickets">Tickets<?php echo $waiting ? ' <i class="count">' . (int) $waiting . '</i>' : ''; ?></a>
		<?php $quotes = ATC_Quotes::count_open(); ?>
		<a href="<?php echo esc_url( home_url( '/control/quotes/' ) ); ?>" data-view="quotes">Quote requests<?php echo $quotes ? ' <i class="count">' . (int) $quotes . '</i>' : ''; ?></a>
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
