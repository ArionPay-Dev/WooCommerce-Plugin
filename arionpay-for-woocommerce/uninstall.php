<?php
/**
 * Removes the gateway settings when the plugin is deleted.
 *
 * Order notes and order meta are left in place on purpose: they are the shop's own records of past payments.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_arionpay_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- short-lived lock rows, removed once on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'arionpay_lock_' ) . '%'
	)
);
