<?php
/** Server sources and signed EUR offers. Delivery claims remain the sole send locks. */
require_once __DIR__ . '/jet-financial-rules.php';

function jet_financial_source( $amount, $lines ) {
	$amount = jet_financial_number( $amount );
	if ( $amount <= 0 || empty( $lines ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_financial_source' );
	}
	return array( 'amount' => number_format( $amount, 2, '.', '' ), 'lines' => $lines );
}

function jet_financial_line( $product_id, $variation_id, $quantity, $unit_price ) {
	$unit_price = jet_financial_number( $unit_price );
	if ( $unit_price < 0 ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_product_price' );
	}
	return array(
		'product_id'   => jet_financial_positive_integer( $product_id ),
		'variation_id' => 0 === $variation_id || '0' === $variation_id || '' === $variation_id ? 0 : jet_financial_positive_integer( $variation_id ),
		'quantity'     => jet_financial_positive_integer( $quantity ),
		'unit_price'   => number_format( $unit_price, 2, '.', '' ),
	);
}

function jet_product_financial_source( $product_id, $variation_id, $quantity ) {
	jet_require_eur_context();
	$product_id = jet_financial_positive_integer( $product_id );
	$quantity   = jet_financial_positive_integer( $quantity );
	$variation_id = '' === $variation_id || '0' === $variation_id || 0 === $variation_id ? 0 : jet_financial_positive_integer( $variation_id );
	$product = wc_get_product( $variation_id ? $variation_id : $product_id );
	if ( ! $product || ! $product->get_id() || ( $variation_id && ( ! $product->is_type( 'variation' ) || $product->get_parent_id() !== $product_id ) ) || ( ! $variation_id && ! $product->is_type( 'simple' ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_application_product' );
	}
	$amount = jet_financial_number( wc_get_price_including_tax( $product, array( 'qty' => $quantity ) ) );
	return jet_financial_source( $amount, array( jet_financial_line( $product_id, $variation_id, $quantity, $amount / $quantity ) ) );
}

/** Cart and order use the same line amounts, not separately rounded unit tax prices. */
function jet_financial_amount_line( $product_id, $variation_id, $quantity, $subtotal, $subtotal_tax, $total, $total_tax ) {
	$subtotal = jet_financial_number( $subtotal ); $subtotal_tax = jet_financial_number( $subtotal_tax );
	$total = jet_financial_number( $total ); $total_tax = jet_financial_number( $total_tax );
	$quantity = jet_financial_positive_integer( $quantity );
	$line = jet_financial_line( $product_id, $variation_id, $quantity, ( $subtotal + $subtotal_tax ) / $quantity );
	foreach ( array( 'subtotal' => $subtotal + $subtotal_tax, 'total' => $total + $total_tax, 'subtotal_net' => $subtotal, 'total_net' => $total, 'subtotal_tax' => $subtotal_tax, 'total_tax' => $total_tax ) as $key => $value ) {
		$line[$key] = number_format( $value, 2, '.', '' );
	}
	return $line;
}

/** Re-run WooCommerce's own pricing/fee/tax hooks before issuing or sending a cart offer. */
function jet_refresh_financial_cart() {
	if ( ! WC()->cart ) { throw new Jet_Financial_Validation_Exception( 'missing_financial_cart' ); }
	WC()->cart->calculate_totals();
}

function jet_cart_financial_source() {
	jet_require_eur_context();
	$cart = WC()->cart;
	if ( ! $cart ) {
		throw new Jet_Financial_Validation_Exception( 'missing_financial_cart' );
	}
	$lines = array();
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['data'] ) || ! $item['data']->get_id() ) {
			throw new Jet_Financial_Validation_Exception( 'invalid_cart_product' );
		}
		$lines[] = jet_financial_amount_line( $item['product_id'], $item['variation_id'], $item['quantity'], $item['line_subtotal'] ?? null, $item['line_subtotal_tax'] ?? null, $item['line_total'] ?? null, $item['line_tax'] ?? null );
	}
	$source = jet_financial_source( $cart->get_total( 'float' ), $lines );
	$source['adjustments'] = jet_financial_adjustments( $cart );
	return $source;
}

function jet_order_financial_source( $order ) {
	jet_require_eur_context( $order );
	$lines = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$quantity = jet_financial_positive_integer( $item->get_quantity() );
		$lines[] = jet_financial_amount_line( $item->get_product_id(), $item->get_variation_id(), $quantity, $item->get_subtotal(), $item->get_subtotal_tax(), $item->get_total(), $item->get_total_tax() );
	}
	$source = jet_financial_source( $order->get_total(), $lines );
	$source['adjustments'] = jet_financial_adjustments( $order, true );
	return $source;
}

/** Normalize equivalent cart/order adjustments without exposing addresses or other PII. */
function jet_financial_adjustments( $object, $order = false ) {
	$values = array();
	foreach ( array( 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax' ) as $field ) {
		$method = 'get_' . $field;
		$values[$field] = number_format( jet_financial_number( method_exists( $object, $method ) ? $object->$method() : 0 ), 2, '.', '' );
	}
	$method = $order ? 'get_total_fees' : 'get_fee_total';
	$values['fees'] = number_format( jet_financial_number( method_exists( $object, $method ) ? $object->$method() : 0 ), 2, '.', '' );
	$fee_tax = $order ? array_sum( array_map( function ( $item ) { return jet_financial_number( $item->get_total_tax() ); }, $object->get_items( 'fee' ) ) ) : $object->get_fee_tax();
	$values['fee_tax'] = number_format( $fee_tax, 2, '.', '' );
	$coupons = $order ? 'get_coupon_codes' : 'get_applied_coupons';
	$values['coupons'] = method_exists( $object, $coupons ) ? array_values( $object->$coupons() ) : array();
	sort( $values['coupons'], SORT_STRING );
	return $values;
}

function jet_financial_source_lists( $source ) {
	$lists = array( 'jet_products' => array(), 'jet_products_qt' => array(), 'jet_products_pr' => array(), 'jet_products_vr' => array() );
	foreach ( $source['lines'] as $line ) {
		$lists['jet_products'][]    = $line['product_id'];
		$lists['jet_products_qt'][] = $line['quantity'];
		$lists['jet_products_pr'][] = $line['unit_price'];
		$lists['jet_products_vr'][] = $line['variation_id'];
	}
	return array_map( function ( $values ) { return implode( '_', $values ); }, $lists );
}

function jet_calculate_source_financing( $source, $initial, $term, $card, $context, $require_minimum_installment = true ) {
	if ( 1 !== (int) get_option( 'jet_status_in' ) || ( $card && 1 !== (int) get_option( 'jet_card_in' ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'financing_unavailable' );
	}
	$initial   = jet_financial_number( $initial );
	$term      = jet_financial_positive_integer( $term );
	$principal = (float) $source['amount'] - $initial;
	$lists     = jet_financial_source_lists( $source );
	$promo_id  = 'product' === $context ? $source['lines'][0]['product_id'] : $lists['jet_products'];
	// Preserve explicit composite/global rules; never turn 42_43 into unrelated ID 4243.
	$promo = jet_get_promo( $promo_id, $term, $principal );
	if ( ! $promo['jet_show_button'] ) {
		throw new Jet_Financial_Validation_Exception( 'financing_unavailable' );
	}
	return jet_financial_calculation( $source['amount'], $initial, $term, $promo[ $card ? 'jet_purcent_card' : 'jet_purcent' ], $require_minimum_installment );
}

/** Submission fields must come from the current request, never a prior financial session. */
function jet_submission_financial_value( $data, $name, $checkout ) {
	if ( array_key_exists( $name, $data ) ) {
		return $data[ $name ];
	}
	throw new Jet_Financial_Validation_Exception( 'missing_financial_value' );
}

function jet_assert_financial_amount( $actual, $expected ) {
	if ( number_format( jet_financial_number( $actual ), 2, '.', '' ) !== number_format( jet_financial_number( $expected ), 2, '.', '' ) ) {
		throw new Jet_Financial_Validation_Exception( 'stale_financial_values' );
	}
}

/** Activate the IMP-01 verification API for every new financial submission. */
function jet_validate_submission_financing( $data, $context, $order = null ) {
	jet_require_eur_context( $order );
	$checkout = 0 === strpos( $context, 'checkout' );
	if ( ! $checkout && isset( $data['jet_card'] ) && ! in_array( $data['jet_card'], array( 0, 1, '0', '1' ), true ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_financing_scheme' );
	}
	$card = $checkout ? 'checkout_card' === $context : ( isset( $data['jet_card'] ) && '1' === (string) $data['jet_card'] );
	if ( $card && 1 !== (int) get_option( 'jet_card_in' ) ) {
		throw new Jet_Financial_Validation_Exception( 'financing_unavailable' );
	}
	$offer_prefix = $checkout && $card ? 'jet_card_' : 'jet_';
	$offer_context = $data[ $offer_prefix . 'context' ] ?? '';
	if ( ! is_string( $offer_context ) || ( $checkout ? ! in_array( $offer_context, array( 'checkout_classic', 'checkout_blocks' ), true ) : $context !== $offer_context ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_offer_context' );
	}
	$selection = array( 'product_id' => $data['jet_product_id'] ?? null, 'variation_id' => $data['jet_variation_id'] ?? 0, 'quantity' => $data['jet_quantity'] ?? null );
	$calculation = jet_verify_financial_offer( $data[ $offer_prefix . 'offer' ] ?? null, $offer_context, $selection, $card, $checkout ? '' : ( $data['jet_submission_id'] ?? '' ), $order );
	$source = $checkout ? ( null === $order ? jet_cart_financial_source() : jet_order_financial_source( $order ) ) : ( 'product' === $context
		? jet_product_financial_source( $data['jet_product_id'] ?? null, $data['jet_variation_id'] ?? 0, $data['jet_quantity'] ?? null )
		: jet_cart_financial_source() );
	$prefix = $checkout && $card ? 'jet_card_' : 'jet_';
	$suffix = $checkout ? '_input' : '';
	$amount = jet_submission_financial_value( $data, $prefix . 'priceall' . $suffix, $checkout );
	$initial = jet_submission_financial_value( $data, $prefix . 'parva' . $suffix, $checkout );
	$term = jet_submission_financial_value( $data, $prefix . 'vnoski' . $suffix, $checkout );
	jet_assert_financial_amount( $amount, $source['amount'] );
	jet_assert_financial_amount( $initial, $calculation['jet_parva'] );
	if ( jet_financial_positive_integer( $term ) !== $calculation['jet_vnoski'] ) {
		throw new Jet_Financial_Validation_Exception( 'stale_financial_values' );
	}
	foreach ( array( 'vnoska', 'total_credit_price', 'obshto', 'glp', 'gpr' ) as $field ) {
		$name = $prefix . $field . $suffix;
		// The installment is mandatory; redundant display fields are checked when supplied.
		if ( 'vnoska' === $field || array_key_exists( $name, $data ) ) {
			jet_assert_financial_amount( jet_submission_financial_value( $data, $name, $checkout ), $calculation[ 'jet_' . $field ] );
		}
	}
	if ( 'product' !== $context ) {
		$lists = jet_financial_source_lists( $source );
		foreach ( $lists as $key => $expected ) {
			$name = $checkout && $card ? str_replace( 'jet_', 'jet_card_', $key ) : $key;
			$actual = jet_submission_financial_value( $data, $name, $checkout );
			if ( ! is_string( $actual ) || $actual !== $expected ) {
				throw new Jet_Financial_Validation_Exception( 'stale_financial_products' );
			}
		}
	}
	return $calculation;
}

function jet_validate_checkout_customer( $data, $card ) {
	$prefix = $card ? 'jet_card_' : 'jet_';
	foreach ( array( 'uslovia', 'uslovia1' ) as $field ) {
		if ( ! isset( $data[ $prefix . $field ] ) || ! in_array( $data[ $prefix . $field ], array( '1', 1 ), true ) ) {
			throw new Jet_Financial_Validation_Exception( 'missing_financing_consent' );
		}
	}
	if ( ! isset( $data[ $prefix . 'egn' ] ) || ! is_string( $data[ $prefix . 'egn' ] ) || '' === trim( sanitize_text_field( $data[ $prefix . 'egn' ] ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'missing_financing_identity' );
	}
}

/** No personal data is embedded. The delivery claim remains the sole send lock. */
function jet_offer_binding() {
	if ( ! isset( WC()->session ) || ! WC()->session->has_session() ) {
		throw new Jet_Financial_Validation_Exception( 'offer_session_required' );
	}
	return hash_hmac( 'sha256', (string) WC()->session->get_customer_id(), wp_salt( 'nonce' ) );
}

function jet_offer_source( $context, $selection, $order = null ) {
	if ( ! in_array( $context, array( 'product', 'cart', 'checkout_classic', 'checkout_blocks' ), true ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_offer_context' );
	}
	if ( 'product' === $context ) {
		return jet_product_financial_source( $selection['product_id'] ?? null, $selection['variation_id'] ?? 0, $selection['quantity'] ?? null );
	}
	return null !== $order ? jet_order_financial_source( $order ) : jet_cart_financial_source();
}

/** Establish a guest binding before issuing a nonce/offer; no form PII is stored. */
function jet_ensure_offer_session() {
	jet_require_eur_context();
	if ( ! isset( WC()->session ) ) {
		throw new Jet_Financial_Validation_Exception( 'offer_session_required' );
	}
	if ( ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
		WC()->session->set_customer_session_cookie( true );
	}
	jet_offer_binding();
}

/** Issued on demand; no additional submission state or delivery lock is created. */
function jet_create_verified_offer( $context, $selection, $initial, $term, $card = false, $submission_id = '' ) {
	jet_require_eur_context();
	if ( ! is_string( $submission_id ) || ( '' !== $submission_id && ! preg_match( '/^[a-f0-9]{16,64}$/D', $submission_id ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_submission_id' );
	}
	$source = jet_offer_source( $context, $selection );
	$calculation = jet_calculate_source_financing( $source, $initial, $term, $card, $context );
	$payload = array(
		'version' => 1, 'currency' => 'EUR', 'context' => $context, 'card' => (bool) $card,
		'binding' => jet_offer_binding(), 'submission_id' => $submission_id,
		'issued' => time(), 'expires' => time() + 30 * MINUTE_IN_SECONDS,
		'source' => hash_hmac( 'sha256', wp_json_encode( $source ), wp_salt( 'nonce' ) ), 'calculation' => $calculation,
	);
	$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
}

function jet_verify_financial_offer( $token, $context, $selection, $card = false, $submission_id = '', $order = null ) {
	jet_require_eur_context( $order );
	if ( ! is_string( $token ) || strlen( $token ) > 8192 || 1 !== substr_count( $token, '.' ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_offer' );
	}
	list( $encoded, $signature ) = explode( '.', $token, 2 );
	if ( ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) ), $signature ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_offer' );
	}
	$offer = json_decode( base64_decode( strtr( $encoded, '-_', '+/' ), true ), true );
	if ( ! is_array( $offer ) || 1 !== ( $offer['version'] ?? null ) || 'EUR' !== ( $offer['currency'] ?? null ) || $context !== ( $offer['context'] ?? null ) || (bool) $card !== ( $offer['card'] ?? null ) || $submission_id !== ( $offer['submission_id'] ?? null ) || jet_offer_binding() !== ( $offer['binding'] ?? null ) || ( $offer['issued'] ?? PHP_INT_MAX ) > time() || ( $offer['expires'] ?? 0 ) <= time() ) {
		throw new Jet_Financial_Validation_Exception( 'stale_offer' );
	}
	if ( ! isset( $offer['calculation']['jet_parva'], $offer['calculation']['jet_vnoski'], $offer['source'] ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_offer' );
	}
	$source = jet_offer_source( $context, $selection, $order );
	$calculation = jet_calculate_source_financing( $source, $offer['calculation']['jet_parva'], $offer['calculation']['jet_vnoski'], $card, $context );
	if ( hash_hmac( 'sha256', wp_json_encode( $source ), wp_salt( 'nonce' ) ) !== $offer['source'] || wp_json_encode( $calculation ) !== wp_json_encode( $offer['calculation'] ) ) {
		throw new Jet_Financial_Validation_Exception( 'stale_offer' );
	}
	return $calculation;
}
