<?php
/** Checkout-only doubles layered over the unchanged Product/Cart test behavior. */
require __DIR__ . '/submission-bootstrap.php';

class WC_Payment_Gateway {
	public $id, $icon, $has_fields, $method_title, $method_description, $title, $description, $form_fields, $settings;
	public function init_settings() { $this->settings = array( 'order_status' => 'wc-on-hold' ); }
	public function get_option( $key, $default = '' ) { return $this->settings[ $key ] ?? $default; }
	public function get_return_url( $order ) {
		if ( 'redirect' === ( $GLOBALS['checkout_failure'] ?? '' ) ) { throw new RuntimeException( 'Synthetic redirect failure.' ); }
		return 'https://shop.example.test/order-received/' . $order->get_id() . '/?key=synthetic-order-key';
	}
}
function apply_filters( $name, $value, ...$args ) { return $value; }
function wc_get_order_statuses() { return array( 'wc-on-hold' => 'On hold', 'wc-processing' => 'Processing' ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wc_get_order( $id ) { return $GLOBALS['checkout_orders'][ $id ] ?? false; }
function wc_add_notice( $message, $type ) { $GLOBALS['checkout_notices'][] = array( $message, $type ); }

class JetCheckoutTestOrder {
	public $id, $fields, $statuses = array();
	public function __construct( $id, $fields ) { $this->id = $id; $this->fields = $fields; }
	public function get_id() { return $this->id; }
	public function update_status( $status, $note ) {
		if ( 'status' === ( $GLOBALS['checkout_failure'] ?? '' ) ) { throw new RuntimeException( 'Synthetic status hook failure.' ); }
		$this->statuses[] = array( $status, $note );
		if ( isset( $GLOBALS['checkout_effect'] ) ) { ($GLOBALS['checkout_effect'])( 'status' ); }
	}
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'get_' ) ) { return $this->fields[ substr( $method, 4 ) ] ?? ''; }
		throw new RuntimeException( 'Unexpected order method: ' . $method );
	}
}
class JetCheckoutTestCart extends JetTestCart {
	public $empties = 0;
	public function empty_cart() {
		if ( 'cart' === ( $GLOBALS['checkout_failure'] ?? '' ) ) { throw new RuntimeException( 'Synthetic empty-cart failure.' ); }
		++$this->empties;
		$this->items = array();
		if ( isset( $GLOBALS['checkout_effect'] ) ) { ($GLOBALS['checkout_effect'])( 'cart' ); }
	}
}
class JetCheckoutTestSession {
	public $values;
	public function __construct( $values = array() ) { $this->values = $values; }
	public function get( $key ) { return $this->values[ $key ] ?? null; }
	public function __unset( $key ) { unset( $this->values[ $key ] ); }
}
require JET_INCLUDES_DIR . '/class-jet-payment-gateway.php';
require JET_INCLUDES_DIR . '/class-card-jet-payment-gateway.php';

function checkout_reset( $case ) {
	$_POST = $case['post'];
	$GLOBALS['records'] = $case['options'];
	$GLOBALS['product_title'] = $case['product_title'];
	$GLOBALS['admin_email'] = 'shop@example.test';
	$GLOBALS['mails'] = $GLOBALS['checkout_notices'] = array();
	$GLOBALS['mail_calls'] = $GLOBALS['order_calls'] = 0;
	$GLOBALS['mail_result'] = true;
	$GLOBALS['mail_behavior'] = $GLOBALS['during_mail'] = null;
	$GLOBALS['checkout_failure'] = '';
	$GLOBALS['wpdb']->fail = false;
	unset( $GLOBALS['checkout_effect'], $GLOBALS['test_option_write'] );
	$GLOBALS['checkout_orders'] = array( $case['order_id'] => new JetCheckoutTestOrder( $case['order_id'], $case['order_fields'] ) );
	$GLOBALS['checkout_wc'] = (object) array( 'cart' => new JetCheckoutTestCart(), 'session' => new JetCheckoutTestSession( $case['session'] ) );
	return 'card' === $case['method'] ? new Jet_Card_Payment_Gateway() : new Jet_Payment_Gateway();
}
