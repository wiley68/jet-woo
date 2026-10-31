<?php
/** In-memory order persistence and isolated execution of installed WC checkout-copy methods. */
class ProductType { const VARIATION = 'variation'; }
class ProductTaxStatus { const TAXABLE = 'taxable'; const NONE = 'none'; }
class JetOfflineCustomer { public function get_is_vat_exempt() { return $GLOBALS['offline_vat_exempt'] ?? false; } }
class JetOfflineShipping { public function get_packages() { return WC()->cart->shipping_packages ?? array(); } }
class JetOfflineWoo {
	public $cart, $session, $payment_gateways, $customer;
	public function __construct( $values ) { foreach ( $values as $key => $value ) { $this->$key = $value; } $this->customer = new JetOfflineCustomer(); }
	public function shipping() { return new JetOfflineShipping(); }
}
trait JetOfflineCartAmounts {
	public $discount_total = 0, $discount_tax = 0, $shipping_total = 0, $shipping_tax = 0;
	public $fees = array(), $coupons = array(), $shipping_packages = array();
	public function calculate_totals() { if ( isset( $GLOBALS['offline_cart_recalculate'] ) ) { ( $GLOBALS['offline_cart_recalculate'] )( $this ); } }
	public function get_customer() { return WC()->customer; }
	public function get_discount_total() { return $this->discount_total; }
	public function get_discount_tax() { return $this->discount_tax; }
	public function get_shipping_total() { return $this->shipping_total; }
	public function get_shipping_tax() { return $this->shipping_tax; }
	public function get_cart_contents_tax() { return array_sum( array_column( $this->items, 'line_tax' ) ); }
	public function get_fee_total() { return array_sum( array_column( $this->fees, 'total' ) ); }
	public function get_fee_tax() { return array_sum( array_column( $this->fees, 'tax' ) ); }
	public function get_fees() { return $this->fees; }
	public function get_coupons() { return $this->coupons; }
	public function get_applied_coupons() { return array_keys( $this->coupons ); }
	public function get_coupon_discount_amount( $code ) { return $this->coupons[$code]->discount; }
	public function get_coupon_discount_tax_amount( $code ) { return $this->coupons[$code]->discount_tax; }
	public function get_cart_contents_taxes() { $taxes = array(); foreach ( $this->items as $item ) { foreach ( $item['line_tax_data']['total'] as $id => $tax ) { $taxes[$id] = ( $taxes[$id] ?? 0 ) + $tax; } } return $taxes; }
	public function get_shipping_taxes() { return $this->shipping_tax ? array( 1 => $this->shipping_tax ) : array(); }
	public function get_tax_amount( $id ) { return ( $this->get_cart_contents_taxes()[$id] ?? 0 ) + ( $this->get_fee_taxes()[$id] ?? 0 ); }
	public function get_shipping_tax_amount( $id ) { return $this->get_shipping_taxes()[$id] ?? 0; }
	public function get_fee_taxes() { $taxes = array(); foreach ( $this->fees as $fee ) { foreach ( $fee->tax_data as $id => $tax ) { $taxes[$id] = ( $taxes[$id] ?? 0 ) + $tax; } } return $taxes; }
}
class WC_Order {
	private $id = 0;
	public $props = array( 'currency' => 'EUR' ), $items = array();
	public function get_id() { return $this->id; }
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'set_' ) ) {
			$this->props[substr( $method, 4 )] = $args[0];
			if ( isset( $GLOBALS['order_events'] ) ) { $GLOBALS['order_events'][] = array( $method, $args ); }
		} elseif ( 0 === strpos( $method, 'get_' ) ) { return $this->props[substr( $method, 4 )] ?? 0; }
	}
	public function add_meta_data( $name, $value, $unique = false ) { $this->props[$name] = $value; }
	public function get_currency( $context = 'view' ) { return $this->props['currency']; }
	public function get_total() { return $this->props['total'] ?? 0; }
	public function get_discount_total() { return $this->props['discount_total'] ?? 0; }
	public function get_discount_tax() { return $this->props['discount_tax'] ?? 0; }
	public function get_shipping_total() { return $this->props['shipping_total'] ?? 0; }
	public function get_shipping_tax() { return $this->props['shipping_tax'] ?? 0; }
	public function get_items( $types = 'line_item' ) { return array_values( array_filter( $this->items, function ( $item ) use ( $types ) { return in_array( $item->type, (array) $types, true ); } ) ); }
	public function add_item( $item ) { $this->items[] = $item; }
	public function get_shipping_methods() { return $this->get_items( 'shipping' ); }
	public function get_total_fees() { return array_sum( array_map( function ( $item ) { return $item->get_total(); }, $this->get_items( 'fee' ) ) ); }
	public function get_coupon_codes() { return array_map( function ( $item ) { return $item->get_code(); }, $this->get_items( 'coupon' ) ); }
	public function get_taxable_location() { return array( 'country' => 'BG', 'state' => '', 'postcode' => '', 'city' => '' ); }
	public function update_status( $status, $note ) { $this->props['status'] = $status; }
	public function save() {
		if ( ! $this->id ) { $persisted = wc_create_order(); $persisted->save(); $this->id = 900; }
		if ( isset( $GLOBALS['offline_order_save_hook'] ) ) { ( $GLOBALS['offline_order_save_hook'] )( $this ); }
		$GLOBALS['saved_financial_order'] = $this;
		return $this->id;
	}
}
class JetOfflineItem {
	public $type, $props = array(), $legacy_values, $legacy_cart_item_key, $legacy_fee, $legacy_fee_key, $legacy_package_key;
	public function get_id() { return 0; }
	public function set_backorder_meta() {}
	public function set_props( $values ) { $this->props = array_merge( $this->props, $values ); if ( isset( $values['taxes'] ) ) { $this->set_taxes( $values['taxes'] ); } }
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'set_' ) ) { $this->props[substr( $method, 4 )] = $args[0]; }
		if ( 0 === strpos( $method, 'get_' ) ) { return $this->props[substr( $method, 4 )] ?? 0; }
	}
	public function add_meta_data( $key, $value, $unique = false ) { $this->props['meta'][$key] = $value; }
	public function set_taxes( $taxes ) {
		$this->props['taxes'] = $taxes ?: array( 'total' => array(), 'subtotal' => array() );
		foreach ( array( 'total', 'subtotal' ) as $field ) {
			$values = $this->props['taxes'][$field] ?? array();
			$this->props[$field . '_tax'] = array_sum( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? $values : array_map( 'wc_round_tax_total', $values ) );
		}
	}
}
class WC_Order_Item_Product extends JetOfflineItem {
	public $type = 'line_item';
	public function set_product( $product ) { $this->props['product_id'] = $product->get_parent_id() ?: $product->get_id(); $this->props['variation_id'] = $product->get_parent_id() ? $product->get_id() : 0; }
	public function calculate_taxes( $location ) {
		$tax = ( $GLOBALS['offline_tax_rate'] ?? 0 ) * $this->get_total();
		$this->set_taxes( array( 'total' => $tax ? array( 1 => $tax ) : array(), 'subtotal' => $tax ? array( 1 => $tax ) : array() ) );
		return true;
	}
}
class WC_Order_Item_Fee extends JetOfflineItem { public $type = 'fee'; }
class WC_Order_Item_Shipping extends JetOfflineItem { public $type = 'shipping'; }
class WC_Order_Item_Coupon extends JetOfflineItem { public $type = 'coupon'; }
class WC_Order_Item_Tax extends JetOfflineItem { public $type = 'tax'; }
class WC_Tax { public static function get_rate_code( $id ) { return 'synthetic-tax'; } public static function get_rate_label( $id ) { return 'VAT'; } public static function is_compound( $id ) { return false; } public static function get_rate_percent_value( $id ) { return 20; } }
function wc_round_tax_total( $amount ) { return round( $amount, 2, PHP_ROUND_HALF_UP ); }
function wc_format_decimal( $amount ) { return (string) $amount; }
function wc_get_price_excluding_tax( $product, $args = array() ) { return $GLOBALS['offline_net_price'] ?? $product->get_price() * ( $args['qty'] ?? 1 ); }
function do_action( $name, ...$args ) { if ( isset( $GLOBALS['offline_checkout_hook'] ) ) { ( $GLOBALS['offline_checkout_hook'] )( $name, ...$args ); } }
function wc_get_container() { return new class { public function get( $name ) { return new class { public function get_rate_objects_for_ids( $ids ) { return array(); } public function get_product_name( $product, $variation ) { return $product->get_name(); } }; } }; }

// Execute installed core copying code, rather than a test implementation of its
// financial behavior. Only data objects, persistence, hooks and transports are doubles.
function jet_offline_checkout_method( $name ) {
	$path = dirname( __DIR__, 2 ) . '/woocommerce/includes/class-wc-checkout.php';
	$tokens = token_get_all( file_get_contents( $path ) ); $capture = false; $depth = 0; $body = '';
	foreach ( $tokens as $i => $token ) {
		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$j = $i + 1; while ( is_array( $tokens[$j] ) && T_WHITESPACE === $tokens[$j][0] ) { ++$j; }
			$capture = isset( $tokens[$j][1] ) && $name === $tokens[$j][1];
		}
		if ( ! $capture ) { continue; }
		$body .= is_array( $token ) ? $token[1] : $token;
		if ( '{' === $token ) { ++$depth; }
		if ( '}' === $token && --$depth === 0 ) { return 'public ' . $body; }
	}
	throw new RuntimeException( 'Installed WC checkout method unavailable: ' . $name );
}
$jet_offline_methods = '';
foreach ( array( 'set_data_from_cart', 'create_order_line_items', 'create_order_fee_lines', 'create_order_shipping_lines', 'create_order_coupon_lines', 'create_order_tax_lines' ) as $jet_offline_method ) { $jet_offline_methods .= jet_offline_checkout_method( $jet_offline_method ); }
eval( 'class WC_Checkout { public static function instance() { return new self(); }' . $jet_offline_methods . '}' );
