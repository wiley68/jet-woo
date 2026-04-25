<?php
class Jet_Payment_Gateway extends WC_Payment_Gateway {
	public $domain;
	public $instructions;
	public $order_status;

	public function __construct() {
		$this->domain = 'jetcredit';
		$this->id = 'jetpayment';
		$this->icon = apply_filters('woocommerce_custom_gateway_icon', '');
		$this->has_fields = false;
		$this->method_title = 'ПБ Лични Финанси';
		$this->method_description = 'Дава възможност на Вашите клиенти да закупуват стока на изплащане с ПБ Лични Финанси';
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions', $this->description );
		$this->order_status = $this->get_option( 'order_status', 'wc-processing' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_dskapipayment', array( $this, 'thankyou_jetpayment_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_jetpayment_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => 'Разреши/Забрани',
				'type'	=> 'checkbox',
				'label'   => 'Разреши ПБ Лични Финанси покупки на Кредит',
				'default' => 'yes'
			),
			'title' => array(
				'title'	   => 'Заглавие',
				'type'		=> 'text',
				'description' => 'Показва това заглавие при избор на метод на плащане ПБ Лични Финанси покупки на Кредит.',
				'default'	 => 'ПБ Лични Финанси',
				'desc_tip'	=> true,
			),
			'order_status' => array(
				'title'	   => 'Състояние на поръчката',
				'type'		=> 'select',
				'class'	   => 'wc-enhanced-select',
				'description' => 'Какво да бъде състоянието на поръчката след като платите с този метод.',
				'default'	 => 'wc-processing',
				'desc_tip'	=> true,
				'options'	 => wc_get_order_statuses()
			),
			'description' => array(
				'title'	   => 'Описание',
				'type'		=> 'textarea',
				'description' => 'Описание на метода за плащане.',
				'default'	 => 'Моля, изберете подходящите за Вас условия:',
				'desc_tip'	=> true,
			),
			'instructions' => array(
				'title'	   => 'Инструкции',
				'type'		=> 'textarea',
				'description' => 'Показва тази инструкция при избор на метод на плащане ПБ Лични Финанси покупки на Кредит.',
				'default'	 => 'Можеш да закупиш избрания продукт на изплащане! Избери най-подходящата месечна вноска.',
				'desc_tip'	=> true,
			),
		);
	}

	public function thankyou_jetpayment_page() {
		if ( $this->instructions )
		echo wpautop( wptexturize( $this->instructions ) );
	}

	public function email_jetpayment_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->instructions && ! $sent_to_admin && 'jetpayment' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
			echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
		}
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if (!WC()->cart) {
			return false;
		}
		if ( did_action( 'wp_loaded' ) && WC()->cart ) {
			if (0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total()) {
				return false;
			}
			$jet_status = (int) get_option( 'jet_status_in' );
			if ( $jet_status !== 1 ) {
				return false;
			}
			$jet_currency_code = get_woocommerce_currency();
			if ( $jet_currency_code !== 'EUR' && $jet_currency_code !== 'BGN' ) {
				return false;
			}
			$jet_minprice = (float) get_option( 'jet_minprice' );
			if ( $this->get_order_total() > 0 ) {
				if ( ( $this->get_order_total() < $jet_minprice ) ) {
					return false;
				}
			}
			return true;
		}
		return true;
	}

	public function payment_fields() {
		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}
		global $woocommerce;
		$jet_price = $woocommerce->cart->get_total('float');
		$jet_currency_code = get_woocommerce_currency();
		$jet_eur = (int)get_option("jet_eur");
		$jet_sign = 'лева';
		$jet_sign_second = 'евро';
		$jet_min_250 = JET_MIN_250;
		switch ($jet_eur) {
			case 0:
				$jet_sign = 'лева';
				$jet_sign_second = '';
				break;
			case 1:
				if ($jet_currency_code == "EUR") {
					$jet_price = number_format($jet_price * 1.95583, 2, ".", "");
				}
				$jet_sign = 'лева';
				$jet_sign_second = 'евро';
				break;
			case 2:
				if ($jet_currency_code == "BGN") {
					$jet_price = number_format($jet_price / 1.95583, 2, ".", "");
				}
				$jet_sign = 'евро';
				$jet_sign_second = 'лева';
				$jet_min_250 = JET_MIN_250_EUR;
				break;
			case 3:
				if ($jet_currency_code == "BGN") {
					$jet_price = number_format($jet_price / 1.95583, 2, ".", "");
				}
				$jet_sign = 'евро';
				$jet_sign_second = '';
				$jet_min_250 = JET_MIN_250_EUR;
				break;
		}
		$jet_vnoski_default = get_option("jet_vnoski_default");
		if ($jet_price < $jet_min_250) {
			$jet_vnoski = '9';
		} else {
			$jet_vnoski = $jet_vnoski_default;
		}
		$jet_products = '';
		$jet_products_qt = '';
		$jet_products_pr = '';
		$jet_products_vr = '';
		foreach ($woocommerce->cart->get_cart() as $cart_item) {
			$jet_products .= $cart_item['product_id'] . '_';
			$jet_products_qt .= $cart_item['quantity'] . '_';
			$jet_product_vr_current = $cart_item['variation_id'];
			if($jet_product_vr_current != 0) {
				$jet_product = new WC_Product_Variation($jet_product_vr_current);
			} else {
				$jet_product = new WC_Product($cart_item['product_id']);
			}
			$jet_products_pr_current = (float)wc_get_price_including_tax($jet_product);
			switch ($jet_eur) {
				case 0:
					break;
				case 1:
					if ($jet_currency_code == "EUR") {
						$jet_products_pr_current = $jet_products_pr_current * 1.95583;
					}
					break;
				case 2:
				case 3:
					if ($jet_currency_code == "BGN") {
						$jet_products_pr_current = $jet_products_pr_current / 1.95583;
					}
					break;
			}
			$jet_products_pr .= number_format($jet_products_pr_current, 2, ".", "") . '_';
			$jet_products_vr .= $jet_product_vr_current . '_';
		}
		$jet_products = trim($jet_products, "_");
		$jet_products_qt = substr($jet_products_qt, 0, -1);
		$jet_products_pr = substr($jet_products_pr, 0, -1);
		$jet_products_vr = substr($jet_products_vr, 0, -1);
		?>
		<input type="hidden" id="jet_price" name="jet_price" value="<?php echo $woocommerce->cart->get_total('float'); ?>" />
		<input type="hidden" id="jet_products" name="jet_products" value="<?php echo $jet_products; ?>" />
		<input type="hidden" id="jet_products_qt" name="jet_products_qt" value="<?php echo $jet_products_qt; ?>" />
		<input type="hidden" id="jet_products_pr" name="jet_products_pr" value="<?php echo $jet_products_pr; ?>" />
		<input type="hidden" id="jet_products_vr" name="jet_products_vr" value="<?php echo $jet_products_vr; ?>" />
		<div id="jet_panel" class="jet_panel">
			<div class="jet_row jet_row_parva">
				<div class="jet_column_left">
					Първоначална вноска (<?php echo $jet_sign; ?>)
				</div>
				<div class="jet_column_right">
					<input
						class="jet_input_text_active"
						type="number"
						min="0"
						id="jet_parva"
						name="jet_parva_input"
						value=0
					/>
					<button
						type="button"
						id="btn_preizcisli"
						class="jet_button_preizcisli"
					>Преизчисли</button>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					<?php if ($jet_sign_second == '') { ?>
						Цена на стоките (<?php echo $jet_sign; ?>)
					<?php } else { ?>
						Цена на стоките (<?php echo $jet_sign; ?>
						<span style='font-size:70%;font-weight:400;height:14px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
					<?php } ?>
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_priceall_input" name="jet_priceall_input" />
					<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_priceall"></span></div>
							<div></div>
						</div>
					<?php } else { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_priceall"></span></div>
							<div>
								<span>/</span><span id="jet_priceall_second"></span>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					Брой погасителни вноски
				</div>
				<div class="jet_column_right">
					<select
						id="jet_vnoski"
						name="jet_vnoski_input"
						class="jet_input_text"
					>
						<option value="3" <?php if ($jet_vnoski == 3) { echo 'selected'; } ?>>3 месеца</option>
						<option value="6" <?php if ($jet_vnoski == 6) { echo 'selected'; } ?>>6 месеца</option>
						<option value="9" <?php if ($jet_vnoski == 9) { echo 'selected'; } ?>>9 месеца</option>
						<option value="10" <?php if ($jet_vnoski == 10) { echo 'selected'; } ?>>10 месеца</option>
						<option value="12" <?php if ($jet_vnoski == 12) { echo 'selected'; } ?>>12 месеца</option>
						<option value="15" <?php if ($jet_vnoski == 15) { echo 'selected'; } ?>>15 месеца</option>
						<option value="18" <?php if ($jet_vnoski == 18) { echo 'selected'; } ?>>18 месеца</option>
						<option value="24" <?php if ($jet_vnoski == 24) { echo 'selected'; } ?>>24 месеца</option>
						<option value="30" <?php if ($jet_vnoski == 30) { echo 'selected'; } ?>>30 месеца</option>
						<option value="36" <?php if ($jet_vnoski == 36) { echo 'selected'; } ?>>36 месеца</option>
					</select>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					<?php if ($jet_sign_second == '') { ?>
						Общо кредит (<?php echo $jet_sign; ?>)
					<?php } else { ?>
						Общо кредит (<?php echo $jet_sign; ?>
						<span style='font-size:70%;font-weight:400;height:14px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
					<?php } ?>
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_total_credit_price_input" name="jet_total_credit_price_input" />
					<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_total_credit_price"></span></div>
							<div></div>
						</div>
					<?php } else { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_total_credit_price"></span></div>
							<div>
								<span>/</span><span id="jet_total_credit_price_second"></span>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					<?php if ($jet_sign_second == '') { ?>
						Месечна вноска (<?php echo $jet_sign; ?>)
					<?php } else { ?>
						Месечна вноска (<?php echo $jet_sign; ?>
						<span style='font-size:70%;font-weight:400;height:14px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
					<?php } ?>
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_vnoska_input" name="jet_vnoska_input" />
					<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_vnoska"></span></div>
							<div></div>
						</div>
					<?php } else { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_vnoska"></span></div>
							<div>
								<span>/</span><span id="jet_vnoska_second"></span>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					Фикс ГПР (%)
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_gpr_input" name="jet_gpr_input" />
					<div class="jet_input_text jet_disable">
						<div><span id="jet_gpr"></span></div>
						<div></div>
					</div>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					ГЛП (%)
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_glp_input" name="jet_glp_input" />
					<div class="jet_input_text jet_disable">
						<div><span id="jet_glp"></span></div>
						<div></div>
					</div>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					<?php if ($jet_sign_second == '') { ?>
						Общо плащания (<?php echo $jet_sign; ?>)
					<?php } else { ?>
						Общо плащания (<?php echo $jet_sign; ?>
						<span style='font-size:70%;font-weight:400;height:14px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
					<?php } ?>
				</div>
				<div class="jet_column_right">
					<input type="hidden" id="jet_obshto_input" name="jet_obshto_input" />
					<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_obshto"></span></div>
							<div></div>
						</div>
					<?php } else { ?>
						<div class="jet_input_text jet_disable">
							<div><span id="jet_obshto"></span></div>
							<div>
								<span>/</span><span id="jet_obshto_second"></span>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="jet_row">
				<div class="jet_column_left">
					ЕГН *
				</div>
				<div class="jet_column_right">
					<input
						class="jet_input_text_active jet_left"
						type="text"
						id="jet_egn"
						name="jet_egn"
						maxlength="10"
					/>
				</div>
			</div>
			<div class="jet_hr"></div>
			<div class="jet_row_footer">
				<div style="padding-bottom: 5px;">
					<input
						type="checkbox"
						name="jet_uslovia"
						value="1"
						id="jet_uslovia"
						class="jet_uslovia"
					/>
					&nbsp;&nbsp;&nbsp;
					<a
						href="https://www.postbank.bg/common-conditions-PFBG"
						class="jet_uslovia_a"
						title="Условия за кандидатстване на ПБ Лични Финанси"
						target="_blank"
					>
						<span style="font-size: 12px;">Запознах се с условията за кандидатстване на ПБ Лични финанси</span>
					</a>
				</div>
				<div>
					<input
						type="checkbox"
						name="jet_uslovia1"
						value="1"
						id="jet_uslovia1"
						class="jet_uslovia"
					/>
					&nbsp;&nbsp;&nbsp;
					<a
						href="https://www.postbank.bg/Personal-Data-PFBG-retailers"
						class="jet_uslovia_a"
						title="Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО"
						target="_blank"
					>
						<span style="font-size: 12px;">"GDPR" означава Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО</span>
					</a>
				</div>
			</div>
		</div>
		<div id="jet_panel_error" class="jet_panel">
			<span>Не можете да поръчвате този продукт с ПБ Лични Финанси!</span>
		</div>
		<?php
	}

	public function validate_fields() {
		if (isset($_POST['jet_uslovia']) && $_POST['jet_uslovia'] !== '') {
			$jet_uslovia = (int)$_POST['jet_uslovia'];
		} else {
			$jet_uslovia = 0;
			if (isset(WC()->session)) {
				if (WC()->session->get('jet_uslovia')) {
					$jet_uslovia = (int)WC()->session->get('jet_uslovia');
				} else {
					$jet_uslovia = 0;
				}
			} else {
				$jet_uslovia = 0;
			}
		}
		if (isset($_POST['jet_uslovia1']) && $_POST['jet_uslovia1'] !== '') {
			$jet_uslovia1 = (int)$_POST['jet_uslovia1'];
		} else {
			$jet_uslovia1 = 0;
			if (isset(WC()->session)) {
				if (WC()->session->get('jet_uslovia1')) {
					$jet_uslovia1 = (int)WC()->session->get('jet_uslovia1');
				} else {
					$jet_uslovia1 = 0;
				}
			} else {
				$jet_uslovia1 = 0;
			}
		}
		if (isset($_POST['jet_egn']) && $_POST['jet_egn'] !== '') {
			$jet_egn = sanitize_text_field($_POST['jet_egn']);
		} else {
			$jet_egn = '';
			if (isset(WC()->session)) {
				if (WC()->session->get('jet_egn')) {
					$jet_egn = (int)WC()->session->get('jet_egn');
				} else {
					$jet_egn = '';
				}
			} else {
				$jet_egn = '';
			}
		}
		if($jet_uslovia === 0 || $jet_uslovia1 === 0) {
			wc_add_notice('Необходимо е да се съгласите с "Условия за кандидатстване на ПБ Лични Финанси" и "Защита на физическите лица по отношение на обработката на лични данни"!', 'error' );
			return false;
		}
		if($jet_egn === '') {
			wc_add_notice('Необходимо е да попълните ЕГН"!', 'error' );
			return false;
		}
		return true;
	}

	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		$new_status = 'wc-' === substr( $this->order_status, 0, 3 ) ? substr( $this->order_status, 3 ) : $this->order_status;
		$order->update_status( $new_status, 'Статусът на поръчката е зададен от платежния метод ПБ Лични Финанси.');
		$jet_fname = filter_input(INPUT_POST, 'billing_first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_first_name();
		$jet_lastname = filter_input(INPUT_POST, 'billing_last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_last_name();
		$jet_phone = filter_input(INPUT_POST, 'billing_phone', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_phone();
		$jet_email = filter_input(INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL) ?? $order->get_billing_email();
		$jet_billing_city = filter_input(INPUT_POST, 'billing_city', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_city();
		$jet_billing_address_1 = filter_input(INPUT_POST, 'billing_address_1', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_address_1();
		$jet_billing_postcode = filter_input(INPUT_POST, 'billing_postcode', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_billing_postcode();
		$jet_shipping_city = filter_input(INPUT_POST, 'shipping_city', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_shipping_city();
		$jet_shipping_address_1 = filter_input(INPUT_POST, 'shipping_address_1', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? $order->get_shipping_address_1();
		if (isset($_POST['jet_priceall_input'])) {
			$jet_priceall = (float)$_POST['jet_priceall_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_priceall') ) {
					$jet_priceall = (float)WC()->session->get('jet_priceall');
					WC()->session->__unset('jet_priceall');
				} else {
					$jet_priceall = 0.00;
				}
			} else {
				$jet_priceall = 0.00;
			}
		}
		if (isset($_POST['jet_vnoski_input'])) {
			$jet_vnoski = (int)$_POST['jet_vnoski_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_vnoski') ) {
					$jet_vnoski = (int)WC()->session->get('jet_vnoski');
					WC()->session->__unset('jet_vnoski');
				} else {
					$jet_vnoski = 12;
				}
			} else {
				$jet_vnoski = 12;
			}
		}
		if (isset($_POST['jet_vnoska_input'])) {
			$jet_vnoska = (float)$_POST['jet_vnoska_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_vnoska') ) {
					$jet_vnoska = (float)WC()->session->get('jet_vnoska');
					WC()->session->__unset('jet_vnoska');
				} else {
					$jet_vnoska = 0.00;
				}
			} else {
				$jet_vnoska = 0.00;
			}
		}
		if (isset($_POST['jet_parva_input'])) {
			$jet_parva = (float)$_POST['jet_parva_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_parva') ) {
					$jet_parva = (float)WC()->session->get('jet_parva');
					WC()->session->__unset('jet_parva');
				} else {
					$jet_parva = 0.00;
				}
			} else {
				$jet_parva = 0.00;
			}
		}
		if (isset($_POST['jet_total_credit_price_input'])) {
			$jet_total_credit_price = (float)$_POST['jet_total_credit_price_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_total_credit_price') ) {
					$jet_total_credit_price = (float)WC()->session->get('jet_total_credit_price');
					WC()->session->__unset('jet_total_credit_price');
				} else {
					$jet_total_credit_price = 0.00;
				}
			} else {
				$jet_total_credit_price = 0.00;
			}
		}
		if (isset($_POST['jet_obshto_input'])) {
			$jet_obshto = (float)$_POST['jet_obshto_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_obshto') ) {
					$jet_obshto = (float)WC()->session->get('jet_obshto');
					WC()->session->__unset('jet_obshto');
				} else {
					$jet_obshto = 0.00;
				}
			} else {
				$jet_obshto = 0.00;
			}
		}
		if (isset($_POST['jet_gpr_input'])) {
			$jet_gpr = (float)$_POST['jet_gpr_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_gpr') ) {
					$jet_gpr = (float)WC()->session->get('jet_gpr');
					WC()->session->__unset('jet_gpr');
				} else {
					$jet_gpr = 0.00;
				}
			} else {
				$jet_gpr = 0.00;
			}
		}
		if (isset($_POST['jet_glp_input'])) {
			$jet_glp = (float)$_POST['jet_glp_input'];
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_glp') ) {
					$jet_glp = (float)WC()->session->get('jet_glp');
					WC()->session->__unset('jet_glp');
				} else {
					$jet_glp = 0.00;
				}
			} else {
				$jet_glp = 0.00;
			}
		}
		if (isset($_POST['jet_products'])) {
			$jet_products = sanitize_text_field($_POST['jet_products']);
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_products') ) {
					$jet_products = WC()->session->get('jet_products');
					WC()->session->__unset('jet_products');
				} else {
					$jet_products = '';
				}
			} else {
				$jet_products = '';
			}
		}
		if (isset($_POST['jet_products_qt'])) {
			$jet_products_qt = sanitize_text_field($_POST['jet_products_qt']);
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_products_qt') ) {
					$jet_products_qt = WC()->session->get('jet_products_qt');
					WC()->session->__unset('jet_products_qt');
				} else {
					$jet_products_qt = '';
				}
			} else {
				$jet_products_qt = '';
			}
		}
		if (isset($_POST['jet_products_pr'])) {
			$jet_products_pr = sanitize_text_field($_POST['jet_products_pr']);
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_products_pr') ) {
					$jet_products_pr = WC()->session->get('jet_products_pr');
					WC()->session->__unset('jet_products_pr');
				} else {
					$jet_products_pr = '';
				}
			} else {
				$jet_products_pr = '';
			}
		}
		if (isset($_POST['jet_products_vr'])) {
			$jet_products_vr = sanitize_text_field($_POST['jet_products_vr']);
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_products_vr') ) {
					$jet_products_vr = WC()->session->get('jet_products_vr');
					WC()->session->__unset('jet_products_vr');
				} else {
					$jet_products_vr = '';
				}
			} else {
				$jet_products_vr = '';
			}
		}
		if (isset($_POST['jet_egn'])) {
			$jet_egn = sanitize_text_field($_POST['jet_egn']);
		} else {
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get('jet_egn') ) {
					$jet_egn = WC()->session->get('jet_egn');
					WC()->session->__unset('jet_egn');
				} else {
					$jet_egn = '';
				}
			} else {
				$jet_egn = '';
			}
		}
		$toEmail_admin = sanitize_email(get_bloginfo('admin_email'));
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
		$body .= "Собствено име: $jet_fname;\r\n";
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
			$jet_product_id = absint($_product[$index]);
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
		$body .= "Данни за кредита:\r\n";
		$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
		$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
		$body .= "Месечна вноска: " . number_format($jet_vnoska, 2, '.', '') . ";\r\n";
		$body .= "Първоначална вноска: " . number_format((float)$jet_parva, 2, ".", "") . ";\r\n";
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
			WC()->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url($order),
			);
		}
	}
}
