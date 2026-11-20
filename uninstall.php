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

// Event posts, then the product and category links.
$fe_event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'fe_event' ) );
foreach ( $fe_event_ids as $fe_event_id ) {
	wp_delete_post( (int) $fe_event_id, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )", '_fe_events', '_fe_excluded_events' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s", '_fe_events' ) );

// Split locks (fe_split_lock_{order id}) are options; remove them all.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'fe_split_lock_' ) . '%' ) );
// Per-user dismissal of the no-shipping hint.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'fe_dismissed_ticket_hint' ) );
// phpcs:enable

delete_transient( 'fe_ticket_hint_products' );
delete_option( 'fe_db_version' );
delete_option( 'fe_staff_email_per_child' );
delete_option( 'fe_delete_data_on_uninstall' );
delete_option( 'fe_courier_instructions' );
