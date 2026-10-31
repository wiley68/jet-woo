<?php
/** Offline integration coverage, including the complete IMP-01 suite. */
require __DIR__ . '/eur-backend.php';
$start = $checks;

function eur_expire_offer( $token ) {
	$payload = json_decode( base64_decode( strtr( explode( '.', $token )[0], '-_', '+/' ) ), true );
	$payload['expires'] = time() - 1;
	$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
}
foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	$prefix = 'checkout_card' === $context ? 'jet_card_' : 'jet_';
	foreach ( array( 'missing', 'forged', 'expired', 'wrong_context', 'changed_price', 'changed_initial', 'changed_term', 'changed_quantity', 'changed_session' ) as $case ) {
		eur_reset(); $_POST = eur_payload( $context );
		switch ( $case ) {
			case 'missing': unset( $_POST[$prefix . 'offer'] ); break;
			case 'forged': $_POST[$prefix . 'offer'] .= 'x'; break;
			case 'expired': $_POST[$prefix . 'offer'] = eur_expire_offer( $_POST[$prefix . 'offer'] ); break;
			case 'wrong_context': $_POST[$prefix . 'context'] = 'unknown'; break;
			case 'changed_price':
				if ( 'product' === $context ) { $catalog[42]++; }
				else { $woocommerce->cart->total++; $order->total++; }
				break;
			case 'changed_initial': $_POST[$prefix . 'parva' . ( 0 === strpos( $context, 'checkout' ) ? '_input' : '' )] = '1.00'; break;
			case 'changed_term': $_POST[$prefix . 'vnoski' . ( 0 === strpos( $context, 'checkout' ) ? '_input' : '' )] = '9'; break;
			case 'changed_quantity':
				if ( 'product' === $context ) { $_POST['jet_quantity'] = '2'; }
				elseif ( 'cart' === $context ) { $woocommerce->cart->items['item']['quantity'] = 2; }
				else { $_POST[$prefix . 'products_qt'] = '2'; }
				break;
			case 'changed_session': $woocommerce->session->customer = 'other'; break;
		}
		eur_expect( ! eur_success( eur_submit( $context ), $context ), "$context/$case was accepted." );
		eur_expect( empty( $mails ) && 0 === $orders_created && empty( $order->statuses ), "$context/$case had side effects." );
		eur_expect( ! array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ) || 0 === strpos( $key, 'jet_checkout_submission_' ); } ), "$context/$case acquired a delivery claim." );
	}
	foreach ( array( true, false ) as $completed ) {
		eur_reset(); $_POST = eur_payload( $context );
		if ( ! $completed ) { $during_mail = function () { throw new RuntimeException( 'Uncertain test transport.' ); }; }
		$first = eur_submit( $context ); $before = count( $mails );
		unset( $_POST[$prefix . 'offer'] );
		eur_expect( eur_success( eur_submit( $context ), $context ) === $completed && $before === count( $mails ), "$context replay required a new offer or resent mail." );
	}
}

// Both Blocks gateways transmit the same required request fields, never session fallback.
foreach ( array( false, true ) as $card ) {
	$context = $card ? 'checkout_card' : 'checkout_classic'; $prefix = $card ? 'jet_card_' : 'jet_';
	foreach ( array( 'valid', 'missing_consent', 'missing_identity', 'non_eur_order' ) as $case ) {
		eur_reset(); $_POST = eur_payload( $context );
		$_POST[$prefix . 'context'] = 'checkout_blocks';
		$_POST[$prefix . 'offer'] = jet_create_verified_offer( 'checkout_blocks', array(), 0, 12, $card );
		if ( 'missing_consent' === $case ) { unset( $_POST[$prefix . 'uslovia1'] ); $woocommerce->session->set( $prefix . 'uslovia1', '1' ); }
		if ( 'missing_identity' === $case ) { unset( $_POST[$prefix . 'egn'] ); $woocommerce->session->set( $prefix . 'egn', 'synthetic' ); }
		if ( 'non_eur_order' === $case ) { $order->currency = 'USD'; }
		eur_expect( eur_success( eur_submit( $context ), $context ) === ( 'valid' === $case ), "Blocks/$context/$case invalid result." );
		eur_expect( count( $mails ) === ( 'valid' === $case ? 3 : 0 ), "Blocks/$context/$case delivery isolation." );
	}
}

// A source change between initial validation and the owned claim must release it.
class JetEurChangedAfterClaim extends JetEurDatabase {
	public function query( $query ) {
		$result = parent::query( $query );
		if ( 1 === $result ) { $GLOBALS['catalog'][42]++; $GLOBALS['woocommerce']->cart->total++; $GLOBALS['order']->total++; }
		return $result;
	}
}
foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	eur_reset(); $_POST = eur_payload( $context ); $wpdb = new JetEurChangedAfterClaim();
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ), "$context post-claim invalid offer sent mail." );
	eur_expect( ! array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ) || 0 === strpos( $key, 'jet_checkout_submission_' ); } ), "$context pre-delivery validation left an uncertain claim." );
}

// Actual AJAX source verification: variations, quantity, WAPF mismatch and both schemes.
foreach ( array( false, true ) as $card ) {
	eur_reset();
	$_POST = array( 'security' => 'valid', 'jet_context' => 'product', 'jet_card' => $card ? '1' : '0', 'jet_product_id' => '42', 'jet_variation_id' => '77', 'jet_quantity' => '2', 'jet_submission_id' => str_repeat( 'd', 32 ), 'jet_parva' => '100.00', 'jet_vnoski' => '12' );
	$reply = eur_ajax( 'jet_calculate' );
	eur_expect( '2400.00' === $reply['jet_priceall'] && '2300.00' === $reply['jet_total_credit_price'], 'Variation/quantity/down payment AJAX calculation.' );
	eur_expect( 2300.0 === jet_verify_financial_offer( $reply['jet_offer'], 'product', array( 'product_id' => 42, 'variation_id' => 77, 'quantity' => 2 ), $card, $_POST['jet_submission_id'] )['jet_total_credit_price'], 'AJAX did not issue a verifiable offer.' );
	$_POST['jet_expected_amount'] = '2500.00';
	eur_expect( 'stale_financial_values' === eur_ajax( 'jet_calculate' )['code'], 'Unsupported WAPF client surcharge was trusted.' );
}

class JetEurAdjustedCart extends JetEurCart {
	public $coupon = 'synthetic-coupon';
	public function get_discount_total() { return 100; }
	public function get_discount_tax() { return 20; }
	public function get_shipping_total() { return 5; }
	public function get_shipping_tax() { return 1; }
	public function get_fee_total() { return 2; }
	public function get_applied_coupons() { return array( $this->coupon ); }
}
class JetEurAdjustedOrder extends JetEurOrder {
	public function get_discount_total() { return 100; }
	public function get_discount_tax() { return 20; }
	public function get_shipping_total() { return 5; }
	public function get_shipping_tax() { return 1; }
	public function get_total_fees() { return 2; }
	public function get_coupon_codes() { return array( 'synthetic-coupon' ); }
}
eur_reset(); $woocommerce->cart = new JetEurAdjustedCart(); $order = new JetEurAdjustedOrder();
$woocommerce->cart->total = $order->total = 888;
$token = jet_create_verified_offer( 'checkout_blocks', array(), 0, 12 );
eur_expect( 888.0 === jet_verify_financial_offer( $token, 'checkout_blocks', array(), false, '', $order )['jet_priceall'], 'Discount/shipping/fee cart-to-order source mismatch.' );
$woocommerce->cart->coupon = 'other-coupon';
eur_reject( function () use ( $token ) { jet_verify_financial_offer( $token, 'checkout_blocks', array() ); }, 'stale_offer' );

// Promotion values remain unchanged; current settings must still match the signed quote.
eur_reset(); $promo_row = array( 'jet_product_percent' => 0 );
$token = jet_create_verified_offer( 'product', array( 'product_id' => 42, 'quantity' => 1 ), 100, 12 );
$quote = jet_verify_financial_offer( $token, 'product', array( 'product_id' => 42, 'quantity' => 1 ) );
eur_expect( 0.0 === $quote['jet_glp'] && 0.0 === $quote['jet_gpr'] && 75.0 === $quote['jet_vnoska'] && 900.0 === $quote['jet_obshto'], 'Zero-interest verified quote changed the formula.' );
$promo_row = null;
eur_reject( function () use ( $token ) { jet_verify_financial_offer( $token, 'product', array( 'product_id' => 42, 'quantity' => 1 ) ); }, 'stale_offer' );

foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	eur_reset(); $_POST = eur_payload( $context );
	// Secondary recipient fields must never inject an additional recipient/header.
	$_POST['jet_email'] = "synthetic@example.test\r\nBcc: injected@example.test";
	eur_expect( eur_success( eur_submit( $context ), $context ), "$context injected notification address changed bank acceptance." );
	$bank = $mails[0];
	eur_expect( array( 'bank@example.test' ) === $bank[0] && false === strpos( implode( '\n', $bank[3] ), 'injected@example.test' ), "$context recipient/header injection." );
	if ( 0 !== strpos( $context, 'checkout' ) ) {
		eur_expect( 2 === count( $mails ), "$context invalid customer notification address was delivered." );
	}
}
echo 'PASS EUR offer integration: ' . ( $checks - $start ) . " additional assertions; no live effects.\n";
