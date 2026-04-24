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
 * Flush the cache after options are deleted.
 */
wp_cache_flush();
