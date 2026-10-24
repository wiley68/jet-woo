<?php
/** Shared standalone test doubles. No WordPress bootstrap, database or network delivery. */
error_reporting( E_ALL );
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
$jet_test_source_root = getenv( 'JET_TEST_SOURCE_ROOT' ) ?: dirname( __DIR__ );
define( 'JET_INCLUDES_DIR', $jet_test_source_root . '/includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class JetTestResponse extends RuntimeException {
	public $response;
	public function __construct( $response ) { $this->response = $response; }
}
class JetTestDatabase {
	public $options = 'wp_options';
	public $fail = false;
	// Optional isolated SQLite store for the real multi-process regression only.
	public $connection = null;
	public function prepare( $sql, ...$args ) {
		if ( strpos( $sql, 'INSERT IGNORE INTO wp_options' ) !== 0 ) { throw new RuntimeException( 'Expected atomic insert.' ); }
		return $args;
	}
	public function query( $args ) {
		if ( $this->fail ) { return false; }
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
// WordPress is_email() is replaced locally; validation never invokes a mail transport.
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function check_ajax_referer( ...$args ) {
	if ( ( $_POST['security'] ?? '' ) !== 'valid' ) { wp_send_json( array( 'code' => 'bad_nonce' ) ); }
}
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-only-key'; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( ...$args ) {}
function get_option( $key ) {
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( 'SELECT option_value FROM wp_options WHERE option_name = ?' );
		$query->execute( array( $key ) );
		$value = $query->fetchColumn();
		return false === $value ? false : unserialize( $value );
	}
	return $GLOBALS['records'][ $key ] ?? false;
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
	public function __construct( $id ) {}
	public function get_title() { return $GLOBALS['product_title']; }
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
class JetTestCart {
	public $items = array( 'first' => array(), 'second' => array() );
	public $removals = 0;
	public function get_cart() { return $this->items; }
	public function remove_cart_item( $key ) {
		if ( 'cart' === $GLOBALS['order_failure'] ) { throw new RuntimeException( 'Simulated cart hook failure.' ); }
		unset( $this->items[ $key ] ); ++$this->removals;
	}
}
$woocommerce = (object) array( 'cart' => new JetTestCart() );
function wc_create_order() {
	++$GLOBALS['order_calls'];
	$GLOBALS['order_saved'] = false;
	if ( isset( $GLOBALS['order_observer'] ) ) { ($GLOBALS['order_observer'])(); }
	if ( $GLOBALS['order_throws'] ) { throw new RuntimeException( 'Simulated order hook failure.' ); }
	return new JetTestOrder();
}
function WC() {
	if ( isset( $GLOBALS['checkout_wc'] ) ) { return $GLOBALS['checkout_wc']; }
	return (object) array( 'payment_gateways' => new class {
		public function get_available_payment_gateways() {
			return array( 'jetpayment' => (object) array( 'settings' => array( 'order_status' => 'wc-on-hold' ) ) );
		}
	} );
}
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
