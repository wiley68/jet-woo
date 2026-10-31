<?php
/** Test-only adapters. Frozen historical JSON is read unchanged; no WP bootstrap. */
class JetTestOrderItem {
	private $line;
	public function __construct( $line ) { $this->line = $line; }
	public function get_product_id() { return $this->line[0]; }
	public function get_variation_id() { return $this->line[1]; }
	public function get_quantity() { return $this->line[2]; }
	public function get_subtotal() { return $this->line[2] * $this->line[3]; }
	public function get_subtotal_tax() { return 0; }
	public function get_total() { return $this->get_subtotal(); }
	public function get_total_tax() { return 0; }
}

function jet_test_setup_source( $case, $context ) {
	$post = array_merge( $case['session'] ?? array(), $case['post'] );
	$card = 'card' === ( $case['method'] ?? '' );
	$prefix = $card ? 'jet_card_' : 'jet_';
	$suffix = 0 === strpos( $context, 'checkout' ) ? '_input' : '';
	$amount = (float) ( $post[$prefix . 'priceall' . $suffix] ?? $post[$prefix . 'priceall'] ?? 1000 );
	$initial = (float) ( $post[$prefix . 'parva' . $suffix] ?? $post[$prefix . 'parva'] ?? 0 );
	$term = (int) ( $post[$prefix . 'vnoski' . $suffix] ?? $post[$prefix . 'vnoski'] ?? 12 );
	$installment = (float) ( $post[$prefix . 'vnoska' . $suffix] ?? $post[$prefix . 'vnoska'] ?? 97.33 );
	// Old fixtures specify a monthly amount but no coefficient. Supply the synthetic
	// coefficient implied by that amount, then run the actual production calculator.
	// Redundant historical GPR/GLP values were arbitrary and are recalculated, never signed.
	$percent = ( $installment * $term / ( $amount - $initial ) - 1 ) * 100 / $term;
	$GLOBALS['records'] = array_merge( array( 'woocommerce_currency' => 'EUR', 'jet_status_in' => 1, 'jet_card_in' => 1, 'jet_minprice' => JET_MIN_PRINCIPAL_EUR, 'jet_vnoski_default' => 12 ), $case['options'], array( 'jet_purcent' => $percent, 'jet_purcent_card' => $percent ) );
	$GLOBALS['test_currency'] = 'EUR';
	$GLOBALS['test_source_amount'] = $amount;
	$GLOBALS['test_catalog'] = $GLOBALS['test_parents'] = $GLOBALS['test_source_lines'] = array();
	$products = explode( '_', $post[$prefix . 'products'] ?? '42_43' );
	$quantities = explode( '_', $post[$prefix . 'products_qt'] ?? '2_1' );
	$prices = explode( '_', $post[$prefix . 'products_pr'] ?? '375.00_250.00' );
	$variations = explode( '_', $post[$prefix . 'products_vr'] ?? '0_77' );
	foreach ( $products as $index => $id ) {
		$variation = (int) $variations[$index];
		$GLOBALS['test_source_lines'][] = array( (int) $id, $variation, (int) $quantities[$index], (float) $prices[$index] );
		$GLOBALS['test_catalog'][$variation ?: (int) $id] = (float) $prices[$index];
		if ( $variation ) { $GLOBALS['test_parents'][$variation] = (int) $id; }
	}
	if ( 'product' === $context ) {
		$id = (int) $post['jet_product_id']; $variation = (int) ( $post['jet_variation_id'] ?? 0 );
		$GLOBALS['test_catalog'][$variation ?: $id] = $amount / (int) $post['jet_quantity'];
		if ( $variation ) { $GLOBALS['test_parents'][$variation] = $id; }
		else { unset( $GLOBALS['test_parents'][$id] ); }
	}
}

/** Mint through the actual AJAX calculator; signature generation/validation is never stubbed. */
function jet_test_issue_offer( $context, $card = false, $inputs = null ) {
	$original = $_POST;
	$inputs = $inputs ?? $original;
	$checkout = 0 === strpos( $context, 'checkout' );
	$prefix = $checkout && $card ? 'jet_card_' : 'jet_';
	$suffix = $checkout ? '_input' : '';
	$request = array_merge( $original, array(
		'security' => 'valid', 'jet_context' => $context, 'jet_card' => $card ? '1' : '0',
		'jet_parva' => $inputs[$prefix . 'parva' . $suffix] ?? $inputs[$prefix . 'parva'] ?? 0,
		'jet_vnoski' => $inputs[$prefix . 'vnoski' . $suffix] ?? $inputs[$prefix . 'vnoski'] ?? 12,
	) );
	try {
		$_POST = $request;
		try { jet_calculate(); } catch ( JetTestResponse $response ) { $quote = $response->response; }
	} finally { $_POST = $original; }
	if ( ! isset( $quote['success'] ) || 'success' !== $quote['success'] || empty( $quote['jet_offer'] ) ) {
		throw new RuntimeException( 'Synthetic offer issuance failed: ' . ( $quote['code'] ?? 'missing_response' ) );
	}
	$expected_monthly = $inputs[$prefix . 'vnoska' . $suffix] ?? $inputs[$prefix . 'vnoska'] ?? null;
	if ( null !== $expected_monthly && $quote['jet_vnoska'] !== number_format( (float) $expected_monthly, 2, '.', '' ) ) {
		throw new RuntimeException( 'Synthetic coefficient does not reproduce the historical installment.' );
	}
	$_POST[$prefix . 'offer'] = $quote['jet_offer']; $_POST[$prefix . 'context'] = $context;
	foreach ( array( 'priceall', 'parva', 'vnoski', 'vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp' ) as $field ) {
		$_POST[$prefix . $field . $suffix] = $quote['jet_' . $field];
	}
	foreach ( array( 'products', 'products_qt', 'products_pr', 'products_vr' ) as $field ) { $_POST[$prefix . $field] = $quote['jet_' . $field]; }
	if ( $checkout ) {
		$_POST[$prefix . 'egn'] = $inputs[$prefix . 'egn'] ?? '0000000000';
		$_POST[$prefix . 'uslovia'] = $_POST[$prefix . 'uslovia1'] = '1';
	} else { $_POST['jet_card'] = $card ? '1' : '0'; }
	return $quote;
}

function jet_test_expired_offer( $token ) {
	$payload = json_decode( base64_decode( strtr( explode( '.', $token )[0], '-_', '+/' ) ), true );
	$payload['expires'] = time() - 1;
	$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
}

/** Extract a frozen v1.9.3 template without including any application/bootstrap from Git. */
function jet_test_original_builder( $context, $card ) {
	$file = 0 === strpos( $context, 'checkout' ) ? ( $card ? 'includes/class-card-jet-payment-gateway.php' : 'includes/class-jet-payment-gateway.php' ) : 'includes/jet_calculate.php';
	$name = 0 === strpos( $context, 'checkout' ) ? 'process_credit_application' : ( 'cart' === $context ? 'jet_process_cart_submission' : 'jet_process_product_submission' );
	$source = shell_exec( 'git show adb74f355cd3384ac2a41644f12097c84aebe3be:' . $file );
	if ( ! is_string( $source ) || '' === $source ) { throw new RuntimeException( 'Original v1.9.3 source unavailable.' ); }
	$tokens = token_get_all( $source ); $capture = false; $started = false; $depth = 0; $body = '';
	foreach ( $tokens as $i => $token ) {
		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$j = $i + 1; while ( isset( $tokens[$j] ) && is_array( $tokens[$j] ) && T_WHITESPACE === $tokens[$j][0] ) { ++$j; }
			$capture = isset( $tokens[$j][1] ) && $name === $tokens[$j][1];
		}
		if ( ! $capture ) { continue; }
		$body .= is_array( $token ) ? $token[1] : $token;
		if ( '{' === $token ) { ++$depth; $started = true; }
		if ( '}' === $token && --$depth === 0 && $started ) {
			return eval( 'return ' . preg_replace( '/^function ' . $name . '/', 'function', $body ) . ';' );
		}
	}
	throw new RuntimeException( 'Original v1.9.3 template not found.' );
}

/** Caller resets doubles before executing the current implementation. */
function jet_test_original_bank_mail( $context, $gateway = null, $order_id = 314 ) {
	$card = $gateway ? 'jetpaymentcard' === $gateway->id : '1' === (string) ( $_POST['jet_card'] ?? 0 );
	$builder = jet_test_original_builder( $context, $card );
	$GLOBALS['records']['jet_eur'] = 3; // Reference code only; never an input to new EUR logic.
	$notification = array();
	if ( $gateway ) {
		$builder = $builder->bindTo( $gateway, get_class( $gateway ) );
		$builder( $GLOBALS['checkout_orders'][$order_id], jet_submission_bank_recipients(), 'synthetic-reference-claim', $notification );
	} else { $builder( $notification ); }
	if ( 1 !== count( $GLOBALS['mails'] ) ) { throw new RuntimeException( 'Original template did not produce exactly one intercepted bank message.' ); }
	return $GLOBALS['mails'][0];
}
