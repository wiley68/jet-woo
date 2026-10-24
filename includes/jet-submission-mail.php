<?php
/**
 * Isolated delivery for credit application messages.
 *
 * @package jetcredit
 */

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
 * @return array Valid bank addresses, or no recipients (no fallback).
 */
function jet_submission_bank_recipients() {
	$configured = get_option( 'jet_email' );
	if ( ! is_string( $configured ) || preg_match( '/[\r\n]/', $configured ) ) {
		return array();
	}
	$recipients = array();
	foreach ( explode( ',', $configured ) as $candidate ) {
		$email = jet_submission_single_email( $candidate );
		if ( '' === $email ) {
			return array();
		}
		$recipients[] = $email;
	}
	return array_values( array_unique( $recipients ) );
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
			$body   .= "Вашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.\r\n\r\n";
			$body   .= "Това съобщение потвърждава подаването на заявката, но не означава, че кредитът е одобрен.\r\n\r\n";
			$body   .= 'Благодарим Ви!';
			wp_mail( $customer, $subject, $body, $notification['headers'] );
		}
	} catch ( Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentionally retain the completed submission result.
		// Notification delivery must not make a completed application retryable.
	}
}
