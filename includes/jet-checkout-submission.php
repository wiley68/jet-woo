<?php
/**
 * Durable per-order delivery protection for both checkout payment methods.
 *
 * @package jetcredit
 */

// Reuse the Product/Cart recipient policy and secondary delivery unchanged.
require_once __DIR__ . '/jet-submission-mail.php';

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
	$key = 'jet_checkout_submission_' . $order->get_id();
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
		if ( is_array( $record ) && isset( $record['state'] ) && 'success' === $record['state'] ) {
			try {
				return array(
					'result'   => 'success',
					'redirect' => $gateway->get_return_url( $order ),
				);
			} catch ( Throwable $error ) {
				// A redirect hook failure must not make the accepted bank mail retryable.
				return jet_checkout_submission_failure( true );
			}
		}
		// Processing, bank-sent, uncertain or malformed state: never guess and resend.
		return jet_checkout_submission_failure( true );
	}

	try {
		$bank_recipients = jet_submission_bank_recipients();
		if ( empty( $bank_recipients ) ) {
			delete_option( $key );
			return jet_checkout_submission_failure();
		}
		$notification = array();
		$result       = $submit( $order, $bank_recipients, $key, $notification );
		if ( ! is_array( $result ) || ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
			// Only a definite bank wp_mail(false) reaches this branch; no status/cart change.
			delete_option( $key );
			return jet_checkout_submission_failure();
		}
		jet_checkout_submission_state( $key, 'success' );
	} catch ( Throwable $error ) {
		try {
			jet_checkout_submission_state( $key, 'uncertain' );
		} catch ( Throwable $storage_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The original processing/bank_sent claim still blocks delivery if storage is unavailable.
			// Retain the existing durable claim even when recording uncertainty fails.
		}
		return jet_checkout_submission_failure( true );
	}

	// Outside the primary exception boundary and after durable completion.
	jet_submission_notify( $notification );
	return $result;
}
