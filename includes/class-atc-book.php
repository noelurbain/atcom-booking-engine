<?php
/**
 * Traveler pages inside the site's theme: the search box (atc_engine_search_box()), the results
 * page at /search (step 1, choose your vehicle) and /checkout (steps 2 and 3, next update).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Book {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'atc_book' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 2 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^search/?$', 'index.php?atc_book=search', 'top' );
		add_rewrite_rule( '^checkout/?$', 'index.php?atc_book=checkout', 'top' );
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
			)
		);
	}

	/**
	 * The search box, for the theme's booking bars: echo atc_engine_search_box( array( 'airport' => 'CUN' ) ).
	 * Without JavaScript it still works: the typed drop-off is looked up on the results page.
	 */
	public static function search_box( array $args = array() ): string {
		self::assets();
		$q        = wp_parse_args( $args, array( 'airport' => '', 'to' => '', 'date' => '', 'time' => '', 'trip' => 'one_way', 'return_date' => '', 'adults' => 2, 'children' => 0, 'lat' => '', 'lng' => '', 'pid' => '', 'compact' => false ) );
		$airport  = $q['airport'] ? ATC_Search::find_airport( $q['airport'] ) : null;
		$alabel   = $airport ? ATC_Catalog::airport_label( $airport ) : '';
		$acode    = $airport ? ( $airport['iata'] ?: $airport['id'] ) : '';
		$round    = 'round_trip' === $q['trip'];
		$pax      = (int) $q['adults'] + (int) $q['children'];
		$uid      = 'ab' . wp_rand( 1000, 9999 );
		$center   = $airport && null !== $airport['lat'] ? $airport['lat'] . ',' . $airport['lng'] : '';
		$country  = $airport ? strtolower( (string) $airport['country'] ) : '';
		ob_start();
		?>
		<form class="ab-search<?php echo $q['compact'] ? ' is-compact' : ''; ?>" action="<?php echo esc_url( home_url( '/search/' ) ); ?>" method="get" role="search" aria-label="Search transfers" data-ab-search data-center="<?php echo esc_attr( $center ); ?>" data-country="<?php echo esc_attr( $country ); ?>">
			<input type="hidden" name="trip" value="<?php echo esc_attr( $round ? 'round_trip' : 'one_way' ); ?>">
			<div class="ab-pills" role="group" aria-label="Trip type">
				<button type="button" data-trip="one_way" aria-pressed="<?php echo $round ? 'false' : 'true'; ?>">One way</button>
				<button type="button" data-trip="round_trip" aria-pressed="<?php echo $round ? 'true' : 'false'; ?>">Round trip</button>
			</div>
			<div class="ab-row">
				<div class="ab-field ab-from ab-ac">
					<label for="<?php echo esc_attr( $uid ); ?>-from">From</label>
					<input id="<?php echo esc_attr( $uid ); ?>-from" type="text" data-ab-airport value="<?php echo esc_attr( $alabel ); ?>" placeholder="Airport or code" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-from-list" required>
					<input type="hidden" name="airport" value="<?php echo esc_attr( (string) $acode ); ?>">
					<ul id="<?php echo esc_attr( $uid ); ?>-from-list" class="ab-list" role="listbox" hidden></ul>
				</div>
				<div class="ab-field ab-to ab-ac">
					<label for="<?php echo esc_attr( $uid ); ?>-to">To</label>
					<input id="<?php echo esc_attr( $uid ); ?>-to" type="text" name="to" data-ab-to value="<?php echo esc_attr( $q['to'] ); ?>" placeholder="Hotel name or address" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-to-list" required>
					<input type="hidden" name="lat" value="<?php echo esc_attr( (string) $q['lat'] ); ?>"><input type="hidden" name="lng" value="<?php echo esc_attr( (string) $q['lng'] ); ?>"><input type="hidden" name="pid" value="<?php echo esc_attr( (string) $q['pid'] ); ?>">
					<ul id="<?php echo esc_attr( $uid ); ?>-to-list" class="ab-list" role="listbox" hidden></ul>
				</div>
				<div class="ab-field ab-date">
					<label for="<?php echo esc_attr( $uid ); ?>-date">Landing date</label>
					<input id="<?php echo esc_attr( $uid ); ?>-date" type="date" name="date" value="<?php echo esc_attr( $q['date'] ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
				</div>
				<div class="ab-field ab-time">
					<label for="<?php echo esc_attr( $uid ); ?>-time">Landing time</label>
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
		$title = 'checkout' === $view ? 'Your details' : 'Choose your vehicle';
		add_filter( 'pre_get_document_title', static fn() => $title . ' · Airport Transportation' );
		add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'ab-page' ) ) );
		get_header();
		echo 'checkout' === $view ? self::checkout( $res ) : self::results( $res ); // phpcs:ignore -- escaped inside.
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
				<span><small>From</small><strong><?php echo esc_html( $res['airport'] ? ( $res['airport']['iata'] ? $res['airport']['iata'] . ' · ' . $res['airport']['city'] : $res['airport']['name'] ) : '–' ); ?></strong></span>
				<span><small>To</small><strong><?php echo esc_html( $res['destination']['label'] ?? ( $q['to'] ?: '–' ) ); ?></strong></span>
				<span><small>Landing</small><strong><?php echo esc_html( $when ); ?></strong></span>
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
			$out .= '<li' . ( $n === $on ? ' class="is-on" aria-current="step"' : '' ) . '>' . $n . ' · ' . esc_html( $label ) . '</li>';
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
						<div class="ab-pills" role="group" aria-label="Trip type">
							<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'trip' => 'one_way', 'return_date' => '' ) ) ) ) ); ?>" aria-current="<?php echo $round ? 'false' : 'true'; ?>">One way</a>
							<a href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( array_merge( $q, array( 'trip' => 'round_trip' ) ) ) ) ); ?>" aria-current="<?php echo $round ? 'true' : 'false'; ?>">Round trip</a>
						</div>
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
						<article class="ab-offer<?php echo 0 === $i ? ' is-on' : ''; ?>" data-ab-offer data-name="<?php echo esc_attr( $o['name'] ); ?>" data-provider="<?php echo esc_attr( $o['provider'] ); ?>" data-vt="<?php echo (int) $o['vehicle_type_id']; ?>" data-price="<?php echo esc_attr( self::money( $o['retail'] ) ); ?>" data-href="<?php echo esc_url( $next ); ?>">
							<div class="ab-pic"><?php echo self::vehicle_icon(); // phpcs:ignore ?></div>
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
						<div class="ab-map" data-ab-map data-from="<?php echo esc_attr( $res['airport']['lat'] . ',' . $res['airport']['lng'] ); ?>" data-to="<?php echo esc_attr( $res['destination']['lat'] . ',' . $res['destination']['lng'] ); ?>" data-from-label="<?php echo esc_attr( $res['airport']['label'] ); ?>" data-to-label="<?php echo esc_attr( $res['destination']['label'] ); ?>"></div>
						<div class="ab-route">
							<span><i class="ab-dot"></i><?php echo esc_html( $res['airport']['name'] ); ?></span>
							<span><i class="ab-dot is-to"></i><span><?php echo esc_html( $res['destination']['label'] ); ?><?php echo $res['destination']['zone'] ? '<small class="ab-muted">Zone: ' . esc_html( $res['destination']['zone'] ) . '</small>' : ''; ?></span></span>
						</div>
					</section>
				<?php endif; ?>
				<?php if ( $res['offers'] ) : $o = $res['offers'][0]; ?>
					<section class="ab-card ab-pad">
						<h2>Your transfer</h2>
						<div class="ab-lines">
							<span><strong><?php echo esc_html( $res['airport']['iata'] ?: $res['airport']['name'] ); ?></strong> → <?php echo esc_html( $res['destination']['label'] ); ?></span>
							<span><?php echo esc_html( $q['date'] ? wp_date( 'D j M Y', strtotime( $q['date'] . ' 12:00 UTC' ), new DateTimeZone( 'UTC' ) ) : '' ); ?> · <?php echo (int) ( $q['adults'] + $q['children'] ); ?> passengers</span>
							<span data-ab-time></span>
						</div>
						<div class="ab-total"><span class="ab-muted"><span data-ab-selname><?php echo esc_html( $o['name'] . ( $round ? ', round trip' : ', one way' ) ); ?></span><small class="ab-selby" data-ab-selby>by <?php echo esc_html( $o['provider'] ); ?></small></span><strong data-ab-selprice><?php echo esc_html( self::money( $o['retail'] ) ); ?></strong></div>
						<a class="ab-btn is-accent is-block" href="<?php echo esc_url( home_url( '/checkout/?' . ATC_Search::query( $q, array( 'service' => $o['service_id'] ) ) ) ); ?>" data-ab-continue>Continue</a>
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

	/** Steps 2 and 3 arrive in the next update; this shows what was chosen. */
	private static function checkout( array $res ): string {
		$sid   = isset( $_GET['service'] ) ? (int) $_GET['service'] : 0; // phpcs:ignore
		$offer = null;
		foreach ( $res['offers'] as $o ) {
			if ( $o['service_id'] === $sid ) {
				$offer = $o;
			}
		}
		ob_start();
		echo self::summary_bar( $res ); // phpcs:ignore
		?>
		<section class="ab-wrap ab-main">
			<div class="ab-col">
				<?php echo self::steps( 2 ); // phpcs:ignore ?>
				<h1 class="ab-h1">Flight and details</h1>
				<?php if ( $offer ) : ?>
					<div class="ab-nothing is-info">
						<h2><?php echo esc_html( $offer['name'] ); ?> · <?php echo esc_html( self::money( $offer['retail'] ) ); ?></h2>
						<p>Your vehicle is chosen. Contact details, flight details and payment open here in the next update of the site, while we test it.</p>
						<div class="ab-actions"><a class="ab-btn is-line" href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( $res['query'] ) ) ); ?>">← Back to vehicles</a></div>
					</div>
				<?php else : ?>
					<div class="ab-nothing"><h2>That vehicle is no longer available</h2><p>Prices or availability changed. Choose again.</p><div class="ab-actions"><a class="ab-btn is-accent" href="<?php echo esc_url( home_url( '/search/?' . ATC_Search::query( $res['query'] ) ) ); ?>">See vehicles</a></div></div>
				<?php endif; ?>
			</div>
			<aside class="ab-side"><?php echo self::trust(); // phpcs:ignore ?></aside>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}

/** For the theme: the engine's search box. */
function atc_engine_search_box( array $args = array() ): string {
	return ATC_Book::search_box( $args );
}
