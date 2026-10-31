<?php
/** JET-WOO-001 C01-C06: offline execution of both real checkout gateways. */
require __DIR__ . '/checkout-bootstrap.php';
$baseline = json_decode( file_get_contents( __DIR__ . '/fixtures/checkout-bank-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
$checks = 0;
function checkout_expect( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function checkout_record( $id = 314 ) { return get_option( 'jet_checkout_submission_' . $id ); }
function checkout_submit( $gateway, $id = 314 ) { return $gateway->process_payment( $id ); }
function checkout_ok( $gateway, $id = 314 ) {
	$result = checkout_submit( $gateway, $id );
	checkout_expect( 'success' === $result['result'], 'Checkout did not succeed.' );
	return $result;
}
function checkout_failed( $gateway, $id = 314 ) {
	$result = checkout_submit( $gateway, $id );
	checkout_expect( 'failure' === $result['result'] && ! isset( $result['redirect'] ), 'Checkout incorrectly reported success/redirect.' );
	checkout_expect( ! empty( $GLOBALS['checkout_notices'] ), 'Failed checkout supplied no error notice.' );
}
function checkout_mail_order( $mail ) {
	$order = $GLOBALS['checkout_orders'][314];
	if ( is_array( $mail[0] ) ) {
		checkout_expect( array( 'state' => 'processing' ) === checkout_record(), 'No held claim before bank mail.' );
		checkout_expect( empty( $order->statuses ) && 0 === $GLOBALS['checkout_wc']->cart->empties, 'Status/cart changed before bank acceptance.' );
	} else {
		checkout_expect( array( 'state' => 'success' ) === checkout_record(), 'Secondary mail ran before durable success.' );
		checkout_expect( 1 === count( $order->statuses ) && 1 === $GLOBALS['checkout_wc']->cart->empties, 'Secondary mail ran before primary completion.' );
	}
}
function checkout_no_copies( $mail ) {
	foreach ( $mail[3] as $header ) { checkout_expect( ! preg_match( '/^(?:Cc|Bcc):/i', $header ), 'Mail exposes a copy recipient.' ); }
}

// Independently reproduce fixtures with an export of the recorded original commit.
if ( getenv( 'JET_TEST_SOURCE_ROOT' ) ) {
	foreach ( $baseline['source_sha256'] as $path => $hash ) {
		checkout_expect( hash_file( 'sha256', $jet_test_source_root . '/' . $path ) === $hash, 'Original source hash mismatch: ' . $path );
	}
	$warnings = array();
	set_error_handler( function ( $severity, $message, $file, $line ) use ( &$warnings ) {
		if ( E_WARNING === $severity && 'Undefined variable $jet_email' === $message && 'class-card-jet-payment-gateway.php' === basename( $file ) ) {
			$warnings[] = $message;
			return true;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	} );
	foreach ( $baseline['cases'] as $name => $case ) {
		$gateway = checkout_reset( $case );
		checkout_expect( checkout_submit( $gateway ) === $case['result'], 'Original checkout result changed: ' . $name );
		checkout_expect( $mails === array( $case['mail'] ), 'Original fixture provenance mismatch: ' . $name );
	}
	checkout_expect( count( $warnings ) === $baseline['known_original_warning_count'], 'Original card Cc warning count changed.' );
	echo "PASS original checkout provenance: eight original messages reproduced, source SHA-256 verified, original card Cc warning recorded.\n";
	exit;
}

set_error_handler( function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

// C01-C04: EUR equivalents of historical product/data branches. BGN/session-only originals are explicitly rejected below.
foreach ( $baseline['cases'] as $name => $case ) {
	$gateway = checkout_reset( $case );
	$original = jet_test_original_bank_mail( 'checkout_classic', $gateway );
	if ( false !== strpos( $name, '-eur-' ) ) {
		checkout_expect( $original[1] === $case['mail'][1] && $original[2] === $case['mail'][2] && $original[3] === array_slice( $case['mail'][3], 0, 3 ), "$name: frozen EUR golden differs from v1.9.3 EUR template." );
	}
	$gateway = checkout_reset( $case );
	$mail_behavior = function ( $mail ) { checkout_mail_order( $mail ); return true; };
	checkout_expect( checkout_ok( $gateway ) === $case['result'], "$name: successful redirect changed." );
	checkout_expect( 3 === count( $mails ), "$name: expected three independent messages." );
	$bank = $mails[0]; $admin = $mails[1]; $customer = $mails[2];
	checkout_expect( array( 'bank@example.test' ) === $bank[0], "$name: wrong bank recipient." );
	checkout_expect( $bank[1] === $original[1], "$name: bank subject not byte-identical." );
	checkout_expect( $bank[2] === $original[2], "$name: bank body not byte-identical." );
	checkout_expect( $bank[3] === $original[3], "$name: MIME/Content-type/From changed." );
	checkout_expect( 1 === preg_match( '//u', $bank[2] ) && ! preg_match( '/(?<!\r)\n|\r(?!\n)/', $bank[2] ), "$name: UTF-8/CRLF damaged." );
	checkout_expect( false !== strpos( $bank[2], ";\r\n\r\n" ), "$name: semicolon/empty row damaged." );
	foreach ( $mails as $mail ) { checkout_no_copies( $mail ); }
	$expected = explode( "\r\n", $original[2] );
	array_splice( $expected, 1, 5, array( 'Собствено име: ********;', 'Фамилия: ********;', 'ЕГН: **********;', 'Телефон за връзка: **********;', 'Имейл адрес: ********;' ) );
	checkout_expect( $admin[0] === 'shop@example.test' && $admin[1] === $bank[1] && $admin[2] === implode( "\r\n", $expected ) && $admin[3] === $bank[3], "$name: incorrect administrator copy." );
	$confirmation = "Здравейте,\r\n\r\nВашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.\r\n\r\nТова съобщение потвърждава подаването на заявката, но не означава, че кредитът е одобрен.\r\n\r\nБлагодарим Ви!";
	checkout_expect( 'applicant@example.test' === $customer[0] && 'Потвърждение за подадена заявка за кредит' === $customer[1] && $customer[2] === $confirmation, "$name: customer received more than the approved confirmation." );
	$personal = array_merge( array_values( $case['order_fields'] ), array( '0000000000' ) );
	foreach ( $personal as $value ) {
		checkout_expect( false === strpos( $admin[2], $value ) && false === strpos( $admin[2], jet_sanitize_bank_email_field( $value ) ), "$name: administrator exposes personal data." );
		checkout_expect( false === strpos( $customer[2], $value ) && false === strpos( $customer[2], jet_sanitize_bank_email_field( $value ) ), "$name: customer notice exposes personal data." );
	}
	foreach ( $case['order_fields'] as $field => $value ) {
		if ( preg_match( '/(?:city|address|postcode|shipping)/', $field ) ) {
			checkout_expect( false === strpos( $bank[2], $value ), "$name: original template unexpectedly includes an address/shipping value requiring additional masking." );
		}
	}
	checkout_expect( $checkout_orders[314]->statuses === $case['statuses'], "$name: configured status/note changed." );
	checkout_expect( $gateway->id === ( 'card' === $case['method'] ? 'jetpaymentcard' : 'jetpayment' ), "$name: gateway ID changed." );
	checkout_expect( 1 === $checkout_wc->cart->empties && 0 === $order_calls, "$name: existing-order/cart flow changed." );
	checkout_expect( $records['jet_count'] === 41 && checkout_record() === array( 'state' => 'success' ), "$name: counter or completed state incorrect." );
	checkout_expect( empty( $scheduled ), "$name: checkout scheduled new background work." );
	checkout_expect( checkout_ok( $gateway ) === $case['result'], "$name: replay redirect changed." );
	checkout_expect( 3 === $mail_calls && 1 === $checkout_wc->cart->empties && 1 === count( $checkout_orders[314]->statuses ) && 41 === $records['jet_count'], "$name: replay repeated primary or secondary effects." );
	echo "PASS C01-C04/C06 EUR/request equivalent of $name: original bytes/headers, limited masking, confirmation, status/redirect/cart, replay.\n";
}
foreach ( array( 'standard', 'card' ) as $method ) {
	$case = $baseline['cases'][$method . '-bgn-normal'];
	$prefix = 'card' === $method ? 'jet_card_' : 'jet_';
	foreach ( array( 'unsigned', 'expired', 'manipulated', 'legacy-session', 'non-eur', 'non-eur-order', 'stale-order', 'changed-scheme' ) as $invalid ) {
		$gateway = checkout_reset( $case );
		if ( 'unsigned' === $invalid ) { unset( $_POST[$prefix . 'offer'] ); }
		if ( 'expired' === $invalid ) { $_POST[$prefix . 'offer'] = jet_test_expired_offer( $_POST[$prefix . 'offer'] ); }
		if ( 'manipulated' === $invalid ) { $_POST[$prefix . 'offer'] .= 'x'; }
		if ( 'legacy-session' === $invalid ) {
			$session_case = $baseline['cases'][$method . '-eur-session'];
			$checkout_wc->session = new JetCheckoutTestSession( $session_case['session'] );
			$_POST = $session_case['post'];
		}
		if ( 'non-eur-order' === $invalid ) { $checkout_orders[314]->currency = 'BGN'; }
		if ( 'stale-order' === $invalid ) { $checkout_orders[314]->total++; }
		if ( 'changed-scheme' === $invalid ) {
			$wrong = jet_create_verified_offer( 'checkout_classic', array(), 50.10, 12, 'card' !== $method );
			$_POST[$prefix . 'offer'] = $wrong;
		}
		if ( 'non-eur' === $invalid ) { $records['woocommerce_currency'] = $test_currency = 'BGN'; }
		$reasons = array( 'unsigned' => 'invalid_offer', 'expired' => 'stale_offer', 'manipulated' => 'invalid_offer', 'legacy-session' => 'invalid_offer_context', 'non-eur' => 'non_eur_currency', 'non-eur-order' => 'non_eur_order_currency', 'stale-order' => 'stale_offer', 'changed-scheme' => 'stale_offer' );
		try {
			jet_validate_submission_financing( $_POST, 'card' === $method ? 'checkout_card' : 'checkout_classic', $checkout_orders[314] );
			throw new RuntimeException( 'Invalid checkout financing unexpectedly validated.' );
		} catch ( Jet_Financial_Validation_Exception $error ) {
			checkout_expect( $reasons[$invalid] === $error->getMessage(), "$method/$invalid: rejected for the wrong reason: " . $error->getMessage() );
		}
		checkout_failed( $gateway );
		checkout_expect( 0 === $mail_calls && empty( $checkout_orders[314]->statuses ) && 0 === $checkout_wc->cart->empties && false === checkout_record(), "$method/$invalid: invalid offer left delivery/order/uncertain state." );
	}
	// C02/C04/C05: raw applicant input is validated, never sanitized into a new recipient.
	foreach ( array( 'changed@example.test', "o'connor@example.test", 'bad-address', '', 'one@example.test,two@example.test', 'one@example.test;two@example.test', "one@example.test\r\nBcc: other@example.test", "one@example.test\r\n", "one@example.test\0", array( 'one@example.test' ), 'Name <one@example.test>' ) as $email ) {
		$gateway = checkout_reset( $case );
		$_POST['billing_email'] = $email;
		$records['jet_email'] = 'first-bank@example.test, second-bank@example.test';
		checkout_ok( $gateway );
		$valid = in_array( $email, array( 'changed@example.test', "o'connor@example.test" ), true );
		checkout_expect( $mails[0][0] === array( 'first-bank@example.test', 'second-bank@example.test' ), "$method: customer expanded bank list." );
		checkout_expect( count( $mails ) === ( $valid ? 3 : 2 ), "$method: invalid raw applicant address accepted." );
		if ( $valid ) { checkout_expect( $mails[2][0] === $email, "$method: raw applicant recipient altered." ); }
		foreach ( $mails as $mail ) { checkout_no_copies( $mail ); }
	}
	$gateway = checkout_reset( $case );
	unset( $_POST['billing_email'] );
	checkout_ok( $gateway );
	checkout_expect( 'applicant@example.test' === $mails[2][0], "$method: order-email fallback failed." );
	// Invalid order-email fallback must also be rejected.
	$gateway = checkout_reset( $case );
	unset( $_POST['billing_email'] );
	$checkout_orders[314]->fields['billing_email'] = 'first@example.test,second@example.test';
	checkout_ok( $gateway );
	checkout_expect( 2 === $mail_calls, "$method: invalid order-email fallback accepted." );
	foreach ( array( false, 'bad', array( 'bank@example.test' ), 'bank@example.test,', 'bank@example.test, invalid', "bank@example.test\r\nBcc: other@example.test" ) as $configured ) {
		$gateway = checkout_reset( $case );
		$records['jet_email'] = $configured;
		checkout_failed( $gateway );
		checkout_expect( 0 === $mail_calls && empty( $checkout_orders[314]->statuses ) && 0 === $checkout_wc->cart->empties && 40 === $records['jet_count'] && false === checkout_record(), "$method: invalid bank caused fallback/side effects or blocked safe retry." );
		$records['jet_email'] = 'fixed-bank@example.test';
		checkout_ok( $gateway );
	}
	// C05/C06: definite bank failure permits retry; uncertainty retains the gate.
	$gateway = checkout_reset( $case );
	$mail_result = false;
	checkout_failed( $gateway );
	checkout_expect( 1 === $mail_calls && empty( $checkout_orders[314]->statuses ) && 0 === $checkout_wc->cart->empties && false === checkout_record(), "$method: bank refusal changed status/cart or sent notices." );
	$mail_result = true;
	checkout_ok( $gateway );
	checkout_expect( 4 === $mail_calls && 1 === $checkout_wc->cart->empties, "$method: safe bank retry failed." );
	foreach ( array( 'bank', 'status', 'cart', 'redirect' ) as $failure ) {
		$gateway = checkout_reset( $case );
		if ( 'bank' === $failure ) { $mail_behavior = function () { throw new RuntimeException( 'Synthetic unknown bank delivery.' ); }; }
		else { $checkout_failure = $failure; }
		checkout_failed( $gateway );
		checkout_expect( 1 === $mail_calls && checkout_record() === array( 'state' => 'uncertain' ), "$method/$failure: bank uncertainty not held or secondary sent prematurely." );
		$checkout_failure = ''; $mail_behavior = null;
		checkout_failed( $gateway );
		checkout_expect( 1 === $mail_calls, "$method/$failure: retry resent bank mail." );
	}
	foreach ( array( 'admin-false', 'customer-false', 'admin-throw', 'customer-throw', 'both-throw' ) as $failure ) {
		$gateway = checkout_reset( $case );
		$mail_behavior = function ( $mail ) use ( $failure ) {
			checkout_mail_order( $mail );
			if ( is_array( $mail[0] ) ) { return true; }
			$affected = 'both-throw' === $failure || 0 === strpos( $failure, 'shop@example.test' === $mail[0] ? 'admin-' : 'customer-' );
			if ( $affected && false !== strpos( $failure, 'throw' ) ) { throw new RuntimeException( 'Synthetic secondary failure.' ); }
			return ! $affected;
		};
		checkout_expect( checkout_ok( $gateway ) === $case['result'], "$method/$failure: secondary failure changed success." );
		checkout_ok( $gateway );
		checkout_expect( 3 === $mail_calls && 1 === $checkout_wc->cart->empties && checkout_record() === array( 'state' => 'success' ), "$method/$failure: replay duplicated bank/order effects or lost success." );
	}
	// A failed durable-state write after bank acceptance must retain the original gate.
	foreach ( array( 'bank_sent-false', 'success-false', 'all-false', 'bank_sent-throw', 'success-throw' ) as $failure ) {
		$gateway = checkout_reset( $case );
		$test_option_write = function ( $key, $value ) use ( $failure ) {
			if ( 0 !== strpos( $key, 'jet_checkout_submission_' ) ) { return null; }
			if ( 'all-false' === $failure || 0 === strpos( $failure, $value['state'] . '-' ) ) {
				if ( false !== strpos( $failure, 'throw' ) ) { throw new RuntimeException( 'Synthetic option write hook exception.' ); }
				return false;
			}
			return null;
		};
		checkout_failed( $gateway );
		checkout_expect( 1 === $mail_calls && is_array( checkout_record() ) && in_array( checkout_record()['state'], array( 'processing', 'uncertain' ), true ), "$method/$failure: lost the durable bank-delivery gate." );
		unset( $test_option_write );
		checkout_failed( $gateway );
		checkout_expect( 1 === $mail_calls, "$method/$failure: storage recovery resent an uncertain bank application." );
	}
	// Personal values coinciding with product values must not be replaced globally.
	$gateway = checkout_reset( $case );
	$product_title = $checkout_orders[314]->fields['billing_first_name'] = 'RepeatedSyntheticValue';
	checkout_ok( $gateway );
	checkout_expect( false !== strpos( $mails[1][2], 'RepeatedSyntheticValue' ) && explode( "\r\n", $mails[1][2] )[1] === 'Собствено име: ********;', "$method: masking altered product data or exposed the personal row." );
	foreach ( array( 'wc-processing', 'pending', 'wc-custom-review' ) as $configured_status ) {
		$gateway = checkout_reset( $case );
		$gateway->order_status = $configured_status;
		checkout_ok( $gateway );
		checkout_expect( $checkout_orders[314]->statuses[0][0] === preg_replace( '/^wc-/', '', $configured_status ), "$method: configured order status changed." );
	}
	// Cross-gateway retry is the same order identity, including session loss and edits.
	$gateway = checkout_reset( $case );
	checkout_ok( $gateway );
	$other_gateway = 'card' === $method ? new Jet_Payment_Gateway() : new Jet_Card_Payment_Gateway();
	$_POST = array(); $checkout_wc->session = new JetCheckoutTestSession();
	$records['jet_email'] = '';
	checkout_expect( checkout_ok( $other_gateway ) === $case['result'] && 3 === $mail_calls, "$method: gateway switch/config/session change bypassed order identity." );
	// A different order is a different deliberate application.
	$gateway = checkout_reset( $case );
	checkout_ok( $gateway );
	$checkout_orders[315] = new JetCheckoutTestOrder( 315, $case['order_fields'] );
	checkout_ok( $gateway, 315 );
	checkout_expect( 6 === $mail_calls && checkout_record(315) === array( 'state' => 'success' ), "$method: new order incorrectly blocked." );
	// Storage failures, malformed records and invalid orders fail closed.
	$gateway = checkout_reset( $case );
	$wpdb->fail = true;
	checkout_failed( $gateway );
	checkout_expect( 0 === $mail_calls && empty( $checkout_orders[314]->statuses ), "$method: claim storage failure was not closed." );
	$wpdb->fail = false;
	foreach ( array( 'processing', 'bank_sent', 'uncertain', 'unknown' ) as $state ) {
		update_option( 'jet_checkout_submission_314', array( 'state' => $state ), false );
		checkout_failed( $gateway );
	}
	$records['jet_checkout_submission_314'] = 'malformed';
	checkout_failed( $gateway );
	checkout_failed( $gateway, 999 );
	checkout_expect( 0 === $mail_calls, "$method: malformed/held state or missing order bypassed claim." );
	// A redirect error on completed replay does not overwrite success or resend.
	$gateway = checkout_reset( $case ); checkout_ok( $gateway );
	$checkout_failure = 'redirect'; checkout_failed( $gateway );
	checkout_expect( checkout_record() === array( 'state' => 'success' ) && 3 === $mail_calls, "$method: replay redirect exception lost completion." );
	echo "PASS C02/C04-C06 $method: strict recipients, bank/secondary failures, held uncertainty, repeat/cross-gateway retry and storage failure.\n";
}
echo "PASS checkout: $checks assertions; all mail/order/options/session effects offline.\n";
