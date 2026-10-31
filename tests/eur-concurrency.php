<?php
/** Separate workers, isolated temporary SQLite only. No real database/mail/order calls. */
require __DIR__ . '/eur-bootstrap.php';
if ( ! function_exists( 'pcntl_fork' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "DEFERRED: pcntl and pdo_sqlite are required.\n" ); exit( 1 );
}
function eur_concurrent_database( $path ) {
	$db = new PDO( 'sqlite:' . $path );
	$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$db->exec( 'PRAGMA busy_timeout = 5000' );
	return $db;
}
function eur_concurrent_submit( $context ) {
	try {
		if ( 'product' === $context ) { jet_send(); }
		elseif ( 'cart' === $context ) { jet_send_card(); }
		else {
			$gateway = 'checkout_card' === $context ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
			return $gateway->process_payment( 314 );
		}
	} catch ( JetEurResponse $response ) { return $response->value; }
}
function eur_concurrent_ok( $result ) { return 'success' === ( $result['success'] ?? $result['result'] ?? '' ); }
function eur_concurrent_wait( $path ) {
	$deadline = microtime( true ) + 5;
	while ( ! file_exists( $path ) && microtime( true ) < $deadline ) { usleep( 1000 ); }
	if ( ! file_exists( $path ) ) { throw new RuntimeException( 'Offline barrier timed out.' ); }
}
foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	eur_reset();
	$card = 'checkout_card' === $context; $checkout = 0 === strpos( $context, 'checkout' );
	$calculation = jet_calculate_source_financing( jet_cart_financial_source(), 0, 12, $card, $context );
	$_POST = array( 'security' => 'valid', 'jet_lname' => '', 'jet_product_id' => '42', 'jet_variation_id' => '0', 'jet_quantity' => '1', 'jet_submission_id' => str_repeat( 'c', 32 ), 'jet_card' => '0', 'jet_email' => 'synthetic@example.test' );
	foreach ( $calculation as $key => $value ) {
		$name = ( $card ? str_replace( 'jet_', 'jet_card_', $key ) : $key ) . ( $checkout ? '_input' : '' );
		$_POST[$name] = 'jet_vnoski' === $key ? (string) $value : number_format( $value, 2, '.', '' );
	}
	foreach ( jet_financial_source_lists( jet_cart_financial_source() ) as $key => $value ) { $_POST[ $card ? str_replace( 'jet_', 'jet_card_', $key ) : $key ] = $value; }
	$prefix = $card ? 'jet_card_' : 'jet_';
	$_POST[$prefix . 'context'] = $checkout ? 'checkout_classic' : $context;
	$_POST[$prefix . 'offer'] = jet_create_verified_offer( $_POST[$prefix . 'context'], array( 'product_id' => 42, 'variation_id' => 0, 'quantity' => 1 ), 0, 12, $card, $checkout ? '' : $_POST['jet_submission_id'] );
	if ( $checkout ) { $_POST[$prefix . 'egn'] = 'synthetic'; $_POST[$prefix . 'uslovia'] = $_POST[$prefix . 'uslovia1'] = '1'; }
	$path = tempnam( sys_get_temp_dir(), 'jet-eur-test-' );
	$pid = -1;
	try {
		$db = eur_concurrent_database( $path );
		$db->exec( 'CREATE TABLE offline_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
		$db->exec( 'CREATE TABLE effects (kind TEXT)' );
		$wpdb->connection = $db;
		foreach ( $options as $key => $value ) { update_option( $key, $value ); }
		$pid = pcntl_fork();
		if ( -1 === $pid ) { throw new RuntimeException( 'Fork failed.' ); }
		if ( 0 === $pid ) {
			$wpdb->connection = eur_concurrent_database( $path );
			$offline_effect = function ( $kind ) use ( $wpdb ) {
				$query = $wpdb->connection->prepare( 'INSERT INTO effects VALUES (?)' ); $query->execute( array( $kind ) );
			};
			$mail_behavior = function ( $args ) use ( $path, $offline_effect ) {
				$bank = is_array( $args[0] ); $offline_effect( $bank ? 'bank' : 'secondary' );
				if ( $bank ) {
					file_put_contents( $path . '.bank', 'bank' );
					eur_concurrent_wait( $path . '.continue' );
				}
				return true;
			};
			try { exit( eur_concurrent_ok( eur_concurrent_submit( $context ) ) ? 0 : 1 ); }
			catch ( Throwable $error ) { fwrite( STDERR, get_class( $error ) . " in offline worker.\n" ); exit( 2 ); }
		}
		eur_concurrent_wait( $path . '.bank' );
		if ( eur_concurrent_ok( eur_concurrent_submit( $context ) ) || ! empty( $mails ) ) { throw new RuntimeException( 'Overlapping worker bypassed claim.' ); }
		file_put_contents( $path . '.continue', 'continue' );
		pcntl_waitpid( $pid, $status ); $pid = -1;
		if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) ) { throw new RuntimeException( 'Primary worker failed.' ); }
		if ( ! eur_concurrent_ok( eur_concurrent_submit( $context ) ) || ! empty( $mails ) ) { throw new RuntimeException( 'Replay did not return stored success.' ); }
		$counts = $db->query( 'SELECT kind, COUNT(*) AS n FROM effects GROUP BY kind' )->fetchAll( PDO::FETCH_KEY_PAIR );
		if ( 1 !== (int) $counts['bank'] || 2 !== (int) $counts['secondary'] || 1 !== (int) $counts[ $checkout ? 'status' : 'order' ] ) { throw new RuntimeException( 'Unexpected offline effects: ' . json_encode( $counts ) ); }
		echo "PASS EUR concurrency: $context, separate workers, one bank attempt and one order/status effect.\n";
	} finally {
		if ( $pid > 0 ) { file_put_contents( $path . '.continue', 'continue' ); pcntl_waitpid( $pid, $status ); }
		$wpdb->connection = null; $db = null;
		foreach ( array( '', '.bank', '.continue' ) as $suffix ) { if ( file_exists( $path . $suffix ) ) { unlink( $path . $suffix ); } }
	}
}
