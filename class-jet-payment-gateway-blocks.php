<?php
/**
 * WooCommerce Blocks integration for Jet gateway.
 *
 * @package jetcredit
 */
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
/**
 * Registers and exposes the main gateway for Checkout Blocks.
 */
final class Jet_Payment_Gateway_Blocks extends AbstractPaymentMethodType {
	/**
	 * Classic gateway instance used by the blocks wrapper.
	 *
	 * @var Jet_Payment_Gateway|null
	 */
	private $gateway;
	/**
	 * Payment method name used by WooCommerce Blocks registry.
	 *
	 * @var string
	 */
	protected $name = 'jetpayment';
	/**
	 * Bootstraps gateway settings and classic gateway instance.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_jetpayment_settings', array() );
		if ( class_exists( 'Jet_Payment_Gateway' ) ) {
			$this->gateway = new Jet_Payment_Gateway();
		} else {
			$this->gateway = null;
		}
	}

	/**
	 * Determines whether this payment method should be active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway && method_exists( $this->gateway, 'is_available' ) ? $this->gateway->is_available() : false;
	}

	/**
	 * Returns frontend script handles for blocks checkout.
	 *
	 * @return array<int, string>
	 */
	public function get_payment_method_script_handles() {
		wp_enqueue_style( 'jet_checkout_styles', plugin_dir_url( __FILE__ ) . 'css/jetcredit_checkout.css', array(), filemtime( JET_PLUGIN_DIR . '/css/jetcredit_checkout.css' ), 'all' );
		jet_register_offer_script();
		if ( ! wp_script_is( 'jet-blocks-ui', 'registered' ) ) {
			wp_register_script( 'jet-blocks-ui', JET_JS_URI . 'jetcredit_blocks.js', array( 'jet-offers', 'wc-blocks-registry', 'wc-settings', 'wc-blocks-data-store', 'wp-data', 'wp-element', 'wp-html-entities' ), filemtime( JET_PLUGIN_DIR . '/js/jetcredit_blocks.js' ), true );
		}
		wp_register_script(
			'jetpayment-blocks-integration',
			plugin_dir_url( __FILE__ ) . 'checkout.js',
			array( 'jet-blocks-ui' ),
			filemtime( JET_PLUGIN_DIR . '/checkout.js' ),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'jetpayment-blocks-integration' );
		}
		return array( 'jetpayment-blocks-integration' );
	}

	/**
	 * Provides payment method data passed to the checkout block.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		try { jet_require_eur_context(); } catch ( Jet_Financial_Validation_Exception $error ) { return array(); }
		global $woocommerce;
		if ( null === $woocommerce->cart ) {
			return array();
		}
		$jet_price = $woocommerce->cart->get_total( 'float' );

		$jet_sign = 'евро';

		$jet_initial_term_threshold = JET_INITIAL_TERM_THRESHOLD_EUR;
		$jet_vnoski_default = get_option( 'jet_vnoski_default' );
		if ( $jet_price < $jet_initial_term_threshold ) {
			$jet_vnoski = '9';
		} else {
			$jet_vnoski = $jet_vnoski_default;
		}
		$jet_products = '';
		$jet_products_qt = '';
		$jet_products_pr = '';
		$jet_products_vr = '';
		foreach ( $woocommerce->cart->get_cart() as $cart_item ) {
			$jet_products .= $cart_item['product_id'] . '_';
			$jet_products_qt .= $cart_item['quantity'] . '_';
			$jet_product_vr_current = $cart_item['variation_id'];
			if ( 0 !== $jet_product_vr_current ) {
				$jet_product = new WC_Product_Variation( $jet_product_vr_current );
			} else {
				$jet_product = new WC_Product( $cart_item['product_id'] );
			}
			$jet_products_pr_current = (float) wc_get_price_including_tax( $jet_product );
			$jet_products_pr .= number_format( $jet_products_pr_current, 2, '.', '' ) . '_';
			$jet_products_vr .= $jet_product_vr_current . '_';
		}
		$jet_products = trim( $jet_products, '_' );
		$jet_products_qt = substr( $jet_products_qt, 0, -1 );
		$jet_products_pr = substr( $jet_products_pr, 0, -1 );
		$jet_products_vr = substr( $jet_products_vr, 0, -1 );
		return array(
			'title'           => $this->gateway->title,
			'description'     => $this->gateway->description,
			'jet_price'       => $jet_price,
			'jet_products'    => $jet_products,
			'jet_products_qt' => $jet_products_qt,
			'jet_products_pr' => $jet_products_pr,
			'jet_products_vr' => $jet_products_vr,
			'jet_sign'        => $jet_sign,
			'jet_vnoski'      => $jet_vnoski,
			'config' => jet_frontend_configuration(),
			'supports' => $this->gateway->supports,
		);
	}
}
