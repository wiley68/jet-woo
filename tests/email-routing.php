<?php
/**
 * JET-WOO-001 offline regression: php tests/email-routing.php.
 * Every mail, order, option and AJAX response is a local double. No WP bootstrap.
 * Frozen fixtures were captured before remediation from the recorded source HEAD.
 */
require __DIR__ . '/submission-bootstrap.php';

$baseline = json_decode( file_get_contents( __DIR__ . '/fixtures/bank-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
$checks = 0;
function routing_expect( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function routing_reset( $case ) {
	$_POST = $case['post'];
	$GLOBALS['records'] = $case['options'];
	$GLOBALS['product_title'] = $case['product_title'];
	$GLOBALS['admin_email'] = 'shop@example.test';
	$GLOBALS['scheduled'] = $GLOBALS['mails'] = $GLOBALS['order_events'] = array();
	$GLOBALS['mail_calls'] = $GLOBALS['order_calls'] = 0;
	$GLOBALS['mail_result'] = true;
	$GLOBALS['order_throws'] = $GLOBALS['order_saved'] = false;
	$GLOBALS['order_failure'] = '';
	$GLOBALS['during_mail'] = $GLOBALS['mail_behavior'] = null;
	$GLOBALS['wpdb']->fail = false;
	$GLOBALS['woocommerce']->cart = new JetTestCart();
}
function routing_submit( $context ) {
	$action = 'wp_ajax_nopriv_jet_send' . ( 'cart' === $context ? '_card' : '' );
	try { ($GLOBALS['actions'][ $action ])(); }
	catch ( JetTestResponse $response ) { return $response->response; }
	throw new RuntimeException( 'No AJAX response.' );
}
function routing_claims() {
	return array_filter( $GLOBALS['records'], function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ); }, ARRAY_FILTER_USE_KEY );
}
function routing_success( $context ) {
	routing_expect( 'success' === routing_submit( $context )['success'], "$context: submission failed." );
}
function routing_no_cc( $mail ) {
	foreach ( $mail[3] as $header ) {
		routing_expect( ! preg_match( '/^(?:Cc|Bcc):/i', $header ), 'Recipient escaped into a copy header.' );
	}
}
function routing_delivery_order( $context, $mail ) {
	if ( is_array( $mail[0] ) ) {
		routing_expect( 0 === $GLOBALS['order_calls'], 'Order created before bank accepted mail.' );
		routing_expect( 'processing' === array_values( routing_claims() )[0]['code'], 'Bank claim not held.' );
	} else {
		routing_expect( $GLOBALS['order_saved'], 'Secondary mail ran before order save.' );
		routing_expect( 'success' === array_values( routing_claims() )[0]['success'], 'Secondary mail ran before success was recorded.' );
		if ( 'cart' === $context ) {
			routing_expect( 2 === $GLOBALS['woocommerce']->cart->removals, 'Secondary mail ran before cart clearing.' );
		}
	}
}

// Optional independent provenance check against a read-only export of source_commit.
// This verifies fixtures; it never writes or regenerates them from remediated code.
if ( getenv( 'JET_TEST_SOURCE_ROOT' ) ) {
	foreach ( $baseline['source_sha256'] as $path => $hash ) {
		routing_expect( hash_file( 'sha256', $jet_test_source_root . '/' . $path ) === $hash, 'Original source hash mismatch: ' . $path );
	}
	foreach ( $baseline['cases'] as $name => $case ) {
		routing_reset( $case );
		$result = 'cart' === $case['context'] ? jet_process_cart_submission() : jet_process_product_submission();
		routing_expect( 'success' === $result['success'] && $mails === array( $case['mail'] ), 'Original fixture provenance mismatch: ' . $name );
	}
	echo "PASS baseline provenance: six original messages reproduced byte-for-byte, including original To/Cc; source SHA-256 verified.\n";
	exit;
}

// T01/T03/T04/T06: execute both real AJAX entrypoints against frozen original output.
foreach ( $baseline['cases'] as $name => $case ) {
	routing_reset( $case );
	$context = $case['context'];
	$mail_behavior = function ( $mail ) use ( $context ) { routing_delivery_order( $context, $mail ); return true; };
	routing_success( $context );
	$bank = $mails[0];
	$admin = $mails[1];
	$customer_present = isset( $case['post']['jet_email'] );
	routing_expect( count( $mails ) === ( $customer_present ? 3 : 2 ), "$name: incorrect message count." );
	routing_expect( $bank[0] === array( 'bank@example.test' ), "$name: bank recipient changed." );
	routing_expect( $bank[1] === $case['mail'][1], "$name: bank subject is not byte-identical." );
	routing_expect( $bank[2] === $case['mail'][2], "$name: bank body is not byte-identical." );
	routing_expect( $bank[3] === array_slice( $case['mail'][3], 0, 3 ), "$name: MIME/content/From headers changed." );
	routing_expect( ! preg_match( '/(?<!\r)\n|\r(?!\n)/', $bank[2] ), "$name: lost CRLF separators." );
	routing_expect( 1 === preg_match( '//u', $bank[2] ), "$name: invalid UTF-8." );
	routing_expect( false !== strpos( $bank[2], ";\r\n\r\n" ), "$name: lost semicolon/blank row." );
	foreach ( $mails as $mail ) { routing_no_cc( $mail ); }
	routing_expect( 'shop@example.test' === $admin[0] && $admin[1] === $bank[1], "$name: incorrect admin delivery." );
	$expected = explode( "\r\n", $case['mail'][2] );
	$masked = array( 'Собствено име: ********;', 'Фамилия: ********;', 'ЕГН: **********;', 'Телефон за връзка: **********;', 'Имейл адрес: ********;' );
	array_splice( $expected, 1, 5, $masked );
	routing_expect( $admin[2] === implode( "\r\n", $expected ), "$name: admin changed more than five personal rows." );
	routing_expect( $admin[3] === $bank[3], "$name: admin content headers changed." );
	if ( $customer_present ) {
		$customer = $mails[2];
		routing_expect( 'fixture@example.test' === $customer[0], "$name: wrong customer recipient." );
		routing_expect( 'Потвърждение за подадена заявка за кредит' === $customer[1], "$name: customer subject changed." );
		$customer_body = "Здравейте,\r\n\r\nВашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.\r\n\r\nТова съобщение потвърждава подаването на заявката, но не означава, че кредитът е одобрен.\r\n\r\nБлагодарим Ви!";
		routing_expect( $customer_body === $customer[2], "$name: customer notice or non-approval wording changed." );
		foreach ( array( 'jet_name', 'jet_lastname', 'jet_egn', 'jet_phone', 'jet_email' ) as $field ) {
			foreach ( array( $case['post'][ $field ], jet_sanitize_bank_email_field( filter_var( $case['post'][ $field ], FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ) as $value ) {
				routing_expect( false === strpos( $admin[2], $value ), "$name: admin exposes $field." );
				routing_expect( false === strpos( $customer[2], $value ), "$name: customer notice exposes $field." );
			}
		}
		foreach ( array( 'Данни за потребителя:', 'Данни за стоката:', 'Данни за кредита:', $bank[2] ) as $value ) {
			routing_expect( false === strpos( $customer[2], $value ), "$name: customer received bank template." );
		}
	}
	$events = array_column( $order_events, 0 );
	routing_expect( in_array( 'set_status', $events, true ) && in_array( array( 'set_status', array( 'on-hold' ) ), $order_events, true ), "$name: configured order status changed." );
	routing_expect( in_array( array( 'set_payment_method', array( 'jetpayment' ) ), $order_events, true ), "$name: gateway changed." );
	routing_expect( count( array_filter( $events, function ( $event ) { return 'add_product' === $event; } ) ) === ( 'cart' === $context ? 2 : 1 ), "$name: order products changed." );
	echo "PASS T01/T03/T04/T06 $name: byte-identical bank subject/body/headers, five-row mask, private confirmation.\n";
}

foreach ( array( 'product', 'cart' ) as $context ) {
	$case = $baseline['cases'][ $context . '-standard' ];
	// T02/T07: public email is one validated address, never part of bank destinations.
	foreach ( array( 'single@example.test', "o'connor@example.test", 'not-an-email', 'one@example.test,two@example.test', 'one@example.test;two@example.test', "one@example.test\r\nBcc: extra@example.test", "one@example.test\r\n", "one@example.test\0", 'Name <one@example.test>', '' ) as $email ) {
		routing_reset( $case );
		$_POST['jet_email'] = $email;
		$records['jet_email'] = 'first-bank@example.test, second-bank@example.test';
		routing_success( $context );
		$valid = in_array( $email, array( 'single@example.test', "o'connor@example.test" ), true );
		routing_expect( count( $mails ) === ( $valid ? 3 : 2 ), "$context: invalid customer accepted or valid customer rejected." );
		routing_expect( $mails[0][0] === array( 'first-bank@example.test', 'second-bank@example.test' ), "$context: public input expanded bank recipients." );
		if ( $valid ) { routing_expect( $mails[2][0] === $email, "$context: customer address was altered." ); }
		foreach ( $mails as $mail ) { routing_no_cc( $mail ); }
	}
	foreach ( array( false, '', 'invalid', array( 'bank@example.test' ), 'bank@example.test, invalid', 'bank@example.test,', "bank@example.test\r\nBcc: other@example.test", "bank@example.test\r\n" ) as $bank_email ) {
		routing_reset( $case );
		$records['jet_email'] = $bank_email;
		routing_expect( 'invalid_bank_recipient' === routing_submit( $context )['code'], "$context: invalid bank configuration accepted." );
		routing_expect( 0 === $mail_calls && 0 === $order_calls && 40 === $records['jet_count'], "$context: invalid bank fell back or caused side effects." );
		routing_expect( empty( routing_claims() ), "$context: pre-mail rejection retained claim." );
		$records['jet_email'] = 'fixed-bank@example.test';
		routing_success( $context );
		routing_expect( $mails[0][0] === array( 'fixed-bank@example.test' ), "$context: safe retry used fallback recipient." );
	}
	// Masking is confined to five rows even when a personal value appears in a product.
	routing_reset( $case );
	$_POST['jet_name'] = $product_title = 'RepeatedSyntheticValue';
	routing_success( $context );
	routing_expect( false !== strpos( $mails[1][2], 'RepeatedSyntheticValue' ), "$context: global PII replacement altered product data." );
	routing_expect( explode( "\r\n", $mails[1][2] )[1] === 'Собствено име: ********;', "$context: personal row not masked." );
	routing_expect( '' === jet_submission_admin_body( "Unexpected\r\n" . $mails[0][2] ), 'Unexpected template was not rejected.' );
	echo "PASS T02/T07 $context: single/list recipients, malformed/missing bank configuration, customer injection, limited masking.\n";

	// T05: each secondary failure remains successful, independent and replay-safe.
	foreach ( array( 'admin-false', 'customer-false', 'admin-exception', 'customer-exception', 'both-exception' ) as $failure ) {
		routing_reset( $case );
		$mail_behavior = function ( $mail ) use ( $context, $failure ) {
			routing_delivery_order( $context, $mail );
			if ( is_array( $mail[0] ) ) { return true; }
			$is_admin = 'shop@example.test' === $mail[0];
			$affected = 'both-exception' === $failure || 0 === strpos( $failure, $is_admin ? 'admin-' : 'customer-' );
			if ( $affected && false !== strpos( $failure, 'exception' ) ) { throw new RuntimeException( 'Simulated secondary mail hook exception.' ); }
			return ! $affected;
		};
		routing_success( $context );
		routing_expect( 3 === $mail_calls && 1 === $order_calls, "$context/$failure: other notification was not attempted." );
		$_POST['jet_email'] = 'edited@example.test';
		routing_success( $context );
		routing_expect( 3 === $mail_calls && 1 === $order_calls, "$context/$failure: replay duplicated delivery/order." );
		routing_expect( array_values( routing_claims() )[0] === array( 'success' => 'success' ), "$context/$failure: claim changed or stored PII." );
	}
	// Bank refusal is safely retryable. No order or success notification before acceptance.
	routing_reset( $case );
	$mail_result = false;
	routing_expect( 'unsuccess' === routing_submit( $context )['success'], "$context: bank refusal accepted." );
	routing_expect( 1 === $mail_calls && 0 === $order_calls && empty( routing_claims() ), "$context: bank refusal created order/notices or retained claim." );
	routing_expect( 0 === $woocommerce->cart->removals, "$context: bank refusal cleared cart." );
	$mail_result = true;
	routing_success( $context );
	routing_expect( 4 === $mail_calls && 1 === $order_calls, "$context: safe bank retry failed." );

	// Unknown delivery and failures after acceptance retain the claim and suppress notices.
	foreach ( array( 'bank-exception', 'order', 'save', 'cart' ) as $failure ) {
		if ( 'cart' === $failure && 'cart' !== $context ) { continue; }
		routing_reset( $case );
		if ( 'bank-exception' === $failure ) { $mail_behavior = function () { throw new RuntimeException( 'Unknown bank delivery.' ); }; }
		elseif ( 'order' === $failure ) { $order_throws = true; }
		else { $order_failure = $failure; }
		routing_expect( 'uncertain' === routing_submit( $context )['code'], "$context/$failure: failure not retained." );
		routing_expect( 1 === $mail_calls, "$context/$failure: premature secondary notice." );
		routing_expect( 'uncertain' === routing_submit( $context )['code'] && 1 === $mail_calls, "$context/$failure: retry resent bank application." );
	}

	// Overlapping requests: bank sees processing; secondary mail sees recorded success.
	routing_reset( $case );
	$mail_behavior = function ( $mail ) use ( $context ) {
		routing_delivery_order( $context, $mail );
		$replay = routing_submit( $context );
		if ( is_array( $mail[0] ) ) { routing_expect( 'processing' === $replay['code'], "$context: concurrent bank request was not blocked." ); }
		else { routing_expect( 'success' === $replay['success'], "$context: concurrent secondary request lost success." ); }
		return true;
	};
	routing_success( $context );
	routing_expect( 3 === $mail_calls && 1 === $order_calls && 1 === count( $scheduled ), "$context: overlap duplicated side effects." );
	if ( 'cart' === $context ) { routing_expect( 2 === $woocommerce->cart->removals, 'Overlap cleared cart twice.' ); }
	echo "PASS T05 $context: false/exception isolation, safe bank retry, uncertain post-bank failures, duplicate and overlapping AJAX requests.\n";
}
echo "PASS: $checks assertions; all mail/database/order effects stubbed; no external delivery.\n";
