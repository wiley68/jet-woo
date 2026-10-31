<?php
/** Isolated doubles only. No WordPress bootstrap, real database, orders or transport. */
error_reporting( E_ALL );
define( 'ABSPATH', '/jet-offline/' );
define( 'JET_INCLUDES_DIR', dirname( __DIR__ ) . '/includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

class JetEurResponse extends RuntimeException {
	public $value;
	public function __construct( $value ) { $this->value = $value; }
}
require __DIR__ . '/counter-double.php';
class JetEurDatabase {
	use JetCounterDouble;
	public $counter_store = 'options';
	public $options = 'offline_options';
	public $prefix = 'offline_';
	public $fail = false;
	public $connection = null;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_row( $query, $format ) { return is_string( $query ) && 0 === strpos( $query, 'SELECT CONNECTION_ID() AS' ) ? $this->counter_row() : $GLOBALS['promo_row']; }
	public function query( $query ) {
		if ( $this->fail ) { return false; }
		list( $sql, $args ) = $query;
		$result = $this->counter_query( $sql, $args, $handled );
		if ( $handled ) { return $result; }
		if ( 0 !== strpos( $sql, 'INSERT IGNORE' ) ) { throw new RuntimeException( 'Unexpected database operation.' ); }
		if ( $this->connection ) {
			$statement = $this->connection->prepare( 'INSERT OR IGNORE INTO offline_options VALUES (?, ?, ?)' );
			$statement->execute( $args );
			return $statement->rowCount();
		}
		if ( array_key_exists( $args[0], $GLOBALS['options'] ) ) { return 0; }
		$GLOBALS['options'][ $args[0] ] = unserialize( $args[1] );
		return 1;
	}
}
class WC_Product {
	public $id, $price, $parent = 0;
	public function __construct( $id ) { $this->id = (int) $id; $this->price = $GLOBALS['catalog'][ $this->id ] ?? 0; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $type ) { return $type === ( $this->parent ? 'variation' : 'simple' ); }
	public function get_title() { return 'Synthetic product'; }
	public function get_name() { return $this->get_title(); }
	public function get_price() { return $this->price; }
	public function get_tax_class() { return ''; }
	public function get_attributes() { return array(); }
}
class WC_Product_Variation extends WC_Product {
	public function __construct( $id ) { parent::__construct( $id ); $this->parent = 42; }
}
require __DIR__ . '/financial-order-bootstrap.php';
class JetEurCart {
	use JetOfflineCartAmounts;
	public $items, $total = 1000, $empties = 0;
	public function __construct() { $this->items = array( 'item' => array( 'product_id' => 42, 'variation_id' => 0, 'quantity' => 1, 'data' => new WC_Product( 42 ), 'line_subtotal' => 1000, 'line_subtotal_tax' => 0, 'line_total' => 1000, 'line_tax' => 0, 'line_tax_data' => array( 'subtotal' => array(), 'total' => array() ) ) ); }
	public function get_cart() { return $this->items; }
	public function get_total( $context = '' ) { return $this->total; }
	public function remove_cart_item( $key ) { unset( $this->items[ $key ] ); }
	public function empty_cart() { $this->items = array(); ++$this->empties; }
}
class JetEurSession {
	public $values = array(), $customer = 'synthetic-session';
	public function get( $key ) { return $this->values[ $key ] ?? null; }
	public function set( $key, $value ) { $this->values[ $key ] = $value; }
	public function __unset( $key ) { unset( $this->values[ $key ] ); }
	public function has_session() { return true; }
	public function get_customer_id() { return $this->customer; }
}
class JetEurItem {
	public function get_product_id() { return 42; }
	public function get_variation_id() { return 0; }
	public function get_quantity() { return 1; }
	public function get_subtotal() { return 1000; }
	public function get_subtotal_tax() { return 0; }
	public function get_total() { return 1000; }
	public function get_total_tax() { return 0; }
}
class JetEurOrder {
	public $currency = 'EUR', $total = 1000, $statuses = array();
	public function get_id() { return 314; }
	public function get_currency( $context = 'view' ) { return $this->currency; }
	public function get_items( $type ) { return array( new JetEurItem() ); }
	public function get_total() { return $this->total; }
	public function update_status( $status, $note ) {
		$this->statuses[] = $status;
		if ( isset( $GLOBALS['offline_effect'] ) ) { ( $GLOBALS['offline_effect'] )( 'status' ); }
	}
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'get_billing_' ) ) { return 'get_billing_email' === $method ? 'synthetic@example.test' : 'Synthetic'; }
		return '';
	}
}
class WC_Payment_Gateway {
	public $id, $icon, $has_fields, $method_title, $method_description, $title, $description, $form_fields, $settings;
	public $enabled = 'yes', $max_amount = 0, $supports = array( 'products' );
	public function init_settings() { $this->settings = array( 'order_status' => 'wc-on-hold' ); }
	public function get_option( $key, $default = '' ) { return $this->settings[ $key ] ?? $default; }
	public function get_return_url( $order ) { return '/synthetic-receipt'; }
	public function get_description() { return $this->description; }
	public function has_fields() { return (bool) $this->has_fields; }
	public function get_order_total() { return WC()->cart->get_total( 'float' ); }
}
function did_action( $name ) { return 1; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function get_option( $key, $default = false ) {
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( 'SELECT option_value FROM offline_options WHERE option_name = ?' );
		$query->execute( array( $key ) ); $value = $query->fetchColumn();
		return false === $value ? $default : unserialize( $value );
	}
	return $GLOBALS['options'][ $key ] ?? $default;
}
function update_option( $key, $value, ...$args ) {
	if ( isset( $GLOBALS['offline_option_write'] ) ) {
		$result = ( $GLOBALS['offline_option_write'] )( $key, $value );
		if ( null !== $result ) { return $result; }
	}
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( "INSERT INTO offline_options VALUES (?, ?, 'no') ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value" );
		return $query->execute( array( $key, serialize( $value ) ) );
	}
	$GLOBALS['options'][ $key ] = $value; return true;
}
function delete_option( $key ) {
	if ( $GLOBALS['wpdb']->connection ) {
		$query = $GLOBALS['wpdb']->connection->prepare( 'DELETE FROM offline_options WHERE option_name = ?' ); $query->execute( array( $key ) ); return;
	}
	unset( $GLOBALS['options'][ $key ] );
}
function wp_cache_delete( ...$args ) { $GLOBALS['offline_cache_deletes'][] = $args; }
function wp_schedule_single_event( ...$args ) {}
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'offline-synthetic-key'; }
function wp_unslash( $value ) { return $value; }
function wp_create_nonce( $action ) { return 'valid'; }
function wp_send_json_success( $value ) { wp_send_json( array( 'success' => true, 'data' => $value ) ); }
function wp_send_json_error( $value ) { wp_send_json( array( 'success' => false, 'data' => $value ) ); }
function wp_send_json( $value ) { throw new JetEurResponse( $value ); }
function check_ajax_referer( ...$args ) { if ( 'valid' !== ( $_POST['security'] ?? '' ) ) { throw new Jet_Financial_Validation_Exception( 'bad_nonce' ); } }
function get_woocommerce_currency() { return $GLOBALS['effective_currency']; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function absint( $value ) { return abs( (int) $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wc_get_product( $id ) { return ! isset( $GLOBALS['catalog'][ (int) $id ] ) ? false : ( 77 === (int) $id ? new WC_Product_Variation( $id ) : new WC_Product( $id ) ); }
function wc_get_price_including_tax( $product, $args = array() ) { return round( $product->price * ( $args['qty'] ?? 1 ) * ( 1 + ( $GLOBALS['offline_tax_rate'] ?? 0 ) ), 2 ); }
function wc_get_order( $id ) { return 314 === $id ? $GLOBALS['order'] : false; }
function wc_create_order() {
	++$GLOBALS['orders_created'];
	if ( isset( $GLOBALS['offline_effect'] ) ) { ( $GLOBALS['offline_effect'] )( 'order' ); }
	return new JetEurOrder();
}
function wc_get_order_statuses() { return array( 'wc-on-hold' => 'Hold' ); }
function wc_add_notice( $message, $type ) { $GLOBALS['notices'][] = $type; }
function is_wp_error( $value ) { return false; }
function WC() { return $GLOBALS['woocommerce']; }
function get_bloginfo( $key ) { return 'shop@example.test'; }
function wp_get_post_terms( ...$args ) { return array(); }
function get_term_by( ...$args ) { return false; }
function is_user_logged_in() { return false; }
function wp_mail( ...$args ) {
	$GLOBALS['mails'][] = $args;
	if ( $GLOBALS['mail_behavior'] ) { return ( $GLOBALS['mail_behavior'] )( $args ); }
	if ( $GLOBALS['during_mail'] ) { $callback = $GLOBALS['during_mail']; $GLOBALS['during_mail'] = null; $callback(); }
	return $GLOBALS['mail_result'];
}
function eur_reset() {
	$GLOBALS['options'] = array( 'woocommerce_currency' => 'EUR', 'jet_minprice' => 75, 'jet_purcent' => 1.4, 'jet_purcent_card' => 1, 'jet_card_in' => 1, 'jet_status_in' => 1, 'jet_vnoski_default' => 12, 'jet_count' => 0, 'jet_email' => 'bank@example.test', 'jet_id' => 'SYNTHETIC' );
	$GLOBALS['effective_currency'] = 'EUR';
	$GLOBALS['catalog'] = array( 42 => 1000, 77 => 1200 );
	$GLOBALS['wpdb'] = new JetEurDatabase();
	$GLOBALS['promo_row'] = null;
	$GLOBALS['woocommerce'] = new JetOfflineWoo( array( 'cart' => new JetEurCart(), 'session' => new JetEurSession(), 'payment_gateways' => new class {
		public function get_available_payment_gateways() { return array(); }
	} ) );
	$GLOBALS['order'] = new JetEurOrder();
	$GLOBALS['mails'] = $GLOBALS['notices'] = array();
	$GLOBALS['orders_created'] = 0;
	$GLOBALS['mail_result'] = true;
	$GLOBALS['during_mail'] = null;
	$GLOBALS['mail_behavior'] = null;
	unset( $GLOBALS['offline_effect'], $GLOBALS['offline_checkout_hook'], $GLOBALS['offline_order_save_hook'], $GLOBALS['offline_cart_recalculate'], $GLOBALS['offline_tax_rate'], $GLOBALS['offline_net_price'], $GLOBALS['saved_financial_order'], $GLOBALS['offline_vat_exempt'], $GLOBALS['offline_option_write'] );
	$_POST = array();
}
eur_reset();
require JET_INCLUDES_DIR . '/functions.php';
require JET_INCLUDES_DIR . '/jet_calculate.php';
require JET_INCLUDES_DIR . '/class-jet-payment-gateway.php';
require JET_INCLUDES_DIR . '/class-card-jet-payment-gateway.php';
