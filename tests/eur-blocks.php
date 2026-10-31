<?php
/** Offline Blocks registration/data contract. No WordPress or Store API bootstrap. */
namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
	abstract class AbstractPaymentMethodType { protected $name, $settings; }
}
namespace {
	require __DIR__ . '/eur-bootstrap.php';
	define( 'JET_PLUGIN_DIR', dirname( __DIR__ ) );
	define( 'JET_JS_URI', '/synthetic/js/' );
	function admin_url( $path ) { return '/synthetic/' . $path; }
	function wp_script_is( $handle, $state ) { return isset( $GLOBALS['offline_scripts'][$handle] ); }
	function wp_register_script( $handle, $url, $dependencies, $version, $footer ) { $GLOBALS['offline_scripts'][$handle] = array( $url, $dependencies, $version, $footer ); }
	function wp_enqueue_style( $handle, $url, $dependencies, $version, $media ) { $GLOBALS['offline_styles'][$handle] = array( $url, $dependencies, $version, $media ); }
	function plugin_dir_url( $file ) { return '/synthetic/'; }
	function wp_set_script_translations( $handle ) {}
	require JET_PLUGIN_DIR . '/class-jet-payment-gateway-blocks.php';
	require JET_PLUGIN_DIR . '/class-jet-card-payment-gateway-blocks.php';
	$checks = 0;
	foreach ( array( 'Jet_Payment_Gateway_Blocks', 'Jet_Card_Payment_Gateway_Blocks' ) as $class ) {
		eur_reset(); $options['jet_minprice'] = 100;
		$wrapper = new $class(); $wrapper->initialize();
		// The actual WooCommerce base gateway exposes this property.
		$property = new \ReflectionProperty( $class, 'gateway' ); $property->setAccessible( true );
		$property->getValue( $wrapper )->supports = array( 'products' );
		$data = $wrapper->get_payment_method_data();
		if ( ! $wrapper->is_active() || 100.0 !== $data['config']['rules']['min_principal'] || ! $data['config']['available'] || 'евро' !== $data['jet_sign'] || isset( $data['jet_sign_second'] ) ) { throw new \RuntimeException( 'Invalid Blocks availability/configuration.' ); } ++$checks;
		$offline_styles = array();
		$handles = $wrapper->get_payment_method_script_handles();
		if ( array( 'jet-blocks-ui' ) !== $offline_scripts[$handles[0]][1] || ! in_array( 'wc-blocks-data-store', $offline_scripts['jet-blocks-ui'][1], true ) || array( 'jquery' ) !== $offline_scripts['jet-offers'][1] ) { throw new \RuntimeException( 'Missing Blocks script dependencies.' ); } ++$checks;
		if ( array( '/synthetic/css/jetcredit_checkout.css', array(), filemtime( JET_PLUGIN_DIR . '/css/jetcredit_checkout.css' ), 'all' ) !== ( $offline_styles['jet_checkout_styles'] ?? null ) ) { throw new \RuntimeException( 'Blocks must enqueue the shared Classic stylesheet independently of is_checkout().' ); } ++$checks;
		$effective_currency = 'USD';
		if ( $wrapper->is_active() || array() !== $wrapper->get_payment_method_data() ) { throw new \RuntimeException( 'Non-EUR Blocks registration active.' ); } ++$checks;
		$effective_currency = 'EUR'; $options['jet_minprice'] = 1001;
		if ( $wrapper->is_active() ) { throw new \RuntimeException( 'Blocks bypassed the administrative minimum.' ); } ++$checks;
	}
	echo "PASS EUR Blocks PHP: $checks assertions; gateway availability, localized rules and script dependencies.\n";
}
