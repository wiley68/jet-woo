<?php
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
	
	function jet_calculate() {
		check_ajax_referer('jet_nonce', 'security');

		$json = [];
		if (isset($_POST['jet_priceall'])) {
			$jet_priceall = filter_var($_POST['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($_POST['jet_parva'])) {
			$jet_parva = filter_var($_POST['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($_POST['jet_vnoski'])) {
			$jet_vnoski = filter_var($_POST['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)get_option("jet_vnoski_default");
		}
		if (isset($_POST['jet_product_id'])) {
			$jet_product_id = filter_var($_POST['jet_product_id'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_product_id = 0;
		}
		if (isset($_POST['jet_uslovia'])) {
			$jet_uslovia = filter_var($_POST['jet_uslovia'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia = 0;
		}
		if (isset($_POST['jet_uslovia1'])) {
			$jet_uslovia1 = filter_var($_POST['jet_uslovia1'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia1 = 0;
		}
		if (isset($_POST['jet_egn'])) {
			$jet_egn = filter_var($_POST['jet_egn'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_egn = "";
		}
		if (isset($_POST['jet_products'])) {
			$jet_products = filter_var($_POST['jet_products'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products = "";
		}
		if (isset($_POST['jet_products_qt'])) {
			$jet_products_qt = filter_var($_POST['jet_products_qt'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_qt = "";
		}
		if (isset($_POST['jet_products_pr'])) {
			$jet_products_pr = filter_var($_POST['jet_products_pr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_pr = "";
		}
		if (isset($_POST['jet_products_vr'])) {
			$jet_products_vr = filter_var($_POST['jet_products_vr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_vr = "";
		}
		$jet_card_in = (int)get_option("jet_card_in");

		$jet_eur = (int)get_option("jet_eur");
		$jet_min_250 = JET_MIN_250;
		$jet_currency_code = get_woocommerce_currency();

		switch ($jet_eur) {
			case 0:
				break;
			case 1:
				if ($jet_currency_code == "EUR") {
					$jet_priceall = number_format($jet_priceall * 1.95583, 2, ".", "");
				}
				break;
			case 2:
			case 3:
				if ($jet_currency_code == "BGN") {
					$jet_priceall = number_format($jet_priceall / 1.95583, 2, ".", "");
				}
				$jet_min_250 = JET_MIN_250_EUR;
				break;
		}

		$jet_total_credit_price = (float)$jet_priceall - (float)$jet_parva;

		$jet_purcent = (float)get_option("jet_purcent");
		if ($jet_card_in == 1) {
			$jet_purcent_card = (float)get_option("jet_purcent_card");
		}
		$jet_show_button = true;
		$jet_promo = jet_get_promo($jet_product_id, $jet_vnoski, $jet_total_credit_price);
		$jet_show_button = (bool) $jet_promo['jet_show_button'];
		$jet_purcent = (float) $jet_promo['jet_purcent'];
		$jet_purcent_card = (float) $jet_promo['jet_purcent_card'];
		$_minprice = (float)get_option( 'jet_minprice' );
		$jet_show_button = $jet_show_button && $jet_total_credit_price > $_minprice;

		$jet_vnoska = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent) / 100));
		if ($jet_card_in == 1) {
			$jet_vnoska_card = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent_card) / 100));
		}

		$jet_gprm = RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12;
		$jet_glp = (RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr = (pow((1 + $jet_gprm / 12), 12) - 1) * 100;
		$jet_obshto = $jet_vnoska * $jet_vnoski;
		if ($jet_card_in == 1) {
			$jet_gprm_card = RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12;
			$jet_glp_card = (RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12) * 100;
			$jet_gpr_card = (pow((1 + $jet_gprm_card / 12), 12) - 1) * 100;
			$jet_obshto_card = $jet_vnoska_card * $jet_vnoski;
		}

		$jet_vnoska_second = 0;
		$jet_vnoska_card_second = 0;
		$jet_priceall_second = $jet_priceall;
		$jet_total_credit_price_second = $jet_total_credit_price;
		$jet_obshto_second = $jet_obshto;
		$jet_obshto_card_second = $jet_obshto_card;
		switch ($jet_eur) {
			case 0:
				$jet_vnoska_second = 0;
				$jet_vnoska_card_second = 0;
				$jet_priceall_second = $jet_priceall;
				$jet_total_credit_price_second = $jet_total_credit_price;
				$jet_obshto_second = $jet_obshto;
				$jet_obshto_card_second = $jet_obshto_card;
				break;
			case 1:
				$jet_vnoska_second = number_format($jet_vnoska / 1.95583, 2, ".", "");
				$jet_vnoska_card_second = number_format($jet_vnoska_card / 1.95583, 2, ".", "");
				$jet_priceall_second = number_format($jet_priceall / 1.95583, 2, ".", "");
				$jet_total_credit_price_second = number_format($jet_total_credit_price_second / 1.95583, 2, ".", "");
				$jet_obshto_second = number_format($jet_obshto_second / 1.95583, 2, ".", "");
				$jet_obshto_card_second = number_format($jet_obshto_card_second / 1.95583, 2, ".", "");
				break;
			case 2:
				$jet_vnoska_second = number_format($jet_vnoska * 1.95583, 2, ".", "");
				$jet_vnoska_card_second = number_format($jet_vnoska_card * 1.95583, 2, ".", "");
				$jet_priceall_second = number_format($jet_priceall * 1.95583, 2, ".", "");
				$jet_total_credit_price_second = number_format($jet_total_credit_price_second * 1.95583, 2, ".", "");
				$jet_obshto_second = number_format($jet_obshto_second * 1.95583, 2, ".", "");
				$jet_obshto_card_second = number_format($jet_obshto_card_second * 1.95583, 2, ".", "");
				break;
			case 3:
				$jet_vnoska_second = 0;
				$jet_vnoska_card_second = 0;
				$jet_priceall_second = $jet_priceall;
				$jet_total_credit_price_second = $jet_total_credit_price;
				$jet_obshto_second = $jet_obshto;
				$jet_obshto_card_second = $jet_obshto_card;
				break;
		}

		$json['success'] = 'success';
		$json['jet_show_button'] = $jet_show_button;
		$json['jet_vnoska'] = number_format($jet_vnoska, 2, ".", "");
		$json['jet_vnoska_second'] = number_format($jet_vnoska_second, 2, ".", "");
		$json['jet_priceall'] = number_format($jet_priceall, 2, ".", "");
		$json['jet_priceall_second'] = number_format($jet_priceall_second, 2, ".", "");
		$json['jet_total_credit_price'] = number_format($jet_total_credit_price, 2, ".", "");
		$json['jet_total_credit_price_second'] = number_format($jet_total_credit_price_second, 2, ".", "");
		$json['jet_gpr'] = number_format($jet_gpr, 2, ".", "");
		$json['jet_glp'] = number_format($jet_glp, 2, ".", "");
		$json['jet_obshto'] = number_format($jet_obshto, 2, ".", "");
		$json['jet_obshto_second'] = number_format($jet_obshto_second, 2, ".", "");
		if ($jet_card_in == 1) {
			$json['jet_vnoska_card'] = number_format($jet_vnoska_card, 2, ".", "");
			$json['jet_vnoska_card_second'] = number_format($jet_vnoska_card_second, 2, ".", "");
			$json['jet_gpr_card'] = number_format($jet_gpr_card, 2, ".", "");
			$json['jet_glp_card'] = number_format($jet_glp_card, 2, ".", "");
			$json['jet_obshto_card'] = number_format($jet_obshto_card, 2, ".", "");
			$json['jet_obshto_card_second'] = number_format($jet_obshto_card_second, 2, ".", "");
		}
		WC()->session->set( 'jet_priceall', sanitize_text_field( $jet_priceall ) );
		WC()->session->set( 'jet_vnoski', sanitize_text_field( $jet_vnoski ) );
		WC()->session->set( 'jet_vnoska', sanitize_text_field( $jet_vnoska ) );
		WC()->session->set( 'jet_parva', sanitize_text_field( $jet_parva ) );
		WC()->session->set( 'jet_total_credit_price', sanitize_text_field( $jet_total_credit_price ) );
		WC()->session->set( 'jet_obshto', sanitize_text_field( $jet_obshto ) );
		WC()->session->set( 'jet_gpr', sanitize_text_field( $jet_gpr ) );
		WC()->session->set( 'jet_glp', sanitize_text_field( $jet_glp ) );
		WC()->session->set( 'jet_products', sanitize_text_field( $jet_products ) );
		WC()->session->set( 'jet_products_qt', sanitize_text_field( $jet_products_qt ) );
		WC()->session->set( 'jet_products_pr', sanitize_text_field( $jet_products_pr ) );
		WC()->session->set( 'jet_products_vr', sanitize_text_field( $jet_products_vr ) );
		WC()->session->set( 'jet_uslovia', sanitize_text_field( $jet_uslovia ) );
		WC()->session->set( 'jet_uslovia1', sanitize_text_field( $jet_uslovia1 ) );
		WC()->session->set( 'jet_egn', sanitize_text_field( $jet_egn ) );

		wp_send_json($json);
		die();
	}
	add_action( 'wp_ajax_jet_calculate', 'jet_calculate' );
	add_action( 'wp_ajax_nopriv_jet_calculate', 'jet_calculate' );

	function jet_send() {
		check_ajax_referer('jet_nonce', 'security');

		if(!empty((string)$_POST['jet_lname'])) die();
		
		$json = [];
		if (isset($_POST['jet_priceall'])) {
			$jet_priceall = filter_var($_POST['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($_POST['jet_vnoski'])) {
			$jet_vnoski = filter_var($_POST['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)get_option("jet_vnoski_default");
		}
		if (isset($_POST['jet_vnoska'])) {
			$jet_vnoska = filter_var($_POST['jet_vnoska'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_vnoska = 0.00;
		}
		if (isset($_POST['jet_parva'])) {
			$jet_parva = filter_var($_POST['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($_POST['jet_total_credit_price'])) {
			$jet_total_credit_price = filter_var($_POST['jet_total_credit_price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_total_credit_price = 0.00;
		}
		if (isset($_POST['jet_obshto'])) {
			$jet_obshto = filter_var($_POST['jet_obshto'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_obshto = 0.00;
		}
		if (isset($_POST['jet_gpr'])) {
			$jet_gpr = filter_var($_POST['jet_gpr'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_gpr = 0.00;
		}
		if (isset($_POST['jet_glp'])) {
			$jet_glp = filter_var($_POST['jet_glp'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_glp = 0.00;
		}
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
		$toEmail_other = get_option('jet_email');
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
		
		$jet_eur = (int)get_option("jet_eur");
		$jet_sign = 'лева';
		switch ($jet_eur) {
			case 0:
				$jet_sign = 'лева';
				break;
			case 1:
				$jet_sign = 'лева';
				break;
			case 2:
				$jet_sign = 'евро';
				break;
			case 3:
				$jet_sign = 'евро';
				break;
		}

		$body = "Данни за потребителя:\r\n";

		$body .= "Собствено име: $jet_name;\r\n";
		$body .= "Фамилия: $jet_lastname;\r\n";
		$body .= "ЕГН: $jet_egn;\r\n";
		$body .= "Телефон за връзка: $jet_phone;\r\n";
		$body .= "Имейл адрес: $jet_email;\r\n\r\n";

		$body .= "Данни за стоката:\r\n";

		$body .= "Тип стока: " . $product_c_txt . ";\r\n";
		$body .= "Марка: " . "(" . $jet_product_id . ") " . $product_m_txt . ";\r\n";
		$body .= "Единична цена с ДДС: " . number_format($product_p_txt, 2, ".", "") . ";\r\n";
		$body .= "Брой стоки: " . $jet_quantity . ";\r\n";
		$body .= "Обща сума с ДДС: " . number_format($jet_priceall, 2, ".", "") . ";\r\n\r\n";

		if ($jet_card == 1){
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}

		$body .= "Данни за кредита:\r\n";

		$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
		$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
		$body .= "Месечна вноска: $jet_vnoska;\r\n";
		$body .= "Първоначална вноска: " . number_format(floatval($jet_parva), 2, ".", "") . ";\r\n";

		$jet_count = (int)get_option("jet_count") + 1;
		update_option("jet_count", $jet_count);
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$cc = $toEmail_other . ", " . $jet_email;
		
		$headers = [
			'MIME-Version: 1.0',
			'Content-type: text/plain; charset=utf-8',
			'From: ' . mb_encode_mimeheader($jet_id,"UTF-8") . ' <' . $toEmail_admin . '>',
			'Cc: ' . $cc
		];
		
		if (wp_mail($toEmail_admin, $subject, $body, $headers)) {
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
		
			$order = wc_create_order();
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
			$order->set_address( $addressBilling, 'billing' );
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
			$order->set_address( $addressShipping, 'shipping' );
			if( isset( $jet_variation_id ) && $jet_variation_id != 0 ) {
				$product_order = new WC_Product_Variation( $jet_variation_id );
			} else {
				$product_order = new WC_Product( $jet_product_id );
			}
			if (!$product_order) {
				return new WP_Error('invalid_product', __('Invalid product ID.'));
			}
			$order->add_product($product_order, $jet_quantity);
			$order->set_payment_method('jetpayment');
			$order->set_payment_method_title('ПБ Лични Финанси');
			$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
			$gateway_settings = isset($payment_gateways['jetpayment']) ? $payment_gateways['jetpayment']->settings : null;
			if ($gateway_settings && isset($gateway_settings['order_status'])) {
				$custom_order_status = $gateway_settings['order_status'];
				$new_status = 'wc-' === substr( $custom_order_status, 0, 3 ) ? substr( $custom_order_status, 3 ) : $custom_order_status;
				$order->set_status($new_status);
			}
			$order->calculate_totals();
			$order->save();

			$json['success'] = 'success';
		}else{
			$json['success'] = 'unsuccess';
		}
		
		wp_send_json($json);
		die();
	}
	add_action( 'wp_ajax_jet_send', 'jet_send' );
	add_action( 'wp_ajax_nopriv_jet_send', 'jet_send' );

	function jet_calculate_cart() {
		check_ajax_referer('jet_nonce', 'security');

		$json = [];
		if (isset($_POST['jet_priceall'])) {
			$jet_priceall = (float) filter_var($_POST['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = (float) 0.00;
		}
		if (isset($_POST['jet_parva'])) {
			$jet_parva = (float) filter_var($_POST['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = (float) 0.00;
		}
		if (isset($_POST['jet_vnoski'])) {
			$jet_vnoski = (int) filter_var($_POST['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int) get_option( 'jet_vnoski_default' );
		}
		if (isset($_POST['jet_products'])) {
			$jet_products = filter_var($_POST['jet_products'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products = "";
		}
		if (isset($_POST['jet_products_qt'])) {
			$jet_products_qt = filter_var($_POST['jet_products_qt'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_qt = "";
		}
		if (isset($_POST['jet_products_pr'])) {
			$jet_products_pr = filter_var($_POST['jet_products_pr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_pr = "";
		}
		if (isset($_POST['jet_products_vr'])) {
			$jet_products_vr = filter_var($_POST['jet_products_vr'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_products_vr = "";
		}
		if (isset($_POST['jet_uslovia'])) {
			$jet_uslovia = (int) filter_var($_POST['jet_uslovia'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia = (int) 0;
		}
		if (isset($_POST['jet_uslovia1'])) {
			$jet_uslovia1 = (int) filter_var($_POST['jet_uslovia1'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia1 = (int) 0;
		}
		if (isset($_POST['jet_egn'])) {
			$jet_egn = filter_var($_POST['jet_egn'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
			$jet_egn = "";
		}
		$jet_card_in = (int) get_option( 'jet_card_in' );
		
		$jet_eur = (int) get_option( 'jet_eur' );
		$jet_min_250 = JET_MIN_250;
		$jet_currency_code = get_woocommerce_currency();
		
		switch ($jet_eur) {
			case 0:
				break;
			case 1:
				if ($jet_currency_code == "EUR") {
					$jet_priceall = number_format($jet_priceall * 1.95583, 2, ".", "");
				}
				break;
			case 2:
			case 3:
				if ($jet_currency_code == "BGN") {
					$jet_priceall = number_format($jet_priceall / 1.95583, 2, ".", "");
				}
				$jet_min_250 = JET_MIN_250_EUR;
				break;
		}
		
		$jet_total_credit_price = (float)$jet_priceall - (float)$jet_parva;
		
		$jet_purcent = (float) get_option("jet_purcent");
		if ($jet_card_in == 1) {
			$jet_purcent_card = (float)get_option("jet_purcent_card");
		}
		$jet_show_button = true;
		$jet_promo = jet_get_promo($jet_products, $jet_vnoski, $jet_total_credit_price);
		$jet_show_button = (bool) $jet_promo['jet_show_button'];
		$jet_purcent = (float) $jet_promo['jet_purcent'];
		$jet_purcent_card = (float) $jet_promo['jet_purcent_card'];
		
		$jet_vnoska = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent) / 100));
		if ($jet_card_in == 1) {
			$jet_vnoska_card = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent_card) / 100));
		}

		$jet_gprm = RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12;
		$jet_glp = (RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr = (pow((1 + $jet_gprm / 12), 12) - 1) * 100;
		$jet_obshto = $jet_vnoska * $jet_vnoski;
		if ($jet_card_in == 1) {
			$jet_gprm_card = RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12;
			$jet_glp_card = (RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12) * 100;
			$jet_gpr_card = (pow((1 + $jet_gprm_card / 12), 12) - 1) * 100;
			$jet_obshto_card = $jet_vnoska_card * $jet_vnoski;
		}
		
		$jet_vnoska_second = 0;
		$jet_vnoska_card_second = 0;
		$jet_priceall_second = $jet_priceall;
		$jet_total_credit_price_second = $jet_total_credit_price;
		$jet_obshto_second = $jet_obshto;
		$jet_obshto_card_second = $jet_obshto_card;
		switch ($jet_eur) {
			case 0:
				$jet_vnoska_second = 0;
				$jet_vnoska_card_second = 0;
				$jet_priceall_second = $jet_priceall;
				$jet_total_credit_price_second = $jet_total_credit_price;
				$jet_obshto_second = $jet_obshto;
				$jet_obshto_card_second = $jet_obshto_card;
				break;
			case 1:
				$jet_vnoska_second = number_format($jet_vnoska / 1.95583, 2, ".", "");
				$jet_vnoska_card_second = number_format($jet_vnoska_card / 1.95583, 2, ".", "");
				$jet_priceall_second = number_format($jet_priceall / 1.95583, 2, ".", "");
				$jet_total_credit_price_second = number_format($jet_total_credit_price_second / 1.95583, 2, ".", "");
				$jet_obshto_second = number_format($jet_obshto_second / 1.95583, 2, ".", "");
				$jet_obshto_card_second = number_format($jet_obshto_card_second / 1.95583, 2, ".", "");
				break;
			case 2:
				$jet_vnoska_second = number_format($jet_vnoska * 1.95583, 2, ".", "");
				$jet_vnoska_card_second = number_format($jet_vnoska_card * 1.95583, 2, ".", "");
				$jet_priceall_second = number_format($jet_priceall * 1.95583, 2, ".", "");
				$jet_total_credit_price_second = number_format($jet_total_credit_price_second * 1.95583, 2, ".", "");
				$jet_obshto_second = number_format($jet_obshto_second * 1.95583, 2, ".", "");
				$jet_obshto_card_second = number_format($jet_obshto_card_second * 1.95583, 2, ".", "");
				break;
			case 3:
				$jet_vnoska_second = 0;
				$jet_vnoska_card_second = 0;
				$jet_priceall_second = $jet_priceall;
				$jet_total_credit_price_second = $jet_total_credit_price;
				$jet_obshto_second = $jet_obshto;
				$jet_obshto_card_second = $jet_obshto_card;
				break;
		}
		
		$json['success'] = 'success';
		$json['jet_show_button'] = $jet_show_button;
		$json['jet_vnoska'] = number_format($jet_vnoska, 2, ".", "");
		$json['jet_vnoska_second'] = number_format($jet_vnoska_second, 2, ".", "");
		$json['jet_priceall'] = number_format($jet_priceall, 2, ".", "");
		$json['jet_priceall_second'] = number_format($jet_priceall_second, 2, ".", "");
		$json['jet_total_credit_price'] = number_format($jet_total_credit_price, 2, ".", "");
		$json['jet_total_credit_price_second'] = number_format($jet_total_credit_price_second, 2, ".", "");
		$json['jet_gpr'] = number_format($jet_gpr, 2, ".", "");
		$json['jet_glp'] = number_format($jet_glp, 2, ".", "");
		$json['jet_obshto'] = number_format($jet_obshto, 2, ".", "");
		$json['jet_obshto_second'] = number_format($jet_obshto_second, 2, ".", "");
		if ($jet_card_in == 1) {
			$json['jet_vnoska_card'] = number_format($jet_vnoska_card, 2, ".", "");
			$json['jet_vnoska_card_second'] = number_format($jet_vnoska_card_second, 2, ".", "");
			$json['jet_gpr_card'] = number_format($jet_gpr_card, 2, ".", "");
			$json['jet_glp_card'] = number_format($jet_glp_card, 2, ".", "");
			$json['jet_obshto_card'] = number_format($jet_obshto_card, 2, ".", "");
			$json['jet_obshto_card_second'] = number_format($jet_obshto_card_second, 2, ".", "");
		}
		WC()->session->set( 'jet_card_priceall', sanitize_text_field( $jet_priceall ) );
		WC()->session->set( 'jet_card_vnoski', sanitize_text_field( $jet_vnoski ) );
		WC()->session->set( 'jet_card_vnoska', sanitize_text_field( $jet_vnoska_card ) );
		WC()->session->set( 'jet_card_parva', sanitize_text_field( $jet_parva ) );
		WC()->session->set( 'jet_card_total_credit_price', sanitize_text_field( $jet_total_credit_price ) );
		WC()->session->set( 'jet_card_obshto', sanitize_text_field( $jet_obshto_card ) );
		WC()->session->set( 'jet_card_gpr', sanitize_text_field( $jet_gpr_card ) );
		WC()->session->set( 'jet_card_glp', sanitize_text_field( $jet_glp_card ) );
		WC()->session->set( 'jet_card_products', sanitize_text_field( $jet_products ) );
		WC()->session->set( 'jet_card_products_qt', sanitize_text_field( $jet_products_qt ) );
		WC()->session->set( 'jet_card_products_pr', sanitize_text_field( $jet_products_pr ) );
		WC()->session->set( 'jet_card_products_vr', sanitize_text_field( $jet_products_vr ) );
		WC()->session->set( 'jet_card_uslovia', sanitize_text_field( $jet_uslovia ) );
		WC()->session->set( 'jet_card_uslovia1', sanitize_text_field( $jet_uslovia1 ) );
		WC()->session->set( 'jet_card_egn', sanitize_text_field( $jet_egn ) );
		
		wp_send_json($json);
		die();
	}
	add_action( 'wp_ajax_jet_calculate_cart', 'jet_calculate_cart' );
	add_action( 'wp_ajax_nopriv_jet_calculate_cart', 'jet_calculate_cart' );

	function jet_send_card() {
		check_ajax_referer('jet_nonce', 'security');

		if(!empty((string)$_POST['jet_lname'])) die();
		
		$json = [];
		if (isset($_POST['jet_priceall'])) {
			$jet_priceall = filter_var($_POST['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($_POST['jet_vnoski'])) {
			$jet_vnoski = filter_var($_POST['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)get_option("jet_vnoski_default");
		}
		if (isset($_POST['jet_vnoska'])) {
			$jet_vnoska = filter_var($_POST['jet_vnoska'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_vnoska = 0.00;
		}
		if (isset($_POST['jet_parva'])) {
			$jet_parva = filter_var($_POST['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($_POST['jet_total_credit_price'])) {
			$jet_total_credit_price = filter_var($_POST['jet_total_credit_price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_total_credit_price = 0.00;
		}
		if (isset($_POST['jet_obshto'])) {
			$jet_obshto = filter_var($_POST['jet_obshto'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_obshto = 0.00;
		}
		if (isset($_POST['jet_gpr'])) {
			$jet_gpr = filter_var($_POST['jet_gpr'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_gpr = 0.00;
		}
		if (isset($_POST['jet_glp'])) {
			$jet_glp = filter_var($_POST['jet_glp'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_glp = 0.00;
		}
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
		$toEmail_other = get_option('jet_email');
		$jet_id = get_option('jet_id');

		$jet_eur = (int)get_option("jet_eur");
		$jet_sign = 'лева';
		switch ($jet_eur) {
			case 0:
				$jet_sign = 'лева';
				break;
			case 1:
				$jet_sign = 'лева';
				break;
			case 2:
				$jet_sign = 'евро';
				break;
			case 3:
				$jet_sign = 'евро';
				break;
		}

		$body = "Данни за потребителя:\r\n";

		$body .= "Собствено име: $jet_name;\r\n";
		$body .= "Фамилия: $jet_lastname;\r\n";
		$body .= "ЕГН: $jet_egn;\r\n";
		$body .= "Телефон за връзка: $jet_phone;\r\n";
		$body .= "Имейл адрес: $jet_email;\r\n\r\n";

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

			$body .= "Тип стока: " . $product_c_txt . ";\r\n";
			$body .= "Марка: " . "(" . $_product[$index] . ") " . $product_m_txt . ";\r\n";
			$body .= "Единична цена с ДДС: " . number_format($product_p_txt, 2, ".", "") . ";\r\n";
			$body .= "Брой стоки: " . $product_q_txt . ";\r\n";
			$body .= "Обща сума с ДДС: " . number_format((float)$product_q_txt * (float)$product_p_txt, 2, ".", "") . ";\r\n\r\n";
		}

		if ($jet_card == 1) {
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}

		$body .= "Данни за кредита:\r\n";

		$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
		$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
		$body .= "Месечна вноска: $jet_vnoska;\r\n";
		$body .= "Първоначална вноска: " . number_format(floatval($jet_parva), 2, ".", "") . ";\r\n";

		$jet_count = (int)get_option("jet_count") + 1;
		update_option("jet_count", $jet_count);
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$cc = $toEmail_other . ", " . $jet_email;

		$headers = [
			'MIME-Version: 1.0',
			'Content-type: text/plain; charset=utf-8',
			'From: ' . mb_encode_mimeheader($jet_id,"UTF-8") . ' <' . $toEmail_admin . '>',
			'Cc: ' . $cc
		];

		if (wp_mail($toEmail_admin, $subject, $body, $headers)) {
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
		
			$order = wc_create_order();
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
			$order->set_address( $addressBilling, 'billing' );
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
			$order->set_address( $addressShipping, 'shipping' );
			
			for ($index = 0; $index < sizeof($_product); $index++) {
				$jet_product_id = $_product[$index];
				$jet_variation_id = $product_v[$index];
				$jet_product_qt = (int)$product_q[$index];
				if( isset($jet_variation_id) && $jet_variation_id != 0) {
					$product = new WC_Product_Variation($jet_variation_id);
				}
				else {
					$product = new WC_Product($jet_product_id);
				}
				if (!$product) {
					return new WP_Error('invalid_product', __('Invalid product ID.'));
				}
				$order->add_product($product, $jet_product_qt);
			}
			
			$order->set_payment_method('jetpayment');
			$order->set_payment_method_title('ПБ Лични Финанси');
			$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
			$gateway_settings = isset($payment_gateways['jetpayment']) ? $payment_gateways['jetpayment']->settings : null;
			if ($gateway_settings && isset($gateway_settings['order_status'])) {
				$custom_order_status = $gateway_settings['order_status'];
				$new_status = 'wc-' === substr( $custom_order_status, 0, 3 ) ? substr( $custom_order_status, 3 ) : $custom_order_status;
				$order->set_status($new_status);
			}
			$order->calculate_totals();
			$order->save();
			
			global $woocommerce;
			foreach($woocommerce->cart->get_cart() as $cart_item_key => $cart_item){
				$woocommerce->cart->remove_cart_item($cart_item_key);
			}
			$json['success'] = 'success';
		}else{
			$json['success'] = 'unsuccess';
		}
		
		wp_send_json($json);
		die();
	}
	add_action( 'wp_ajax_jet_send_card', 'jet_send_card' );
	add_action( 'wp_ajax_nopriv_jet_send_card', 'jet_send_card' );
