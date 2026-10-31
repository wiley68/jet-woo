<?php
/** Shared standalone test doubles. No WordPress bootstrap, database or network delivery. */
error_reporting( E_ALL );
define( 'ABSPATH', '/jet-regression-offline/' );
$jet_test_source_root = getenv( 'JET_TEST_SOURCE_ROOT' ) ?: dirname( __DIR__ );
define( 'JET_INCLUDES_DIR', $jet_test_source_root . '/includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
$jet_test_salt = bin2hex( random_bytes( 32 ) );

class JetTestResponse extends RuntimeException {
	public $response;
	public function __construct( $response ) { $this->response = $response; }
}
require __DIR__ . '/counter-double.php';
class JetTestDatabase {
	use JetCounterDouble;
	public $counter_store = 'records';
	public $options = 'wp_options';
	public $prefix = 'wp_';
	public $fail = false;
	// Optional isolated SQLite store for the real multi-process regression only.
	public $connection = null;
	public function prepare( $sql, ...$args ) {
		if ( false === strpos( $sql, 'SELECT jet_product_percent' ) && strpos( $sql, 'INSERT IGNORE INTO wp_options' ) !== 0 && strpos( $sql, 'UPDATE wp_options' ) !== 0 ) { throw new RuntimeException( 'Unexpected prepared SQL.' ); }
		return array( $sql, $args );
	}
	public function get_row( $query, $format ) { return is_string( $query ) && 0 === strpos( $query, 'SELECT CONNECTION_ID() AS' ) ? $this->counter_row() : null; }
	public function query( $query ) {
		if ( $this->fail ) { return false; }
		list( $sql, $args ) = $query;
		$result = $this->counter_query( $sql, $args, $handled );
		if ( $handled ) { return $result; }
		if ( $this->connection ) {
			$query = $this->connection->prepare( 'INSERT OR IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)' );
			$query->execute( $args );
			return $query->rowCount();
		}
		if ( isset( $GLOBALS['records'][ $args[0] ] ) ) { return 0; }
		$GLOBALS['records'][ $args[0] ] = unserialize( $args[1] );
		return 1;
	}
}
$wpdb = new JetTestDatabase();
$records = array();
$scheduled = array();
$mail_calls = $order_calls = 0;
$mail_result = true;
$mails = array();
$mail_behavior = null;
$actions = array();
$order_saved = false;
$product_title = 'Test product';
$admin_email = 'shop@example.test';
$order_events = array();
$order_failure = '';
$order_throws = false;
$during_mail = null;
function add_action( ...$args ) { $GLOBALS['actions'][ $args[0] ] = $args[1]; }
function add_filter( ...$args ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
// WordPress is_email() is replaced locally; validation never invokes a mail transport.
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function check_ajax_referer( ...$args ) {
	if ( ( $_POST['security'] ?? '' ) !== 'valid' ) { wp_send_json( array( 'code' => 'bad_nonce' ) ); }
}
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return $GLOBALS['jet_test_salt']; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( ...$args ) { $GLOBALS['offline_cache_deletes'][] = $args; }
function get_option( $key, $default = false ) {
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( 'SELECT option_value FROM wp_options WHERE option_name = ?' );
		$query->execute( array( $key ) );
		$value = $query->fetchColumn();
		return false === $value ? $default : unserialize( $value );
	}
	return $GLOBALS['records'][ $key ] ?? $default;
}
function update_option( $key, $value, ...$args ) {
	if ( isset( $GLOBALS['test_option_write'] ) ) {
		$result = ($GLOBALS['test_option_write'])( $key, $value );
		if ( null !== $result ) { return $result; }
	}
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( "INSERT INTO wp_options VALUES (?, ?, 'no') ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value" );
		return $query->execute( array( $key, serialize( $value ) ) );
	}
	$GLOBALS['records'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( 'DELETE FROM wp_options WHERE option_name = ?' );
		$query->execute( array( $key ) );
		return;
	}
	unset( $GLOBALS['records'][ $key ] );
}
function wp_schedule_single_event( $when, $hook, $args ) { $GLOBALS['scheduled'][] = array( $when, $hook, $args ); }
function wp_send_json( $response ) { throw new JetTestResponse( $response ); }
function get_bloginfo( $key ) { return $GLOBALS['admin_email']; }
function wp_get_post_terms( ...$args ) { return array(); }
function get_term_by( ...$args ) { return false; }
function is_user_logged_in() { return false; }
function is_wp_error( $value ) { return false; }
class WC_Product {
	public $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $GLOBALS['test_parents'][$this->id] ?? 0; }
	public function is_type( $type ) { return $type === ( $this->get_parent_id() ? 'variation' : 'simple' ); }
	public function get_title() { return $GLOBALS['product_title']; }
	public function get_name() { return $this->get_title(); }
	public function get_price() { return $GLOBALS['test_catalog'][$this->id] ?? 0; }
	public function get_tax_class() { return ''; }
}
class WC_Product_Variation extends WC_Product {
	public function get_attributes() { return array(); }
}
class JetTestOrder {
	public function save() {
		if ( 'save' === $GLOBALS['order_failure'] ) { throw new RuntimeException( 'Simulated order save failure.' ); }
		$GLOBALS['order_saved'] = true;
	}
	public function __call( $name, $args ) { $GLOBALS['order_events'][] = array( $name, $args ); }
}
require __DIR__ . '/financial-order-bootstrap.php';
class JetTestCart {
	use JetOfflineCartAmounts;
	public $items = array();
	public $total;
	public function __construct() {
		$this->total = $GLOBALS['test_source_amount'] ?? 1000;
		$lines = $GLOBALS['test_source_lines'] ?? array( array( 42, 0, 2, 375 ), array( 43, 77, 1, 250 ) );
		foreach ( $lines as $key => $line ) {
			list( $product, $variation, $quantity, $price ) = $line;
			$this->items[$key] = array( 'product_id' => $product, 'variation_id' => $variation, 'quantity' => $quantity, 'line_subtotal' => $price * $quantity, 'line_subtotal_tax' => 0, 'line_total' => $price * $quantity, 'line_tax' => 0, 'line_tax_data' => array( 'subtotal' => array(), 'total' => array() ), 'data' => $variation ? new WC_Product_Variation( $variation ) : new WC_Product( $product ) );
		}
	}
	public function get_total( $context = '' ) { return $this->total; }
	public $removals = 0;
	public function get_cart() { return $this->items; }
	public function remove_cart_item( $key ) {
		if ( 'cart' === $GLOBALS['order_failure'] ) { throw new RuntimeException( 'Simulated cart hook failure.' ); }
		unset( $this->items[ $key ] ); ++$this->removals;
	}
}
class JetTestSession {
	public $values = array();
	public function __construct( $values = array() ) { $this->values = $values; }
	public function get( $key ) { return $this->values[$key] ?? null; }
	public function set( $key, $value ) { $this->values[$key] = $value; }
	public function __unset( $key ) { unset( $this->values[$key] ); }
	public function has_session() { return true; }
	public function get_customer_id() { return 'synthetic-regression-session'; }
}
function jet_test_payment_gateways() {
	return new class {
		public function get_available_payment_gateways() { return array( 'jetpayment' => (object) array( 'settings' => array( 'order_status' => 'wc-on-hold' ) ) ); }
	};
}
$woocommerce = new JetOfflineWoo( array( 'cart' => new JetTestCart(), 'session' => new JetTestSession(), 'payment_gateways' => jet_test_payment_gateways() ) );
function wc_create_order() {
	++$GLOBALS['order_calls'];
	$GLOBALS['order_saved'] = false;
	if ( isset( $GLOBALS['order_observer'] ) ) { ($GLOBALS['order_observer'])(); }
	if ( $GLOBALS['order_throws'] ) { throw new RuntimeException( 'Simulated order hook failure.' ); }
	return new JetTestOrder();
}
function WC() { return $GLOBALS['checkout_wc'] ?? $GLOBALS['woocommerce']; }
function get_woocommerce_currency() { return $GLOBALS['test_currency'] ?? 'EUR'; }
function wc_get_product( $id ) { return isset( $GLOBALS['test_catalog'][(int) $id] ) ? ( isset( $GLOBALS['test_parents'][(int) $id] ) ? new WC_Product_Variation( $id ) : new WC_Product( $id ) ) : false; }
function wc_get_price_including_tax( $product, $args = array() ) { return ( $GLOBALS['test_catalog'][$product->get_id()] ?? 0 ) * ( $args['qty'] ?? 1 ); }
function wp_create_nonce( $action ) { return 'valid'; }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_mail( ...$args ) {
	++$GLOBALS['mail_calls'];
	$GLOBALS['mails'][] = $args;
	if ( $GLOBALS['mail_behavior'] ) { return ($GLOBALS['mail_behavior'])( $args ); }
	if ( $GLOBALS['during_mail'] ) {
		$callback = $GLOBALS['during_mail'];
		$GLOBALS['during_mail'] = null;
		$callback();
	}
	return $GLOBALS['mail_result'];
}
require JET_INCLUDES_DIR . '/functions.php';
require JET_INCLUDES_DIR . '/jet_calculate.php';

require __DIR__ . '/eur-regression-fixtures.php';
