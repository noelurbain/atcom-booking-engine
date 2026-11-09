<?php
/**
 * Plugin Name:       ATCOM Booking Engine
 * Description:       Booking engine for AirportTransportation.com: providers, prices, bookings, payments and payouts.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            AirportTransportation.com
 * Text Domain:       atcom-booking-engine
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'ATC_VERSION', '0.1.0' );
define( 'ATC_DB_VERSION', '1' );
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

if ( is_admin() ) {
	require_once ATC_DIR . 'admin/class-atc-admin.php';
}

register_activation_hook( __FILE__, array( 'ATC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ATC_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ATC_Install', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'ATC_REST', 'register_routes' ) );

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'ATC_Admin', 'init' ) );
}
