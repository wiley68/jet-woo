<?php
	require_once __DIR__ . '/jet-product-submission.php';
	require_once __DIR__ . '/jet-submission-mail.php';
	require_once __DIR__ . '/jet-verified-offer.php';
	require_once __DIR__ . '/jet-financial-order.php';
	if (!defined('JETCREDIT_FINANCIAL_MAX_ITERATIONS')) define('JETCREDIT_FINANCIAL_MAX_ITERATIONS', 128);
	if (!defined('JETCREDIT_FINANCIAL_PRECISION')) define('JETCREDIT_FINANCIAL_PRECISION', 1.0e-08);

	function RATE($nper, $pmt, $pv, $fv = 0.0, $type = 0, $guess = 0.1) {
		$rate = $guess;
		if (abs($rate) < JETCREDIT_FINANCIAL_PRECISION) {
			$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
		} else {
			$f = exp($nper * log(1 + $rate));
			$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		}
		$y0 = $pv + $pmt * $nper + $fv;
		$y1 = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		$i  = $x0 = 0.0;
		$x1 = $rate;
		while ((abs($y0 - $y1) > JETCREDIT_FINANCIAL_PRECISION) && ($i < JETCREDIT_FINANCIAL_MAX_ITERATIONS)) {
			$rate = ($y1 * $x0 - $y0 * $x1) / ($y1 - $y0);
			$x0 = $x1;
			$x1 = $rate;
			if (abs($rate) < JETCREDIT_FINANCIAL_PRECISION) {
				$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
			} else {
				$f = exp($nper * log(1 + $rate));
				$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
			}
			$y0 = $y1;
			$y1 = $y;
			++$i;
		}
		return $rate;
	}

	/** Financial inputs are read from WooCommerce; posted amounts never create an offer. */
	function jet_ajax_financial_calculation( $cart = false ) {
		check_ajax_referer( 'jet_nonce', 'security' );
		$source = null;
		try {
			jet_require_eur_context();
			$context = $_POST['jet_context'] ?? '';
			if ( ! is_string( $context ) || ! in_array( $context, array( 'product', 'cart', 'checkout_classic', 'checkout_blocks' ), true ) ) {
				throw new Jet_Financial_Validation_Exception( 'invalid_offer_context' );
			}
			$scheme = $_POST['jet_card'] ?? '0';
			if ( ! in_array( $scheme, array( '0', '1', 0, 1 ), true ) ) { throw new Jet_Financial_Validation_Exception( 'invalid_financing_scheme' ); }
			$card = '1' === (string) $scheme;
			$selection = array( 'product_id' => $_POST['jet_product_id'] ?? null, 'variation_id' => $_POST['jet_variation_id'] ?? 0, 'quantity' => $_POST['jet_quantity'] ?? null );
			if ( 'product' !== $context ) { jet_refresh_financial_cart(); }
			$source = jet_offer_source( $context, $selection );
			if ( isset( $_POST['jet_expected_amount'] ) ) { jet_assert_financial_amount( $_POST['jet_expected_amount'], $source['amount'] ); }
			$initial = jet_financial_money( $_POST['jet_parva'] ?? 0 );
			$term = jet_financial_positive_integer( ! empty( $_POST['jet_vnoski'] ) ? $_POST['jet_vnoski'] : jet_initial_financing_term( $source['amount'], get_option( 'jet_vnoski_default' ) ) );
			$calculation = jet_calculate_source_financing( $source, $initial, $term, $card, $context, false );
			$eligible = jet_installment_meets_minimum( $calculation['jet_vnoska'] );
			jet_ensure_offer_session();
			$id = in_array( $context, array( 'product', 'cart' ), true ) ? ( $_POST['jet_submission_id'] ?? '' ) : '';
			if ( in_array( $context, array( 'product', 'cart' ), true ) && ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{16,64}$/D', $id ) ) ) { throw new Jet_Financial_Validation_Exception( 'invalid_submission_id' ); }
			$json = array(
				'success' => 'success', 'jet_eligible' => $eligible,
				'jet_context' => $context, 'jet_card' => $card ? '1' : '0',
				'jet_offer' => $eligible ? jet_create_verified_offer( $context, $selection, $initial, $term, $card, $id ) : '',
				'jet_expires' => time() + 30 * MINUTE_IN_SECONDS,
				'jet_allowed_terms' => jet_allowed_financing_terms( $source['amount'] ),
				'nonce' => wp_create_nonce( 'jet_nonce' ),
			);
			foreach ( $calculation as $field => $value ) { $json[$field] = 'jet_vnoski' === $field ? (string) $value : number_format( $value, 2, '.', '' ); }
			foreach ( jet_financial_source_lists( $source ) as $field => $value ) { $json[$field] = $value; }
			foreach ( array( false, true ) as $alternative ) {
				if ( $alternative && 1 !== (int) get_option( 'jet_card_in' ) ) { continue; }
				try {
					$other = jet_calculate_source_financing( $source, $initial, $term, $alternative, $context, false );
					$json[ $alternative ? 'jet_vnoska_card' : 'jet_vnoska_regular' ] = number_format( $other['jet_vnoska'], 2, '.', '' );
				} catch ( Jet_Financial_Validation_Exception $alternative_error ) {
					// An unavailable preview must not block the already validated selected scheme.
				}
			}
		} catch ( Jet_Financial_Validation_Exception $error ) {
			$json = array( 'success' => 'unsuccess', 'code' => $error->getMessage() );
			if ( null !== $source ) { $json['jet_priceall'] = $source['amount']; $json['jet_allowed_terms'] = jet_allowed_financing_terms( $source['amount'] ); }
		}
		wp_send_json( $json );
	}

	function jet_calculate() {
		jet_ajax_financial_calculation();
	}
	add_action( 'wp_ajax_jet_calculate', 'jet_calculate' );
	add_action( 'wp_ajax_nopriv_jet_calculate', 'jet_calculate' );

	function jet_send() {
		check_ajax_referer('jet_nonce', 'security');

		if(!empty((string)$_POST['jet_lname'])) die();

		try {
			jet_require_eur_context();
			$existing = jet_product_submission_status( wp_unslash( $_POST ), 'product' );
			if ( null !== $existing ) { wp_send_json( $existing ); }
			jet_validate_submission_financing( wp_unslash( $_POST ), 'product' );
		} catch ( Jet_Financial_Validation_Exception $error ) {
			wp_send_json( array( 'success' => 'unsuccess', 'code' => $error->getMessage() ) );
		}

		$submission_key = jet_claim_product_submission( wp_unslash( $_POST ) );
		try {
			$notifications = array();
			$bank_attempted = false;
			$manual_registration = false;
			$json          = jet_process_product_submission( $notifications, $bank_attempted, $manual_registration );
			if ( 'success' === $json['success'] ) {
				$recorded = update_option( $submission_key, $json, false );
				if ( $manual_registration && ! $recorded ) { throw new RuntimeException( 'Unable to record manual registration.' ); }
			} else {
				// A rejected mail submission has no order; a deliberate retry is safe.
				delete_option( $submission_key );
			}
		} catch ( Jet_Financial_Validation_Exception $error ) {
			if ( ! $bank_attempted && ! $manual_registration ) {
				delete_option( $submission_key );
				wp_send_json( array( 'success' => 'unsuccess', 'code' => $error->getMessage() ) );
			}
			$json = array( 'success' => 'unsuccess', 'code' => $manual_registration ? 'manual_registration_failed' : 'uncertain' );
			update_option( $submission_key, $json, false );
		} catch ( Throwable $error ) {
			// Mail or order hooks may already have run. Keep the claim to prevent replay.
			$json = array( 'success' => 'unsuccess', 'code' => $manual_registration ? 'manual_registration_failed' : 'uncertain' );
			update_option( $submission_key, $json, false );
		}

		if ( 'success' === $json['success'] ) {
			jet_submission_notify( $notifications );
		}
		wp_send_json( $json );
	}

	function jet_process_product_submission( &$notifications = null, &$bank_attempted = null, &$manual_registration = null ) {
		$bank_recipients = jet_submission_bank_recipients();
		$manual_registration = array() === $bank_recipients;
		$finance = jet_validate_submission_financing( wp_unslash( $_POST ), 'product' );
		$notifications = array();
		$json = [];
		$jet_priceall = number_format( $finance['jet_priceall'], 2, '.', '' );
		$jet_vnoski = (string) $finance['jet_vnoski'];
		$jet_vnoska = number_format( $finance['jet_vnoska'], 2, '.', '' );
		$jet_parva = number_format( $finance['jet_parva'], 2, '.', '' );
		$jet_total_credit_price = number_format( $finance['jet_total_credit_price'], 2, '.', '' );
		$jet_obshto = number_format( $finance['jet_obshto'], 2, '.', '' );
		$jet_gpr = number_format( $finance['jet_gpr'], 2, '.', '' );
		$jet_glp = number_format( $finance['jet_glp'], 2, '.', '' );
		if (isset($_POST['jet_name'])) {
			$jet_name = filter_var($_POST['jet_name'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_name = " - ";
		}
		if (isset($_POST['jet_lastname'])) {
			$jet_lastname = filter_var($_POST['jet_lastname'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_lastname = " - ";
		}
		if (isset($_POST['jet_egn'])) {
			$jet_egn = filter_var($_POST['jet_egn'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_egn = " - ";
		}
		if (isset($_POST['jet_email'])) {
			$jet_email = filter_var($_POST['jet_email'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_email = " - ";
		}
		if (isset($_POST['jet_phone'])) {
			$jet_phone = filter_var($_POST['jet_phone'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_phone = " - ";
		}
		if (isset($_POST['jet_card'])) {
			$jet_card = filter_var($_POST['jet_card'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_card = 0;
		}
		if (isset($_POST['jet_product_id'])) {
			$jet_product_id = filter_var($_POST['jet_product_id'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_product_id = 0;
		}
		if (isset($_POST['jet_variation_id'])) {
			$jet_variation_id = (int) filter_var($_POST['jet_variation_id'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_variation_id = 0;
		}
		if (isset($_POST['jet_quantity'])) {
			$jet_quantity = filter_var($_POST['jet_quantity'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_quantity = 1;
		}

		$toEmail_admin = get_bloginfo('admin_email');
		if ( null === $bank_recipients ) {
			return array(
				'success' => 'unsuccess',
				'code'    => 'invalid_bank_recipient',
			);
		}
		$jet_id = get_option('jet_id');
		$term_list = wp_get_post_terms($jet_product_id,'product_cat',array('fields'=>'ids'));
		$cat_id = empty($term_list[0]) ? 0 : (int)$term_list[0];
		if($term = get_term_by('id', $cat_id, 'product_cat')){
			$product_c_txt = $term->name;
		}else{
			$product_c_txt = " - ";
		}

		if(isset($jet_variation_id) && $jet_variation_id != 0) {
			$product = new WC_Product_Variation($jet_variation_id);
			$attributes = $product->get_attributes();
			$att_name = '';
			foreach ($attributes as $attribute_name => $attribute_value) {
				$taxonomy = wc_attribute_taxonomy_name($attribute_name);
				$term = get_term_by('slug', $attribute_value, $taxonomy);
				if ($term) {
					$att_name .= $term->name . ',';
				} else {
					$att_name .= $attribute_value . ',';
				}
			}
			$product_m_txt = $product->get_title() . ', ' . rtrim($att_name, ",");
		} else {
			$product = new WC_Product($jet_product_id);
			$product_m_txt = $product->get_title();
		}
		if ($product_m_txt == ""){
			$product_m_txt = " - ";
		}

		$product_p_txt = $jet_priceall / $jet_quantity;


		$body = "Данни за потребителя:\r\n";

		$body .= 'Собствено име: ' . jet_sanitize_bank_email_field( $jet_name ) . ";\r\n";
		$body .= 'Фамилия: ' . jet_sanitize_bank_email_field( $jet_lastname ) . ";\r\n";
		$body .= 'ЕГН: ' . jet_sanitize_bank_email_field( $jet_egn ) . ";\r\n";
		$body .= 'Телефон за връзка: ' . jet_sanitize_bank_email_field( $jet_phone ) . ";\r\n";
		$body .= 'Имейл адрес: ' . jet_sanitize_bank_email_field( $jet_email ) . ";\r\n\r\n";

		$body .= "Данни за стоката:\r\n";

		$body .= 'Тип стока: ' . jet_sanitize_bank_email_field( $product_c_txt ) . ";\r\n";
		$body .= 'Марка: ' . jet_sanitize_bank_email_field( '(' . $jet_product_id . ') ' . $product_m_txt ) . ";\r\n";
		$body .= 'Единична цена с ДДС: ' . jet_sanitize_bank_email_field( number_format( $product_p_txt, 2, '.', '' ) ) . ";\r\n";
		$body .= 'Брой стоки: ' . jet_sanitize_bank_email_field( $jet_quantity ) . ";\r\n";
		$body .= 'Обща сума с ДДС: ' . jet_sanitize_bank_email_field( number_format( $jet_priceall, 2, '.', '' ) ) . ";\r\n\r\n";

		if ($jet_card == 1){
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}

		$body .= "Данни за кредита:\r\n";

		$body .= 'Размер на кредита: ' . jet_sanitize_bank_email_field( number_format( $jet_priceall - $jet_parva, 2, '.', '' ) ) . ";\r\n";
		$body .= 'Срок на изплащане в месеца: ' . jet_sanitize_bank_email_field( $jet_vnoski ) . ";\r\n";
		$body .= 'Месечна вноска: ' . jet_sanitize_bank_email_field( $jet_vnoska ) . ";\r\n";
		$body .= 'Първоначална вноска: ' . jet_sanitize_bank_email_field( number_format( floatval( $jet_parva ), 2, '.', '' ) ) . ";\r\n";

		$jet_billing_address_1 = '';
		$jet_billing_address_2 = '';
		$jet_billing_city = '';
		$jet_billing_state = '';
		$jet_billing_postcode = '';
		$jet_billing_country = '';
		$jet_shipping_address_1 = '';
		$jet_shipping_address_2 = '';
		$jet_shipping_city = '';
		$jet_shipping_state = '';
		$jet_shipping_postcode = '';
		$jet_shipping_country = '';
		if (is_user_logged_in()) {
			$user_id = get_current_user_id();
			$jet_billing_address_1 = get_user_meta($user_id, 'billing_address_1', true);
			$jet_billing_address_2 = get_user_meta($user_id, 'billing_address_2', true);
			$jet_billing_city = get_user_meta($user_id, 'billing_city', true);
			$jet_billing_state = get_user_meta($user_id, 'billing_state', true);
			$jet_billing_postcode = get_user_meta($user_id, 'billing_postcode', true);
			$jet_billing_country = get_user_meta($user_id, 'billing_country', true);
			$jet_shipping_address_1 = get_user_meta($user_id, 'shipping_address_1', true);
			$jet_shipping_address_2 = get_user_meta($user_id, 'shipping_address_2', true);
			$jet_shipping_city = get_user_meta($user_id, 'shipping_city', true);
			$jet_shipping_state = get_user_meta($user_id, 'shipping_state', true);
			$jet_shipping_postcode = get_user_meta($user_id, 'shipping_postcode', true);
			$jet_shipping_country = get_user_meta($user_id, 'shipping_country', true);
		}

		$addressBilling = array(
			'first_name' => $jet_name,
			'email'	  => $jet_email,
			'phone'	  => $jet_phone,
			'address_1'  => $jet_billing_address_1,
			'address_2'  => $jet_billing_address_2,
			'city'	   => $jet_billing_city,
			'state'	  => $jet_billing_state,
			'postcode'   => $jet_billing_postcode,
			'country'	=> $jet_billing_country);
		$addressShipping = array(
			'first_name' => $jet_name,
			'email'	  => $jet_email,
			'phone'	  => $jet_phone,
			'address_1'  => $jet_shipping_address_1,
			'address_2'  => $jet_shipping_address_2,
			'city'	   => $jet_shipping_city,
			'state'	  => $jet_shipping_state,
			'postcode'   => $jet_shipping_postcode,
			'country'	=> $jet_shipping_country);
		$order = jet_prepare_financial_order( 'product', wp_unslash( $_POST ), $addressBilling, $addressShipping );
		$order->set_payment_method('jetpayment');
		$order->set_payment_method_title('ПБ Лични Финанси');
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateway_settings = isset($payment_gateways['jetpayment']) ? $payment_gateways['jetpayment']->settings : null;
		if ($gateway_settings && isset($gateway_settings['order_status'])) {
			$custom_order_status = $gateway_settings['order_status'];
			$new_status = 'wc-' === substr( $custom_order_status, 0, 3 ) ? substr( $custom_order_status, 3 ) : $custom_order_status;
			$order->set_status($new_status);
		}
		jet_validate_submission_financing( wp_unslash( $_POST ), 'product' );
		jet_assert_financial_amount( $order->get_total(), $finance['jet_priceall'] );
		$order_financial_snapshot = jet_order_financial_source( $order );

		$headers = [
			'MIME-Version: 1.0',
			'Content-type: text/plain; charset=utf-8',
			'From: ' . mb_encode_mimeheader($jet_id,"UTF-8") . ' <' . $toEmail_admin . '>',
		];

		$delivery = jet_submission_deliver( $bank_recipients, $jet_id, $body, $headers, $subject, $bank_attempted );
		if ( 'bank_failed' !== $delivery ) {
			$order->save();
			jet_assert_financial_source( jet_order_financial_source( $order ), $order_financial_snapshot );

			$json['success'] = 'success';
			if ( $manual_registration ) { $json['code'] = 'manual_registered'; $json['message'] = jet_submission_manual_message(); }
			$notifications   = array(
				'manual'   => $manual_registration,
				'admin'    => $toEmail_admin,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wrapper verifies the nonce; delivery validates one address without altering the bank field.
				'customer' => isset( $_POST['jet_email'] ) ? wp_unslash( $_POST['jet_email'] ) : '',
				'subject'  => $subject,
				'body'     => $body,
				'headers'  => $headers,
			);
		}else{
			$json['success'] = 'unsuccess';
		}

		return $json;
	}
	add_action( 'wp_ajax_jet_send', 'jet_send' );
	add_action( 'wp_ajax_nopriv_jet_send', 'jet_send' );

	function jet_calculate_cart() {
		jet_ajax_financial_calculation( true );
	}
	add_action( 'wp_ajax_jet_calculate_cart', 'jet_calculate_cart' );
	add_action( 'wp_ajax_nopriv_jet_calculate_cart', 'jet_calculate_cart' );

	function jet_send_card() {
		check_ajax_referer('jet_nonce', 'security');

		if(!empty((string)$_POST['jet_lname'])) die();

		try {
			jet_require_eur_context();
			$existing = jet_product_submission_status( wp_unslash( $_POST ), 'cart' );
			if ( null !== $existing ) { wp_send_json( $existing ); }
			jet_refresh_financial_cart();
			jet_validate_submission_financing( wp_unslash( $_POST ), 'cart' );
		} catch ( Jet_Financial_Validation_Exception $error ) {
			wp_send_json( array( 'success' => 'unsuccess', 'code' => $error->getMessage() ) );
		}

		$submission_key = jet_claim_product_submission( wp_unslash( $_POST ), 'cart' );
		try {
			$notifications = array();
			$bank_attempted = false;
			$manual_registration = false;
			$json          = jet_process_cart_submission( $notifications, $bank_attempted, $manual_registration );
			if ( 'success' === $json['success'] ) {
				$recorded = update_option( $submission_key, $json, false );
				if ( $manual_registration && ! $recorded ) { throw new RuntimeException( 'Unable to record manual registration.' ); }
			} else {
				delete_option( $submission_key );
			}
		} catch ( Jet_Financial_Validation_Exception $error ) {
			if ( ! $bank_attempted && ! $manual_registration ) {
				delete_option( $submission_key );
				wp_send_json( array( 'success' => 'unsuccess', 'code' => $error->getMessage() ) );
			}
			$json = array( 'success' => 'unsuccess', 'code' => $manual_registration ? 'manual_registration_failed' : 'uncertain' );
			update_option( $submission_key, $json, false );
		} catch ( Throwable $error ) {
			// Preserve the claim if mail, order or cart hooks may already have run.
			$json = array( 'success' => 'unsuccess', 'code' => $manual_registration ? 'manual_registration_failed' : 'uncertain' );
			update_option( $submission_key, $json, false );
		}

		if ( 'success' === $json['success'] ) {
			jet_submission_notify( $notifications );
		}
		wp_send_json( $json );
	}

	function jet_process_cart_submission( &$notifications = null, &$bank_attempted = null, &$manual_registration = null ) {
		$bank_recipients = jet_submission_bank_recipients();
		$manual_registration = array() === $bank_recipients;
		jet_refresh_financial_cart();
		$finance = jet_validate_submission_financing( wp_unslash( $_POST ), 'cart' );
		$source = jet_cart_financial_source();
		$notifications = array();
		$json = [];
		$jet_priceall = number_format( $finance['jet_priceall'], 2, '.', '' );
		$jet_vnoski = (string) $finance['jet_vnoski'];
		$jet_vnoska = number_format( $finance['jet_vnoska'], 2, '.', '' );
		$jet_parva = number_format( $finance['jet_parva'], 2, '.', '' );
		$jet_total_credit_price = number_format( $finance['jet_total_credit_price'], 2, '.', '' );
		$jet_obshto = number_format( $finance['jet_obshto'], 2, '.', '' );
		$jet_gpr = number_format( $finance['jet_gpr'], 2, '.', '' );
		$jet_glp = number_format( $finance['jet_glp'], 2, '.', '' );
		if (isset($_POST['jet_name'])) {
			$jet_name = filter_var($_POST['jet_name'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_name = " - ";
		}
		if (isset($_POST['jet_lastname'])) {
			$jet_lastname = filter_var($_POST['jet_lastname'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_lastname = " - ";
		}
		if (isset($_POST['jet_egn'])) {
			$jet_egn = filter_var($_POST['jet_egn'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_egn = " - ";
		}
		if (isset($_POST['jet_email'])) {
			$jet_email = filter_var($_POST['jet_email'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_email = " - ";
		}
		if (isset($_POST['jet_phone'])) {
			$jet_phone = filter_var($_POST['jet_phone'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_phone = " - ";
		}
		if (isset($_POST['jet_card'])) {
			$jet_card = filter_var($_POST['jet_card'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_card = 0;
		}
		if (isset($_POST['jet_products'])) {
			$jet_products = filter_var($_POST['jet_products'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products = '';
		}
		if (isset($_POST['jet_products_qt'])) {
			$jet_products_qt = filter_var($_POST['jet_products_qt'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_qt = '';
		}
		if (isset($_POST['jet_products_pr'])) {
			$jet_products_pr = filter_var($_POST['jet_products_pr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_pr = '';
		}
		if (isset($_POST['jet_products_vr'])) {
			$jet_products_vr = filter_var($_POST['jet_products_vr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_vr = '';
		}

		$toEmail_admin = get_bloginfo('admin_email');
		if ( null === $bank_recipients ) {
			return array(
				'success' => 'unsuccess',
				'code'    => 'invalid_bank_recipient',
			);
		}
		$jet_id = get_option('jet_id');


		$body = "Данни за потребителя:\r\n";

		$body .= 'Собствено име: ' . jet_sanitize_bank_email_field( $jet_name ) . ";\r\n";
		$body .= 'Фамилия: ' . jet_sanitize_bank_email_field( $jet_lastname ) . ";\r\n";
		$body .= 'ЕГН: ' . jet_sanitize_bank_email_field( $jet_egn ) . ";\r\n";
		$body .= 'Телефон за връзка: ' . jet_sanitize_bank_email_field( $jet_phone ) . ";\r\n";
		$body .= 'Имейл адрес: ' . jet_sanitize_bank_email_field( $jet_email ) . ";\r\n\r\n";

		$body .= "Данни за стоката:\r\n";

		$_product = explode('_', $jet_products);
		$product_q = explode('_', $jet_products_qt);
		$product_p = explode('_', $jet_products_pr);
		$product_v = explode('_', $jet_products_vr);

		for ($index = 0; $index < sizeof($_product); $index++) {
			$term_list = wp_get_post_terms($_product[$index],'product_cat',array('fields'=>'ids'));
			$cat_id = empty($term_list[0]) ? 0 : (int)$term_list[0];
			if($term = get_term_by('id', $cat_id, 'product_cat')) {
				$product_c_txt = $term->name;
			}else{
				$product_c_txt = " - ";
			}

			$jet_product_id = $_product[$index];
			$jet_variation_id = $product_v[$index];
			if(isset($jet_variation_id) && $jet_variation_id != 0) {
				$product = new WC_Product_Variation($jet_variation_id);
				$attributes = $product->get_attributes();
				$att_name = '';
				foreach ($attributes as $attribute_name => $attribute_value) {
					$taxonomy = wc_attribute_taxonomy_name($attribute_name);
					$term = get_term_by('slug', $attribute_value, $taxonomy);
					if ($term) {
						$att_name .= $term->name . ',';
					} else {
						$att_name .= $attribute_value . ',';
					}
				}
				$product_m_txt = $product->get_title() . ', ' . rtrim($att_name, ",");
			} else {
				$product = new WC_Product($jet_product_id);
				$product_m_txt = $product->get_title();
			}
			if ($product_m_txt == "") {
				$product_m_txt = " - ";
			}

			if (isset($product_p[$index]) && (float)$product_p[$index] != 0) {
				$product_p_txt = (float)$product_p[$index];
			}else{
				$product_p_txt = (float)wc_get_price_including_tax($jet_product);
			}
			if (isset($product_q[$index]) && (int)$product_q[$index] != 0) {
				$product_q_txt = (int)$product_q[$index];
			}else{
				$product_q_txt = 1;
			}

			$body .= 'Тип стока: ' . jet_sanitize_bank_email_field( $product_c_txt ) . ";\r\n";
			$body .= 'Марка: ' . jet_sanitize_bank_email_field( '(' . $_product[ $index ] . ') ' . $product_m_txt ) . ";\r\n";
			$body .= 'Единична цена с ДДС: ' . jet_sanitize_bank_email_field( number_format( $product_p_txt, 2, '.', '' ) ) . ";\r\n";
			$body .= 'Брой стоки: ' . jet_sanitize_bank_email_field( $product_q_txt ) . ";\r\n";
			$body .= 'Обща сума с ДДС: ' . jet_sanitize_bank_email_field( number_format( (float) $source['lines'][$index]['subtotal'], 2, '.', '' ) ) . ";\r\n\r\n";
		}

		if ($jet_card == 1) {
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}

		$body .= "Данни за кредита:\r\n";

		$body .= 'Размер на кредита: ' . jet_sanitize_bank_email_field( number_format( $jet_priceall - $jet_parva, 2, '.', '' ) ) . ";\r\n";
		$body .= 'Срок на изплащане в месеца: ' . jet_sanitize_bank_email_field( $jet_vnoski ) . ";\r\n";
		$body .= 'Месечна вноска: ' . jet_sanitize_bank_email_field( $jet_vnoska ) . ";\r\n";
		$body .= 'Първоначална вноска: ' . jet_sanitize_bank_email_field( number_format( floatval( $jet_parva ), 2, '.', '' ) ) . ";\r\n";

		$jet_billing_address_1 = '';
		$jet_billing_address_2 = '';
		$jet_billing_city = '';
		$jet_billing_state = '';
		$jet_billing_postcode = '';
		$jet_billing_country = '';
		$jet_shipping_address_1 = '';
		$jet_shipping_address_2 = '';
		$jet_shipping_city = '';
		$jet_shipping_state = '';
		$jet_shipping_postcode = '';
		$jet_shipping_country = '';
		if (is_user_logged_in()) {
			$user_id = get_current_user_id();
			$jet_billing_address_1 = get_user_meta($user_id, 'billing_address_1', true);
			$jet_billing_address_2 = get_user_meta($user_id, 'billing_address_2', true);
			$jet_billing_city = get_user_meta($user_id, 'billing_city', true);
			$jet_billing_state = get_user_meta($user_id, 'billing_state', true);
			$jet_billing_postcode = get_user_meta($user_id, 'billing_postcode', true);
			$jet_billing_country = get_user_meta($user_id, 'billing_country', true);
			$jet_shipping_address_1 = get_user_meta($user_id, 'shipping_address_1', true);
			$jet_shipping_address_2 = get_user_meta($user_id, 'shipping_address_2', true);
			$jet_shipping_city = get_user_meta($user_id, 'shipping_city', true);
			$jet_shipping_state = get_user_meta($user_id, 'shipping_state', true);
			$jet_shipping_postcode = get_user_meta($user_id, 'shipping_postcode', true);
			$jet_shipping_country = get_user_meta($user_id, 'shipping_country', true);
		}

		$addressBilling = array(
			'first_name' => $jet_name,
			'email'	  => $jet_email,
			'phone'	  => $jet_phone,
			'address_1'  => $jet_billing_address_1,
			'address_2'  => $jet_billing_address_2,
			'city'	   => $jet_billing_city,
			'state'	  => $jet_billing_state,
			'postcode'   => $jet_billing_postcode,
			'country'	=> $jet_billing_country);
		$addressShipping = array(
			'first_name' => $jet_name,
			'email'	  => $jet_email,
			'phone'	  => $jet_phone,
			'address_1'  => $jet_shipping_address_1,
			'address_2'  => $jet_shipping_address_2,
			'city'	   => $jet_shipping_city,
			'state'	  => $jet_shipping_state,
			'postcode'   => $jet_shipping_postcode,
			'country'	=> $jet_shipping_country);
		$order = jet_prepare_financial_order( 'cart', wp_unslash( $_POST ), $addressBilling, $addressShipping );

		$order->set_payment_method('jetpayment');
		$order->set_payment_method_title('ПБ Лични Финанси');
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateway_settings = isset($payment_gateways['jetpayment']) ? $payment_gateways['jetpayment']->settings : null;
		if ($gateway_settings && isset($gateway_settings['order_status'])) {
			$custom_order_status = $gateway_settings['order_status'];
			$new_status = 'wc-' === substr( $custom_order_status, 0, 3 ) ? substr( $custom_order_status, 3 ) : $custom_order_status;
			$order->set_status($new_status);
		}
		jet_validate_submission_financing( wp_unslash( $_POST ), 'cart' );
		jet_assert_financial_amount( $order->get_total(), $finance['jet_priceall'] );
		$order_financial_snapshot = jet_order_financial_source( $order );

		$headers = [
			'MIME-Version: 1.0',
			'Content-type: text/plain; charset=utf-8',
			'From: ' . mb_encode_mimeheader($jet_id,"UTF-8") . ' <' . $toEmail_admin . '>',
		];

		$delivery = jet_submission_deliver( $bank_recipients, $jet_id, $body, $headers, $subject, $bank_attempted );
		if ( 'bank_failed' !== $delivery ) {
			$order->save();
			jet_assert_financial_source( jet_order_financial_source( $order ), $order_financial_snapshot );

			global $woocommerce;
			foreach($woocommerce->cart->get_cart() as $cart_item_key => $cart_item){
				$woocommerce->cart->remove_cart_item($cart_item_key);
			}
			$json['success'] = 'success';
			if ( $manual_registration ) { $json['code'] = 'manual_registered'; $json['message'] = jet_submission_manual_message(); }
			$notifications   = array(
				'manual'   => $manual_registration,
				'admin'    => $toEmail_admin,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wrapper verifies the nonce; delivery validates one address without altering the bank field.
				'customer' => isset( $_POST['jet_email'] ) ? wp_unslash( $_POST['jet_email'] ) : '',
				'subject'  => $subject,
				'body'     => $body,
				'headers'  => $headers,
			);
		}else{
			$json['success'] = 'unsuccess';
		}

		return $json;
	}
	add_action( 'wp_ajax_jet_send_card', 'jet_send_card' );
	add_action( 'wp_ajax_nopriv_jet_send_card', 'jet_send_card' );
