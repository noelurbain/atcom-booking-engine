<?php
/**
 * Runs only when the plugin is deleted from wp-admin.
 * Bookings and money records are kept unless the option atc_delete_data_on_uninstall is set to 1.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( '1' !== (string) get_option( 'atc_delete_data_on_uninstall' ) ) {
	return;
}

require_once __DIR__ . '/includes/class-atc-schema.php';
require_once __DIR__ . '/includes/class-atc-roles.php';

global $wpdb;
foreach ( ATC_Schema::tables() as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'atc_' . $t ); // phpcs:ignore
}
ATC_Roles::remove();
delete_option( 'atc_settings' );
delete_option( 'atc_db_version' );
delete_option( 'atc_delete_data_on_uninstall' );
