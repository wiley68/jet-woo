<?php
/**
 * Atomic duplicate protection for product-page and cart applications.
 *
 * @package jetcredit
 */

/**
 * Compute the unchanged application identity for lookup and atomic claiming.
 *
 * INSERT IGNORE relies on the unique option_name index, so simultaneous PHP
 * workers cannot both claim the same application. A transient or a nonce alone
 * cannot provide this guarantee. Only a keyed hash is stored, never form data.
 *
 * @param array  $data    Unslashed request data, after nonce verification in the AJAX handler.
 * @param string $context Application source: product or cart.
 * @return string Deterministic option name; this function does not claim it.
 */
function jet_product_submission_key( $data, $context = 'product' ) {
	$submission_id = isset( $data['jet_submission_id'] ) ? $data['jet_submission_id'] : '';
	if ( ! is_string( $submission_id ) || ( '' !== $submission_id && ! preg_match( '/^[a-f0-9]{16,64}$/', $submission_id ) ) ) {
		wp_send_json(
			array(
				'success' => 'unsuccess',
				'code'    => 'invalid_submission_id',
			)
		);
	}

	$identity = array( 'id' => $submission_id );
	if ( 'cart' === $context ) {
		// Keep product request keys compatible with the initial 1.9.2 implementation.
		$identity['context'] = 'cart';
	}
	if ( '' === $submission_id ) {
		// Compatibility with cached copies of the previous frontend script.
		$fields = array(
			'jet_product_id',
			'jet_variation_id',
			'jet_quantity',
			'jet_card',
			'jet_priceall',
			'jet_vnoski',
			'jet_vnoska',
			'jet_parva',
			'jet_total_credit_price',
			'jet_obshto',
			'jet_gpr',
			'jet_glp',
			'jet_name',
			'jet_lastname',
			'jet_egn',
			'jet_email',
			'jet_phone',
		);
		if ( 'cart' === $context ) {
			$fields = array_merge( $fields, array( 'jet_products', 'jet_products_qt', 'jet_products_pr', 'jet_products_vr' ) );
		}
		foreach ( $fields as $field ) {
			$value              = isset( $data[ $field ] ) ? $data[ $field ] : '';
			$identity[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
	}

	return 'jet_submission_' . hash_hmac( 'sha256', wp_json_encode( $identity ), wp_salt( 'nonce' ) );
}

/** Read an existing delivery result before revalidating a cart already emptied by success. */
function jet_product_submission_status( $data, $context = 'product' ) {
	$key = jet_product_submission_key( $data, $context );
	wp_cache_delete( $key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$response = get_option( $key );
	if ( false === $response ) {
		return null;
	}
	return is_array( $response ) ? $response : array( 'success' => 'unsuccess', 'code' => 'uncertain' );
}

/** Own an application atomically before any mail or order side effects. */
function jet_claim_product_submission( $data, $context = 'product' ) {
	global $wpdb;
	$key = jet_product_submission_key( $data, $context );
	$submission_id = isset( $data['jet_submission_id'] ) ? $data['jet_submission_id'] : '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic claim requires INSERT IGNORE, not the Options API upsert.
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			$key,
			maybe_serialize(
				array(
					'success' => 'unsuccess',
					'code'    => 'processing',
				)
			),
			'no'
		)
	);

	if ( false === $result ) {
		// Fail closed: do not create an order when protection cannot be recorded.
		wp_send_json(
			array(
				'success' => 'unsuccess',
				'code'    => 'submission_storage_error',
			)
		);
	}

	// Another worker may have completed the request since this worker started.
	wp_cache_delete( $key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( 0 === $result ) {
		$response = get_option( $key );
		wp_send_json(
			is_array( $response ) ? $response : array(
				'success' => 'unsuccess',
				'code'    => 'uncertain',
			)
		);
	}

	// Retain browser request IDs for a day; old scripts use a short dedupe window.
	$retention = '' === $submission_id ? 10 * MINUTE_IN_SECONDS : DAY_IN_SECONDS;
	wp_schedule_single_event( time() + $retention, 'jet_cleanup_product_submission', array( $key ) );
	return $key;
}

/**
 * Remove expired duplicate protection without autoloading records on page views.
 *
 * @param string $key Stored option name.
 * @return void
 */
function jet_cleanup_product_submission( $key ) {
	if ( is_string( $key ) && preg_match( '/^jet_submission_[a-f0-9]{64}$/', $key ) ) {
		delete_option( $key );
	}
}
add_action( 'jet_cleanup_product_submission', 'jet_cleanup_product_submission' );
