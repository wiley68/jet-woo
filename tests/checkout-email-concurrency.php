<?php
/** C06: separate overlapping checkout PHP workers; isolated SQLite doubles, no SMTP/WP database. */
require __DIR__ . '/checkout-bootstrap.php';
if ( ! function_exists( 'pcntl_fork' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "BLOCKED: pcntl and pdo_sqlite are required for concurrent checkout tests.\n" );
	exit( 1 );
}
$baseline = json_decode( file_get_contents( __DIR__ . '/fixtures/checkout-bank-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
function checkout_concurrent_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function checkout_concurrent_connect( $path ) {
	$db = new PDO( 'sqlite:' . $path );
	$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$db->exec( 'PRAGMA busy_timeout = 5000' );
	$GLOBALS['wpdb']->connection = $db;
	return $db;
}
function checkout_concurrent_wait( $path ) {
	$deadline = microtime( true ) + 10;
	do {
		clearstatcache( true, $path );
		if ( file_exists( $path ) ) { return; }
		if ( '.bank' === substr( $path, -5 ) && file_exists( substr( $path, 0, -5 ) . '.result' ) ) {
			throw new RuntimeException( 'Worker stopped before bank delivery: ' . file_get_contents( substr( $path, 0, -5 ) . '.result' ) );
		}
		usleep( 1000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Checkout worker barrier timed out: ' . basename( $path ) );
}
foreach ( array( 'standard', 'card' ) as $method ) {
	foreach ( array( 'success', 'secondary-exception', 'bank-exception', 'status-exception', 'cross-gateway' ) as $scenario ) {
		$path = tempnam( sys_get_temp_dir(), 'jet-checkout-concurrency-' );
		$worker = null;
		try {
			$case = $baseline['cases'][$method . '-bgn-normal'];
			$gateway = checkout_reset( $case );
			$db = checkout_concurrent_connect( $path );
			$db->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
			$db->exec( 'CREATE TABLE effects (kind TEXT)' );
			foreach ( $records as $key => $value ) { update_option( $key, $value ); }
			$wpdb->connection = $db = null;
			$worker = pcntl_fork();
			checkout_concurrent_expect( -1 !== $worker, 'Cannot fork checkout worker.' );
			if ( 0 === $worker ) {
				try {
					checkout_concurrent_connect( $path );
					$checkout_effect = function ( $kind ) {
						$query = $GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' );
						$query->execute( array( $kind ) );
					};
					if ( 'status-exception' === $scenario ) { $checkout_failure = 'status'; }
					$mail_behavior = function ( $mail ) use ( $path, $scenario, $checkout_effect ) {
						$kind = is_array( $mail[0] ) ? 'bank' : ( 'shop@example.test' === $mail[0] ? 'admin' : 'customer' );
						$checkout_effect( $kind );
						if ( 'bank' === $kind ) {
							file_put_contents( $path . '.bank', 'bank' );
							checkout_concurrent_wait( $path . '.continue' );
							if ( 'bank-exception' === $scenario ) { throw new RuntimeException( 'Unknown bank delivery in worker.' ); }
						}
						if ( 'admin' === $kind && 'secondary-exception' === $scenario ) { throw new RuntimeException( 'Secondary hook failure in worker.' ); }
						return true;
					};
					$result = $gateway->process_payment(314);
					file_put_contents( $path . '.result', json_encode( $result ) );
					exit( 0 );
				} catch ( Throwable $error ) {
					file_put_contents( $path . '.result', json_encode( array( 'unexpected_error' => $error->getMessage() ) ) );
					fwrite( STDERR, $error->getMessage() . "\n" );
					exit( 1 );
				}
			}
			checkout_concurrent_wait( $path . '.bank' );
			$db = checkout_concurrent_connect( $path );
			if ( 'cross-gateway' === $scenario ) { $gateway = 'card' === $method ? new Jet_Payment_Gateway() : new Jet_Card_Payment_Gateway(); }
			checkout_concurrent_expect( array( 'result' => 'failure' ) === $gateway->process_payment(314), 'Concurrent checkout escaped the gate.' );
			checkout_concurrent_expect( get_option( 'jet_checkout_submission_314' ) === array( 'state' => 'processing' ), 'Concurrent request changed the held state.' );
			checkout_concurrent_expect( 0 === $mail_calls && 0 === $checkout_wc->cart->empties && empty( $checkout_orders[314]->statuses ), 'Concurrent request performed side effects.' );
			file_put_contents( $path . '.continue', 'continue' );
			checkout_concurrent_wait( $path . '.result' );
			pcntl_waitpid( $worker, $status );
			$worker = null;
			checkout_concurrent_expect( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Checkout worker failed.' );
			$expected_success = ! in_array( $scenario, array( 'bank-exception', 'status-exception' ), true );
			$result = json_decode( file_get_contents( $path . '.result' ), true, 512, JSON_THROW_ON_ERROR );
			checkout_concurrent_expect( $result === ( $expected_success ? $case['result'] : array( 'result' => 'failure' ) ), 'Primary result inconsistent with delivery uncertainty.' );
			$effects = $db->query( 'SELECT kind FROM effects ORDER BY rowid' )->fetchAll( PDO::FETCH_COLUMN );
			checkout_concurrent_expect( $effects === ( $expected_success ? array( 'bank', 'status', 'cart', 'admin', 'customer' ) : array( 'bank' ) ), 'Duplicate or premature delivery/status/cart side effects: ' . json_encode( $effects ) );
			$record = get_option( 'jet_checkout_submission_314' );
			checkout_concurrent_expect( $record === array( 'state' => $expected_success ? 'success' : 'uncertain' ), 'Durable claim lost success/uncertainty or contains personal data.' );
			// A fresh worker request with no financing/session data still uses the durable claim.
			$_POST = array(); $checkout_wc->session = new JetCheckoutTestSession();
			checkout_concurrent_expect( $result === $gateway->process_payment(314), 'Retry did not reuse the recorded outcome.' );
			checkout_concurrent_expect( $effects === $db->query( 'SELECT kind FROM effects ORDER BY rowid' )->fetchAll( PDO::FETCH_COLUMN ), 'Retry duplicated side effects.' );
			checkout_concurrent_expect( 'no' === $db->query( "SELECT autoload FROM wp_options WHERE option_name = 'jet_checkout_submission_314'" )->fetchColumn(), 'Claim is autoloaded.' );
			echo "PASS C06 $method/$scenario: separate workers, one bank attempt, durable result, no replay side effects.\n";
		} finally {
			if ( $worker > 0 ) {
				file_put_contents( $path . '.continue', 'continue' );
				pcntl_waitpid( $worker, $status );
			}
			$wpdb->connection = $db = null;
			foreach ( array( '', '.bank', '.continue', '.result' ) as $suffix ) {
				if ( file_exists( $path . $suffix ) ) { unlink( $path . $suffix ); }
			}
		}
	}
}
