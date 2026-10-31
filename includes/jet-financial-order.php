<?php
/** Unsaved financial snapshots. Call only inside the existing delivery claim. */
require_once __DIR__ . '/jet-verified-offer.php';

function jet_assert_financial_source( $actual, $expected ) {
	if ( wp_json_encode( $actual ) !== wp_json_encode( $expected ) ) {
		throw new Jet_Financial_Validation_Exception( 'stale_financial_values' );
	}
}

function jet_prepare_financial_order( $context, $data, $billing, $shipping ) {
	$source = jet_offer_source( $context, array( 'product_id' => $data['jet_product_id'] ?? null, 'variation_id' => $data['jet_variation_id'] ?? 0, 'quantity' => $data['jet_quantity'] ?? null ) );
	$order = new WC_Order();
	$order->set_currency( 'EUR' );
	$order->set_address( $billing, 'billing' );
	$order->set_address( $shipping, 'shipping' );
	$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
	if ( 'cart' === $context ) {
		// Native copying preserves discounts, shipping/fee/tax/coupon lines and extension
		// metadata handled by WooCommerce's checkout item hooks. No save or recalculation.
		WC_Checkout::instance()->set_data_from_cart( $order );
		jet_assert_financial_source( jet_order_financial_source( $order ), $source );
		$shipping = 0; $shipping_tax = 0;
		foreach ( $order->get_shipping_methods() as $item ) {
			$shipping += jet_financial_number( $item->get_total() );
			$shipping_tax += jet_financial_number( $item->get_total_tax() );
		}
		jet_assert_financial_amount( $shipping, $order->get_shipping_total() );
		jet_assert_financial_amount( $shipping_tax, $order->get_shipping_tax() );
	} else {
		$line = $source['lines'][0];
		$product = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $line['quantity'] );
		$net = wc_get_price_excluding_tax( $product, array( 'qty' => $line['quantity'], 'order' => $order ) );
		$item->set_subtotal( $net ); $item->set_total( $net );
		$exempt = isset( WC()->customer ) && WC()->customer && WC()->customer->get_is_vat_exempt();
		$order->add_meta_data( 'is_vat_exempt', $exempt ? 'yes' : 'no', true );
		$order->add_item( $item );
		if ( $exempt ) { $item->set_taxes( false ); }
		elseif ( ! $item->calculate_taxes( $order->get_taxable_location() ) ) {
			throw new Jet_Financial_Validation_Exception( 'unsupported_financial_order' );
		}
		jet_assert_financial_amount( (float) $item->get_total() + (float) $item->get_total_tax(), $source['amount'] );
		foreach ( $item->get_taxes()['total'] as $rate => $tax ) {
			$tax_item = new WC_Order_Item_Tax();
			$tax_item->set_rate( $rate );
			$tax_item->set_tax_total( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? $tax : wc_round_tax_total( $tax ) );
			$order->add_item( $tax_item );
		}
		$order->set_cart_tax( $item->get_total_tax() );
		$order->set_total( $source['amount'] );
	}
	// A third-party hook that saves early must never make automatic repetition safe.
	if ( $order->get_id() ) { throw new RuntimeException( 'Financial snapshot was persisted before bank delivery.' ); }
	foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee', 'tax', 'coupon' ) ) as $item ) {
		if ( $item->get_id() ) { throw new RuntimeException( 'Financial item was persisted before bank delivery.' ); }
	}
	return $order;
}
