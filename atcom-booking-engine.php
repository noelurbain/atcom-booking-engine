<?php
/**
 * Plugin Name:       ATCOM Booking Engine
 * Description:       Booking engine for AirportTransportation.com: providers, prices, bookings, payments and payouts.
 * Version:           0.22.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            AirportTransportation.com
 * Text Domain:       atcom-booking-engine
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'ATC_VERSION', '0.22.2' );
define( 'ATC_DB_VERSION', '23' );
define( 'ATC_FILE', __FILE__ );
define( 'ATC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATC_URL', plugin_dir_url( __FILE__ ) );

require_once ATC_DIR . 'includes/class-atc-money.php';
require_once ATC_DIR . 'includes/class-atc-codes.php';
require_once ATC_DIR . 'includes/class-atc-settings.php';
require_once ATC_DIR . 'includes/class-atc-schema.php';
require_once ATC_DIR . 'includes/class-atc-roles.php';
require_once ATC_DIR . 'includes/class-atc-install.php';
require_once ATC_DIR . 'includes/class-atc-rest.php';
require_once ATC_DIR . 'includes/class-atc-geo.php';
require_once ATC_DIR . 'includes/class-atc-catalog.php';
require_once ATC_DIR . 'includes/class-atc-rest-control.php';
require_once ATC_DIR . 'includes/class-atc-control.php';
require_once ATC_DIR . 'includes/class-atc-partner.php';
require_once ATC_DIR . 'includes/class-atc-migration.php';
require_once ATC_DIR . 'includes/class-atc-dashboard.php';
require_once ATC_DIR . 'includes/class-atc-prices.php';
require_once ATC_DIR . 'includes/class-atc-bookings.php';
require_once ATC_DIR . 'includes/class-atc-search.php';
require_once ATC_DIR . 'includes/class-atc-meeting.php';
require_once ATC_DIR . 'includes/class-atc-checkout.php';
require_once ATC_DIR . 'includes/class-atc-manage.php';
require_once ATC_DIR . 'includes/class-atc-mail.php';
require_once ATC_DIR . 'includes/class-atc-trips.php';
require_once ATC_DIR . 'includes/class-atc-ops.php';
require_once ATC_DIR . 'includes/class-atc-charges.php';
require_once ATC_DIR . 'includes/class-atc-affiliates.php';
require_once ATC_DIR . 'includes/class-atc-tickets.php';
require_once ATC_DIR . 'includes/class-atc-coupons.php';
require_once ATC_DIR . 'includes/class-atc-extras.php';
require_once ATC_DIR . 'includes/class-atc-directory.php';
require_once ATC_DIR . 'includes/class-atc-insights.php';
require_once ATC_DIR . 'includes/class-atc-report.php';
require_once ATC_DIR . 'includes/class-atc-analytics.php';
require_once ATC_DIR . 'includes/class-atc-payouts.php';
require_once ATC_DIR . 'includes/class-atc-airports.php';
require_once ATC_DIR . 'includes/class-atc-book.php';
require_once ATC_DIR . 'includes/class-atc-rest-portal.php';
require_once ATC_DIR . 'includes/class-atc-rest-affiliate.php';
require_once ATC_DIR . 'includes/class-atc-portal.php';
require_once ATC_DIR . 'includes/class-atc-brand.php';

if ( is_admin() ) {
	require_once ATC_DIR . 'admin/class-atc-admin.php';
}

register_activation_hook( __FILE__, array( 'ATC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ATC_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ATC_Install', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'ATC_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ATC_REST_Control', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ATC_REST_Portal', 'register_routes' ) );
ATC_Control::init();
ATC_Portal::init();
ATC_Brand::init();
ATC_Book::init();
ATC_Mail::init();
ATC_Trips::init();
ATC_Affiliates::init();
ATC_Analytics::init();
ATC_Directory::init();
add_shortcode( 'atc_provider_agreement', array( 'ATC_Partner', 'agreement_html' ) );

/** Fields of the provider sign-up form, used by the theme's /partners/provider/ page. */
function atc_provider_signup_fields(): string {
	return ATC_Portal::signup_fields();
}

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'ATC_Admin', 'init' ) );
}

/**
 * The public traveler count for the theme: echo atc_traveler_count() (30 days), atc_traveler_count( '7d' ) or
 * atc_traveler_count( '24h' ). Also the shortcode [atc_travelers span="30d"]. Formatted, e.g. "1,821".
 */
function atc_traveler_count( string $span = '30d' ): string {
	return number_format( ATC_Insights::traveler_count( $span ) );
}
add_shortcode( 'atc_travelers', static fn( $a ) => esc_html( atc_traveler_count( (string) ( $a['span'] ?? '30d' ) ) ) );
