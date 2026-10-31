<?php
/**
 * Real overlapping PHP workers using an isolated temporary SQLite options double.
 * No WordPress database or mail transport is opened.
 * Run: php tests/email-concurrency.php (requires pcntl and pdo_sqlite).
 */
require __DIR__ . '/submission-bootstrap.php';
if ( ! function_exists( 'pcntl_fork' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "BLOCKED: pcntl and pdo_sqlite are required for the separate-worker test.\n" );
	exit( 1 );
}
$baseline = json_decode( file_get_contents( __DIR__ . '/fixtures/bank-baseline.json' ), true, 512, JSON_THROW_ON_ERROR );
function concurrent_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function concurrent_submit( $context ) {
	try {
		if ( 'cart' === $context ) { jet_send_card(); } else { jet_send(); }
	} catch ( JetTestResponse $response ) { return $response->response; }
	throw new RuntimeException( 'No AJAX response.' );
}
function concurrent_connect( $path ) {
	$connection = new PDO( 'sqlite:' . $path );
	$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$connection->exec( 'PRAGMA busy_timeout = 5000' );
	$GLOBALS['wpdb']->connection = $connection;
	return $connection;
}
function concurrent_wait( $path ) {
	$deadline = microtime( true ) + 10;
	do {
		clearstatcache( true, $path );
		if ( file_exists( $path ) ) { return; }
		if ( '.bank' === substr( $path, -5 ) && file_exists( substr( $path, 0, -5 ) . '.result' ) ) {
			throw new RuntimeException( 'Worker stopped before bank delivery: ' . file_get_contents( substr( $path, 0, -5 ) . '.result' ) );
		}
		usleep( 1000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Worker barrier timed out: ' . basename( $path ) );
}
foreach ( array( 'product', 'cart' ) as $context ) {
	$path = tempnam( sys_get_temp_dir(), 'jet-concurrency-' );
	$worker = null;
	try {
		$db = concurrent_connect( $path );
		$db->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
		$db->exec( 'CREATE TABLE effects (kind TEXT)' );
		$case = $baseline['cases'][ $context . '-standard' ];
		jet_test_setup_source( $case, $context );
		foreach ( $records as $key => $value ) { update_option( $key, $value ); }
		$_POST = $case['post'];
		$woocommerce->cart = new JetTestCart();
		jet_test_issue_offer( $context, false );
		// Each child opens its own connection, like independent PHP request workers.
		$wpdb->connection = $db = null;
		$worker = pcntl_fork();
		concurrent_expect( -1 !== $worker, 'Cannot fork primary request.' );
		if ( 0 === $worker ) {
			try {
				concurrent_connect( $path );
				$order_observer = function () { $GLOBALS['wpdb']->connection->exec( "INSERT INTO effects VALUES ('order')" ); };
				$mail_behavior = function ( $mail ) use ( $path ) {
					$kind = is_array( $mail[0] ) ? 'bank' : ( 'shop@example.test' === $mail[0] ? 'admin' : 'customer' );
					$query = $GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' );
					$query->execute( array( $kind ) );
					if ( 'bank' === $kind ) {
						file_put_contents( $path . '.bank', 'bank' );
						concurrent_wait( $path . '.continue' );
					}
					if ( 'admin' === $kind ) { throw new RuntimeException( 'Secondary hook failure in primary worker.' ); }
					return true;
				};
				$result = concurrent_submit( $context );
				concurrent_expect( 'success' === $result['success'], 'Primary worker failed: ' . ( $result['code'] ?? 'unknown' ) );
				file_put_contents( $path . '.result', 'success' );
				exit( 0 );
			} catch ( Throwable $error ) {
				file_put_contents( $path . '.result', $error->getMessage() );
				fwrite( STDERR, $error->getMessage() . "\n" );
				exit( 1 );
			}
		}
		concurrent_wait( $path . '.bank' );
		// Parent is a separate request while the original worker is still in bank wp_mail.
		$db = concurrent_connect( $path );
		concurrent_expect( 'processing' === concurrent_submit( $context )['code'], 'Concurrent request escaped the atomic claim.' );
		concurrent_expect( 0 === $mail_calls && 0 === $order_calls, 'Concurrent request performed side effects.' );
		file_put_contents( $path . '.continue', 'continue' );
		concurrent_wait( $path . '.result' );
		concurrent_expect( 'success' === file_get_contents( $path . '.result' ), 'Primary request lost success after secondary exception.' );
		pcntl_waitpid( $worker, $status );
		$worker = null;
		concurrent_expect( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Primary worker exited unsuccessfully.' );
		concurrent_expect( 'success' === concurrent_submit( $context )['success'], 'Completed request replay failed.' );
		$effects = $db->query( 'SELECT kind FROM effects ORDER BY rowid' )->fetchAll( PDO::FETCH_COLUMN );
		concurrent_expect( $effects === array( 'bank', 'order', 'admin', 'customer' ), 'Workers duplicated or skipped delivery/order: ' . json_encode( $effects ) );
		$claim = $db->query( "SELECT option_value FROM wp_options WHERE option_name LIKE 'jet_submission_%'" )->fetchColumn();
		concurrent_expect( unserialize( $claim ) === array( 'success' => 'success' ), 'Claim stored PII or lost completed success.' );
		echo "PASS $context: separate overlapping PHP workers, one bank mail/order, secondary exception isolated, replay returns success.\n";
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
