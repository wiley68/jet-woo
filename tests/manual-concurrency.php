<?php
/** Cross-process manual claims: temporary SQLite only; no WordPress/bootstrap/transport. */
require __DIR__ . '/optional-recipient.php';
if ( ! function_exists( 'pcntl_fork' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "DEFERRED: pcntl and pdo_sqlite are required.\n" ); exit( 1 );
}
function manual_database( $path ) {
	$db = new PDO( 'sqlite:' . $path ); $db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$db->exec( 'PRAGMA busy_timeout = 5000' ); return $db;
}
function manual_wait( $path ) {
	$deadline = microtime( true ) + 10;
	while ( ! file_exists( $path ) && microtime( true ) < $deadline ) { usleep( 1000 ); }
	if ( ! file_exists( $path ) ) { throw new RuntimeException( 'Synthetic manual barrier timed out.' ); }
}
foreach ( array_merge( $manual_cases, array( array( 'product', false, true ), array( 'cart', false, true ) ) ) as $case ) {
	list( $context, $blocks ) = $case; $distinct = $case[2] ?? false;
	manual_setup( $context, $blocks ); $checkout = 0 === strpos( $context, 'checkout' );
	$path = tempnam( sys_get_temp_dir(), 'jet-manual-' ); $pid = -1;
	try {
		$db = manual_database( $path );
		$db->exec( 'CREATE TABLE offline_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
		$db->exec( 'CREATE TABLE effects (kind TEXT)' );
		$wpdb->connection = $db;
		foreach ( $options as $key => $value ) { update_option( $key, $value ); }
		$pid = pcntl_fork();
		if ( -1 === $pid ) { throw new RuntimeException( 'Fork failed.' ); }
		if ( 0 === $pid ) {
			$wpdb->connection = manual_database( $path );
			$offline_effect = function ( $kind ) use ( $path ) {
				$GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' )->execute( array( $kind ) );
				file_put_contents( $path . '.saving', 'saving' ); manual_wait( $path . '.continue' );
			};
			$mail_behavior = function ( $mail ) {
				if ( is_array( $mail[0] ) ) { throw new RuntimeException( 'Manual worker attempted bank delivery.' ); }
				$GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' )->execute( array( 'secondary' ) ); return true;
			};
			try {
				$result = eur_submit( $context );
				exit( eur_success( $result, $context ) && 'manual_registered' === ( $result['code'] ?? '' ) ? 0 : 1 );
			} catch ( Throwable $error ) { fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" ); exit( 2 ); }
		}
		manual_wait( $path . '.saving' );
		if ( $distinct ) {
			$_POST['jet_submission_id'] = str_repeat( 'd', 32 );
			$_POST['jet_offer'] = jet_create_verified_offer( $context, array( 'product_id' => 42, 'variation_id' => 0, 'quantity' => 1 ), 0, 12, false, $_POST['jet_submission_id'] );
			$offline_effect = function ( $kind ) { $GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' )->execute( array( $kind ) ); };
			$mail_behavior = function ( $mail ) {
				if ( is_array( $mail[0] ) ) { throw new RuntimeException( 'Distinct manual request attempted bank delivery.' ); }
				$GLOBALS['wpdb']->connection->prepare( 'INSERT INTO effects VALUES (?)' )->execute( array( 'secondary' ) ); return true;
			};
		}
		$overlap = eur_submit( $context );
		if ( eur_success( $overlap, $context ) !== $distinct || count( $mails ) !== ( $distinct ? 2 : 0 ) || 'uncertain' === ( $overlap['code'] ?? '' ) ) { throw new RuntimeException( 'Manual overlap did not distinguish identical and independent applications.' ); }
		if ( $checkout ) {
			$other = 'checkout_card' === $context ? 'checkout_classic' : 'checkout_card';
			if ( eur_success( eur_submit( $other ), $other ) ) { throw new RuntimeException( 'Concurrent gateway switch bypassed manual claim.' ); }
		}
		file_put_contents( $path . '.continue', 'continue' ); pcntl_waitpid( $pid, $status ); $pid = -1;
		if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) ) { throw new RuntimeException( 'Primary manual worker failed.' ); }
		// Other requests may now have a valid bank list: a completed manual claim still wins.
		update_option( 'jet_email', 'bank@example.test' ); unset( $_POST['jet_offer'], $_POST['jet_card_offer'] );
		$result = eur_submit( $context ); manual_assert_complete( $context, $result );
		if ( count( $mails ) !== ( $distinct ? 2 : 0 ) || $orders_created !== ( $distinct ? 1 : 0 ) ) { throw new RuntimeException( 'Manual replay caused effects in the second worker.' ); }
		$counts = $db->query( 'SELECT kind, COUNT(*) FROM effects GROUP BY kind' )->fetchAll( PDO::FETCH_KEY_PAIR );
		$applications = $distinct ? 2 : 1;
		if ( array_sum( $counts ) !== 3 * $applications || 2 * $applications !== (int) ( $counts['secondary'] ?? 0 ) || $applications !== (int) ( $counts[$checkout ? 'status' : 'order'] ?? 0 ) ) { throw new RuntimeException( 'Manual workers duplicated or lost effects.' ); }
		echo 'PASS manual concurrency: ' . $context . ( $blocks ? '/Blocks' : '' ) . ( $distinct ? '/distinct applications' : '/same application' ) . "; two independent workers, $applications order/status effect(s), private notices, no bank/reference allocation.\n";
	} finally {
		if ( $pid > 0 ) { file_put_contents( $path . '.continue', 'continue' ); pcntl_waitpid( $pid, $status ); }
		$wpdb->connection = null; $db = null;
		foreach ( array( '', '.saving', '.continue' ) as $suffix ) { if ( file_exists( $path . $suffix ) ) { unlink( $path . $suffix ); } }
	}
}
