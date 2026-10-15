<?php
/** Standalone regression test: php tests/product-submission.php; JET_TEST_CART=1 tests the cart endpoint. */
error_reporting( E_ALL );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class JetTestResponse extends RuntimeException {
	public $response;
	public function __construct( $response ) { $this->response = $response; }
}
class JetTestDatabase {
	public $options = 'wp_options';
	public $fail = false;
	public function prepare( $sql, ...$args ) {
		if ( strpos( $sql, 'INSERT IGNORE INTO wp_options' ) !== 0 ) { throw new RuntimeException( 'Expected atomic insert.' ); }
		return $args;
	}
	public function query( $args ) {
		if ( $this->fail ) { return false; }
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
$order_throws = false;
$during_mail = null;
function add_action( ...$args ) {}
function check_ajax_referer( ...$args ) {
	if ( ( $_POST['security'] ?? '' ) !== 'valid' ) { wp_send_json( array( 'code' => 'bad_nonce' ) ); }
}
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-only-key'; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( ...$args ) {}
function get_option( $key ) { return $GLOBALS['records'][ $key ] ?? false; }
function update_option( $key, $value, ...$args ) { $GLOBALS['records'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['records'][ $key ] ); }
function wp_schedule_single_event( $when, $hook, $args ) { $GLOBALS['scheduled'][] = array( $when, $hook, $args ); }
function wp_send_json( $response ) { throw new JetTestResponse( $response ); }
function get_bloginfo( $key ) { return 'shop@example.test'; }
function wp_get_post_terms( ...$args ) { return array(); }
function get_term_by( ...$args ) { return false; }
function is_user_logged_in() { return false; }
function is_wp_error( $value ) { return false; }
function jet_sanitize_bank_email_field( $value ) { return (string) $value; }
class WC_Product {
	public function __construct( $id ) {}
	public function get_title() { return 'Test product'; }
}
class WC_Product_Variation extends WC_Product {
	public function get_attributes() { return array(); }
}
class JetTestOrder {
	public function __call( $name, $args ) {}
}
class JetTestCart {
	public $items = array( 'first' => array(), 'second' => array() );
	public $removals = 0;
	public function get_cart() { return $this->items; }
	public function remove_cart_item( $key ) { unset( $this->items[ $key ] ); ++$this->removals; }
}
$woocommerce = (object) array( 'cart' => new JetTestCart() );
function wc_create_order() {
	++$GLOBALS['order_calls'];
	if ( $GLOBALS['order_throws'] ) { throw new RuntimeException( 'Simulated order hook failure.' ); }
	return new JetTestOrder();
}
function WC() {
	return (object) array( 'payment_gateways' => new class {
		public function get_available_payment_gateways() { return array(); }
	} );
}
function wp_mail( ...$args ) {
	++$GLOBALS['mail_calls'];
	if ( $GLOBALS['during_mail'] ) {
		$callback = $GLOBALS['during_mail'];
		$GLOBALS['during_mail'] = null;
		$callback();
	}
	return $GLOBALS['mail_result'];
}
require dirname( __DIR__ ) . '/includes/jet_calculate.php';
function expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function submit() {
	try {
		if ( getenv( 'JET_TEST_CART' ) ) { jet_send_card(); } else { jet_send(); }
	} catch ( JetTestResponse $response ) { return $response->response; }
	throw new RuntimeException( 'No JSON response.' );
}
$_POST = array(
	'security' => 'valid', 'jet_lname' => '', 'jet_product_id' => '42', 'jet_quantity' => '1',
	'jet_priceall' => '1000', 'jet_name' => 'Test', 'jet_lastname' => 'Customer',
	'jet_email' => 'customer@example.test', 'jet_submission_id' => str_repeat( 'a', 32 ),
	'jet_products' => '42_43', 'jet_products_qt' => '2_1',
	'jet_products_pr' => '500.00_250.00', 'jet_products_vr' => '0_77',
);
// Re-enter while the first request is inside wp_mail: the second worker must stop before side effects.
$during_mail = function () { expect( submit()['code'] === 'processing', 'Concurrent request was not blocked.' ); };
expect( submit()['success'] === 'success', 'First request failed.' );
expect( submit()['success'] === 'success', 'Replay did not return original success.' );
expect( $mail_calls === 1 && $order_calls === 1, 'Replay sent mail or created another order.' );
if ( getenv( 'JET_TEST_CART' ) ) {
	expect( $woocommerce->cart->removals === 2, 'Cart was not emptied exactly once.' );
}
expect( count( $scheduled ) === 1, 'Replay scheduled extra cleanup.' );
expect( $scheduled[0][0] >= time() + DAY_IN_SECONDS - 2, 'Token retention is too short.' );

// The same token keeps its identity after a lost response, even if the form was edited.
$_POST['jet_name'] = 'Edited';
expect( submit()['success'] === 'success' && $order_calls === 1, 'Edited retry created another order.' );

// A new deliberate application can succeed.
$_POST['jet_submission_id'] = str_repeat( 'b', 32 );
expect( submit()['success'] === 'success' && $order_calls === 2, 'New application was blocked.' );
// The product and cart endpoints must not share a request identity.
$other_key = jet_claim_product_submission( $_POST, getenv( 'JET_TEST_CART' ) ? 'product' : 'cart' );
delete_option( $other_key );

// Cached old frontend: nonce changes must not bypass the payload fingerprint.
unset( $_POST['jet_submission_id'] );
expect( submit()['success'] === 'success', 'Legacy request failed.' );
$before = $order_calls;
expect( submit()['success'] === 'success' && $order_calls === $before, 'Legacy replay created an order.' );
expect( end( $scheduled )[0] <= time() + 10 * MINUTE_IN_SECONDS, 'Legacy retention is incorrect.' );
if ( getenv( 'JET_TEST_CART' ) ) {
	foreach ( array( 'jet_products' => '42_44', 'jet_products_qt' => '3_1', 'jet_products_pr' => '501.00_250.00', 'jet_products_vr' => '0_78' ) as $field => $value ) {
		$original = $_POST[ $field ];
		$_POST[ $field ] = $value;
		$before = $order_calls;
		expect( submit()['success'] === 'success' && $order_calls === $before + 1, 'Changed cart incorrectly deduplicated: ' . $field );
		expect( submit()['success'] === 'success' && $order_calls === $before + 1, 'Changed cart replay created an order: ' . $field );
		$_POST[ $field ] = $original;
	}
}

// Failure after sending mail remains claimed: automatic replay would be unsafe.
$_POST['jet_submission_id'] = str_repeat( 'c', 32 );
$order_throws = true;
expect( submit()['code'] === 'uncertain', 'Partial failure was not recorded.' );
$before = $mail_calls;
$order_throws = false;
expect( submit()['code'] === 'uncertain' && $mail_calls === $before, 'Partial failure resent mail.' );

// A mail rejection creates no order and permits a deliberate retry.
$_POST['jet_submission_id'] = str_repeat( 'd', 32 );
$mail_result = false;
$before = $order_calls;
expect( submit()['success'] === 'unsuccess' && $order_calls === $before, 'Mail rejection created an order.' );
$mail_result = true;
expect( submit()['success'] === 'success' && $order_calls === $before + 1, 'Safe retry failed.' );

// Storage failure and invalid inputs must fail before any mail or order.
$_POST['jet_submission_id'] = str_repeat( 'e', 32 );
$wpdb->fail = true;
$before = $mail_calls;
expect( submit()['code'] === 'submission_storage_error' && $mail_calls === $before, 'Storage failure was not closed.' );
$wpdb->fail = false;
$_POST['jet_submission_id'] = array( 'invalid' );
expect( submit()['code'] === 'invalid_submission_id' && $mail_calls === $before, 'Invalid ID was processed.' );
$_POST['security'] = 'invalid';
expect( submit()['code'] === 'bad_nonce' && $mail_calls === $before, 'Nonce validation was bypassed.' );

// Cleanup cannot delete unrelated plugin settings; protection records contain no customer data.
$records['jet_count'] = 123;
jet_cleanup_product_submission( 'jet_count' );
expect( $records['jet_count'] === 123, 'Cleanup deleted an unrelated setting.' );
foreach ( $records as $key => $record ) {
	if ( strpos( $key, 'jet_submission_' ) === 0 ) {
		expect( strpos( serialize( $record ), '@' ) === false, 'Protection stored customer data.' );
		jet_cleanup_product_submission( $key );
		expect( ! isset( $records[ $key ] ), 'Expired record was not removed.' );
	}
}
echo 'PASS (' . ( getenv( 'JET_TEST_CART' ) ? 'cart' : 'product' ) . "): concurrent/repeated requests, endpoint isolation, legacy frontend, partial failure, mail retry, storage failure, validation, cleanup.\n";
