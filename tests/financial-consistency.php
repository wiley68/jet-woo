<?php
/** Isolated IMP-03C regression. No WP bootstrap, DB connection or actual transport. */
require __DIR__ . '/eur-backend.php';
$start = $checks;
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class JetOfflineCoupon {
	public $discount, $discount_tax;
	public function __construct( $net, $tax ) { $this->discount = $net; $this->discount_tax = $tax; }
	public function get_short_info() { return array( 'synthetic' => true ); }
}
class JetOfflineRate {
	public $label = 'Synthetic shipping', $method_id = 'flat_rate', $instance_id = 1, $cost = 5, $taxes = array( 1 => 1 ), $tax_status = 'taxable';
	public function get_meta_data() { return array( 'synthetic-meta' => 'retained' ); }
}
foreach ( array( 'fixed' => 50, 'percentage' => 100 ) as $coupon => $discount ) {
	eur_reset();
	$cart = WC()->cart;
	$cart->items['item']['line_subtotal_tax'] = 200;
	$cart->items['item']['line_total'] = 1000 - $discount;
	$cart->items['item']['line_tax'] = 200 - $discount * 0.2;
	$cart->items['item']['line_tax_data'] = array( 'subtotal' => array( 1 => 200 ), 'total' => array( 1 => $cart->items['item']['line_tax'] ) );
	$cart->discount_total = $discount; $cart->discount_tax = $discount * 0.2;
	$cart->shipping_total = 5; $cart->shipping_tax = 1;
	$cart->fees = array( (object) array( 'name' => 'Synthetic fee', 'taxable' => true, 'tax_class' => '', 'amount' => 2, 'total' => 2, 'tax' => 0.4, 'tax_data' => array( 1 => 0.4 ) ) );
	$cart->coupons = array( $coupon => new JetOfflineCoupon( $discount, $discount * 0.2 ) );
	$cart->shipping_packages = array( array( 'rates' => array( 'flat_rate:1' => new JetOfflineRate() ) ) );
	WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
	$cart->total = ( 1000 - $discount ) * 1.2 + 6 + 2.4;
	$cart->items['item']['synthetic-addon'] = 'engraving';
	$offline_checkout_hook = function ( $hook, ...$args ) {
		if ( 'woocommerce_checkout_create_order_line_item' === $hook ) { $args[0]->add_meta_data( 'addon', $args[2]['synthetic-addon'] ); }
	};
	$_POST = eur_payload( 'cart' );
	$during_mail = function () { eur_expect( 0 === $GLOBALS['orders_created'] && ! isset( $GLOBALS['saved_financial_order'] ), 'Order was persisted before bank acceptance.' ); };
	eur_expect( eur_success( eur_submit( 'cart' ), 'cart' ), "$coupon cart refused." );
	$order = $saved_financial_order;
	eur_expect( number_format( $cart->total, 2, '.', '' ) === number_format( $order->get_total(), 2, '.', '' ), "$coupon order lost financial adjustments." );
	eur_expect( number_format( $discount, 2, '.', '' ) === number_format( $order->get_discount_total(), 2, '.', '' ) && '5.00' === number_format( $order->get_shipping_total(), 2, '.', '' ) && '2.00' === number_format( $order->get_total_fees(), 2, '.', '' ), "$coupon order dropped component amounts." );
	eur_expect( array( $coupon ) === $order->get_coupon_codes() && 1 === count( $order->get_items( 'fee' ) ) && 1 === count( $order->get_shipping_methods() ) && 1 === count( $order->get_items( 'tax' ) ), 'Native component lines were lost.' );
	eur_expect( 'engraving' === $order->get_items()[0]->props['meta']['addon'] && 'retained' === $order->get_shipping_methods()[0]->props['meta']['synthetic-meta'], 'Supported checkout metadata was lost.' );
	eur_expect( false !== strpos( $mails[0][2], 'Размер на кредита: ' . number_format( $cart->total, 2, '.', '' ) . ';' ), 'Bank financed a different amount.' );
	unset( $_POST['jet_offer'] );
	eur_expect( eur_success( eur_submit( 'cart' ), 'cart' ) && 3 === count( $mails ) && 1 === $orders_created, 'Cart replay repeated financial effects.' );
}

foreach ( array( 'hook-changes-item', 'missing-shipping-rate' ) as $unsupported ) {
	eur_reset();
	if ( 'hook-changes-item' === $unsupported ) {
		$offline_checkout_hook = function ( $hook, ...$args ) { if ( 'woocommerce_checkout_create_order_line_item' === $hook ) { $args[0]->set_total( 999 ); } };
	} else { WC()->cart->shipping_total = 5; WC()->cart->total = 1005; }
	$_POST = eur_payload( 'cart' );
	eur_expect( ! eur_success( eur_submit( 'cart' ), 'cart' ) && empty( $mails ) && 0 === $orders_created && 0 === $options['jet_count'], "$unsupported was not rejected before side effects." );
	eur_expect( ! array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ); } ), 'Unsupported snapshot retained an uncertain claim.' );
}

eur_reset(); $_POST = eur_payload( 'cart' );
$offline_checkout_hook = function ( $hook, ...$args ) {
	if ( 'woocommerce_checkout_create_order_line_item' === $hook ) {
		eur_expect( (bool) array_filter( array_keys( $GLOBALS['options'] ), function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ); } ), 'Native checkout hook ran before the claim.' );
		$args[3]->save(); // Simulate an extension that incorrectly persists before delivery.
	}
};
$result = eur_submit( 'cart' );
eur_expect( 'uncertain' === ( $result['code'] ?? '' ) && empty( $mails ) && 1 === $orders_created, 'Early extension persistence was treated as safely retryable.' );
eur_submit( 'cart' );
eur_expect( empty( $mails ) && 1 === $orders_created, 'Early persistence allowed a second financial attempt.' );

// A save hook after bank acceptance cannot make an altered order silently successful.
foreach ( array( 'product', 'cart' ) as $context ) {
	eur_reset(); $_POST = eur_payload( $context );
	$offline_order_save_hook = function ( $order ) { $order->set_total( 999 ); };
	$result = eur_submit( $context );
	eur_expect( 'uncertain' === ( $result['code'] ?? '' ) && 1 === count( $mails ) && 1 === $orders_created, "$context: post-bank financial mutation was not held for review." );
	eur_submit( $context );
	eur_expect( 1 === count( $mails ) && 1 === $orders_created, "$context: post-bank mutation caused automatic redelivery." );
}

eur_reset(); $_POST = eur_payload( 'product' ); $offline_net_price = 900;
eur_expect( ! eur_success( eur_submit( 'product' ), 'product' ) && empty( $mails ) && 0 === $orders_created && 0 === $options['jet_count'], 'Product tax/price mismatch reached bank delivery.' );

eur_reset(); $cart = WC()->cart;
$cart->items['item']['data'] = wc_get_product( 77 );
$cart->items['item']['variation_id'] = 77;
$cart->items['item']['quantity'] = 2;
$cart->items['item']['variation'] = array( 'attribute_size' => 'synthetic-large' );
$cart->items['item']['line_subtotal'] = $cart->items['item']['line_total'] = $cart->total = 2400;
$_POST = eur_payload( 'cart' );
eur_expect( eur_success( eur_submit( 'cart' ), 'cart' ), 'Variable cart failed native order copying.' );
$line = $saved_financial_order->get_items()[0];
eur_expect( 42 === $line->get_product_id() && 77 === $line->get_variation_id() && 2 === $line->get_quantity() && array( 'attribute_size' => 'synthetic-large' ) === $line->get_variation(), 'Native copying lost product, variation, quantity or variation attributes.' );

// Woo's quantity-aware inclusive price is the authority; no per-unit multiplication.
foreach ( array( 1 => 120.02, 2 => 240.05, 3 => 360.07 ) as $quantity => $expected ) {
	eur_reset(); $catalog[42] = 100.02; $offline_tax_rate = 0.2;
	$source = jet_product_financial_source( 42, 0, $quantity );
	eur_expect( number_format( $expected, 2, '.', '' ) === $source['amount'], 'Quantity-aware product tax total.' );
	$_POST = array( 'security' => 'valid', 'jet_lname' => '', 'jet_context' => 'product', 'jet_product_id' => '42', 'jet_quantity' => (string) $quantity, 'jet_submission_id' => str_repeat( 'e', 32 ), 'jet_parva' => '0', 'jet_vnoski' => '3', 'jet_email' => 'synthetic@example.test' );
	$quote = eur_ajax( 'jet_calculate' ); $_POST = array_merge( $_POST, $quote );
	eur_expect( eur_success( eur_submit( 'product' ), 'product' ), 'Taxable product submission failed.' );
	eur_expect( number_format( $expected, 2, '.', '' ) === number_format( $saved_financial_order->get_total(), 2, '.', '' ), 'Product order differs from quantity-aware bank amount.' );
}
eur_reset(); $catalog[42] = 100.02; $offline_tax_rate = 0.2;
$cart = WC()->cart; $cart->total = 240.05;
$cart->items['item']['quantity'] = 2;
$cart->items['item']['line_subtotal'] = $cart->items['item']['line_total'] = 200.04;
$cart->items['item']['line_subtotal_tax'] = $cart->items['item']['line_tax'] = 40.01;
$cart->items['item']['line_tax_data'] = array( 'subtotal' => array( 1 => 40.008 ), 'total' => array( 1 => 40.008 ) );
$_POST = eur_payload( 'cart' );
$prepared = jet_prepare_financial_order( 'cart', $_POST, array(), array() );
eur_expect( jet_cart_financial_source() === jet_order_financial_source( $prepared ), 'Equivalent cart/order line tax rounding was rejected.' );
eur_expect( eur_success( eur_submit( 'cart' ), 'cart' ), 'Rounded cart submission failed.' );
eur_expect( false !== strpos( $mails[0][2], 'Обща сума с ДДС: 240.05;' ) && false === strpos( $mails[0][2], 'Обща сума с ДДС: 240.06;' ), 'Bank row multiplied a rounded unit display price.' );

// Repricing by WC hooks after an offer must be rejected, not silently financed.
eur_reset(); $_POST = eur_payload( 'cart' );
$offline_cart_recalculate = function ( $cart ) { $cart->total = 999; $cart->items['item']['line_total'] = 999; };
eur_expect( ! eur_success( eur_submit( 'cart' ), 'cart' ) && empty( $mails ) && 0 === $orders_created, 'Repriced cart reused stale offer.' );
unset( $offline_cart_recalculate );

// Inspect actual promotion SQL arguments: a mixed cart must not query product 4243.
class JetPromoProbe extends JetEurDatabase {
	public $keys = array();
	public function get_row( $query, $format ) {
		$this->keys[] = $query[1][0];
		return '4243' === $query[1][0] ? array( 'jet_product_percent' => -1 ) : null;
	}
}
eur_reset(); $wpdb = new JetPromoProbe();
$source = jet_cart_financial_source(); $source['lines'][] = array_merge( $source['lines'][0], array( 'product_id' => 43 ) );
foreach ( array( 'cart', 'checkout_classic', 'checkout_blocks' ) as $context ) {
	foreach ( array( false, true ) as $card ) { jet_calculate_source_financing( $source, 0, 12, $card, $context ); }
}
eur_expect( array( '42_43' ) === array_unique( $wpdb->keys ), 'Mixed products were concatenated into an unrelated promotion ID.' );

// Run the real installed Store API context/result/legacy classes against local gateways.
require dirname( __DIR__, 2 ) . '/woocommerce/src/StoreApi/Payments/PaymentContext.php';
require dirname( __DIR__, 2 ) . '/woocommerce/src/StoreApi/Payments/PaymentResult.php';
require dirname( __DIR__, 2 ) . '/woocommerce/src/StoreApi/Legacy.php';
function esc_url_raw( $url ) { return $url; }
foreach ( array( 'success', 'processing', 'bank_sent', 'uncertain' ) as $state ) {
	foreach ( array( 'jetpayment', 'jetpaymentcard' ) as $method ) {
		eur_reset(); $options['jet_checkout_submission_314'] = array( 'state' => $state );
		WC()->cart->empty_cart(); WC()->session = null; $_POST = array();
		$context = new Automattic\WooCommerce\StoreApi\Payments\PaymentContext(); $context->set_payment_method( $method );
		// Production context has a WC_Order type; the isolated read model has the same data.
		$server_order = new WC_Order(); $server_order->props['currency'] = 'EUR';
		$id = new ReflectionProperty( WC_Order::class, 'id' ); $id->setAccessible( true ); $id->setValue( $server_order, 314 );
		$context->set_order( $server_order );
		$result = new Automattic\WooCommerce\StoreApi\Payments\PaymentResult();
		jet_checkout_store_api_replay( $context, $result );
		( new Automattic\WooCommerce\StoreApi\Legacy() )->process_legacy_payment( $context, $result );
		eur_expect( ( 'success' === $state ? 'success' : 'failure' ) === $result->status && empty( $mails ) && 0 === $orders_created, 'Store API replay entered fresh validation/delivery.' );
		eur_expect( array( 'state' => $state ) === $options['jet_checkout_submission_314'], 'Replay changed a durable claim.' );
	}
}
eur_reset();
$context = new Automattic\WooCommerce\StoreApi\Payments\PaymentContext(); $context->set_payment_method( 'jetpayment' ); $server_order = new WC_Order(); $id->setValue( $server_order, 314 ); $context->set_order( $server_order );
$result = new Automattic\WooCommerce\StoreApi\Payments\PaymentResult(); jet_checkout_store_api_replay( $context, $result );
eur_expect( '' === $result->status, 'New checkout bypassed full legacy validation.' );
$options['jet_checkout_submission_314'] = array( 'state' => 'success' ); $options['woocommerce_currency'] = 'BGN';
jet_checkout_store_api_replay( $context, $result );
eur_expect( 'failure' === $result->status && empty( $mails ), 'Stored replay bypassed the EUR guard.' );
$result = new Automattic\WooCommerce\StoreApi\Payments\PaymentResult( 'pending' );
jet_checkout_store_api_replay( $context, $result );
eur_expect( 'pending' === $result->status, 'Replay overwrote another payment processor result.' );
echo 'PASS IMP-03C financial consistency: ' . ( $checks - $start ) . " additional assertions; native WC cart copying, tax quantities, promotions and real Store API replay bridge.\n";
