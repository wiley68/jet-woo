<?php
/**
 * Durable per-order delivery protection for both checkout payment methods.
 *
 * @package jetcredit
 */

// Reuse the Product/Cart recipient policy and secondary delivery unchanged.
require_once __DIR__ . '/jet-submission-mail.php';
require_once __DIR__ . '/jet-verified-offer.php';

/**
 * Return an unsuccessful checkout without implying bank acceptance.
 *
 * @param bool $held Whether the order already has a held delivery claim.
 * @return array WooCommerce payment result.
 */
function jet_checkout_submission_failure( $held = false ) {
	$message = $held
		? 'Заявката за тази поръчка се обработва или изисква проверка. Моля, свържете се с магазина.'
		: 'Заявката за кредит не беше подадена. Моля, опитайте отново или се свържете с магазина.';
	wc_add_notice( $message, 'error' );
	return array( 'result' => 'failure' );
}

/**
 * Save non-personal delivery state before allowing later side effects.
 *
 * @param string $key   Owned per-order option name.
 * @param string $state New delivery state.
 * @return void
 * @throws RuntimeException When the new state cannot be recorded.
 */
function jet_checkout_submission_state( $key, $state ) {
	if ( ! update_option( $key, array( 'state' => $state ), false ) ) {
		throw new RuntimeException( 'Unable to record checkout submission state.' );
	}
}

/** The same completed result is used on first registration and durable replay. */
function jet_checkout_submission_result( $gateway, $order, $manual = false ) {
	$result = array( 'result' => 'success', 'redirect' => $gateway->get_return_url( $order ) );
	if ( $manual ) { $result['code'] = 'manual_registered'; $result['message'] = jet_submission_manual_message(); }
	return $result;
}

/** Explain manual registration on the existing receipt, without a new interface. */
function jet_checkout_manual_notice( $order_id ) {
	if ( $order_id <= 0 ) { return; }
	$record = get_option( 'jet_checkout_submission_' . (int) $order_id );
	if ( is_array( $record ) && 'manual_registered' === ( $record['state'] ?? '' ) ) {
		echo '<p>' . esc_html( jet_submission_manual_message() ) . '</p>';
	}
}

/** Read a prior server-owned order outcome without requiring consumed offer/session data. */
function jet_checkout_stored_result( $gateway, $order ) {
	try {
		jet_require_eur_context( $order );
	} catch ( Jet_Financial_Validation_Exception $error ) {
		return jet_checkout_submission_failure();
	}
	$key = 'jet_checkout_submission_' . $order->get_id();
	// Read-only replay handling must precede checks against consumed financial/session data.
	wp_cache_delete( $key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$record = get_option( $key );
	if ( false !== $record ) {
		if ( is_array( $record ) && in_array( $record['state'] ?? '', array( 'success', 'manual_registered' ), true ) ) {
			try {
				return jet_checkout_submission_result( $gateway, $order, 'manual_registered' === $record['state'] );
			} catch ( Throwable $error ) {
				return jet_checkout_submission_failure( true );
			}
		}
		return jet_checkout_submission_failure( true );
	}
	return null;
}

/**
 * Own an order atomically, run its original application flow, then notify.
 *
 * The unique option_name index serializes independent PHP workers. The key is
 * shared by both gateways, survives session loss and HPOS, and never expires:
 * changing payment method or retrying an old order must not resend an application.
 * Only delivery state is stored, never personal data or a return URL/order key.
 *
 * @param WC_Payment_Gateway $gateway Payment gateway serving the order.
 * @param int                $order_id Existing WooCommerce order ID.
 * @param callable           $submit Original application flow, called only by the owner.
 * @return array WooCommerce payment result.
 */
function jet_checkout_process_submission( $gateway, $order_id, $submit ) {
	global $wpdb;

	$order = wc_get_order( $order_id );
	if ( ! $order || is_wp_error( $order ) || $order->get_id() <= 0 ) {
		return jet_checkout_submission_failure();
	}
	$stored = jet_checkout_stored_result( $gateway, $order );
	if ( null !== $stored ) { return $stored; }
	$key = 'jet_checkout_submission_' . $order->get_id();
	try {
		jet_validate_submission_financing( wp_unslash( $_POST ), 'jetpaymentcard' === $gateway->id ? 'checkout_card' : 'checkout_classic', $order );
		jet_validate_checkout_customer( wp_unslash( $_POST ), 'jetpaymentcard' === $gateway->id );
	} catch ( Jet_Financial_Validation_Exception $error ) {
		return jet_checkout_submission_failure();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Unique option_name plus INSERT IGNORE is the cross-worker concurrency gate.
	$claimed = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			$key,
			maybe_serialize( array( 'state' => 'processing' ) ),
			'no'
		)
	);
	if ( false === $claimed ) {
		return jet_checkout_submission_failure();
	}
	wp_cache_delete( $key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( 0 === $claimed ) {
		$record = get_option( $key );
		if ( is_array( $record ) && in_array( $record['state'] ?? '', array( 'success', 'manual_registered' ), true ) ) {
			try {
				return jet_checkout_submission_result( $gateway, $order, 'manual_registered' === $record['state'] );
			} catch ( Throwable $error ) {
				// A redirect hook failure must not make the accepted bank mail retryable.
				return jet_checkout_submission_failure( true );
			}
		}
		// Processing, bank-sent, uncertain or malformed state: never guess and resend.
		return jet_checkout_submission_failure( true );
	}

	$bank_attempted = false;
	$manual_registration = false;
	try {
		$bank_recipients = jet_submission_bank_recipients();
		if ( null === $bank_recipients ) {
			delete_option( $key );
			return jet_checkout_submission_failure();
		}
		$manual_registration = array() === $bank_recipients;
		if ( $manual_registration ) { jet_checkout_submission_state( $key, 'manual_processing' ); }
		$notification = array();
		$result       = $submit( $order, $bank_recipients, $key, $notification, $bank_attempted );
		if ( ! is_array( $result ) || ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
			// Only a definite bank wp_mail(false) reaches this branch; no status/cart change.
			delete_option( $key );
			return jet_checkout_submission_failure();
		}
		jet_checkout_submission_state( $key, $manual_registration ? 'manual_registered' : 'success' );
	} catch ( Jet_Financial_Validation_Exception $error ) {
		if ( ! $bank_attempted && ! $manual_registration ) {
			delete_option( $key );
			return jet_checkout_submission_failure();
		}
		try { jet_checkout_submission_state( $key, $manual_registration ? 'manual_registration_failed' : 'uncertain' ); } catch ( Throwable $storage_error ) {}
		return jet_checkout_submission_failure( true );
	} catch ( Throwable $error ) {
		try {
			jet_checkout_submission_state( $key, $manual_registration ? 'manual_registration_failed' : 'uncertain' );
		} catch ( Throwable $storage_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The original processing/bank_sent claim still blocks delivery if storage is unavailable.
			// Retain the existing durable claim even when recording uncertainty fails.
		}
		return jet_checkout_submission_failure( true );
	}

	// Outside the primary exception boundary and after durable completion.
	jet_submission_notify( $notification );
	return $result;
}

/** Server-authenticated Store API context only; never take an order ID from payment_data/POST. */
function jet_checkout_store_api_replay( $context, &$result ) {
	if ( $result->status || ! in_array( $context->payment_method, array( 'jetpayment', 'jetpaymentcard' ), true ) || ! $context->order || $context->order->get_id() <= 0 ) { return; }
	$gateway = 'jetpaymentcard' === $context->payment_method ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
	$stored = jet_checkout_stored_result( $gateway, $context->order );
	if ( null === $stored ) { return; }
	$result->set_status( $stored['result'] );
	$result->set_payment_details( $stored );
	$result->set_redirect_url( $stored['redirect'] ?? '' );
}
// The WC legacy bridge validates current fields at priority 999. Only an existing
// durable outcome bypasses that bridge; new requests still undergo full validation.
add_action( 'woocommerce_rest_checkout_process_payment_with_context', 'jet_checkout_store_api_replay', 998, 2 );
