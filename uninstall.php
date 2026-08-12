<?php
/**
 * Runs when the plugin is uninstalled (deleted) by an administrator.
 *
 * Removes plugin options and dedicated Analytics tables to avoid leaving
 * orphaned data. WooCommerce products, orders, and their private BumpMint
 * audit metadata are preserved.
 *
 * @package BumpMint
 */

// Prevent direct access before checking the dedicated uninstall context.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bumpmint_rules' );
delete_option( 'bumpmint_storage_version' );
delete_option( 'bumpmint_analytics_started_at' );
delete_option( 'bumpmint_analytics_tracking_id' );

global $wpdb;

$bumpmint_views_table       = $wpdb->prefix . 'bumpmint_analytics_views';
$bumpmint_conversions_table = $wpdb->prefix . 'bumpmint_analytics_conversions';
$bumpmint_drop_views        = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $bumpmint_views_table );
$bumpmint_drop_conversions  = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $bumpmint_conversions_table );

$wpdb->query( $bumpmint_drop_views ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall removes its dedicated table; prepared immediately above.
$wpdb->query( $bumpmint_drop_conversions ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall removes its dedicated table; prepared immediately above.
