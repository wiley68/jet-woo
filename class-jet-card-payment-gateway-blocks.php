<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Jet_Card_Payment_Gateway_Blocks extends AbstractPaymentMethodType {

	private $gateway;
	protected $name = 'jetpaymentcard';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_jetpaymentcard_settings', array() );
		if ( class_exists( 'Jet_Card_Payment_Gateway' ) ) {
			$this->gateway = new Jet_Card_Payment_Gateway();
		} else {
			$this->gateway = null;
		}
	}

	public function is_active() {
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'jetpaymentcard-blocks-integration',
			plugin_dir_url( __FILE__ ) . 'checkout_card.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			filemtime(JET_PLUGIN_DIR . '/checkout_card.js'),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'jetpaymentcard-blocks-integration' );
		}
		return array( 'jetpaymentcard-blocks-integration' );
	}

	public function get_payment_method_data() {
		global $woocommerce;
		if ( null === $woocommerce->cart ) {
			return array();
		}

		$jet_price = $woocommerce->cart->get_total( 'float' );

		$jet_eur           = (int) get_option( 'jet_eur' );
		$jet_currency_code = get_woocommerce_currency();
		$jet_sign          = 'лв.';
		$jet_sign_second   = 'евро';
		$jet_min_250       = JET_MIN_250;

		if ( $jet_eur === 1 && 'EUR' === $jet_currency_code ) {
			$jet_price = number_format( $jet_price * 1.95583, 2, '.', '' );
		} elseif ( $jet_eur >= 2 && 'BGN' === $jet_currency_code ) {
			$jet_price = number_format( $jet_price / 1.95583, 2, '.', '' );
			$jet_sign = 'евро';
			$jet_sign_second = $jet_eur === 2 ? 'лв.' : '';
			$jet_min_250 = JET_MIN_250_EUR;
		}

		$jet_vnoski = ( $jet_price < $jet_min_250 ) ? '9' : get_option( 'jet_vnoski_default' );

		$jet_products    = '';
		$jet_products_qt = '';
		$jet_products_pr = '';
		$jet_products_vr = '';
		foreach ( $woocommerce->cart->get_cart() as $cart_item ) {
			$jet_products    .= $cart_item['product_id'] . '_';
			$jet_products_qt .= $cart_item['quantity'] . '_';

			$jet_product_vr_current = $cart_item['variation_id'];
			if ( 0 !== $jet_product_vr_current ) {
				$jet_product = new WC_Product_Variation( $jet_product_vr_current );
			} else {
				$jet_product = new WC_Product( $cart_item['product_id'] );
			}
			$jet_products_pr_current = (float) wc_get_price_including_tax( $jet_product );

			switch ( $jet_eur ) {
				case 0:
					break;
				case 1:
					if ( 'EUR' === $jet_currency_code ) {
						$jet_products_pr_current *= 1.95583;
					}
					break;
				case 2:
				case 3:
					if ( 'BGN' === $jet_currency_code ) {
						$jet_products_pr_current /= 1.95583;
					}
					break;
			}
			$jet_products_pr .= number_format( $jet_products_pr_current, 2, '.', '' ) . '_';
			$jet_products_vr .= $jet_product_vr_current . '_';
		}
		$jet_products    = trim( $jet_products, '_' );
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
			'jet_sign_second' => $jet_sign_second,
			'jet_eur'         => $jet_eur,
			'jet_vnoski'      => $jet_vnoski,
		);
	}
}
