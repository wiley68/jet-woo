<?php
/**
 * Plugin Name: ПБ Лични Финанси
 * Plugin URI: https://avalonbg.com/software/cc-woo.html
 * Description: Дава възможност на Вашите клиенти да закупуват стока на изплащане с ПБ Лични Финанси
 * Version: 1.8.5
 * Author: Ilko Ivanov
 * Author URI: https://avalonbg.com
 * Text Domain: jetcredit
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package jetcredit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function jet_is_woocommerce_active() {
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )
		|| is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
}

if ( ! jet_is_woocommerce_active() ) {
	add_action( 'admin_notices', function() {
		echo '<div class="error"><p><strong>ПБ Лични Финанси:</strong> WooCommerce не е активиран! Моля, активирайте го.</p></div>';
	});

	return;
}

define( 'JET_PLUGIN_DIR', untrailingslashit( __DIR__ ) );
define( 'JET_INCLUDES_DIR', JET_PLUGIN_DIR . '/includes' );
define( 'JET_PLUGIN_URL', plugin_dir_url(__FILE__) );
define( 'JET_PLUGIN_PATH', plugin_dir_path(__FILE__) );
define( 'JET_IMAGES_URI', JET_PLUGIN_URL . 'images/' );
define( 'JET_CSS_URI', JET_PLUGIN_URL . 'css/' );
define( 'JET_JS_URI', JET_PLUGIN_URL . 'js/' );
define( 'JET_VERSION', '1.8.5' );
define( 'JET_MINPRICE', 75.00 );
define( 'JET_MIN_250', 250.00 );
define( 'JET_MIN_250_EUR', 125.00 );

$jet_files = [
	'/functions.php',
	'/admin.php',
	'/jet_calculate.php'
];
foreach ( $jet_files as $file ) {
	require_once JET_INCLUDES_DIR . $file;
}

register_activation_hook( __FILE__, 'jet_create_tables' );
register_deactivation_hook( __FILE__, 'jet_remove_tables' );

function jet_declare_cart_checkout_blocks_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'jet_declare_cart_checkout_blocks_compatibility' );

function jet_register_order_approval_payment_method_type() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'class-jet-payment-gateway-blocks.php';
	require_once plugin_dir_path( __FILE__ ) . 'class-jet-card-payment-gateway-blocks.php';
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new Jet_Payment_Gateway_Blocks() );
			$payment_method_registry->register( new Jet_Card_Payment_Gateway_Blocks() );
		}
	);
}
add_action( 'woocommerce_blocks_loaded', 'jet_register_order_approval_payment_method_type' );
