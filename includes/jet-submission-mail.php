<?php
/**
 * Isolated delivery for credit application messages.
 *
 * @package jetcredit
 */

/** Reserve a reference before bank delivery, inside the existing submission claim. */
function jet_next_application_number() {
	global $wpdb;
	$maximum = (string) PHP_INT_MAX;
	try {
		$connection = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( ! is_scalar( $connection ) || ! ctype_digit( (string) $connection ) ) {
			throw new RuntimeException( 'Unable to identify the counter connection.' );
		}
		// Unlike an Options API upsert, a competing initializer cannot reset the row.
		$initialized = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			'jet_count', '0', 'no'
		) );
		if ( false === $initialized ) {
			throw new RuntimeException( 'Unable to initialize the application counter.' );
		}
		// InnoDB serializes this update. The reserved value belongs to this connection,
		// not to a later read of the shared row or wpdb's INSERT-only insert_id property.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options}
			 SET option_value = LAST_INSERT_ID(CAST(CASE WHEN option_value = '' THEN '0' ELSE option_value END AS UNSIGNED) + 1)
			 WHERE option_name = %s
			 AND option_value NOT REGEXP '[^0-9]'
			 AND CHAR_LENGTH(option_value) <= %d
			 AND CAST(CASE WHEN option_value = '' THEN '0' ELSE option_value END AS DECIMAL(20,0)) < CAST(%s AS DECIMAL(20,0))",
			'jet_count', strlen( $maximum ), $maximum
		) );
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Unable to reserve an application number.' );
		}
		$reserved = $wpdb->get_row( 'SELECT CONNECTION_ID() AS connection_id, LAST_INSERT_ID() AS number', ARRAY_A );
		$number = is_array( $reserved ) ? (string) ( $reserved['number'] ?? '' ) : '';
		if ( ! is_array( $reserved ) || (string) $connection !== (string) ( $reserved['connection_id'] ?? '' )
			|| ! ctype_digit( $number ) || '0' === $number || strlen( $number ) > strlen( $maximum )
			|| ( strlen( $number ) === strlen( $maximum ) && strcmp( $number, $maximum ) > 0 ) ) {
			throw new RuntimeException( 'Unable to read the reserved application number.' );
		}
		return (int) $number;
	} finally {
		// SQL may have committed even if reading its result failed. Never cache a guess.
		wp_cache_delete( 'jet_count', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}

/**
 * Validate one address without turning a public field into a recipient list.
 *
 * @param mixed $email Candidate address.
 * @return string Valid address or an empty string.
 */
function jet_submission_single_email( $email ) {
	if ( ! is_string( $email ) || preg_match( '/[\x00-\x1f\x7f,;]/', $email ) ) {
		return '';
	}
	$email = trim( $email );
	return is_email( $email ) ? $email : '';
}

/**
 * Accept only an explicitly configured bank list; reject the whole invalid list.
 *
 * @return array|null Addresses, an intentionally empty list, or null for invalid configuration.
 */
function jet_submission_bank_recipients() {
	$configured = get_option( 'jet_email' );
	if ( ! is_string( $configured ) || preg_match( '/[\r\n]/', $configured ) ) {
		return null;
	}
	if ( '' === trim( $configured, " \t" ) ) { return array(); }
	$recipients = array();
	foreach ( explode( ',', $configured ) as $candidate ) {
		$email = jet_submission_single_email( $candidate );
		if ( '' === $email ) {
			return null;
		}
		$recipients[] = $email;
	}
	return array_values( array_unique( $recipients ) );
}

/** A store registration is not a bank delivery or credit approval. */
function jet_submission_manual_message() {
	return 'Вашата заявка е регистрирана в магазина за ръчна обработка. Не е изпратена до банката и не означава одобрение на кредит.';
}

/** Reserve bank references only for bank attempts; keep manual outcomes explicit. */
function jet_submission_deliver( $recipients, $merchant, $body, $headers, &$subject, &$bank_attempted ) {
	if ( array() === $recipients ) {
		$subject = $merchant . ', заявка за ръчна обработка';
		return 'manual_registered';
	}
	if ( ! is_array( $recipients ) ) { throw new RuntimeException( 'Invalid bank recipients.' ); }
	$number = jet_next_application_number();
	$subject = $merchant . ", онлайн заявка по поръчка $number";
	$bank_attempted = true;
	return wp_mail( $recipients, $subject, $body, $headers ) ? 'bank_sent' : 'bank_failed';
}

/**
 * Replace just the five personal rows at the start of the original bank body.
 * Fail closed if the expected structure is absent, never send an unmasked copy.
 *
 * @param string $body Original bank message.
 * @return string Masked copy, or an empty string for an unexpected template.
 */
function jet_submission_admin_body( $body ) {
	$lines = explode( "\r\n", $body, 8 );
	if ( 8 !== count( $lines ) || 'Данни за потребителя:' !== $lines[0] || '' !== $lines[6] ) {
		return '';
	}
	$fields = array(
		'Собствено име: '     => '********',
		'Фамилия: '           => '********',
		'ЕГН: '               => '**********',
		'Телефон за връзка: ' => '**********',
		'Имейл адрес: '       => '********',
	);
	$index  = 1;
	foreach ( $fields as $label => $mask ) {
		if ( 0 !== strpos( $lines[ $index ], $label ) || ';' !== substr( $lines[ $index ], -1 ) || preg_match( '/[\r\n]/', $lines[ $index ] ) ) {
			return '';
		}
		$lines[ $index ] = $label . $mask . ';';
		++$index;
	}
	return implode( "\r\n", $lines );
}

/**
 * Best-effort secondary mail after the completed submission has been recorded.
 * Each notification has its own exception boundary. No payload is persisted in
 * the claim, and notification failures must never change its completed result.
 *
 * @param array $notification In-memory message data from a successful flow.
 * @return void
 */
function jet_submission_notify( $notification ) {
	try {
		$admin = jet_submission_single_email( $notification['admin'] );
		$body  = jet_submission_admin_body( $notification['body'] );
		if ( '' !== $admin && '' !== $body ) {
			if ( ! empty( $notification['manual'] ) ) { $body = jet_submission_manual_message() . "\r\n\r\n" . $body; }
			wp_mail( $admin, $notification['subject'], $body, $notification['headers'] );
		}
	} catch ( Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentionally isolate secondary delivery failures.
		// The primary submission is complete; continue with the customer notice.
	}

	try {
		$customer = jet_submission_single_email( $notification['customer'] );
		if ( '' !== $customer ) {
			$subject = 'Потвърждение за подадена заявка за кредит';
			$body    = "Здравейте,\r\n\r\n";
			$body   .= ! empty( $notification['manual'] )
				? jet_submission_manual_message() . "\r\n\r\n"
				: "Вашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.\r\n\r\n";
			$body   .= "Това съобщение потвърждава подаването на заявката, но не означава, че кредитът е одобрен.\r\n\r\n";
			$body   .= 'Благодарим Ви!';
			wp_mail( $customer, $subject, $body, $notification['headers'] );
		}
	} catch ( Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentionally retain the completed submission result.
		// Notification delivery must not make a completed application retryable.
	}
}
