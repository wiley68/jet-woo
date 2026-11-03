<?php
/** Product/Cart payment titles against Checkout defaults; all persistence and mail are doubles. */
require __DIR__ . '/checkout-bootstrap.php';

$baseline = json_decode( file_get_contents( __DIR__ . '/fixtures/bank-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
$gateways = array( new Jet_Payment_Gateway(), new Jet_Card_Payment_Gateway() );
$checks = 0;
function payment_title_expect( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

foreach ( array( 'product', 'cart' ) as $context ) {
	foreach ( array( false, true ) as $card ) {
		$name = $context . ( $card ? '-variation-card-eur' : '-standard' );
		$case = $baseline['cases'][$name];
		$_POST = $case['post'];
		$records = $case['options'];
		$product_title = $case['product_title'];
		$admin_email = 'shop@example.test';
		$mails = $order_events = $scheduled = array();
		$mail_calls = $order_calls = 0;
		$mail_result = true;
		$order_throws = $order_saved = false;
		$order_failure = '';
		$during_mail = $mail_behavior = null;
		$wpdb->fail = false;
		jet_test_setup_source( $case, $context );
		$woocommerce->cart = new JetTestCart();
		$woocommerce->session = new JetTestSession();
		jet_test_issue_offer( $context, $card );

		$result = null;
		try {
			if ( 'cart' === $context ) { jet_send_card(); } else { jet_send(); }
		} catch ( JetTestResponse $response ) { $result = $response->response; }

		payment_title_expect( isset( $result['success'] ) && 'success' === $result['success'], "$name: submission failed." );
		payment_title_expect( 1 === $order_calls && $order_saved, "$name: order was not saved exactly once." );
		$expected = $gateways[(int) $card]->form_fields['title']['default'];
		payment_title_expect( $expected === $saved_financial_order->get_payment_method_title(), "$name: payment title differs from Checkout." );
		payment_title_expect( 'jetpayment' === $saved_financial_order->get_payment_method(), "$name: gateway ID changed." );
		payment_title_expect( 'on-hold' === $saved_financial_order->get_status(), "$name: configured order status changed." );
		payment_title_expect( 3 === $mail_calls, "$name: notification count changed." );
		echo "PASS $name: $expected; gateway ID jetpayment; status on-hold.\n";
	}
}
echo "PASS payment method titles: $checks assertions; no real orders or mail.\n";
