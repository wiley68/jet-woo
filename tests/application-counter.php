<?php
/** Counter failures/replays exercise all production submission paths, offline only. */
require __DIR__ . '/eur-backend.php';
$start = $checks;
foreach ( array( 0, 40, '', '0007', PHP_INT_MAX - 1 ) as $seed ) {
	eur_reset(); $options['jet_count'] = $seed;
	eur_expect( (int) $seed + 1 === jet_next_application_number(), 'Counter did not advance exactly once.' );
	eur_expect( array( array( 'jet_count', 'options' ), array( 'alloptions', 'options' ), array( 'notoptions', 'options' ) ) === array_slice( $offline_cache_deletes, -3 ), 'Counter caches were not invalidated.' );
}
eur_reset(); unset( $options['jet_count'] );
eur_expect( 1 === jet_next_application_number() && 2 === jet_next_application_number(), 'Missing option initialization reset a value.' );
foreach ( array( -1, '-1', '1.2', "1\n", 'bad', (string) PHP_INT_MAX, str_repeat( '9', 25 ) ) as $invalid ) {
	eur_reset(); $options['jet_count'] = $invalid;
	try { jet_next_application_number(); throw new LogicException( 'Invalid counter accepted.' ); }
	catch ( RuntimeException $error ) { eur_expect( ! ( $error instanceof LogicException ) && $invalid === $options['jet_count'], 'Invalid value changed or was accepted.' ); }
}
foreach ( array( 'product', 'cart', 'checkout_classic', 'checkout_card' ) as $context ) {
	foreach ( array( 'connection', 'initialize', 'update', 'read', 'reconnect' ) as $failure ) {
		eur_reset(); $_POST = eur_payload( $context ); $wpdb->counter_failure = $failure;
		eur_expect( ! eur_success( eur_submit( $context ), $context ) && empty( $mails ) && 0 === $orders_created && empty( $order->statuses ), "$context/$failure: counter failure reached banking/order effects." );
		$allocated = $wpdb->counter_updates; $wpdb->counter_failure = '';
		eur_expect( ! eur_success( eur_submit( $context ), $context ) && $allocated === $wpdb->counter_updates && empty( $mails ), "$context/$failure: uncertain replay allocated or resent." );
	}
	eur_reset(); $options['jet_count'] = 40; $_POST = eur_payload( $context );
	eur_expect( eur_success( eur_submit( $context ), $context ) && 41 === $options['jet_count'] && 1 === $wpdb->counter_updates, "$context: successful reservation invalid." );
	eur_expect( eur_success( eur_submit( $context ), $context ) && 41 === $options['jet_count'] && 1 === $wpdb->counter_updates && 3 === count( $mails ), "$context: successful replay allocated twice." );
	eur_expect( false !== strpos( $mails[0][1], ', онлайн заявка по поръчка 41' ), "$context: bank subject changed." );
	eur_reset(); $_POST = eur_payload( $context ); $mail_result = false;
	eur_expect( ! eur_success( eur_submit( $context ), $context ) && 1 === $options['jet_count'] && 1 === count( $mails ), "$context: failed delivery recycled its reference." );
}
echo 'PASS application counter: ' . ( $checks - $start ) . " additional assertions; validation/overflow, cache invalidation, all four failure and replay paths, no real side effects.\n";
