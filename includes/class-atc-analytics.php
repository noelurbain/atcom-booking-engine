<?php
/**
 * Tracking, once the ids are in Booking Engine → Settings → Analytics:
 * - PostHog: page views from the browser (no cookies: kept in memory, same visitor id as the server), and
 *   from the server the events ads can't hide: atcom_transfer_searched, atcom_booking_placed,
 *   atcom_payment_captured. No names, emails or phones, only the booking reference.
 * - GA4 and the Google Ads conversion: page views, and one purchase per booking (value in USD, the
 *   reservation number as transaction id) on the first view of the confirmation.
 * Our team signed in is never tracked, nor visitors from the countries in "Don't track visitors from" (bots).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Analytics {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_action( 'atc_booking_confirmed', array( __CLASS__, 'paid' ), 20 );
	}

	private static function skip(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	private static function host(): string {
		return 'eu' === ATC_Settings::get( 'posthog_region' ) ? 'https://eu.i.posthog.com' : 'https://us.i.posthog.com';
	}

	public static function head(): void {
		if ( is_admin() || self::skip() ) {
			return;
		}
		$ph  = (string) ATC_Settings::get( 'posthog_key' );
		$ga  = (string) ATC_Settings::get( 'ga4_id' );
		$ads = (string) ATC_Settings::get( 'ads_id' );
		if ( '' === $ph && '' === $ga && '' === $ads ) {
			return;
		}
		self::gate();
		if ( '' !== $ph ) {
			$cfg = array( 'api_host' => self::host(), 'persistence' => 'memory', 'person_profiles' => 'identified_only', 'capture_pageleave' => true, 'bootstrap' => array( 'distinctID' => ATC_Insights::visitor() ) );
			?>
<script>!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once unregister opt_out_capturing has_opted_out_capturing opt_in_capturing reset isFeatureEnabled onFeatureFlags getFeatureFlag identify alias set_config".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
window.atcTrack.push(function(){posthog.init(<?php echo wp_json_encode( $ph ); ?>,<?php echo wp_json_encode( $cfg ); ?>);posthog.register({site:<?php echo wp_json_encode( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>});});</script>
			<?php
		}
		$tag = $ga ?: $ads;
		if ( '' !== $tag ) {
			?>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.atcTrack.push(function(){var s=document.createElement('script');s.async=true;s.src=<?php echo wp_json_encode( 'https://www.googletagmanager.com/gtag/js?id=' . $tag ); ?>;document.head.appendChild(s);});gtag('js',new Date());<?php echo $ga ? "gtag('config'," . wp_json_encode( $ga ) . ');' : ''; ?><?php echo $ads ? "gtag('config'," . wp_json_encode( $ads ) . ');' : ''; ?></script>
			<?php
		}
	}

	/** Countries whose visitors we don't track (bot farms). */
	private static function skip_countries(): array {
		return array_values( array_filter( explode( ',', (string) ATC_Settings::get( 'analytics_skip_countries' ) ) ) );
	}

	/**
	 * Pages are cached at the edge, so the country is checked in the browser: Cloudflare's /cdn-cgi/trace says
	 * where the visitor is (remembered for the session). Trackers queue in window.atcTrack and start only
	 * when the country is not on the list. If the check fails, we track.
	 */
	private static function gate(): void {
		$skip = self::skip_countries();
		?>
<script>(function(){var q=[],go=null;window.atcTrack={push:function(f){go===true?f():q.push(f);}};function run(ok){go=ok;if(ok){q.forEach(function(f){try{f();}catch(e){}});}q=[];}var skip=<?php echo wp_json_encode( $skip ); ?>;if(!skip.length){run(true);return;}var c=null;try{c=sessionStorage.getItem('atc_cc');}catch(e){}if(c){run(skip.indexOf(c)<0);return;}fetch('/cdn-cgi/trace').then(function(r){return r.text();}).then(function(t){var m=t.match(/^loc=([A-Z]{2})/m);c=m?m[1]:'';try{sessionStorage.setItem('atc_cc',c);}catch(e){}run(skip.indexOf(c)<0);}).catch(function(){run(true);});})();</script>
		<?php
	}

	/** Server events: Cloudflare tells the country in a header, when it is there. */
	private static function from_skipped_country(): bool {
		$cc = strtoupper( (string) ( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) ); // phpcs:ignore
		return '' !== $cc && in_array( $cc, self::skip_countries(), true );
	}

	/** An event from the server (search, booking placed, payment): can't be blocked or faked in the browser. */
	public static function server_event( string $event, string $distinct, array $props ): void {
		$ph = (string) ATC_Settings::get( 'posthog_key' );
		if ( '' === $ph || self::skip() ) {
			return;
		}
		// Searches come from the visitor's own request; payments come from Stripe's webhook, so only searches are filtered.
		if ( 'atcom_transfer_searched' === $event && self::from_skipped_country() ) {
			return;
		}
		wp_remote_post(
			self::host() . '/capture/',
			array(
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'api_key' => $ph, 'event' => $event, 'distinct_id' => $distinct ?: 'server', 'properties' => $props + array( 'site' => (string) wp_parse_url( home_url(), PHP_URL_HOST ), '$process_person_profile' => false ), 'timestamp' => gmdate( 'c' ) ) ),
			)
		);
	}

	private static function props( array $b ): array {
		$snap = (array) $b['snap'];
		return array(
			'booking'   => $b['number'],
			'airport'   => (string) ( $snap['iata'] ?? '' ),
			'zone'      => (string) ( $snap['zone'] ?? '' ),
			'vehicle'   => (string) ( $snap['vehicle'] ?? '' ),
			'trip'      => (string) $b['trip_type'],
			'channel'   => (string) $b['channel'],
			'affiliate' => (string) ( $snap['affiliate']['code'] ?? '' ),
			'coupon'    => (string) $b['coupon_code'],
			'currency'  => 'USD',
			'value'     => round( (int) $b['total_cents'] / 100, 2 ),
		);
	}

	/** Stripe confirmed the payment (webhook or return page). */
	public static function paid( int $booking_id ): void {
		$b = ATC_Checkout::find( '', $booking_id );
		if ( ! $b ) {
			return;
		}
		global $wpdb;
		$aff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . ATC_Schema::table( 'ledger' ) . " WHERE booking_id = %d AND role IN ('affiliate','referrer') AND status <> 'cancelled'", $booking_id ) );
		$prv = array_sum( array_map( static fn( $l ) => (int) $l['provider_cents'], $b['legs'] ) );
		self::server_event( 'atcom_payment_captured', (string) ( $b['snap']['visitor'] ?? '' ), self::props( $b ) + array( 'margin' => round( ( (int) $b['total_cents'] - $prv - $aff ) / 100, 2 ), 'affiliate_share' => round( $aff / 100, 2 ) ) );
	}

	/** Checkout started: the booking exists, before payment. */
	public static function placed( array $b ): void {
		self::server_event( 'atcom_booking_placed', ATC_Insights::visitor(), self::props( $b ) );
	}

	/** The confirmation page, first time after paying: GA4 purchase and the Google Ads conversion, once. */
	public static function purchase_tag( array $b ): string {
		$ga  = (string) ATC_Settings::get( 'ga4_id' );
		$ads = (string) ATC_Settings::get( 'ads_id' );
		$lab = (string) ATC_Settings::get( 'ads_label' );
		if ( ( '' === $ga && '' === $ads ) || ! empty( $b['snap']['ga_sent'] ) || self::skip() || ! in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			return '';
		}
		$b['snap']['ga_sent'] = current_time( 'mysql', true );
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
		$val  = round( (int) $b['total_cents'] / 100, 2 );
		$out  = '<script>window.gtag&&(function(){';
		if ( $ga ) {
			$out .= "gtag('event','purchase'," . wp_json_encode( array( 'transaction_id' => $b['number'], 'value' => $val, 'currency' => 'USD', 'coupon' => (string) $b['coupon_code'], 'items' => array( array( 'item_id' => (string) ( $b['snap']['slug'] ?? '' ), 'item_name' => (string) ( $b['snap']['vehicle'] ?? '' ), 'item_category' => (string) ( $b['snap']['iata'] ?? '' ), 'price' => $val, 'quantity' => 1 ) ) ) ) . ');';
		}
		if ( $ads && $lab ) {
			$out .= "gtag('event','conversion'," . wp_json_encode( array( 'send_to' => $ads . '/' . $lab, 'value' => $val, 'currency' => 'USD', 'transaction_id' => $b['number'] ) ) . ');';
		}
		return $out . '})();</script>';
	}
}
