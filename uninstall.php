<?php
/**
 * Plugin uninstall script.
 *
 * @package jetcredit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! current_user_can( 'delete_plugins' ) ) {
	exit;
}

/**
 * Options to be deleted on plugin uninstall.
 */
$options = array(
	'jet_status_in',
	'jet_email',
	'jet_id',
	'jet_purcent',
	'jet_vnoski_default',
	'jet_card',
	'jet_purcent_card',
	'jet_count',
	'jet_gap',
	'jet_shirina',
	'jet_vnoska',
	'jet_button_type',
	'jet_button_scheme',
	'jet_btn_text',
	'jet_btn_text_card',
	'jet_btn_logo',
	'jet_btn_max_width',
	'jet_btn_round',
	'jet_btn_font',
	'jet_minprice',
	'jet_eur',
	'jet_order_status_id',
);

/**
 * Loop through each option and delete it from the database.
 */
foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

/**
 * Remove temporary application claims and their scheduled cleanup events.
 */
global $wpdb;
wp_unschedule_hook( 'jet_cleanup_product_submission' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes all plugin claims; caches are flushed below.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'jet_submission_' ) . '%' ) );

/**
 * Flush the cache after options are deleted.
 */
wp_cache_flush();
