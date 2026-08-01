<?php
/**
 * Runs when the plugin is uninstalled (deleted) by an administrator.
 *
 * Removes the options created by the plugin to avoid leaving orphaned data
 * in the database. It does not remove WooCommerce products, only the order
 * bump rule settings.
 *
 * @package BumpMint
 */

// Ensure this file only runs in the WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bumpmint_rules' );
