<?php
/** IMP-04B: real financing/claim/order-copy code, synthetic services only. */
require __DIR__ . '/financial-consistency.php';
$manual_start = $checks;
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }

function manual_setup( $context, $blocks = false, $scheme = false ) {
	eur_reset();
	$GLOBALS['options']['jet_email'] = '';
	$GLOBALS['options']['jet_count'] = 40;
	$_POST = eur_payload( $context, $scheme );
	if ( $blocks ) {
		$card = 'checkout_card' === $context; $prefix = $card ? 'jet_card_' : 'jet_';
		$_POST[$prefix . 'context'] = 'checkout_blocks';
		$_POST[$prefix . 'offer'] = jet_create_verified_offer( 'checkout_blocks', array(), 0, 12, $card );
	}
	return $_POST;
}
function manual_record( $context ) {
	return get_option( 0 === strpos( $context, 'checkout' ) ? 'jet_checkout_submission_314' : jet_product_submission_key( $_POST, $context ) );
}
function manual_assert_complete( $context, $result ) {
	eur_expect( eur_success( $result, $context ) && 'manual_registered' === ( $result['code'] ?? '' ), "$context manual success missing." );
	eur_expect( jet_submission_manual_message() === ( $result['message'] ?? '' ), "$context manual result misrepresents bank delivery." );
	$record = manual_record( $context );
	eur_expect( 0 === strpos( $context, 'checkout' ) ? array( 'state' => 'manual_registered' ) === $record : $result === $record, "$context manual result not durably distinct." );
	eur_expect( 40 === get_option( 'jet_count' ) && 0 === $GLOBALS['wpdb']->counter_updates, "$context manual registration consumed bank reference." );
	foreach ( $GLOBALS['mails'] as $mail ) {
		eur_expect( ! is_array( $mail[0] ), "$context attempted bank delivery." );
		foreach ( $mail[3] as $header ) { eur_expect( ! preg_match( '/^(?:Cc|Bcc):/i', $header ), 'Notification leaked recipients.' ); }
	}
}

$manual_cases = array( array( 'product', false ), array( 'cart', false ), array( 'checkout_classic', false ), array( 'checkout_card', false ), array( 'checkout_classic', true ), array( 'checkout_card', true ) );
foreach ( $manual_cases as list( $context, $blocks ) ) {
	foreach ( array( '', " \t " ) as $empty ) {
		manual_setup( $context, $blocks ); $options['jet_email'] = $empty;
		$checkout = 0 === strpos( $context, 'checkout' ); $existing = $order;
		$source = $checkout ? jet_order_financial_source( $order ) : ( 'cart' === $context ? jet_cart_financial_source() : jet_product_financial_source( 42, 0, 1 ) );
		// A broken bank counter must not affect a flow with no bank attempt.
		$wpdb->counter_failure = 'connection';
		$states = array();
		$offline_option_write = function ( $key, $value ) use ( &$states ) { $states[] = $value['state'] ?? $value['code'] ?? ''; return null; };
		$mail_behavior = function ( $mail ) use ( $context ) {
			eur_expect( ! is_array( $mail[0] ), 'Manual registration used the bank transport.' );
			$record = manual_record( $context );
			eur_expect( 'manual_registered' === ( $record['state'] ?? $record['code'] ?? '' ), 'Manual notification preceded durable completion.' );
			return true;
		};
		$result = eur_submit( $context ); manual_assert_complete( $context, $result );
		eur_expect( ! in_array( 'bank_sent', $states, true ) && ! in_array( 'uncertain', $states, true ), 'Manual registration wrote a bank delivery state.' );
		eur_expect( 2 === count( $mails ), "$context missing separate admin/customer notices." );
		eur_expect( $checkout ? ( 0 === $orders_created && $existing === $order && array( 'on-hold' ) === $order->statuses ) : ( 1 === $orders_created && $saved_financial_order->get_id() > 0 ), "$context order creation/status convention changed." );
		$actual = jet_order_financial_source( $checkout ? $order : $saved_financial_order );
		if ( 'product' === $context ) {
			unset( $actual['adjustments'] );
			eur_expect( $source['amount'] === $actual['lines'][0]['total'] && $source['amount'] === $actual['lines'][0]['subtotal'], 'Manual product line totals changed.' );
			$actual['lines'][0] = array_intersect_key( $actual['lines'][0], $source['lines'][0] );
		}
		eur_expect( $source === $actual, "$context order financial values changed." );
		if ( ! $checkout ) {
			eur_expect( 'Synthetic' === $saved_financial_order->props['address']['first_name'] && 'synthetic@example.test' === $saved_financial_order->props['address']['email'], 'Permitted order customer data lost.' );
			eur_expect( 42 === $saved_financial_order->get_items()[0]->get_product_id(), 'Order product lost.' );
		}
		eur_expect( 'shop@example.test' === $mails[0][0] && 'synthetic@example.test' === $mails[1][0], 'Notification recipients changed.' );
		eur_expect( false !== strpos( $mails[0][2], 'Собствено име: ********;' ) && false !== strpos( $mails[0][2], 'ЕГН: **********;' ), 'Admin notification was not masked.' );
		foreach ( array( 'Собствено име: Synthetic;', 'Фамилия: Applicant;', 'ЕГН: synthetic;', 'Телефон за връзка: synthetic;', 'synthetic@example.test' ) as $personal ) {
			foreach ( $mails as $mail ) { eur_expect( false === strpos( $mail[2], $personal ), 'Personal data leaked to secondary notice.' ); }
		}
		eur_expect( false !== strpos( $mails[0][2], 'Размер на кредита' ) && false === strpos( $mails[1][2], 'Данни за кредита:' ), 'Admin financing summary/customer privacy changed.' );
		foreach ( $mails as $mail ) { eur_expect( false !== strpos( $mail[2], jet_submission_manual_message() ), 'Notification falsely implies bank delivery.' ); }
		if ( $checkout ) {
			$gateway = 'checkout_card' === $context ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
			$gateway->instructions = '';
			ob_start(); $gateway->{'checkout_card' === $context ? 'thankyou_jetpaymentcard_page' : 'thankyou_jetpayment_page'}( 314 ); $receipt = ob_get_clean();
			eur_expect( false !== strpos( $receipt, jet_submission_manual_message() ), 'Checkout receipt omitted manual outcome.' );
		}
		// Changing configuration, losing the offer/session or switching gateways cannot send later.
		$options['jet_email'] = 'bank@example.test'; $_POST = array( 'security' => 'valid', 'jet_lname' => '', 'jet_submission_id' => str_repeat( 'a', 32 ) );
		WC()->session->values = array();
		eur_expect( $result === eur_submit( $context ) && 2 === count( $mails ), 'Manual replay lost outcome or repeated mail.' );
		eur_expect( $checkout ? 0 === $orders_created : 1 === $orders_created, 'Manual replay created a second order.' );
		if ( $checkout ) {
			$other = 'checkout_card' === $context ? 'checkout_classic' : 'checkout_card';
			eur_expect( $result === eur_submit( $other ) && 2 === count( $mails ) && 1 === count( $order->statuses ), 'Gateway switch bypassed manual claim.' );
			$bridge = new class {
				public $status = '', $details, $redirect;
				public function set_status( $value ) { $this->status = $value; }
				public function set_payment_details( $value ) { $this->details = $value; }
				public function set_redirect_url( $value ) { $this->redirect = $value; }
			};
			jet_checkout_store_api_replay( (object) array( 'payment_method' => 'jetpayment', 'order' => $order ), $bridge );
			eur_expect( 'success' === $bridge->status && $result === $bridge->details && 2 === count( $mails ), 'Store API manual replay lost result.' );
		}
	}

	foreach ( array( false, null, array(), 'bad', ',', 'bank@example.test,', "\r\n", "\0", "bank@example.test\r\nBcc: other@example.test" ) as $invalid ) {
		manual_setup( $context, $blocks ); $options['jet_email'] = $invalid;
		eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ) && 0 === $orders_created && empty( $order->statuses ) && 40 === $options['jet_count'], 'Invalid nonempty/malformed recipient became manual registration.' );
		eur_expect( false === manual_record( $context ), 'Invalid recipient retained a claim.' );
		$options['jet_email'] = ''; manual_assert_complete( $context, eur_submit( $context ) );
	}
	foreach ( array( 'missing', 'manipulated', 'expired', 'non_eur', 'missing_consent' ) as $invalid ) {
		if ( 'missing_consent' === $invalid && 0 !== strpos( $context, 'checkout' ) ) { continue; }
		manual_setup( $context, $blocks ); $prefix = 'checkout_card' === $context ? 'jet_card_' : 'jet_';
		if ( 'missing' === $invalid ) { unset( $_POST[$prefix . 'offer'] ); }
		elseif ( 'manipulated' === $invalid ) { $_POST[$prefix . 'offer'] .= 'x'; }
		elseif ( 'expired' === $invalid ) {
			$payload = json_decode( base64_decode( strtr( explode( '.', $_POST[$prefix . 'offer'] )[0], '-_', '+/' ) ), true );
			$payload['expires'] = time() - 1;
			$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
			$_POST[$prefix . 'offer'] = $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
		}
		elseif ( 'missing_consent' === $invalid ) { unset( $_POST[$prefix . 'uslovia1'] ); }
		else { $effective_currency = 'BGN'; }
		eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ) && 0 === $orders_created && empty( $order->statuses ), 'Manual flow bypassed EUR/offer validation.' );
	}
	foreach ( array( 'admin-false', 'admin-throw', 'customer-throw' ) as $failure ) {
		manual_setup( $context, $blocks );
		$mail_behavior = function ( $mail ) use ( $failure ) {
			$target = 'shop@example.test' === $mail[0] ? 'admin' : 'customer';
			if ( $failure === $target . '-throw' ) { throw new RuntimeException( 'Synthetic notification failure.' ); }
			return $failure !== $target . '-false';
		};
		$result = eur_submit( $context ); manual_assert_complete( $context, $result );
		eur_expect( 2 === count( $mails ) && $result === eur_submit( $context ) && 2 === count( $mails ), 'Manual secondary failure enabled replay.' );
	}
}

foreach ( array( 'product', 'cart' ) as $context ) {
	manual_setup( $context, false, true ); manual_assert_complete( $context, eur_submit( $context ) );
	eur_expect( 1 === $orders_created && 2 === count( $mails ), 'Manual Product/Cart Card scheme failed.' );
	manual_setup( $context );
	WC()->payment_gateways = new class {
		public function get_available_payment_gateways() { return array( 'jetpayment' => (object) array( 'settings' => array( 'order_status' => 'wc-on-hold' ) ) ); }
	};
	manual_assert_complete( $context, eur_submit( $context ) );
	eur_expect( 'on-hold' === $saved_financial_order->props['status'] && 'jetpayment' === $saved_financial_order->props['payment_method'], 'Manual Product/Cart changed configured order status or gateway.' );
}

manual_setup( 'cart' ); $refreshes = 0;
$offline_cart_recalculate = function () use ( &$refreshes ) { if ( ++$refreshes > 1 ) { throw new RuntimeException( 'Synthetic post-claim refresh failure.' ); } };
$result = eur_submit( 'cart' );
eur_expect( 'manual_registration_failed' === ( $result['code'] ?? '' ) && empty( $mails ) && 0 === $orders_created, 'Pre-save manual error implied bank uncertainty.' );

// Storage failures hold the original claim; no successful result or second order is guessed.
foreach ( $manual_cases as list( $context, $blocks ) ) {
	foreach ( array( 'complete-false', 'complete-throw', 'all-false' ) as $failure ) {
		manual_setup( $context, $blocks );
		$offline_option_write = function ( $key, $value ) use ( $failure ) {
			$state = $value['state'] ?? $value['code'] ?? '';
			if ( 'all-false' !== $failure && 'manual_registered' !== $state ) { return null; }
			if ( 'complete-throw' === $failure ) { throw new RuntimeException( 'Synthetic claim storage failure.' ); }
			return false;
		};
		$result = eur_submit( $context ); $record = manual_record( $context );
		eur_expect( ! eur_success( $result, $context ) && empty( $mails ) && 40 === $options['jet_count'], 'Failed manual claim write fabricated completion or sent mail.' );
		eur_expect( in_array( $record['state'] ?? $record['code'] ?? '', array( 'processing', 'manual_processing', 'manual_registration_failed' ), true ), 'Failed manual storage lost claim or implied bank uncertainty.' );
		$before = $orders_created; unset( $offline_option_write ); eur_submit( $context );
		eur_expect( $before === $orders_created && empty( $mails ), 'Storage recovery replay duplicated a manual order.' );
	}
}

// Failure after persistence retains a manual-specific claim, never bank uncertainty.
foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	manual_setup( $context );
	if ( 0 === strpos( $context, 'checkout' ) ) {
		$offline_effect = function () { throw new RuntimeException( 'Synthetic status hook failure.' ); };
	} else { $offline_order_save_hook = function () { throw new RuntimeException( 'Synthetic save hook failure.' ); }; }
	$result = eur_submit( $context ); $record = manual_record( $context );
	eur_expect( ! eur_success( $result, $context ) && 'manual_registration_failed' === ( $record['state'] ?? $record['code'] ?? '' ) && empty( $mails ), 'Manual failure became bank uncertainty/success.' );
	$created = $orders_created; eur_submit( $context );
	eur_expect( $created === $orders_created && empty( $mails ), 'Manual failure replay repeated side effects.' );
}

// Native WC copying: discounted, taxable cart with shipping, fee, coupon and addon.
manual_setup( 'cart' ); $cart = WC()->cart;
$cart->items['item']['line_subtotal_tax'] = 200; $cart->items['item']['line_total'] = 900; $cart->items['item']['line_tax'] = 180;
$cart->items['item']['line_tax_data'] = array( 'subtotal' => array( 1 => 200 ), 'total' => array( 1 => 180 ) );
$cart->discount_total = 100; $cart->discount_tax = 20; $cart->shipping_total = 5; $cart->shipping_tax = 1;
$cart->fees = array( (object) array( 'name' => 'Synthetic fee', 'taxable' => true, 'tax_class' => '', 'amount' => 2, 'total' => 2, 'tax' => 0.4, 'tax_data' => array( 1 => 0.4 ) ) );
$cart->coupons = array( 'synthetic' => new JetOfflineCoupon( 100, 20 ) );
$cart->shipping_packages = array( array( 'rates' => array( 'flat_rate:1' => new JetOfflineRate() ) ) ); WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
$cart->total = 1088.4;
$offline_checkout_hook = function ( $hook, ...$args ) { if ( 'woocommerce_checkout_create_order_line_item' === $hook ) { $args[0]->add_meta_data( 'addon', 'engraving' ); } };
$_POST = eur_payload( 'cart' ); $source = jet_cart_financial_source();
manual_assert_complete( 'cart', eur_submit( 'cart' ) );
eur_expect( $source === jet_order_financial_source( $saved_financial_order ), 'Manual order differs from authoritative adjusted cart.' );
eur_expect( 100.0 === (float) $saved_financial_order->get_discount_total() && 5.0 === (float) $saved_financial_order->get_shipping_total() && 2.0 === (float) $saved_financial_order->get_total_fees(), 'Manual order lost monetary components.' );
eur_expect( array( 'synthetic' ) === $saved_financial_order->get_coupon_codes() && 'engraving' === $saved_financial_order->get_items()[0]->props['meta']['addon'], 'Manual order lost coupon/addon data.' );

// Variable product, quantity and fractional totals use the same native copy path.
manual_setup( 'cart' ); $cart = WC()->cart;
$cart->items['item']['data'] = wc_get_product( 77 ); $cart->items['item']['variation_id'] = 77; $cart->items['item']['quantity'] = 2;
$cart->items['item']['variation'] = array( 'attribute_size' => 'large' );
$cart->items['item']['line_subtotal'] = $cart->items['item']['line_total'] = $cart->total = 2400;
$_POST = eur_payload( 'cart' ); manual_assert_complete( 'cart', eur_submit( 'cart' ) );
$line = $saved_financial_order->get_items()[0];
eur_expect( 42 === $line->get_product_id() && 77 === $line->get_variation_id() && 2 === $line->get_quantity() && array( 'attribute_size' => 'large' ) === $line->get_variation(), 'Manual order lost variation/quantity.' );

echo 'PASS optional recipient: ' . ( $checks - $manual_start ) . " additional assertions; six UI paths, privacy, financial copying, manual failures and durable replay.\n";
