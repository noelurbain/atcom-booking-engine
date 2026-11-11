<?php
/**
 * Traveler pages inside the site's theme: the search box (atc_engine_search_box()), the results
 * page at /search (step 1, choose your vehicle), /checkout (step 2, contact, flight and trip details),
 * /checkout/pay (step 3, Stripe payment) and /reservation/{number} (the confirmation).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Book {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_book', 'atc_number' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 2 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^search/?$', 'index.php?atc_book=search', 'top' );
		add_rewrite_rule( '^checkout/?$', 'index.php?atc_book=checkout', 'top' );
		add_rewrite_rule( '^checkout/pay/?$', 'index.php?atc_book=pay', 'top' );
		add_rewrite_rule( '^manage-booking/?$', 'index.php?atc_book=manage', 'top' );
		add_rewrite_rule( '^reservation/([A-Za-z0-9]+)/?$', 'index.php?atc_book=reservation&atc_number=$matches[1]', 'top' );
	}

	public static function money( int $cents ): string {
		return 'USD ' . number_format( $cents / 100, $cents % 100 ? 2 : 0 );
	}

	private static function assets(): void {
		wp_enqueue_style( 'atc-book', ATC_URL . 'assets/book.css', array(), ATC_VERSION );
		wp_enqueue_script( 'atc-book', ATC_URL . 'assets/book.js', array(), ATC_VERSION, true );
		wp_localize_script(
			'atc-book',
			'ATCBook',
			array(
				'airports' => esc_url_raw( rest_url( 'atc/v1/airports/search' ) ),
				'mapsKey'  => ATC_Settings::maps_browser_key(),
				'pages'    => esc_url_raw( rest_url( 'wp/v2/pages' ) ),
				'api'      => esc_url_raw( rest_url( 'atc/v1/' ) ),
			)
		);
	}

	/**
	 * The search box, for the theme's booking bars: echo atc_engine_search_box( array( 'airport' => 'CUN' ) ).
	 * Without JavaScript it still works: the typed drop-off is looked up on the results page.
	 */
	public static function search_box( array $args = array() ): string {
		self::assets();
		$q        = wp_parse_args( $args, array( 'dir' => 'from_airport', 'airport' => '', 'to' => '', 'date' => '', 'time' => '', 'trip' => 'one_way', 'return_date' => '', 'adults' => 2, 'children' => 0, 'lat' => '', 'lng' => '', 'pid' => '', 'compact' => false ) );
		$airport  = $q['airport'] ? ATC_Search::find_airport( $q['airport'] ) : null;
		$alabel   = $airport ? ATC_Catalog::airport_label( $airport ) : '';
		$acode    = $airport ? ( $airport['iata'] ?: $airport['id'] ) : '';
		$rev      = 'to_airport' === $q['dir'];
		$round    = ! $rev && 'round_trip' === $q['trip'];
		$pax      = (int) $q['adults'] + (int) $q['children'];
		$uid      = 'ab' . wp_rand( 1000, 9999 );
		$center   = $airport && null !== $airport['lat'] ? $airport['lat'] . ',' . $airport['lng'] : '';
		$country  = $airport ? strtolower( (string) $airport['country'] ) : '';
		ob_start();
		?>
		<form class="ab-search<?php echo $q['compact'] ? ' is-compact' : ''; ?>" action="<?php echo esc_url( home_url( '/search/' ) ); ?>" method="get" role="search" aria-label="Search transfers" data-ab-search data-center="<?php echo esc_attr( $center ); ?>" data-country="<?php echo esc_attr( $country ); ?>">
			<input type="hidden" name="trip" value="<?php echo esc_attr( $round ? 'round_trip' : 'one_way' ); ?>">
			<input type="hidden" name="dir" value="<?php echo $rev ? 'to_airport' : ''; ?>">
			<div class="ab-pills" role="group" aria-label="Trip type">
				<button type="button" data-trip="one_way" aria-pressed="<?php echo $round ? 'false' : 'true'; ?>">One way</button>
				<button type="button" data-trip="round_trip" aria-pressed="<?php echo $round ? 'true' : 'false'; ?>">Round trip</button>
			</div>
			<div class="ab-row">
				<?php ob_start(); ?>
				<div class="ab-field ab-from ab-ac" data-ab-airportfield>
					<label for="<?php echo esc_attr( $uid ); ?>-from" data-ab-airportlabel><?php echo $rev ? 'To' : 'From'; ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-from" type="text" data-ab-airport value="<?php echo esc_attr( $alabel ); ?>" placeholder="Airport or code" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-from-list" required>
					<input type="hidden" name="airport" value="<?php echo esc_attr( (string) $acode ); ?>">
					<ul id="<?php echo esc_attr( $uid ); ?>-from-list" class="ab-list" role="listbox" hidden></ul>
				</div>
				<?php $airport_html = ob_get_clean(); ob_start(); ?>
				<div class="ab-field ab-to ab-ac" data-ab-placefield>
					<label for="<?php echo esc_attr( $uid ); ?>-to" data-ab-placelabel><?php echo $rev ? 'From' : 'To'; ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-to" type="text" name="to" data-ab-to value="<?php echo esc_attr( $q['to'] ); ?>" placeholder="Hotel name or address" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-to-list" required>
					<input type="hidden" name="lat" value="<?php echo esc_attr( (string) $q['lat'] ); ?>"><input type="hidden" name="lng" value="<?php echo esc_attr( (string) $q['lng'] ); ?>"><input type="hidden" name="pid" value="<?php echo esc_attr( (string) $q['pid'] ); ?>">
					<ul id="<?php echo esc_attr( $uid ); ?>-to-list" class="ab-list" role="listbox" hidden></ul>
				</div>
				<?php
				$place_html = ob_get_clean();
				$swap       = '<button type="button" class="ab-swap" data-ab-swap aria-label="Swap: ' . ( $rev ? 'from the airport to a hotel' : 'from a hotel to the airport' ) . '" title="Swap direction"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"></path></svg></button>';
				echo $rev ? $place_html . $swap . $airport_html : $airport_html . $swap . $place_html; // phpcs:ignore -- escaped above.
				?>
				<div class="ab-field ab-date">
					<label for="<?php echo esc_attr( $uid ); ?>-date" data-ab-datelabel><?php echo $rev ? 'Pickup date' : 'Landing date'; ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-date" type="date" name="date" value="<?php echo esc_attr( $q['date'] ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
				</div>
				<div class="ab-field ab-time">
					<label for="<?php echo esc_attr( $uid ); ?>-time" data-ab-timelabel><?php echo $rev ? 'Pickup time' : 'Landing time'; ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-time" type="time" name="time" value="<?php echo esc_attr( $q['time'] ); ?>">
				</div>
				<div class="ab-field ab-pax">
					<span class="ab-label" id="<?php echo esc_attr( $uid ); ?>-paxl">Passengers</span>
					<button type="button" class="ab-paxbtn" aria-expanded="false" aria-labelledby="<?php echo esc_attr( $uid ); ?>-paxl <?php echo esc_attr( $uid ); ?>-paxv" data-ab-paxbtn><span id="<?php echo esc_attr( $uid ); ?>-paxv"><?php echo esc_html( $pax . ( 1 === $pax ? ' passenger' : ' passengers' ) ); ?></span><span aria-hidden="true">▾</span></button>
					<input type="hidden" name="adults" value="<?php echo (int) $q['adults']; ?>"><input type="hidden" name="children" value="<?php echo (int) $q['children']; ?>">
					<div class="ab-paxpop" role="dialog" aria-label="Passengers" hidden>
						<div class="ab-paxrow"><span><strong>Adults</strong><small>12 years and over</small></span><span class="ab-step"><button type="button" data-ab-step="adults:-1" aria-label="Fewer adults">−</button><output data-ab-out="adults"><?php echo (int) $q['adults']; ?></output><button type="button" data-ab-step="adults:1" aria-label="More adults">+</button></span></div>
						<div class="ab-paxrow"><span><strong>Children</strong><small>Under 12 · same price, it’s per vehicle</small></span><span class="ab-step"><button type="button" data-ab-step="children:-1" aria-label="Fewer children">−</button><output data-ab-out="children"><?php echo (int) $q['children']; ?></output><button type="button" data-ab-step="children:1" aria-label="More children">+</button></span></div>
						<p>Need a child seat? You can add one in the next step.</p>
						<button type="button" class="ab-btn is-dark" data-ab-paxdone>Done</button>
					</div>
				</div>
				<button type="submit" class="ab-btn is-accent ab-go">Search</button>
			</div>
			<div class="ab-row ab-return" <?php echo $round ? '' : 'hidden'; ?>>
				<div class="ab-field ab-date">
					<label for="<?php echo esc_attr( $uid ); ?>-ret">Return date (hotel pickup)</label>
					<input id="<?php echo esc_attr( $uid ); ?>-ret" type="date" name="return_date" value="<?php echo esc_attr( $q['return_date'] ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" <?php disabled( ! $round ); ?>>
				</div>
				<p class="ab-hint">You’ll add the departure flight in the next step; we suggest the pickup time for you.</p>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function maybe_render(): void {
		$view = get_query_var( 'atc_book' );
		if ( ! $view ) {
			return;
		}
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		nocache_headers();
		// phpcs:disable WordPress.Security.NonceVerification -- public pages; the booking key is the access check.
		$key = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		if ( in_array( $view, array( 'pay', 'reservation' ), true ) || ( 'checkout' === $view && isset( $_GET['b'] ) ) ) {
			$number = 'reservation' === $view ? (string) get_query_var( 'atc_number' ) : sanitize_text_field( wp_unslash( $_GET['b'] ?? '' ) );
			$b      = ATC_Checkout::load( $number, $key );
			if ( $b && 'reservation' === $view && ! empty( $_GET['ics'] ) && 'confirmed' === $b['status'] ) {
				self::ics( $b );
			}
			self::page( $view, $b, $key );
		}
		// phpcs:enable
		if ( 'manage' === $view ) {
			self::assets();
			add_filter( 'pre_get_document_title', static fn() => 'Manage booking · Airport Transportation' );
			add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'ab-page' ) ) );
			get_header();
			echo self::manage_find(); // phpcs:ignore -- escaped inside.
			get_footer();
			exit;
		}
		self::assets();
		$q   = ATC_Search::parse( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- a public search.
		$res = ATC_Search::run( $q );
		if ( 'search' === $view ) {
			$reason = $res['reason'] ?? '';
			if ( 'no_airport' === $reason && '' !== $q['airport'] ) {
				ATC_Search::log_miss( 'airport', $q['airport'] );
			} elseif ( 'coverage' === $reason ) {
				ATC_Search::log_miss( 'address', (string) $res['destination']['label'], (int) $res['airport']['id'], (float) $res['destination']['lat'], (float) $res['destination']['lng'] );
			} elseif ( 'none' === $reason ) {
				ATC_Search::log_miss( 'unpriced', (string) $res['destination']['zone'], (int) $res['airport']['id'] );
			}
		}
		$title = 'checkout' === $view ? 'Flight and details' : 'Choose your vehicle';
		add_filter( 'pre_get_document_title', static fn() => $title . ' · Airport Transportation' );
		add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'ab-page' ) ) );
		get_header();
		echo 'checkout' === $view ? self::checkout( $res, null, '' ) : self::results( $res ); // phpcs:ignore -- escaped inside.
		get_footer();
		exit;
	}

	/** Pages opened with a booking number and key: step 2 again, payment, confirmation. */
	private static function page( string $view, ?array $b, string $key ): void {
		self::assets();
		add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'ab-page' ) ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		if ( ! $b ) {
			add_filter( 'pre_get_document_title', static fn() => 'Booking not found · Airport Transportation' );
			get_header();
			echo '<section class="ab-wrap ab-main"><div class="ab-col"><div class="ab-nothing"><h1 class="ab-h3">We could not open that booking</h1><p>Use the link in your email, or search again to make a new booking.</p><div class="ab-actions"><a class="ab-btn is-accent" href="' . esc_url( home_url( '/' ) ) . '">Search transfers</a></div></div></div></section>';
			get_footer();
			exit;
		}
		$paid = 'held' !== $b['status'];
		if ( $paid && 'reservation' !== $view ) {
			wp_safe_redirect( ATC_Checkout::reservation_url( $b['number'], $key ) );
			exit;
		}
		if ( 'reservation' === $view && ! $paid ) {
			$status = ATC_Checkout::sync_payment( $b );
			if ( 'confirmed' === $status ) {
				$b = ATC_Checkout::load( $b['number'], $key );
			} elseif ( 'processing' !== $status ) {
				$back = ATC_Checkout::pay_url( $b['number'], $key );
				wp_safe_redirect( isset( $_GET['redirect_status'] ) ? add_query_arg( 'failed', 1, $back ) : $back ); // phpcs:ignore
				exit;
			}
		}
		$titles = array( 'checkout' => 'Flight and details', 'pay' => 'Payment', 'reservation' => 'Your booking ' . $b['number'] );
		add_filter( 'pre_get_document_title', static fn() => $titles[ $view ] . ' · Airport Transportation' );
		if ( 'checkout' === $view ) {
			$q   = ATC_Search::parse( (array) ( $b['snap']['search'] ?? array() ) );
			$res = ATC_Search::run( $q );
			get_header();
			echo self::checkout( $res, $b, $key ); // phpcs:ignore -- escaped inside.
		} elseif ( 'pay' === $view ) {
			$html = self::pay( $b, $key );
			get_header();
			echo $html; // phpcs:ignore -- escaped inside.
		} else {
			get_header();
			echo self::reservation( $b, $key ); // phpcs:ignore -- escaped inside.
		}
		get_footer();
		exit;
	}

	private static function summary_bar( array $res ): string {
		$q   = $res['query'];
		$pax = (int) $q['adults'] + (int) $q['children'];
		$when = $q['date'] ? wp_date( 'D j M Y', strtotime( $q['date'] . ' 12:00 UTC' ), new DateTimeZone( 'UTC' ) ) . ( $q['time'] ? ', ' . $q['time'] : '' ) : 'Pick a date';
		ob_start();
		?>
		<section class="ab-bar"><div class="ab-wrap">
			<div class="ab-sum" data-ab-sum>
				<?php
				$air_txt   = $res['airport'] ? ( $res['airport']['iata'] ? $res['airport']['iata'] . ' · ' . $res['airport']['city'] : $res['airport']['name'] ) : '–';
				$place_txt = $res['destination']['label'] ?? ( $q['to'] ?: '–' );
				$rev       = 'to_airport' === ( $q['dir'] ?? '' );
				?>
				<span><small>From</small><strong><?php echo esc_html( $rev ? $place_txt : $air_txt ); ?></strong></span>
				<span><small>To</small><strong><?php echo esc_html( $rev ? $air_txt : $place_txt ); ?></strong></span>
				<span><small><?php echo $rev ? 'Pickup' : 'Landing'; ?></small><strong><?php echo esc_html( $when ); ?></strong></span>
				<span><small>Passengers</small><strong><?php echo esc_html( $pax . ( $q['children'] ? ' (' . $q['children'] . ( 1 === (int) $q['children'] ? ' child)' : ' children)' ) : '' ) ); ?></strong></span>
				<button type="button" class="ab-btn is-line" data-ab-edit aria-expanded="false">Edit search</button>
			</div>
			<div class="ab-editbox" hidden data-ab-editbox>
				<?php
				echo self::search_box( // phpcs:ignore -- escaped inside.
					array(
						'airport'     => $res['airport']['iata'] ?? $q['airport'],
						'to'          => $res['destination']['label'] ?? $q['to'],
						'lat'         => $res['destination']['lat'] ?? '',
						'lng'         => $res['destination']['lng'] ?? '',
						'pid'         => $res['destination']['pid'] ?? '',
						'date'        => $q['date'],
						'time'        => $q['time'],
						'trip'        => $q['trip'],
						'return_date' => $q['return_date'],
						'adults'      => $q['adults'],
						'children'    => $q['children'],
						'compact'     => true,
					)
				);
				?>
			</div>
		</div></section>
		<?php
		return (string) ob_get_clean();
	}

	private static function steps( int $on ): string {
		$out = '<ol class="ab-steps" aria-label="Booking steps">';
		foreach ( array( 1 => 'Vehicle', 2 => 'Flight and details', 3 => 'Payment' ) as $n => $label ) {
			$out .= '<li' . ( $n === $on ? ' class="is-on" aria-current="step"' : ( $n < $on ? ' class="is-done"' : '' ) ) . '>' . ( $n < $on ? '<span aria-hidden="true">✓</span> ' : $n . ' · ' ) . esc_html( $label ) . ( $n < $on ? '<span class="screen-reader-text"> (done)</span>' : '' ) . '</li>';
		}
		return $out . '</ol>';
	}

	private static function trust(): string {
		$tick = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>';
		$free = (int) ATC_Settings::get( 'free_cancel_hours' );
		return '<div class="ab-trust"><span>' . $tick . 'Price per vehicle, all taxes included</span><span>' . $tick . 'Free cancellation up to ' . $free . ' h before pickup</span><span>' . $tick . 'Change your flight in Manage booking</span></div>';
	}

	/** "★ 4.8 (23 reviews)", accessible. */
	private static function stars( float $rating, int $count ): string {
		$full = (int) round( $rating );
		return '<span class="ab-stars" role="img" aria-label="' . esc_attr( number_format( $rating, 1 ) . ' out of 5 stars from ' . $count . ' reviews' ) . '"><span aria-hidden="true">' . str_repeat( '★', $full ) . '<span class="ab-off">' . str_repeat( '★', 5 - $full ) . '</span></span> <strong>' . esc_html( number_format( $rating, 1 ) ) . '</strong> <span class="ab-muted">(' . (int) $count . ')</span></span>';
	}

	private static function vehicle_icon(): string {
		return '<svg width="88" height="48" viewBox="0 0 88 48" aria-hidden="true"><path d="M8 32 L14 16 Q16 11 22 11 H60 Q66 11 70 16 L80 28 Q82 31 82 34 V38 H8 Z" fill="#E9E4DA"></path><path d="M20 15 H40 V26 H15 Z M44 15 H60 Q63 15 66 19 L71 26 H44 Z" fill="#FFFFFF"></path><circle cx="24" cy="38" r="7" fill="#15171C"></circle><circle cx="66" cy="38" r="7" fill="#15171C"></circle><circle cx="24" cy="38" r="2.5" fill="#E9E4DA"></circle><circle cx="66" cy="38" r="2.5" fill="#E9E4DA"></circle></svg>';
	}

	/** Step 1: choose your vehicle. */
	private static function results( array $res ): string {
		$q     = $res['query'];
		$round = 'round_trip' === $q['trip'];
		$free  = (int) ATC_Settings::get( 'free_cancel_hours' );
		ob_start();
		echo self::summary_bar( $res ); // phpcs:ignore
		?>
		<section class="ab-wrap ab-main">
			<div class="ab-col">
				<?php echo self::steps( 1 ); // phpcs:ignore ?>
				<div class="ab-titlerow">
					<h1 class="ab-h1">Choose your vehicle</h1>
					<?php if ( $res['airport'] && $res['destination'] ) : ?>
						<div class="ab-tools">
						<?php if ( ! empty( $res['rated'] ) && count( $res['offers'] ) > 1 ) : $by_rating = 'rating' === $q['sort']; ?>
							<div class="ab-pills" role="group" aria-label="Sort by">
								<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'sort' => 'price' ) ) ) ) ); ?>" aria-current="<?php echo $by_rating ? 'false' : 'true'; ?>">Lowest price</a>
								<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'sort' => 'rating' ) ) ) ) ); ?>" aria-current="<?php echo $by_rating ? 'true' : 'false'; ?>">Best rated</a>
							</div>
						<?php endif; ?>
						<?php if ( 'to_airport' !== $q['dir'] ) : ?><div class="ab-pills" role="group" aria-label="Trip type">
							<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'trip' => 'one_way', 'return_date' => '' ) ) ) ) ); ?>" aria-current="<?php echo $round ? 'false' : 'true'; ?>">One way</a>
							<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'trip' => 'round_trip' ) ) ) ) ); ?>" aria-current="<?php echo $round ? 'true' : 'false'; ?>">Round trip</a>
						</div><?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php
				$types = array();
				foreach ( $res['offers'] as $o ) {
					$types[ $o['vehicle_type_id'] ] = $o['name'];
				}
				if ( count( $types ) > 1 ) :
					?>
					<div class="ab-chips" role="group" aria-label="Vehicle type">
						<button type="button" aria-pressed="true" data-ab-vt="">All (<?php echo count( $res['offers'] ); ?>)</button>
						<?php foreach ( $types as $vt => $name ) : ?>
							<button type="button" aria-pressed="false" data-ab-vt="<?php echo (int) $vt; ?>"><?php echo esc_html( $name ); ?></button>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
				if ( $res['offers'] ) :
					foreach ( $res['offers'] as $i => $o ) :
						$next = home_url( '/checkout/?' . ATC_Search::query( $q, array( 'service' => $o['service_id'] ) ) );
						?>
						<article class="ab-offer" data-ab-offer data-name="<?php echo esc_attr( $o['name'] ); ?>" data-provider="<?php echo esc_attr( $o['provider'] ); ?>" data-vt="<?php echo (int) $o['vehicle_type_id']; ?>" data-price="<?php echo esc_attr( self::money( $o['retail'] ) ); ?>" data-href="<?php echo esc_url( $next ); ?>">
							<div class="ab-pic"><?php $img = ATC_Catalog::vehicle_image( $o['slug'] ); echo $img ? '<img src="' . esc_url( $img ) . '" alt="" width="320" height="200" loading="lazy">' : self::vehicle_icon(); // phpcs:ignore ?></div>
							<div class="ab-info">
								<h2><?php echo esc_html( $o['name'] ); ?></h2>
								<span class="ab-muted">Up to <?php echo (int) $o['max_passengers']; ?> passengers · <?php echo (int) $o['max_bags']; ?> bags</span>
								<span>Private · Flight monitored · <?php echo (int) $o['free_wait']; ?> min free waiting · Free cancellation up to <?php echo (int) $free; ?> h</span>
								<span class="ab-by">by <?php echo esc_html( $o['provider'] ); ?><?php echo null !== $o['rating'] ? ' · ' . self::stars( $o['rating'], $o['reviews'] ) : ''; // phpcs:ignore -- escaped in stars(). ?></span>
							</div>
							<div class="ab-price">
								<small><?php echo $round ? 'Round trip, total' : 'One way, total'; ?></small>
								<strong><?php echo esc_html( self::money( $o['retail'] ) ); ?></strong>
								<a class="ab-btn <?php echo 0 === $i ? 'is-accent' : 'is-dark'; ?>" href="<?php echo esc_url( $next ); ?>" data-ab-select>Select</a>
							</div>
						</article>
						<?php
					endforeach;
				else :
					echo self::nothing( $res ); // phpcs:ignore
				endif;
				?>
			</div>
			<aside class="ab-side">
				<?php if ( $res['destination'] && $res['airport'] ) : ?>
					<section class="ab-card ab-mapcard" aria-label="Trip map">
						<div class="ab-cardhead"><h2>Your route</h2><span class="ab-muted" data-ab-dist></span></div>
						<?php
						$rev    = 'to_airport' === $q['dir'];
						$a_ll   = $res['airport']['lat'] . ',' . $res['airport']['lng'];
						$p_ll   = $res['destination']['lat'] . ',' . $res['destination']['lng'];
						$a_line = '<span><i class="ab-dot' . ( $rev ? ' is-to' : '' ) . '"></i>' . esc_html( $res['airport']['name'] ) . '</span>';
						$p_line = '<span><i class="ab-dot' . ( $rev ? '' : ' is-to' ) . '"></i><span>' . esc_html( $res['destination']['label'] ) . ( $res['destination']['zone'] ? '<small class="ab-muted">Zone: ' . esc_html( $res['destination']['zone'] ) . '</small>' : '' ) . '</span></span>';
						?>
						<div class="ab-map" data-ab-map data-airport="<?php echo $rev ? 'to' : 'from'; ?>" data-from="<?php echo esc_attr( $rev ? $p_ll : $a_ll ); ?>" data-to="<?php echo esc_attr( $rev ? $a_ll : $p_ll ); ?>" data-from-label="<?php echo esc_attr( $rev ? $res['destination']['label'] : $res['airport']['label'] ); ?>" data-to-label="<?php echo esc_attr( $rev ? $res['airport']['label'] : $res['destination']['label'] ); ?>"></div>
						<div class="ab-route"><?php echo $rev ? $p_line . $a_line : $a_line . $p_line; // phpcs:ignore -- escaped above. ?></div>
					</section>
				<?php endif; ?>
				<?php echo self::trust(); // phpcs:ignore ?>
			</aside>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** When nothing can be booked: say why and what to do. */
	private static function nothing( array $res ): string {
		$q   = $res['query'];
		$msg = array(
			'no_airport'     => array( 'Pick your arrival airport', 'We could not find that airport. Choose it from the list in the search box.' ),
			'no_destination' => array( 'Where are you going?', 'Type your hotel or address and pick it from the list, so we know where to take you.' ),
			'coverage'       => array( 'We don’t serve that address yet', 'No provider covers ' . ( $res['destination']['label'] ?? 'that address' ) . ' from ' . ( $res['airport']['label'] ?? 'this airport' ) . ' yet. The nearest places we do serve are below, or try another address.' ),
			'none'           => array( 'No vehicles for this trip yet', 'Providers at ' . ( $res['airport']['label'] ?? 'this airport' ) . ' have not priced ' . ( $res['destination']['zone'] ?? 'this area' ) . ( 'round_trip' === $q['trip'] ? ' as a round trip' : '' ) . ' yet.' . ( 'round_trip' === $q['trip'] ? ' Try one way.' : '' ) ),
			'notice'         => array( 'Your pickup is too soon to book online', 'Providers here need at least ' . ( $res['hours'] ?? 24 ) . ' hours’ notice, so the earliest pickup we can book now is ' . ( $res['earliest'] ?? '' ) . '. Pick a later time or another day.' ),
			'party'          => array( 'No single vehicle fits ' . ( (int) $q['adults'] + (int) $q['children'] ) . ' passengers', 'Our largest vehicle here carries ' . ( $res['largest'] ?? 0 ) . '. Book two vehicles, one booking each, or write to us and we will arrange it.' ),
		)[ $res['reason'] ?? 'none' ] ?? array( 'Nothing available', '' );
		ob_start();
		?>
		<div class="ab-nothing" role="status">
			<h2><?php echo esc_html( $msg[0] ); ?></h2>
			<p><?php echo esc_html( $msg[1] ); ?></p>
			<div class="ab-actions"><button type="button" class="ab-btn is-accent" data-ab-edit>Change search</button><a class="ab-btn is-line" href="<?php echo esc_url( home_url( '/destinations/' ) ); ?>">See where we go</a></div>
		</div>
		<?php if ( ! empty( $res['nearest'] ) ) : ?>
			<h2 class="ab-h3">Nearest places we serve</h2>
			<?php foreach ( $res['nearest'] as $n ) : ?>
				<div class="ab-near">
					<span><strong><?php echo esc_html( $n['name'] ); ?></strong><?php echo null !== $n['km'] ? '<small class="ab-muted">About ' . (int) $n['km'] . ' km from your address</small>' : ''; ?></span>
					<span class="ab-near-r"><strong>From <?php echo esc_html( self::money( $n['from'] ) ); ?></strong>
					<?php if ( null !== $n['lat'] ) : ?><a class="ab-btn is-dark" href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'to' => $n['name'], 'lat' => $n['lat'], 'lng' => $n['lng'], 'pid' => '' ) ) ) ) ); ?>">Select</a><?php endif; ?></span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/** Every country with its calling code; the usual ones first. Value "+1|US" keeps the country. */
	private static function phone_options( string $selected ): string {
		$all  = require ATC_DIR . 'includes/phone-codes.php';
		$top  = array( 'US', 'CA', 'MX', 'GB', 'ES', 'FR', 'DE', 'IT', 'NL', 'BR', 'AR', 'CO' );
		$opt  = static fn( $iso, $sel ) => '<option value="' . esc_attr( $all[ $iso ][1] . '|' . $iso ) . '"' . ( $all[ $iso ][1] . '|' . $iso === $sel ? ' selected' : '' ) . '>' . esc_html( $all[ $iso ][0] . ' ' . $all[ $iso ][1] ) . '</option>';
		$out  = '<optgroup label="Most used">';
		foreach ( $top as $iso ) {
			$out .= $opt( $iso, $selected );
		}
		$out .= '</optgroup><optgroup label="All countries">';
		foreach ( array_keys( $all ) as $iso ) {
			$out .= $opt( $iso, in_array( $iso, $top, true ) ? '' : $selected );
		}
		return $out . '</optgroup>';
	}

	/** A UTC datetime shown in the airport's time zone. */
	private static function local( ?string $utc, string $tz, string $format = 'D j M Y, H:i' ): string {
		if ( ! $utc ) {
			return '';
		}
		try {
			return wp_date( $format, strtotime( $utc . ' UTC' ), new DateTimeZone( $tz ?: 'UTC' ) );
		} catch ( Exception $e ) {
			return $utc;
		}
	}

	private static function day( string $date ): string {
		return $date ? wp_date( 'D j M Y', strtotime( $date . ' 12:00 UTC' ), new DateTimeZone( 'UTC' ) ) : '';
	}

	private static function pax_text( int $adults, int $children ): string {
		$t = $adults . ( 1 === $adults ? ' adult' : ' adults' );
		return $children ? $t . ', ' . $children . ( 1 === $children ? ' child' : ' children' ) : $t;
	}

	/** The vehicle card used on the side of steps 2 and 3. */
	private static function transfer_card( array $o, array $q, array $air, string $to, int $total, string $cta = '', array $legs = array(), string $tz = 'UTC' ): string {
		$round = 'round_trip' === $q['trip'];
		$pax   = (int) $q['adults'] + (int) $q['children'];
		$img   = ATC_Catalog::vehicle_image( (string) ( $o['slug'] ?? '' ) );
		ob_start();
		?>
		<section class="ab-card ab-pad ab-sumcard">
			<h2><?php echo $round ? 'Your round trip' : 'Your transfer'; ?></h2>
			<div class="ab-veh">
				<?php echo $img ? '<img src="' . esc_url( $img ) . '" alt="" width="120" height="75">' : ''; ?>
				<span><strong><?php echo esc_html( $o['name'] ); ?> · private</strong><small class="ab-muted">Up to <?php echo (int) ( $o['max_passengers'] ?? 0 ); ?> passengers · <?php echo (int) ( $o['max_bags'] ?? 0 ); ?> bags</small><small class="ab-muted">by <?php echo esc_html( $o['provider'] ); ?></small></span>
			</div>
			<div class="ab-lines">
				<?php if ( $round ) : ?>
					<span><small class="ab-k">Arrival</small><strong><?php echo esc_html( $air['iata'] ?: $air['name'] ); ?></strong> → <?php echo esc_html( $to ); ?></span>
					<span class="ab-muted"><?php echo esc_html( self::day( $q['date'] ) ); ?> · on landing</span>
					<span><small class="ab-k">Return</small><?php echo esc_html( $to ); ?> → <strong><?php echo esc_html( $air['iata'] ?: $air['name'] ); ?></strong></span>
					<span class="ab-muted"><?php echo esc_html( self::day( $q['return_date'] ) ); ?><?php echo ! empty( $legs[1]['pickup_at'] ) ? ' · pickup ' . esc_html( self::local( $legs[1]['pickup_at'], $tz, 'H:i' ) ) : ''; ?></span>
				<?php elseif ( 'to_airport' === ( $q['dir'] ?? '' ) ) : ?>
					<span><?php echo esc_html( $to ); ?> → <strong><?php echo esc_html( $air['iata'] ?: $air['name'] ); ?></strong></span>
					<span class="ab-muted"><?php echo esc_html( self::day( $q['date'] ) ); ?><?php echo ! empty( $legs[0]['pickup_at'] ) ? ' · pickup ' . esc_html( self::local( $legs[0]['pickup_at'], $tz, 'H:i' ) ) : ''; ?> · <?php echo (int) $pax; ?> passengers</span>
				<?php else : ?>
					<span><strong><?php echo esc_html( $air['iata'] ?: $air['name'] ); ?></strong> → <?php echo esc_html( $to ); ?></span>
					<span class="ab-muted"><?php echo esc_html( self::day( $q['date'] ) ); ?> · <?php echo (int) $pax; ?> passengers</span>
				<?php endif; ?>
			</div>
			<div class="ab-total is-grand"><span>Total<small class="ab-muted"><?php echo esc_html( $o['name'] . ( $round ? ', round trip' : ', one way' ) ); ?> · taxes included</small></span><strong><?php echo esc_html( self::money( $total ) ); ?></strong></div>
			<?php echo $cta; // phpcs:ignore -- built by the caller. ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Step 2: contact, flight and trip details. $b is set when the booking was already started. */
	private static function checkout( array $res, ?array $b, string $key ): string {
		$q     = $res['query'];
		$sid   = $b ? (int) ( $b['legs'][0]['service_id'] ?? 0 ) : ( isset( $_GET['service'] ) ? (int) $_GET['service'] : 0 ); // phpcs:ignore
		$offer = null;
		foreach ( $res['offers'] as $o ) {
			if ( $o['service_id'] === $sid ) {
				$offer = $o;
			}
		}
		if ( ! $offer && $b ) {
			$offer = array( 'service_id' => $sid, 'name' => $b['snap']['vehicle'] ?? '', 'provider' => $b['snap']['provider'] ?? '', 'slug' => $b['snap']['slug'] ?? '', 'retail' => (int) $b['total_cents'] );
		}
		$back = home_url( '/search/?' . ATC_Search::query( $q ) );
		ob_start();
		echo self::summary_bar( $res ); // phpcs:ignore
		if ( ! $offer || ! $res['airport'] ) {
			?>
			<section class="ab-wrap ab-main"><div class="ab-col"><?php echo self::steps( 2 ); // phpcs:ignore ?>
				<div class="ab-nothing"><h1 class="ab-h3">That vehicle is no longer available</h1><p>Prices or availability changed. Choose again.</p><div class="ab-actions"><a class="ab-btn is-accent" href="<?php echo esc_url( $back ); ?>">See vehicles</a></div></div>
			</div></section>
			<?php
			return (string) ob_get_clean();
		}
		$round  = 'round_trip' === $q['trip'];
		$rev    = 'to_airport' === ( $q['dir'] ?? '' );
		$air    = $res['airport'];
		$to     = (string) ( $res['destination']['label'] ?? $q['to'] );
		$d      = $b['snap']['details'] ?? array();
		$v      = static fn( $k, $def = '' ) => (string) ( $d[ $k ] ?? $def );
		$phone  = $b ? explode( ' ', (string) $b['phone'], 2 ) : array();
		$pcode  = ( $b['snap']['phone_country'] ?? '' ) ? $phone[0] . '|' . $b['snap']['phone_country'] : '+1|US';
		$pnum   = isset( $phone[1] ) ? $phone[1] : ( $phone[0] ?? '' );
				$total  = $b ? (int) $b['total_cents'] : (int) $offer['retail'];
		$cfg    = array(
			'start'   => esc_url_raw( rest_url( 'atc/v1/checkout/start' ) ),
			'details' => esc_url_raw( rest_url( 'atc/v1/checkout/details' ) ),
			'search'  => $q,
			'service' => $sid,
			'number'  => $b ? $b['number'] : '',
			'key'     => $b ? $key : '',
		);
		$step   = static fn( $name, $label, $hint, $val ) => '<div class="ab-paxrow"><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $hint ) . '</small></span><span class="ab-step"><button type="button" data-ab-step="' . esc_attr( $name ) . ':-1" aria-label="Fewer ' . esc_attr( strtolower( $label ) ) . '">−</button><output data-ab-out="' . esc_attr( $name ) . '">' . (int) $val . '</output><button type="button" data-ab-step="' . esc_attr( $name ) . ':1" aria-label="More ' . esc_attr( strtolower( $label ) ) . '">+</button></span><input type="hidden" name="' . esc_attr( $name ) . '" value="' . (int) $val . '"></div>';
		?>
		<section class="ab-wrap ab-main">
			<div class="ab-col">
				<?php echo self::steps( 2 ); // phpcs:ignore ?>
				<h1 class="ab-h1">Flight and details</h1>
				<noscript><div class="ab-nothing"><p>Please turn on JavaScript to finish your booking: our payment form needs it.</p></div></noscript>
				<form class="ab-form" id="ab-details" data-ab-checkout="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>" novalidate>
					<div class="ab-alert" role="alert" hidden data-ab-error></div>

					<section class="ab-card ab-pad" aria-labelledby="ab-h-contact">
						<h2 id="ab-h-contact">Your contact details</h2>
						<p class="ab-muted">Your confirmation goes to this email, and your driver uses this number to reach you on the day of your transfer.</p>
						<div class="ab-grid is-two">
							<div class="ab-field"><label for="ab-first">First name</label><input id="ab-first" name="first_name" autocomplete="given-name" required value="<?php echo esc_attr( $b['first_name'] ?? '' ); ?>"></div>
							<div class="ab-field"><label for="ab-last">Last name</label><input id="ab-last" name="last_name" autocomplete="family-name" required value="<?php echo esc_attr( $b['last_name'] ?? '' ); ?>"></div>
							<div class="ab-field"><label for="ab-email">Email</label><input id="ab-email" name="email" type="email" autocomplete="email" required value="<?php echo esc_attr( $b['email'] ?? '' ); ?>"></div>
							<div class="ab-field"><label for="ab-phone">Mobile number</label>
								<div class="ab-tel">
									<select name="phone_code" aria-label="Country code" autocomplete="tel-country-code">
										<?php echo self::phone_options( $pcode ); // phpcs:ignore -- escaped inside. ?>
									</select>
									<input id="ab-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" required value="<?php echo esc_attr( $pnum ); ?>">
								</div>
							</div>
						</div>
						<label class="ab-check"><input type="checkbox" name="whatsapp" value="1" <?php checked( $b ? ! empty( $b['snap']['whatsapp'] ) : true ); ?>> This number has WhatsApp</label>
					</section>

					<?php if ( $rev ) : ?>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-pickup">
						<h2 id="ab-h-pickup">Your pickup</h2>
						<p class="ab-muted">The address the price is based on. To be picked up somewhere else, change your search: another area may have another price.</p>
						<div class="ab-place"><span><?php echo esc_html( $to ); ?><?php echo ! empty( $res['destination']['zone'] ) ? '<small class="ab-muted">Zone: ' . esc_html( $res['destination']['zone'] ) . '</small>' : ''; ?></span><button type="button" class="ab-link" data-ab-edit>Change</button></div>
						<div class="ab-field"><label for="ab-hname">Name of the hotel reservation (optional)</label><input id="ab-hname" name="hotel_name" autocomplete="off" value="<?php echo esc_attr( $v( 'hotel_name' ) ); ?>" placeholder="If it is not the lead traveler’s name"></div>
					</section>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-dep" data-ab-return>
						<h2 id="ab-h-dep">Your flight</h2>
						<p class="ab-muted">We use your flight time to suggest when your driver picks you up, so you reach <?php echo esc_html( $air['name'] ); ?> with time to spare.</p>
						<div class="ab-grid">
							<div class="ab-field"><label for="ab-rairline">Airline</label><input id="ab-rairline" name="return_airline" autocomplete="off" value="<?php echo esc_attr( $v( 'return_airline' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rflight">Flight number</label><input id="ab-rflight" name="return_flight" autocomplete="off" value="<?php echo esc_attr( $v( 'return_flight' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rdate">Pickup date</label><input id="ab-rdate" name="return_date" type="date" required value="<?php echo esc_attr( $v( 'return_date', $q['date'] ) ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rtime">Flight departure time</label><input id="ab-rtime" name="return_flight_time" type="time" value="<?php echo esc_attr( $v( 'return_flight_time' ) ); ?>" data-ab-rtime></div>
						</div>
						<label class="ab-check"><input type="checkbox" name="domestic" value="1" data-ab-domestic <?php checked( ! empty( $d['domestic'] ) ); ?>> <span>It’s a domestic flight (within <?php echo esc_html( 'MX' === (string) ( $air['country'] ?? '' ) ? 'Mexico' : 'the country' ); ?>)</span></label>
						<div class="ab-suggest">
							<div class="ab-field"><label for="ab-rpick">Hotel pickup time</label><input id="ab-rpick" name="return_pickup" type="time" required value="<?php echo esc_attr( $v( 'return_pickup', $q['time'] ) ); ?>" data-ab-rpick></div>
							<p class="ab-muted" data-ab-suggest-note>Type your flight time and we suggest a pickup: 3 hours before an international flight, 2 hours before a domestic one, including the drive. You can change it.</p>
						</div>
						<p class="ab-muted">Don’t know your flight yet? Leave it empty and add it later in Manage booking, at least 24 h before pickup.</p>
						<input type="hidden" name="return_from" value="<?php echo esc_attr( $to ); ?>">
					</section>
					<?php else : ?>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-arrival">
						<h2 id="ab-h-arrival">Your arrival</h2>
						<p class="ab-muted">We follow your flight. If it lands early or late, your driver adjusts the pickup.</p>
						<div class="ab-grid" data-ab-flight>
							<div class="ab-field"><label for="ab-airline">Airline</label><input id="ab-airline" name="airline" autocomplete="off" value="<?php echo esc_attr( $v( 'airline' ) ); ?>" placeholder="e.g. American Airlines"></div>
							<div class="ab-field"><label for="ab-flight">Flight number</label><input id="ab-flight" name="flight" autocomplete="off" value="<?php echo esc_attr( $v( 'flight' ) ); ?>" placeholder="e.g. AA 1234" required></div>
						</div>
						<div class="ab-grid">
							<div class="ab-field"><label for="ab-ldate">Landing date</label><input id="ab-ldate" name="landing_date" type="date" required value="<?php echo esc_attr( $v( 'landing_date', $q['date'] ) ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-ltime">Landing time</label><input id="ab-ltime" name="landing_time" type="time" required value="<?php echo esc_attr( $v( 'landing_time', $q['time'] ) ); ?>"></div>
						</div>
						<label class="ab-check"><input type="checkbox" name="no_flight" value="1" data-ab-noflight <?php checked( ! empty( $d['no_flight'] ) ); ?>> I don’t have my flight yet. I’ll add it later in Manage booking.</label>
					</section>

					<section class="ab-card ab-pad" aria-labelledby="ab-h-drop">
						<h2 id="ab-h-drop">Drop-off</h2>
						<p class="ab-muted">The address the price is based on. To go somewhere else, change your search: another area may have another price.</p>
						<div class="ab-place"><span><?php echo esc_html( $to ); ?><?php echo ! empty( $res['destination']['zone'] ) ? '<small class="ab-muted">Zone: ' . esc_html( $res['destination']['zone'] ) . '</small>' : ''; ?></span><button type="button" class="ab-link" data-ab-edit>Change</button></div>
						<div class="ab-field"><label for="ab-hname">Name of the hotel reservation (optional)</label><input id="ab-hname" name="hotel_name" autocomplete="off" value="<?php echo esc_attr( $v( 'hotel_name' ) ); ?>" placeholder="If it is not the lead traveler’s name"></div>
					</section>

					<?php endif; ?>

					<?php if ( $round ) : ?>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-return" data-ab-return>
						<h2 id="ab-h-return">Your return</h2>
						<p class="ab-muted">Pickup at your hotel, drop-off at <?php echo esc_html( $air['name'] ); ?>.</p>
						<div class="ab-field"><label for="ab-rfrom">Hotel pickup address</label><input id="ab-rfrom" name="return_from" autocomplete="off" value="<?php echo esc_attr( $v( 'return_from', $to ) ); ?>"></div>
						<div class="ab-grid">
							<div class="ab-field"><label for="ab-rairline">Departure airline</label><input id="ab-rairline" name="return_airline" autocomplete="off" value="<?php echo esc_attr( $v( 'return_airline' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rflight">Departure flight number</label><input id="ab-rflight" name="return_flight" autocomplete="off" value="<?php echo esc_attr( $v( 'return_flight' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rdate">Departure date</label><input id="ab-rdate" name="return_date" type="date" required value="<?php echo esc_attr( $v( 'return_date', $q['return_date'] ) ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></div>
							<div class="ab-field"><label for="ab-rtime">Flight departure time</label><input id="ab-rtime" name="return_flight_time" type="time" value="<?php echo esc_attr( $v( 'return_flight_time' ) ); ?>" data-ab-rtime></div>
						</div>
						<label class="ab-check"><input type="checkbox" name="domestic" value="1" data-ab-domestic <?php checked( ! empty( $d['domestic'] ) ); ?>> It’s a domestic flight (within <?php echo esc_html( (string) ( $air['country'] ?? '' ) === 'MX' ? 'Mexico' : 'the country' ); ?>)</label>
						<div class="ab-suggest">
							<div class="ab-field"><label for="ab-rpick">Hotel pickup time</label><input id="ab-rpick" name="return_pickup" type="time" required value="<?php echo esc_attr( $v( 'return_pickup' ) ); ?>" data-ab-rpick></div>
							<p class="ab-muted" data-ab-suggest-note>Type your flight time and we suggest a pickup: 3 hours before an international flight, 2 hours before a domestic one, including the drive. You can change it.</p>
						</div>
						<p class="ab-muted">Don’t know your departure flight yet? Leave it empty and add it later in Manage booking, at least 24 h before pickup.</p>
					</section>
					<?php endif; ?>

					<section class="ab-card ab-pad" aria-labelledby="ab-h-bags">
						<h2 id="ab-h-bags">Luggage</h2>
						<p class="ab-muted">Helps the provider send the right vehicle.</p>
						<div class="ab-place"><span><strong><?php echo esc_html( ( (int) $q['adults'] + (int) $q['children'] ) . ' passengers · ' . self::pax_text( (int) $q['adults'], (int) $q['children'] ) ); ?></strong><small class="ab-muted"><?php echo $round ? 'Same for both rides. ' : ''; ?>Set in your search. Changing it takes you back to step 1, because another vehicle may be needed.</small></span><button type="button" class="ab-link" data-ab-edit>Change passengers</button></div>
						<?php echo $step( 'suitcases', 'Suitcases', 'Checked bags · up to ' . (int) ( $offer['max_bags'] ?? 0 ) . ' in a ' . $offer['name'], $v( 'suitcases', (string) min( (int) $q['adults'] + (int) $q['children'], (int) ( $offer['max_bags'] ?? 0 ) ) ) ); // phpcs:ignore ?>
						<?php echo $step( 'carry_ons', 'Carry-ons', 'Small bags and backpacks', $v( 'carry_ons', '0' ) ); // phpcs:ignore ?>
					</section>

					<section class="ab-card ab-pad" aria-labelledby="ab-h-notes">
						<h2 id="ab-h-notes">Notes for your driver <span class="ab-muted">(optional)</span></h2>
						<div class="ab-field"><label for="ab-notes" class="screen-reader-text">Notes for your driver</label><textarea id="ab-notes" name="notes" rows="3" maxlength="1000" placeholder="e.g. we travel with a stroller, or a child seat is needed"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea></div>
					</section>

					<div class="ab-actions ab-formend">
						<a class="ab-btn is-line" href="<?php echo esc_url( $back ); ?>">← Back to vehicles</a>
						<button type="submit" class="ab-btn is-accent" data-ab-submit>Continue to payment →</button>
					</div>
				</form>
			</div>
			<aside class="ab-side">
				<?php echo self::transfer_card( $offer, $q, $air, $to, $total, '<button type="submit" form="ab-details" class="ab-btn is-accent is-block">Continue to payment</button>' ); // phpcs:ignore ?>
				<?php echo self::trust(); // phpcs:ignore ?>
			</aside>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Step 3: review and pay with Stripe. Runs before the header, so it can hold again and redirect. */
	private static function pay( array $b, string $key ): string {
		$q   = ATC_Search::parse( (array) ( $b['snap']['search'] ?? array() ) );
		$err = '';
		$was = (int) $b['total_cents'];
		if ( empty( $b['snap']['details'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'b' => $b['number'], 'k' => $key ), home_url( '/checkout/' ) ) );
			exit;
		}
		if ( ATC_Checkout::hold_left( $b ) < 30 ) {
			$again = ATC_Checkout::rehold( $b, $key );
			if ( is_wp_error( $again ) ) {
				$err = $again->get_error_message();
			} else {
				$b = $again;
			}
		}
		$pi = $err ? null : ATC_Checkout::payment_intent( $b );
		if ( is_wp_error( $pi ) ) {
			$err = $pi->get_error_message();
			$pi  = null;
		}
		$pk    = ATC_Settings::stripe_publishable();
		$round = 'round_trip' === $q['trip'];
		$tz    = (string) ( $b['snap']['timezone'] ?? 'UTC' );
		$d     = (array) $b['snap']['details'];
		$edit  = add_query_arg( array( 'b' => $b['number'], 'k' => $key ), home_url( '/checkout/' ) );
		$air   = array( 'iata' => $b['snap']['iata'] ?? '', 'name' => $b['snap']['airport'] ?? '' );
		$to    = (string) ( $d['dropoff'] ?? $b['snap']['to'] ?? '' );
		$offer = array( 'name' => $b['snap']['vehicle'] ?? '', 'provider' => $b['snap']['provider'] ?? '', 'slug' => $b['snap']['slug'] ?? '' );
		$res   = ATC_Search::run( $q );
		foreach ( $res['offers'] as $o ) {
			if ( $o['service_id'] === (int) $b['legs'][0]['service_id'] ) {
				$offer = $o;
			}
		}
		if ( $pi && $pk ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore -- Stripe asks to load it from js.stripe.com, unversioned.
			wp_localize_script(
				'atc-book',
				'ATCPay',
				array(
					'pk'      => $pk,
					'secret'  => $pi['client_secret'],
					'return'  => ATC_Checkout::reservation_url( $b['number'], $key ),
					'name'    => trim( $b['first_name'] . ' ' . $b['last_name'] ),
					'email'   => $b['email'],
					'phone'   => $b['phone'],
					'left'    => ATC_Checkout::hold_left( $b ),
					'reload'  => ATC_Checkout::pay_url( $b['number'], $key ),
				)
			);
		} elseif ( ! $err ) {
			$err = 'Payments are not set up yet on this site.';
		}
		$arr = ATC_Checkout::leg( $b, 'arrival' );
		$ret = ATC_Checkout::leg( $b, 'departure' );
		$one = $b['legs'][0] ?? array();
		$row = static fn( $k, $val, $href = '' ) => '<div class="ab-rev"><span class="ab-k">' . esc_html( $k ) . '</span><span>' . $val . '</span>' . ( $href ? '<a class="ab-link" href="' . esc_url( $href ) . '">Edit</a>' : '' ) . '</div>';
		$flight = ! empty( $d['no_flight'] ) || empty( $arr['flight_number'] ) ? 'Flight to be added · lands ' . esc_html( self::local( $arr['pickup_at'] ?? null, $tz ) ) : esc_html( trim( ( $arr['flight_airline'] ?? '' ) . ' ' . $arr['flight_number'] ) . ' · lands ' . self::local( $arr['flight_time'] ?? null, $tz ) );
		$moved = $was !== (int) $b['total_cents'];
		ob_start();
		?>
		<section class="ab-wrap ab-main">
			<div class="ab-col">
				<?php echo self::steps( 3 ); // phpcs:ignore ?>
				<h1 class="ab-h1">Payment</h1>
				<?php if ( ! empty( $_GET['failed'] ) ) : // phpcs:ignore ?>
					<div class="ab-alert" role="alert">The payment did not go through, and you were not charged. Try again or use another card.</div>
				<?php endif; ?>
				<?php if ( $moved ) : ?>
					<div class="ab-alert is-info" role="status">Your hold ran out, so we checked the price again: it is now <?php echo esc_html( self::money( (int) $b['total_cents'] ) ); ?>.</div>
				<?php endif; ?>
				<?php if ( $err ) : ?>
					<div class="ab-nothing"><h2>We can’t take the payment right now</h2><p><?php echo esc_html( $err ); ?></p><div class="ab-actions"><a class="ab-btn is-accent" href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( $q ) ) ); ?>">See vehicles</a></div></div>
				<?php else : ?>
				<form class="ab-form" id="ab-pay" data-ab-pay novalidate>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-pay">
						<div class="ab-cardhead"><h2 id="ab-h-pay">Payment</h2><span class="ab-hold" data-ab-hold role="timer" aria-live="off"></span></div>
						<p class="ab-muted">Charged in USD now. Free cancellation up to <?php echo (int) ATC_Settings::get( 'free_cancel_hours' ); ?> h before pickup, refunded to the same card.</p>
						<div class="ab-stripe" data-ab-element><p class="ab-muted">Loading the secure payment form…</p></div>
						<p class="ab-secure"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg> Payments are processed by Stripe. Your card details never touch our servers.</p>
					</section>
					<section class="ab-card ab-pad" aria-labelledby="ab-h-rev">
						<h2 id="ab-h-rev">Review and book</h2>
						<?php
						if ( $arr ) {
							echo $row( 'Arrival', $flight, $edit ); // phpcs:ignore
							echo $row( 'Drop-off', esc_html( $to ) . ( ! empty( $d['hotel_name'] ) ? ' · reservation under ' . esc_html( $d['hotel_name'] ) : '' ), $edit ); // phpcs:ignore
						} elseif ( ! empty( $d['hotel_name'] ) ) {
							echo $row( 'Hotel booking', esc_html( 'Under ' . $d['hotel_name'] ), $edit ); // phpcs:ignore
						}
						if ( $ret ) {
							echo $row( $arr ? 'Return' : 'Pickup', 'Pickup ' . esc_html( self::local( $ret['pickup_at'] ?? null, $tz ) ) . ' at ' . esc_html( (string) ( $ret['from_place'] ?? '' ) ) . ( ! empty( $ret['flight_number'] ) ? ' · ' . esc_html( trim( $ret['flight_airline'] . ' ' . $ret['flight_number'] ) ) : '' ), $edit ); // phpcs:ignore
						}
						echo $row( 'Lead traveler', esc_html( $b['first_name'] . ' ' . $b['last_name'] . ' · ' . $b['email'] . ' · ' . $b['phone'] ), $edit ); // phpcs:ignore
						echo $row( 'Passengers', esc_html( self::pax_text( (int) $b['adults'], (int) $b['children'] ) . ' · ' . (int) ( $one['suitcases'] ?? 0 ) . ' suitcases, ' . (int) ( $one['carry_ons'] ?? 0 ) . ' carry-ons' ), $edit ); // phpcs:ignore
						?>
						<label class="ab-check"><input type="checkbox" name="agree" required data-ab-agree> <span>I agree to the <a href="<?php echo esc_url( home_url( '/reservation-policies/' ) ); ?>" target="_blank" rel="noopener" data-ab-policy="reservation-policies">reservation policies</a> and the <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank" rel="noopener" data-ab-policy="privacy-policy">privacy policy</a>.</span></label>
						<div class="ab-alert" role="alert" hidden data-ab-error></div>
						<button type="submit" class="ab-btn is-accent is-block ab-paybtn" data-ab-submit disabled>Pay <?php echo esc_html( self::money( (int) $b['total_cents'] ) ); ?> and book</button>
						<p class="ab-muted ab-center">You get your confirmation by email right away.</p>
					</section>
				</form>
				<?php endif; ?>
			</div>
			<aside class="ab-side">
				<?php echo self::transfer_card( $offer, $q, $air, $to, (int) $b['total_cents'], '', $b['legs'], $tz ); // phpcs:ignore ?>
				<?php echo self::trust(); // phpcs:ignore ?>
			</aside>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** The confirmation, reopened from the email with the booking key. */
	private static function reservation( array $b, string $key ): string {
		$snap  = $b['snap'];
		$tz    = (string) ( $snap['timezone'] ?? 'UTC' );
		$d     = (array) ( $snap['details'] ?? array() );
		$arr   = ATC_Checkout::leg( $b, 'arrival' );
		$ret   = ATC_Checkout::leg( $b, 'departure' );
		$one   = $b['legs'][0] ?? array();
		$prov  = ATC_Checkout::provider( $b );
		$free  = (int) ATC_Settings::get( 'free_cancel_hours' );
		$first = $one['pickup_at'] ?? null;
		ob_start();
		if ( 'held' === $b['status'] ) :
			?>
			<section class="ab-wrap ab-main"><div class="ab-col">
				<div class="ab-nothing is-info" role="status"><h1 class="ab-h3">We’re confirming your payment</h1><p>This usually takes a few seconds. This page refreshes by itself.</p></div>
				<meta http-equiv="refresh" content="5">
			</div></section>
			<?php
			return (string) ob_get_clean();
		endif;
		$cancelled = 'cancelled' === $b['status'];
		$row       = static fn( $k, $v ) => '<div class="ab-rev"><span class="ab-k">' . esc_html( $k ) . '</span><span>' . $v . '</span></div>';
		$aid       = (int) ( $snap['airport_id'] ?? 0 );
		if ( ! $aid && ! empty( $snap['iata'] ) ) {
			$found = ATC_Search::find_airport( (string) $snap['iata'] );
			$aid   = $found ? (int) $found['id'] : 0;
		}
		$meet      = ATC_Meeting::for_booking( $aid, (int) ( $one['party_id'] ?? 0 ) );
		$where     = ( $snap['iata'] ?? '' ) ?: ( $snap['airport'] ?? '' );
		$land      = $arr ? ( $arr['flight_time'] ?? $arr['pickup_at'] ) : null;
		$until     = $first ? gmdate( 'Y-m-d H:i:s', strtotime( $first . ' UTC' ) - $free * HOUR_IN_SECONDS ) : null;
		$can_free  = $until && strtotime( $until . ' UTC' ) > time();
		?>
		<section class="ab-wrap ab-main ab-confirm">
			<div class="ab-col">
				<?php echo self::done_notice(); // phpcs:ignore -- escaped inside. ?>
				<?php if ( $cancelled ) : ?>
					<div class="ab-band is-off">
						<h1 class="ab-h1">This booking is cancelled.</h1>
						<p>Reservation <strong><?php echo esc_html( $b['number'] ); ?></strong> was cancelled.</p>
					</div>
				<?php else : ?>
					<div class="ab-band">
						<div class="ab-bandtop">
							<span class="ab-okmark" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg></span>
							<div>
								<span class="ab-pill">Confirmed</span>
								<h1 class="ab-h1">You’re booked.</h1>
							</div>
						</div>
						<p>Reservation <strong><?php echo esc_html( $b['number'] ); ?></strong> is confirmed. Your booking details go to <strong><?php echo esc_html( $b['email'] ); ?></strong>.</p>
						<div class="ab-actions">
							<a class="ab-btn is-line" href="<?php echo esc_url( add_query_arg( array( 'k' => $key, 'ics' => 1 ), home_url( '/reservation/' . $b['number'] . '/' ) ) ); ?>">Add to calendar</a>
							<button type="button" class="ab-btn is-line" data-ab-print>Print voucher</button>
						</div>
					</div>

					<div class="ab-facts">
						<?php if ( $arr ) : ?>
							<div class="ab-fact is-key"><small class="ab-k"><?php echo $ret ? 'Arrival pickup' : 'Pickup'; ?></small><strong><?php echo esc_html( self::local( $land, $tz, 'D j M' ) ); ?></strong><span><?php echo esc_html( self::local( $land, $tz, 'H:i' ) ); ?> · on landing at <?php echo esc_html( $where ); ?></span></div>
						<?php endif; ?>
						<?php if ( $ret ) : ?>
							<div class="ab-fact is-key"><small class="ab-k"><?php echo $arr ? 'Return pickup' : 'Pickup'; ?></small><strong><?php echo esc_html( self::local( $ret['pickup_at'], $tz, 'D j M' ) ); ?></strong><span><?php echo esc_html( self::local( $ret['pickup_at'], $tz, 'H:i' ) ); ?> · at your hotel</span></div>
						<?php endif; ?>
						<div class="ab-fact"><small class="ab-k">Vehicle</small><strong><?php echo esc_html( (string) ( $snap['vehicle'] ?? '' ) ); ?></strong><span>Private · <?php echo (int) $b['adults'] + (int) $b['children']; ?> passengers</span></div>
						<div class="ab-fact"><small class="ab-k">Total paid</small><strong><?php echo esc_html( self::money( (int) $b['total_cents'] ) ); ?></strong><span><?php echo ! empty( $snap['card']['last4'] ) ? esc_html( ucfirst( (string) $snap['card']['brand'] ) . ' •••• ' . $snap['card']['last4'] ) : 'Paid'; ?></span></div>
					</div>

					<section class="ab-card ab-pad ab-voucher" aria-labelledby="ab-h-v">
						<h2 id="ab-h-v">Show this to your driver</h2>
						<div class="ab-vrow">
							<span><small class="ab-k">Reservation</small><strong class="ab-big"><?php echo esc_html( $b['number'] ); ?></strong><span class="ab-muted"><?php echo esc_html( $b['first_name'] . ' ' . $b['last_name'] . ' · ' . ( (int) $b['adults'] + (int) $b['children'] ) . ' passengers · ' . ( $snap['vehicle'] ?? '' ) ); ?></span></span>
							<span class="ab-codebox"><small class="ab-k">Service code</small><strong class="ab-big ab-code"><?php echo esc_html( $b['service_code'] ); ?></strong><span>Give it to your driver when the ride is done.</span></span>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $arr ) : ?>
				<section class="ab-card ab-pad ab-meetcard" aria-labelledby="ab-h-meet">
					<h2 id="ab-h-meet">Meeting your driver at <?php echo esc_html( $where ); ?></h2>
					<?php if ( count( $meet['sets'] ) > 1 ) : ?>
						<p class="ab-muted">Your terminal is on your boarding pass or your airline’s app.</p>
					<?php endif; ?>
					<?php foreach ( $meet['sets'] as $set ) : ?>
						<div class="ab-meetset">
							<?php if ( '' !== $set['terminal'] ) : ?><h3><?php echo esc_html( $set['terminal'] ); ?></h3><?php endif; ?>
							<ol class="ab-meet">
								<?php foreach ( $set['steps'] as $st ) : ?>
									<li><strong><?php echo esc_html( $st['title'] ); ?></strong><?php echo '' !== $st['text'] ? '<span>' . esc_html( $st['text'] ) . '</span>' : ''; ?></li>
								<?php endforeach; ?>
							</ol>
						</div>
					<?php endforeach; ?>
				</section>
				<?php else : ?>
				<section class="ab-card ab-pad ab-meetcard" aria-labelledby="ab-h-meet">
					<h2 id="ab-h-meet">Meeting your driver</h2>
					<p>Your driver waits at the entrance or lobby of <strong><?php echo esc_html( (string) ( $ret['from_place'] ?? '' ) ); ?></strong> at the pickup time, with a sign with your name. Be ready a few minutes early with your luggage.</p>
				</section>
				<?php endif; ?>

				<?php echo self::rate_card( $b, $key ); // phpcs:ignore -- escaped inside. ?>

				<section class="ab-card ab-pad" aria-labelledby="ab-h-trip">
					<h2 id="ab-h-trip">Trip details</h2>
					<?php
					echo $row( 'Route', esc_html( $arr ? ( $snap['iata'] ?? '' ) . ' ' . ( $snap['airport'] ?? '' ) . ' → ' . ( $arr['to_place'] ?? '' ) : ( $ret['from_place'] ?? '' ) . ' → ' . ( $snap['iata'] ?? '' ) . ' ' . ( $snap['airport'] ?? '' ) ) ); // phpcs:ignore
					if ( $arr ) {
						echo $row( 'Arrival', '<strong>' . esc_html( self::local( $land, $tz ) ) . '</strong>' . esc_html( ! empty( $arr['flight_number'] ) ? ' · ' . trim( $arr['flight_airline'] . ' ' . $arr['flight_number'] ) : ' · flight to be added in Manage booking' ) ); // phpcs:ignore
					}
					if ( $ret ) {
						echo $row( $arr ? 'Return' : 'Pickup', '<strong>Pickup ' . esc_html( self::local( $ret['pickup_at'], $tz ) ) . '</strong>' . esc_html( ' at ' . $ret['from_place'] . ( ! empty( $ret['flight_number'] ) ? ' · ' . trim( $ret['flight_airline'] . ' ' . $ret['flight_number'] ) : '' ) ) ); // phpcs:ignore
					}
					echo $row( 'Passengers', esc_html( self::pax_text( (int) $b['adults'], (int) $b['children'] ) . ' · ' . (int) ( $one['suitcases'] ?? 0 ) . ' suitcases, ' . (int) ( $one['carry_ons'] ?? 0 ) . ' carry-ons' ) ); // phpcs:ignore
					if ( ! empty( $d['hotel_name'] ) ) {
						echo $row( 'Hotel booking', esc_html( 'Under ' . $d['hotel_name'] ) ); // phpcs:ignore
					}
					if ( ! empty( $d['notes'] ) ) {
						echo $row( 'Notes for the driver', esc_html( $d['notes'] ) ); // phpcs:ignore
					}
					?>
				</section>

				<?php echo self::manage_card( $b, $key ); // phpcs:ignore -- escaped inside. ?>
			</div>
			<aside class="ab-side">
				<?php if ( $can_free && ! $cancelled ) : ?>
					<div class="ab-freecancel"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg><span><strong>Free cancellation</strong> until <?php echo esc_html( self::local( $until, $tz ) ); ?></span></div>
				<?php endif; ?>
				<?php if ( $prov ) : ?>
				<section class="ab-card ab-pad ab-provcard">
					<h2>Your provider</h2>
					<p><strong><?php echo esc_html( $prov['name'] ); ?></strong><br><span class="ab-muted">Operates your transfer</span><?php echo ( (int) $prov['rating_count'] >= ATC_Search::MIN_REVIEWS && null !== $prov['rating_avg'] ) ? '<br>' . self::stars( (float) $prov['rating_avg'], (int) $prov['rating_count'] ) : ''; // phpcs:ignore ?></p>
					<p class="ab-muted">To change your flight, hotel or time, use Manage booking so the change reaches them in writing.</p>
				</section>
				<?php endif; ?>
				<div class="ab-trust">
					<?php if ( ! $cancelled && ATC_Manage::can_change( $b ) ) : ?><span>Flight changed? <a href="#manage" data-ab-tomanage>Change it here</a></span><?php endif; ?>
					<span class="ab-keep"><span>Save this page’s link to come back to your booking any time.</span><button type="button" class="ab-btn is-line ab-copy" data-ab-copy="<?php echo esc_attr( ATC_Checkout::reservation_url( $b['number'], $key ) ); ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"></rect><path d="M5 15V5a2 2 0 0 1 2-2h10"></path></svg><span data-ab-copylabel>Copy link</span></button></span>
				</div>
			</aside>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Notices after a change, a cancellation or a rating (?done=...). */
	private static function done_notice(): string {
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore
		$msg  = array(
			'updated'   => 'Your changes are saved. We sent the new details to you and to your provider.',
			'cancelled' => 'Your booking is cancelled and the refund is on its way to your card. We sent you an email.',
			'rated'     => 'Thanks for rating your ride. It helps other travelers choose.',
		)[ $done ] ?? '';
		return $msg ? '<div class="ab-alert is-ok" role="status">' . esc_html( $msg ) . '</div>' : '';
	}

	/** Change details and cancel, while the free-cancellation time has not passed. */
	private static function manage_card( array $b, string $key ): string {
		if ( 'confirmed' !== $b['status'] ) {
			return '';
		}
		$change = ATC_Manage::can_change( $b );
		$cancel = ATC_Manage::can_cancel( $b );
		$free   = (int) ATC_Settings::get( 'free_cancel_hours' );
		$tz     = (string) ( $b['snap']['timezone'] ?? 'UTC' );
		$d      = (array) ( $b['snap']['details'] ?? array() );
		$arr    = ATC_Checkout::leg( $b, 'arrival' );
		$ret    = ATC_Checkout::leg( $b, 'departure' );
		$one    = $b['legs'][0] ?? array();
		$v      = static fn( $k, $def = '' ) => (string) ( $d[ $k ] ?? $def );
		$cfg    = array( 'number' => $b['number'], 'key' => $key, 'back' => ATC_Checkout::reservation_url( $b['number'], $key ) );
		ob_start();
		?>
		<section class="ab-card ab-pad ab-managecard" id="manage" aria-labelledby="ab-h-manage" data-ab-manage="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">
			<h2 id="ab-h-manage">Manage your booking</h2>
			<?php if ( ! $change && ! $cancel ) : ?>
				<p class="ab-muted">Changes and cancellations close <?php echo (int) $free; ?> hours before pickup, so this booking can no longer be changed online. For anything on the day, contact your provider.</p>
			<?php else : ?>
				<p class="ab-muted">You can change your flight, times and details<?php echo $cancel ? ', or cancel with a full refund,' : ''; ?> until <?php echo (int) $free; ?> hours before pickup. Your provider gets the changes by email.</p>
				<div class="ab-actions">
					<?php if ( $change ) : ?><button type="button" class="ab-btn is-dark" data-ab-open="change" aria-expanded="false" aria-controls="ab-change">Change flight or details</button><?php endif; ?>
					<?php if ( $cancel ) : ?><button type="button" class="ab-btn is-line" data-ab-open="cancel" aria-expanded="false" aria-controls="ab-cancel">Cancel booking</button><?php endif; ?>
				</div>
				<?php if ( $change ) : ?>
				<form class="ab-form" id="ab-change" hidden novalidate data-ab-change>
					<?php if ( $arr && ATC_Manage::open_leg( $arr ) ) : ?>
						<h3 class="ab-h3">Arrival</h3>
						<div class="ab-grid">
							<div class="ab-field"><label for="m-airline">Airline</label><input id="m-airline" name="airline" value="<?php echo esc_attr( (string) $arr['flight_airline'] ); ?>"></div>
							<div class="ab-field"><label for="m-flight">Flight number</label><input id="m-flight" name="flight" value="<?php echo esc_attr( (string) $arr['flight_number'] ); ?>" placeholder="Leave empty if you don’t have it yet"></div>
							<div class="ab-field"><label for="m-ldate">Landing date</label><input id="m-ldate" name="landing_date" type="date" required value="<?php echo esc_attr( self::local( $arr['pickup_at'], $tz, 'Y-m-d' ) ); ?>"></div>
							<div class="ab-field"><label for="m-ltime">Landing time</label><input id="m-ltime" name="landing_time" type="time" required value="<?php echo esc_attr( self::local( $arr['pickup_at'], $tz, 'H:i' ) ); ?>"></div>
						</div>
					<?php endif; ?>
					<?php if ( $ret && ATC_Manage::open_leg( $ret ) ) : ?>
						<h3 class="ab-h3"><?php echo $arr ? 'Return' : 'Pickup and flight'; ?></h3>
						<div class="ab-field"><label for="m-rfrom">Hotel pickup address</label><input id="m-rfrom" name="return_from" value="<?php echo esc_attr( (string) $ret['from_place'] ); ?>"></div>
						<div class="ab-grid">
							<div class="ab-field"><label for="m-rairline">Departure airline</label><input id="m-rairline" name="return_airline" value="<?php echo esc_attr( (string) $ret['flight_airline'] ); ?>"></div>
							<div class="ab-field"><label for="m-rflight">Departure flight number</label><input id="m-rflight" name="return_flight" value="<?php echo esc_attr( (string) $ret['flight_number'] ); ?>"></div>
							<div class="ab-field"><label for="m-rdate"><?php echo $arr ? 'Departure date' : 'Pickup date'; ?></label><input id="m-rdate" name="return_date" type="date" required value="<?php echo esc_attr( self::local( $ret['pickup_at'], $tz, 'Y-m-d' ) ); ?>"></div>
							<div class="ab-field"><label for="m-rft">Flight departure time</label><input id="m-rft" name="return_flight_time" type="time" value="<?php echo esc_attr( $ret['flight_time'] ? self::local( $ret['flight_time'], $tz, 'H:i' ) : '' ); ?>"></div>
							<div class="ab-field"><label for="m-rpick">Hotel pickup time</label><input id="m-rpick" name="return_pickup" type="time" required value="<?php echo esc_attr( self::local( $ret['pickup_at'], $tz, 'H:i' ) ); ?>"></div>
						</div>
					<?php endif; ?>
					<h3 class="ab-h3">Details</h3>
					<div class="ab-grid">
						<div class="ab-field"><label for="m-bags">Suitcases</label><input id="m-bags" name="suitcases" type="number" min="0" max="60" value="<?php echo (int) ( $one['suitcases'] ?? 0 ); ?>"></div>
						<div class="ab-field"><label for="m-carry">Carry-ons</label><input id="m-carry" name="carry_ons" type="number" min="0" max="60" value="<?php echo (int) ( $one['carry_ons'] ?? 0 ); ?>"></div>
						<div class="ab-field"><label for="m-hname">Name of the hotel reservation (optional)</label><input id="m-hname" name="hotel_name" value="<?php echo esc_attr( $v( 'hotel_name' ) ); ?>"></div>
					</div>
					<div class="ab-field"><label for="m-notes">Notes for your driver</label><textarea id="m-notes" name="notes" rows="3" maxlength="1000"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea></div>
					<p class="ab-muted">To change the passengers, the vehicle or the address, cancel and book again: the price may differ.</p>
					<div class="ab-alert" role="alert" hidden data-ab-error></div>
					<div class="ab-actions"><button type="submit" class="ab-btn is-accent">Save changes</button></div>
				</form>
				<?php endif; ?>
				<?php if ( $cancel ) : ?>
				<div class="ab-cancelbox" id="ab-cancel" hidden data-ab-cancel>
					<p><strong>Cancel and get <?php echo esc_html( self::money( (int) $b['total_cents'] ) ); ?> back on your card?</strong><br><span class="ab-muted">This can’t be undone. Banks usually show the refund within 5 to 10 days.</span></p>
					<div class="ab-alert" role="alert" hidden data-ab-error></div>
					<div class="ab-actions"><button type="button" class="ab-btn is-danger" data-ab-cancelgo>Yes, cancel my booking</button><button type="button" class="ab-btn is-line" data-ab-open="cancel">Keep my booking</button></div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** After the ride: rate the provider (once). */
	private static function rate_card( array $b, string $key ): string {
		if ( ! ATC_Manage::can_review( $b ) ) {
			return '';
		}
		$pre  = isset( $_GET['rate'] ) ? max( 0, min( 5, (int) $_GET['rate'] ) ) : 0; // phpcs:ignore
		$cfg  = array( 'number' => $b['number'], 'key' => $key, 'back' => ATC_Checkout::reservation_url( $b['number'], $key ) );
		$name = (string) ( $b['snap']['provider'] ?? 'your provider' );
		ob_start();
		?>
		<section class="ab-card ab-pad ab-ratecard" id="rate" aria-labelledby="ab-h-rate">
			<h2 id="ab-h-rate">How was your ride with <?php echo esc_html( $name ); ?>?</h2>
			<form class="ab-form" data-ab-rate="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>" novalidate>
				<fieldset class="ab-stars-in"><legend class="screen-reader-text">Your rating</legend>
					<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<input type="radio" name="rating" id="r<?php echo (int) $i; ?>" value="<?php echo (int) $i; ?>" <?php checked( $pre, $i ); ?>><label for="r<?php echo (int) $i; ?>" title="<?php echo (int) $i; ?> of 5"><span aria-hidden="true">★</span><span class="screen-reader-text"><?php echo (int) $i; ?> stars</span></label>
					<?php endfor; ?>
				</fieldset>
				<div class="ab-field"><label for="r-comment">Anything to add? (optional)</label><textarea id="r-comment" name="comment" rows="3" maxlength="1000"></textarea></div>
				<div class="ab-alert" role="alert" hidden data-ab-error></div>
				<div class="ab-actions"><button type="submit" class="ab-btn is-accent">Send rating</button></div>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** /manage-booking: find a booking by number and email; the link goes to that email. */
	private static function manage_find(): string {
		ob_start();
		?>
		<section class="ab-wrap ab-main">
			<div class="ab-col" style="max-width:640px">
				<h1 class="ab-h1">Manage booking</h1>
				<p class="ab-muted">See, change or cancel your booking. Type your reservation number and the email you booked with: we send you a link to it.</p>
				<form class="ab-form ab-card ab-pad" data-ab-find novalidate>
					<div class="ab-grid">
						<div class="ab-field"><label for="f-number">Reservation number</label><input id="f-number" name="number" required autocomplete="off" placeholder="e.g. ATK7Q2MX" style="text-transform:uppercase"></div>
						<div class="ab-field"><label for="f-email">Email</label><input id="f-email" name="email" type="email" required autocomplete="email"></div>
					</div>
					<div class="ab-alert" role="alert" hidden data-ab-error></div>
					<div class="ab-alert is-ok" role="status" hidden data-ab-sent>If that reservation and email match, the link is on its way. Check your inbox (and spam) in a minute.</div>
					<div class="ab-actions"><button type="submit" class="ab-btn is-accent">Send me the link</button></div>
					<p class="ab-muted" style="font-size:13px">Your reservation number is in your confirmation email.</p>
				</form>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Add to calendar: one event per ride. */
	private static function ics( array $b ): void {
		$esc   = static fn( $t ) => str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $t );
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Airport Transportation//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH' );
		foreach ( $b['legs'] as $l ) {
			if ( empty( $l['pickup_at'] ) ) {
				continue;
			}
			$start   = strtotime( $l['pickup_at'] . ' UTC' );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $b['number'] . '-' . $l['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $start + HOUR_IN_SECONDS );
			$lines[] = 'SUMMARY:' . $esc( ( 'arrival' === $l['direction'] ? 'Airport pickup' : 'Hotel pickup to the airport' ) . ' · ' . $b['number'] );
			$lines[] = 'LOCATION:' . $esc( $l['from_place'] );
			$lines[] = 'DESCRIPTION:' . $esc( ( $b['snap']['vehicle'] ?? '' ) . ' to ' . $l['to_place'] . '. Reservation ' . $b['number'] . '.' );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="airport-transfer-' . $b['number'] . '.ics"' );
		echo implode( "\r\n", $lines ); // phpcs:ignore
		exit;
	}
}

/** For the theme: the engine's search box. */
function atc_engine_search_box( array $args = array() ): string {
	return ATC_Book::search_box( $args );
}
