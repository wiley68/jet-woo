<?php
/** Emit actual gateway HTML with empty descriptions using offline WC/WP doubles only. */
require __DIR__ . '/eur-bootstrap.php';
echo '<form class="checkout"><input type="radio" name="payment_method" value="jetpayment" checked><input type="radio" name="payment_method" value="jetpaymentcard">';
foreach ( array( 'Jet_Payment_Gateway', 'Jet_Card_Payment_Gateway' ) as $class ) {
	$gateway = new $class(); $gateway->description = '';
	if ( ! $gateway->has_fields() ) { throw new RuntimeException( 'An empty description prevents WC payment_fields rendering.' ); }
	$gateway->payment_fields();
}
echo '</form>';
