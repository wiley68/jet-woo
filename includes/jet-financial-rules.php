<?php
/** EUR financial rules shared by the backend and future script localization. */

const JET_MIN_PRINCIPAL_EUR = 75.00;
const JET_INITIAL_TERM_THRESHOLD_EUR = 125.00;
const JET_EXTENDED_TERM_THRESHOLD_EUR = 201.00;
const JET_LONG_TERM_THRESHOLD_EUR = 301.00;
const JET_MIN_INSTALLMENT_EUR = 10.00;

class Jet_Financial_Validation_Exception extends RuntimeException {

	public function __construct( $reason ) {
		parent::__construct( $reason );
	}
}

/** Contains no state changes; safe to pass to wp_localize_script in IMP-02. */
function jet_financial_rules() {
	return array(
		'currency'                => 'EUR',
		'min_principal'           => JET_MIN_PRINCIPAL_EUR,
		'initial_term_threshold'  => JET_INITIAL_TERM_THRESHOLD_EUR,
		'extended_term_threshold' => JET_EXTENDED_TERM_THRESHOLD_EUR,
		'long_term_threshold'     => JET_LONG_TERM_THRESHOLD_EUR,
		'min_installment'         => JET_MIN_INSTALLMENT_EUR,
	);
}

function jet_financial_number( $value ) {
	if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_number' );
	}
	if ( is_string( $value ) && ! preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', trim( $value ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_number' );
	}
	$number = (float) $value;
	if ( ! is_finite( $number ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_number' );
	}
	return $number;
}

function jet_financial_positive_integer( $value ) {
	if ( ! is_int( $value ) && ! ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) && ! ( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_integer' );
	}
	if ( (float) $value <= 0 || (float) $value >= PHP_INT_MAX ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_integer' );
	}
	return (int) $value;
}

function jet_financial_money( $value ) {
	$number = jet_financial_number( $value );
	if ( abs( $number - (float) number_format( $number, 2, '.', '' ) ) > 1.0e-8 ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_money_precision' );
	}
	return $number;
}

/** A configured shop minimum may raise, but cannot lower, the EUR principal floor. */
function jet_minimum_principal() {
	$configured = get_option( 'jet_minprice' );
	if ( false === $configured || '' === $configured ) {
		return JET_MIN_PRINCIPAL_EUR;
	}
	return max( JET_MIN_PRINCIPAL_EUR, jet_financial_money( $configured ) );
}

function jet_require_eur_context( $order = null ) {
	if ( 'EUR' !== get_option( 'woocommerce_currency' ) || 'EUR' !== get_woocommerce_currency() ) {
		throw new Jet_Financial_Validation_Exception( 'non_eur_currency' );
	}
	if ( null !== $order && ( 'EUR' !== $order->get_currency( 'edit' ) || 'EUR' !== $order->get_currency() ) ) {
		throw new Jet_Financial_Validation_Exception( 'non_eur_order_currency' );
	}
}

function jet_allowed_financing_terms( $amount ) {
	$amount = jet_financial_number( $amount );
	$terms  = array( 3, 6, 9, 10, 12 );
	if ( $amount >= JET_EXTENDED_TERM_THRESHOLD_EUR ) {
		$terms = array_merge( $terms, array( 15, 18, 24 ) );
	}
	if ( $amount >= JET_LONG_TERM_THRESHOLD_EUR ) {
		$terms = array_merge( $terms, array( 30, 36 ) );
	}
	return $terms;
}

function jet_initial_financing_term( $amount, $default ) {
	return jet_financial_number( $amount ) < JET_INITIAL_TERM_THRESHOLD_EUR ? 9 : $default;
}

/** Keep the existing bank formula, RATE implementation and output precision. */
function jet_financial_calculation( $amount, $initial, $term, $percent, $require_minimum_installment = true ) {
	$amount  = jet_financial_money( $amount );
	$initial = jet_financial_money( $initial );
	$term    = jet_financial_positive_integer( $term );
	$percent = jet_financial_number( $percent );
	$principal = $amount - $initial;
	if ( $amount <= 0 || $initial < 0 || $principal <= 0 || $percent < 0 ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_financial_amount' );
	}
	// Compare monetary boundaries in cents; retain unrounded P in the bank formula.
	$minimum = jet_minimum_principal();
	$below_minimum = max( $amount, $initial, $minimum ) <= PHP_FLOAT_MAX / 100
		? round( $amount * 100 ) - round( $initial * 100 ) < round( $minimum * 100 )
		: $principal < $minimum;
	if ( $below_minimum ) {
		throw new Jet_Financial_Validation_Exception( 'principal_below_minimum' );
	}
	if ( ! in_array( $term, jet_allowed_financing_terms( $amount ), true ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_financing_term' );
	}
	$installment = ( $principal / $term ) * ( 1 + ( $term * $percent ) / 100 );
	if ( ! is_finite( $installment ) || ( $require_minimum_installment && ! jet_installment_meets_minimum( $installment ) ) ) {
		throw new Jet_Financial_Validation_Exception( 'installment_below_minimum' );
	}
	$monthly_rate = RATE( $term, $installment, -1 * $principal ) * 12;
	$glp         = ( RATE( $term, $installment, -1 * $principal ) * 12 ) * 100;
	$gpr         = ( pow( 1 + $monthly_rate / 12, 12 ) - 1 ) * 100;
	$total       = $installment * $term;
	if ( ! is_finite( $glp ) || ! is_finite( $gpr ) || ! is_finite( $total ) ) {
		throw new Jet_Financial_Validation_Exception( 'invalid_financial_result' );
	}
	return array(
		'jet_priceall'           => $amount,
		'jet_parva'              => $initial,
		'jet_total_credit_price' => $principal,
		'jet_vnoski'             => $term,
		'jet_vnoska'             => $installment,
		'jet_obshto'             => $total,
		'jet_glp'                => $glp,
		'jet_gpr'                => $gpr,
	);
}

function jet_installment_meets_minimum( $installment ) {
	return (float) number_format( jet_financial_number( $installment ), 2, '.', '' ) >= JET_MIN_INSTALLMENT_EUR;
}
