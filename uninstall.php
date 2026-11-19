<?php
/**
 * Uninstall routine. Removes tables and options only when the
 * "delete data on uninstall" setting (fe_delete_data_on_uninstall) is on.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( 'yes' !== get_option( 'fe_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'fe_event_dates', 'fe_locations', 'fe_zone_rules' ) as $fe_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$fe_table}" );
}
// phpcs:enable

delete_option( 'fe_db_version' );
delete_option( 'fe_delete_data_on_uninstall' );
