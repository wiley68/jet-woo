<?php
/** Run: php tests/eur-backend.php. All effects are intercepted by eur-bootstrap.php. */
require __DIR__ . '/eur-bootstrap.php';
$checks = 0;
function eur_expect( $condition, $label ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $label ); }
}
function eur_reject( $callback, $reason = null ) {
	try { $callback(); } catch ( Jet_Financial_Validation_Exception $error ) {
		eur_expect( null === $reason || $reason === $error->getMessage(), 'Unexpected rejection: ' . $error->getMessage() );
		return;
	}
	throw new RuntimeException( 'Invalid input was accepted.' );
}
function eur_ajax( $callback ) {
	try { $callback(); } catch ( JetEurResponse $response ) { return $response->value; }
	throw new RuntimeException( 'Missing AJAX response.' );
}
function eur_payload( $context, $scheme = false ) {
	$checkout = 0 === strpos( $context, 'checkout' );
	$card = 'checkout_card' === $context || ( ! $checkout && $scheme );
	$source = 'product' === $context ? jet_product_financial_source( 42, 0, 1 ) : jet_cart_financial_source();
	$calculation = jet_calculate_source_financing( $source, 0, 12, $card, $context );
	$data = array( 'security' => 'valid', 'jet_lname' => '', 'jet_submission_id' => str_repeat( 'a', 32 ), 'jet_product_id' => '42', 'jet_variation_id' => '0', 'jet_quantity' => '1', 'jet_card' => $card ? '1' : '0', 'jet_name' => 'Synthetic', 'jet_lastname' => 'Applicant', 'jet_egn' => 'synthetic', 'jet_phone' => 'synthetic', 'jet_email' => 'synthetic@example.test' );
	foreach ( $calculation as $key => $value ) {
		$key = $checkout && $card ? str_replace( 'jet_', 'jet_card_', $key ) : $key;
		$data[ $key . ( $checkout ? '_input' : '' ) ] = 'jet_vnoski' === str_replace( 'jet_card_', 'jet_', $key ) ? (string) $value : number_format( $value, 2, '.', '' );
	}
	foreach ( jet_financial_source_lists( $source ) as $key => $value ) { $data[ $checkout && $card ? str_replace( 'jet_', 'jet_card_', $key ) : $key ] = $value; }
	$prefix = $checkout && $card ? 'jet_card_' : 'jet_';
	$data[$prefix . 'context'] = $checkout ? 'checkout_classic' : $context;
	$data[$prefix . 'offer'] = jet_create_verified_offer( $data[$prefix . 'context'], array( 'product_id' => 42, 'variation_id' => 0, 'quantity' => 1 ), 0, 12, $card, $checkout ? '' : $data['jet_submission_id'] );
	if ( $checkout ) { $data[$prefix . 'uslovia'] = $data[$prefix . 'uslovia1'] = '1'; $data[$prefix . 'egn'] = 'synthetic'; }
	return $data;
}
function eur_submit( $context ) {
	if ( 'product' === $context || 'cart' === $context ) { return eur_ajax( 'product' === $context ? 'jet_send' : 'jet_send_card' ); }
	$gateway = 'checkout_card' === $context ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
	return $gateway->process_payment( 314 );
}
function eur_success( $result, $context ) { return ( 0 === strpos( $context, 'checkout' ) ? ( $result['result'] ?? '' ) : ( $result['success'] ?? '' ) ) === 'success'; }

eur_expect( jet_financial_rules() === array( 'currency' => 'EUR', 'min_principal' => 75.0, 'initial_term_threshold' => 125.0, 'extended_term_threshold' => 201.0, 'long_term_threshold' => 301.0, 'min_installment' => 10.0 ), 'Approved rules changed.' );
foreach ( array( 74.99, 75.0, 75.01 ) as $principal ) {
	if ( $principal < 75 ) { eur_reject( function () use ( $principal ) { jet_financial_calculation( $principal, 0, 3, 0 ); }, 'principal_below_minimum' ); }
	else { eur_expect( jet_financial_calculation( $principal, 0, 3, 0 )['jet_total_credit_price'] === $principal, 'Principal boundary.' ); }
}
foreach ( array( array( 200.99, 15, false ), array( 201, 15, true ), array( 201, 18, true ), array( 201, 24, true ), array( 300.99, 30, false ), array( 301, 30, true ), array( 301, 36, true ) ) as $case ) {
	list( $amount, $term, $accepted ) = $case;
	if ( $accepted ) { eur_expect( jet_financial_calculation( $amount, 0, $term, 1.4 )['jet_vnoski'] === $term, 'Term boundary.' ); }
	else { eur_reject( function () use ( $amount, $term ) { jet_financial_calculation( $amount, 0, $term, 1.4 ); }, 'invalid_financing_term' ); }
}
eur_expect( 9 === jet_initial_financing_term( 124.99, 12 ) && 12 === jet_initial_financing_term( 125, 12 ) && 12 === jet_initial_financing_term( 125.01, 12 ), 'Initial-term boundary.' );
eur_expect( in_array( 36, jet_allowed_financing_terms( 1000 ), true ), 'Term rules must use total price, not principal.' );
eur_reject( function () { jet_financial_calculation( 89.95, 0, 9, 0 ); }, 'installment_below_minimum' );
eur_expect( '10.00' === number_format( jet_financial_calculation( 89.96, 0, 9, 0 )['jet_vnoska'], 2, '.', '' ), 'Displayed installment boundary.' );
eur_expect( 10.0 === jet_financial_calculation( 90, 0, 9, 0 )['jet_vnoska'], 'Exact installment boundary.' );
eur_expect( '75.00' === number_format( jet_financial_calculation( 150.01, 75.01, 3, 0 )['jet_total_credit_price'], 2, '.', '' ), 'Binary floating-point error at principal boundary.' );
eur_reject( function () { jet_financial_calculation( 1000, 0.001, 12, 0 ); }, 'invalid_money_precision' );
foreach ( array( INF, NAN, 'NaN', 'INF', '1,25', '1e3', '', array(), true, null, str_repeat( '9', 400 ) ) as $value ) { eur_reject( function () use ( $value ) { jet_financial_number( $value ); } ); }
foreach ( array( 0, -1, '1.0', '1e2', array() ) as $value ) { eur_reject( function () use ( $value ) { jet_financial_positive_integer( $value ); } ); }
foreach ( array( array( 1000, -1, 12, 1.4 ), array( 1000, 1000, 12, 1.4 ), array( 1000, 0, 0, 1.4 ), array( 1000, 0, 5, 1.4 ), array( 1000, 0, 12, INF ) ) as $args ) { eur_reject( function () use ( $args ) { jet_financial_calculation( ...$args ); } ); }
$zero = jet_financial_calculation( 1000, 0, 12, 0 );
eur_expect( 0.0 === $zero['jet_glp'] && 0.0 === $zero['jet_gpr'] && 1000.0 === $zero['jet_obshto'], 'Zero-interest convention.' );
$normal = jet_financial_calculation( 1000, 0, 12, 1.4 );
eur_expect( '97.33' === number_format( $normal['jet_vnoska'], 2, '.', '' ) && 1168.0 === $normal['jet_obshto'], 'Bank formula changed.' );
$options['jet_minprice'] = 100;
eur_reject( function () { jet_financial_calculation( 99.99, 0, 3, 0 ); }, 'principal_below_minimum' );
$options['jet_minprice'] = 1;
eur_reject( function () { jet_financial_calculation( 74.99, 0, 3, 0 ); }, 'principal_below_minimum' );
eur_reset();
eur_expect( '2400.00' === jet_product_financial_source( 42, 77, 2 )['amount'], 'Variation/quantity source.' );
eur_reject( function () { jet_product_financial_source( 43, 77, 1 ); }, 'invalid_application_product' );
$catalog[42] = 80;
$_POST = array( 'security' => 'valid', 'jet_context' => 'product', 'jet_card' => '0', 'jet_submission_id' => str_repeat( 'a', 32 ), 'jet_parva' => '0', 'jet_vnoski' => '9', 'jet_product_id' => '42', 'jet_quantity' => '1' );
$display = eur_ajax( 'jet_calculate' );
eur_expect( 'success' === $display['success'] && true === $display['jet_eligible'], 'An ineligible card scheme blocked the regular scheme.' );
eur_expect( '10.01' === $display['jet_vnoska'] && '9.69' === $display['jet_vnoska_card'] && ! isset( $display['jet_vnoska_second'] ), 'Informational EUR calculation output.' );
$_POST['jet_card'] = '1';
$display = eur_ajax( 'jet_calculate' );
eur_expect( ! $display['jet_eligible'] && '' === $display['jet_offer'], 'Below-floor displayed installment issued a token.' );
eur_reset(); $catalog[42] = 100;
$_POST = array( 'security' => 'valid', 'jet_context' => 'product', 'jet_submission_id' => str_repeat( 'a', 32 ), 'jet_parva' => '0', 'jet_product_id' => '42', 'jet_quantity' => '1' );
$display = eur_ajax( 'jet_calculate' );
eur_expect( 'success' === $display['success'] && '9' === $display['jet_vnoski'] && empty( $woocommerce->session->values ), 'Backend default or request-only financial transport changed.' );
$_POST['jet_parva'] = array();
eur_expect( 'invalid_number' === eur_ajax( 'jet_calculate' )['code'] && empty( $woocommerce->session->values ), 'Invalid calculation changed session state.' );
$effective_currency = 'BGN';
eur_expect( 'non_eur_currency' === eur_ajax( 'jet_calculate_cart' )['code'] && empty( $woocommerce->session->values ), 'Non-EUR calculator changed session state.' );
foreach ( array( 'checkout_classic', 'checkout_card' ) as $context ) {
	eur_reset(); $_POST = eur_payload( $context );
	$prefix = 'checkout_card' === $context ? 'jet_card_' : 'jet_';
	$gateway = 'checkout_card' === $context ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
	$_POST[ $prefix . 'uslovia' ] = $_POST[ $prefix . 'uslovia1' ] = '1'; $_POST[ $prefix . 'egn' ] = 'synthetic';
	eur_expect( $gateway->is_available() && $gateway->validate_fields(), 'Valid Classic gateway validation.' );
	$options['jet_minprice'] = 'invalid';
	eur_expect( ! $gateway->is_available(), 'Malformed configured minimum was not handled.' );
	$options['jet_minprice'] = JET_MIN_PRINCIPAL_EUR; $effective_currency = 'USD';
	eur_expect( ! $gateway->is_available() && ! $gateway->validate_fields(), 'Non-EUR gateway validation.' );
	eur_reset(); $_POST = eur_payload( $context );
	foreach ( array_keys( $_POST ) as $key ) {
		if ( 0 === strpos( $key, $prefix ) && ( '_input' === substr( $key, -6 ) || 0 === strpos( $key, $prefix . 'products' ) ) ) {
			$woocommerce->session->set( preg_replace( '/_input$/', '', $key ), $_POST[$key] ); unset( $_POST[$key] );
		}
	}
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ), 'Stale session financial transport was accepted.' );
}

foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	eur_reset(); $_POST = eur_payload( $context );
	eur_expect( eur_success( eur_submit( $context ), $context ), "$context: valid current form rejected." );
	eur_expect( 3 === count( $mails ), "$context: three isolated messages required." );
	eur_expect( false !== strpos( $mails[1][2], 'ЕГН: **********;' ) && false === strpos( $mails[2][2], 'Данни за потребителя:' ), "$context: notification privacy." );
	$before = count( $mails );
	eur_expect( eur_success( eur_submit( $context ), $context ) && $before === count( $mails ), "$context: replay duplicated mail after cart emptying." );
	if ( $checkout = 0 === strpos( $context, 'checkout' ) ) {
		$other = 'checkout_card' === $context ? 'checkout_classic' : 'checkout_card';
		eur_expect( eur_success( eur_submit( $other ), $other ) && $before === count( $mails ), 'Cross-gateway retry bypassed order claim.' );
	}
	foreach ( array( false, true ) as $throws ) {
		eur_reset(); $_POST = eur_payload( $context );
		$mail_behavior = function ( $args ) use ( $throws ) {
			if ( is_array( $args[0] ) ) { return true; }
			if ( $throws ) { throw new RuntimeException( 'Synthetic secondary failure.' ); }
			return false;
		};
		eur_expect( eur_success( eur_submit( $context ), $context ) && 3 === count( $mails ), "$context: secondary failure changed primary result." );
		eur_expect( eur_success( eur_submit( $context ), $context ) && 3 === count( $mails ), "$context: secondary failure permitted resend." );
	}
	foreach ( array( 'base', 'effective', 'order' ) as $currency_case ) {
		eur_reset(); $_POST = eur_payload( $context );
		if ( 'base' === $currency_case ) { $options['woocommerce_currency'] = 'BGN'; }
		$_POST['currency'] = 'EUR'; // A client flag must not bypass the server context.
		if ( 'effective' === $currency_case ) { $effective_currency = 'USD'; }
		if ( 'order' === $currency_case ) { if ( 0 !== strpos( $context, 'checkout' ) ) { continue; } $order->currency = 'BGN'; }
		eur_expect( ! eur_success( eur_submit( $context ), $context ), "$context: non-EUR accepted." );
		eur_expect( empty( $mails ) && 0 === $orders_created && 0 === $options['jet_count'] && empty( $order->statuses ) && 1 === count( $woocommerce->cart->items ), "$context: rejection had financial side effects." );
		eur_expect( 0 === count( array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'jet_submission_' ) || 0 === strpos( $key, 'jet_checkout_submission_' ); } ) ), 'Rejected request acquired claim.' );
	}
	eur_reset(); $_POST = eur_payload( $context );
	$prefix = 'checkout_card' === $context ? 'jet_card_' : 'jet_'; $suffix = 0 === strpos( $context, 'checkout' ) ? '_input' : '';
	$_POST[ $prefix . 'vnoska' . $suffix ] = '1.00';
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ) && 0 === $options['jet_count'], "$context: forged installment accepted." );
	eur_reset(); $_POST = eur_payload( $context ); $mail_result = false;
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && 1 === count( $mails ) && empty( $order->statuses ), "$context: bank refusal handling." );
	$mail_result = true;
	eur_expect( eur_success( eur_submit( $context ), $context ), "$context: definite bank refusal prevented safe retry." );
	eur_reset(); $_POST = eur_payload( $context );
	$during_mail = function () use ( $context ) { eur_expect( ! eur_success( eur_submit( $context ), $context ), "$context: concurrent request bypassed claim." ); };
	eur_expect( eur_success( eur_submit( $context ), $context ) && 3 === count( $mails ), "$context: concurrent duplicate." );
	eur_reset(); $_POST = eur_payload( $context );
	$during_mail = function () { throw new RuntimeException( 'Synthetic uncertain delivery.' ); };
	eur_expect( ! eur_success( eur_submit( $context ), $context ), "$context: uncertain attempt reported success." );
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && 1 === count( $mails ), "$context: uncertain delivery was retried." );
	foreach ( array( 0, 1, 2, 3 ) as $legacy_mode ) {
		eur_reset(); $options['jet_eur'] = $legacy_mode; $_POST = eur_payload( $context );
		eur_expect( eur_success( eur_submit( $context ), $context ) && $legacy_mode === $options['jet_eur'], 'Legacy option influenced EUR submission or was modified.' );
	}
}

foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_blocks' ) as $context ) {
	eur_reset(); $selection = array( 'product_id' => 42, 'variation_id' => 0, 'quantity' => 1 );
	$token = jet_create_verified_offer( $context, $selection, 0, 12, false, str_repeat( 'b', 32 ) );
	eur_expect( 1000.0 === jet_verify_financial_offer( $token, $context, $selection, false, str_repeat( 'b', 32 ) )['jet_total_credit_price'], 'Valid offer rejected.' );
	eur_reject( function () use ( $token, $context, $selection ) { jet_verify_financial_offer( $token . 'x', $context, $selection, false, str_repeat( 'b', 32 ) ); }, 'invalid_offer' );
	eur_reject( function () use ( $token, $context, $selection ) { jet_verify_financial_offer( $token, $context, $selection, true, str_repeat( 'b', 32 ) ); }, 'stale_offer' );
	$woocommerce->session->customer = 'another-session';
	eur_reject( function () use ( $token, $context, $selection ) { jet_verify_financial_offer( $token, $context, $selection, false, str_repeat( 'b', 32 ) ); }, 'stale_offer' );
	$woocommerce->session->customer = 'synthetic-session';
	list( $encoded ) = explode( '.', $token ); $payload = json_decode( base64_decode( strtr( $encoded, '-_', '+/' ) ), true );
	eur_expect( empty( array_intersect( array( 'jet_egn', 'jet_email', 'jet_name', 'customer' ), array_keys( $payload ) ) ), 'Offer token contains personal fields.' );
	$payload['expires'] = time() - 1; $encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	$expired = $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
	eur_reject( function () use ( $expired, $context, $selection ) { jet_verify_financial_offer( $expired, $context, $selection, false, str_repeat( 'b', 32 ) ); }, 'stale_offer' );
	if ( 'product' === $context ) { $catalog[42] = 1001; } else { $woocommerce->cart->total = 1001; }
	eur_reject( function () use ( $token, $context, $selection ) { jet_verify_financial_offer( $token, $context, $selection, false, str_repeat( 'b', 32 ) ); }, 'stale_offer' );
}

/** Extract an original function body without including a WordPress/application bootstrap. */
function eur_original_function( $source, $name ) {
	$tokens = token_get_all( $source ); $capture = false; $started = false; $depth = 0; $result = '';
	foreach ( $tokens as $i => $token ) {
		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$j = $i + 1; while ( isset( $tokens[$j] ) && is_array( $tokens[$j] ) && T_WHITESPACE === $tokens[$j][0] ) { ++$j; }
			$capture = isset( $tokens[$j][1] ) && $name === $tokens[$j][1];
		}
		if ( ! $capture ) { continue; }
		$text = is_array( $token ) ? $token[1] : $token; $result .= $text;
		if ( '{' === $token ) { ++$depth; $started = true; }
		if ( '}' === $token && --$depth === 0 && $started ) { return $result; }
	}
	throw new RuntimeException( 'Missing original function.' );
}
foreach ( array( array( 'product', false ), array( 'product', true ), array( 'cart', false ), array( 'cart', true ), array( 'checkout_classic', false ), array( 'checkout_card', true ) ) as $identity_case ) {
	list( $context, $scheme ) = $identity_case;
	$checkout = 0 === strpos( $context, 'checkout' );
	$file = $checkout ? ( 'checkout_card' === $context ? 'includes/class-card-jet-payment-gateway.php' : 'includes/class-jet-payment-gateway.php' ) : 'includes/jet_calculate.php';
	// Fixed read-only Git command; no checkout, worktree writes or external connections.
	$source = shell_exec( 'git show adb74f355cd3384ac2a41644f12097c84aebe3be:' . $file );
	eur_expect( is_string( $source ) && '' !== $source, 'Original source unavailable.' );
	$name = $checkout ? 'process_credit_application' : ( 'product' === $context ? 'jet_process_product_submission' : 'jet_process_cart_submission' );
	$function = eur_original_function( $source, $name );
	$function = preg_replace( '/^function ' . $name . '/', 'function', $function );
	$original = eval( 'return ' . $function . ';' );
	eur_reset(); $_POST = eur_payload( $context, $scheme ); $options['jet_eur'] = 3; $notification = array();
	if ( $checkout ) {
		$gateway = 'checkout_card' === $context ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
		$original = $original->bindTo( $gateway, get_class( $gateway ) );
		$original( $order, jet_submission_bank_recipients(), 'synthetic-claim', $notification );
	} else { $original( $notification ); }
	jet_submission_notify( $notification ); $expected = $mails;
	eur_reset(); $_POST = eur_payload( $context, $scheme );
	eur_expect( eur_success( eur_submit( $context ), $context ) && $expected === $mails, "$context: v1.9.3 EUR bank/notification bytes changed." );
}
echo "PASS EUR backend: $checks assertions; all mail/order/database/session effects intercepted.\n";
