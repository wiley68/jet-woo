<?php
function jet_do_output_buffer() {
	ob_start();
}
add_action( 'init', 'jet_do_output_buffer' );

function jet_load_class_plugin() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	include JET_INCLUDES_DIR . '/class-jet-payment-gateway.php';
	include JET_INCLUDES_DIR . '/class-card-jet-payment-gateway.php';
}
add_action( 'plugins_loaded', 'jet_load_class_plugin', 0 );

function add_jet_gateway_class( $gateways ) {
	$gateways[] = 'Jet_Payment_Gateway';
	$gateways[] = 'Jet_Card_Payment_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'add_jet_gateway_class' );

add_action('wp_ajax_nopriv_jet_get_nonce', 'jet_get_nonce');
add_action('wp_ajax_jet_get_nonce', 'jet_get_nonce');

function jet_get_nonce() {
	wp_send_json_success([
		'nonce' => wp_create_nonce('jet_nonce'),
	]);
}

function jet_add_meta_admin() {
	$screen = get_current_screen();
	if ( isset( $screen->id ) && 'settings_page_jet-options' === $screen->id ) {
		if ( ! wp_style_is( 'jet_style_admin', 'enqueued' ) ) {
			wp_enqueue_style(
				'jet_style_admin',
				plugin_dir_url( __FILE__ ) . '../css/jet_admin.css',
				array(),
				filemtime(JET_PLUGIN_DIR . '/css/jet_admin.css'),
				'all'
			);
		}
		if ( ! wp_script_is( 'jet_credit_admin', 'enqueued' ) ) {
			wp_enqueue_script(
				'jet_credit_admin',
				plugin_dir_url( __FILE__ ) . '../js/jet_admin.js',
				array( 'jquery' ),
				filemtime(JET_PLUGIN_DIR . '/js/jet_admin.js'),
				true
			);
		}
		wp_localize_script(
			'jet_credit_admin',
			'jet_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'jet_nonce' ),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'jet_add_meta_admin' );

function jet_add_meta() {
	global $post;
	if ( is_product() ) {
		wp_enqueue_style(
			'jet_styles',
			JET_CSS_URI . '/jetcredit_product.css',
			array(),
			filemtime(JET_PLUGIN_DIR . '/css/jetcredit_product.css'),
			'all'
		);
		wp_enqueue_script(
			'jet_js',
			JET_JS_URI . '/jetcredit_product.js',
			array( 'jquery' ),
			filemtime(JET_PLUGIN_DIR . '/js/jetcredit_product.js'),
			true
		);
		wp_localize_script(
			'jet_js',
			'jet_js',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'jet_nonce' ),
			)
		);
	}
	if ( is_cart() ) {
		wp_enqueue_style(
			'jet_cart_styles',
			JET_CSS_URI . '/jetcredit_cart.css',
			array(),
			filemtime(JET_PLUGIN_DIR . '/css/jetcredit_cart.css'),
			'all'
		);
		wp_enqueue_script(
			'jet_cart_js',
			JET_JS_URI . '/jetcredit_cart.js',
			array( 'jquery' ),
			filemtime(JET_PLUGIN_DIR . '/js/jetcredit_cart.js'),
			true
		);
		wp_localize_script(
			'jet_cart_js',
			'jet_cart_js',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'jet_nonce' ),
			)
		);
	}
	if ( is_checkout() ) {
		wp_enqueue_style(
			'jet_checkout_styles',
			JET_CSS_URI . '/jetcredit_checkout.css',
			array(),
			filemtime(JET_PLUGIN_DIR . '/css/jetcredit_checkout.css'),
			'all'
		);
		wp_enqueue_script(
			'jet_checkout_js',
			JET_JS_URI . '/jetcredit_checkout.js',
			array( 'jquery' ),
			filemtime(JET_PLUGIN_DIR . '/js/jetcredit_checkout.js'),
			true
		);
		wp_localize_script(
			'jet_checkout_js',
			'jet_checkout_js',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'jet_nonce' ),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'jet_add_meta' );

if ( function_exists( 'is_cart' ) && is_cart() ) {
	return function_exists( 'WC' ) && WC()->cart && is_page( wc_get_page_id( 'cart' ) );
}

function jet_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_kop_name   = $wpdb->prefix . 'jet_kop';
	$charset_collate  = $wpdb->get_charset_collate();
	$table_kop_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_kop_name ) ) === $table_kop_name );

	if ( ! $table_kop_exists ) {
		$sql_kop = "CREATE TABLE $table_kop_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			jet_product_id varchar(20) NOT NULL,
			jet_product_percent DECIMAL(5,2) NOT NULL,
			jet_product_meseci varchar(50) NOT NULL,
			jet_product_price DECIMAL(10,2) UNSIGNED NOT NULL,
			jet_product_start DATETIME NOT NULL,
			jet_product_end DATETIME NOT NULL,
			PRIMARY KEY (id),
			FULLTEXT idx (`jet_product_id`)
		) $charset_collate;";
		dbDelta( $sql_kop );
	}
}

function jet_remove_tables() {
	global $wpdb;
	$table_kop_name = $wpdb->prefix . 'jet_kop';
	$sql_kop = "DROP TABLE IF EXISTS $table_kop_name;";
	$wpdb->query( $sql_kop );
}

function jet_read_kop() {
	global $wpdb;
	$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}jet_kop", OBJECT );
	return $results;
}

function jet_get_promo( $jet_product_id, $jet_vnoski, $jet_total_credit_price ) {
	$result = array();
	$jet_purcent = (float) get_option( 'jet_purcent' );
	$jet_purcent_card = (float) get_option( 'jet_purcent_card' );
	$jet_show_button = true;

	global $wpdb;

	$table = $wpdb->prefix . 'jet_kop';

	$sql = $wpdb->prepare(
		"
		SELECT jet_product_percent
		FROM {$table}
		WHERE (jet_product_id = '*' OR jet_product_id = %s)
		  AND FIND_IN_SET(%d, REPLACE(jet_product_meseci, '_', ','))
		  AND %f >= jet_product_price
		  AND jet_product_start <= CURDATE()
		  AND jet_product_end >= CURDATE()
		ORDER BY CASE WHEN jet_product_id = %s THEN 0 ELSE 1 END, id ASC
		LIMIT 1
		",
		(string) $jet_product_id,
		(int) $jet_vnoski,
		(float) $jet_total_credit_price,
		(string) $jet_product_id
	);

	$row = $wpdb->get_row($sql, ARRAY_A);

	if (!empty($row)) {
		if ((float)$row['jet_product_percent'] === -1.00) {
			$jet_show_button = false;
		} else {
			$jet_purcent = (float)$row['jet_product_percent'];
			$jet_purcent_card = (float)$row['jet_product_percent'];
		}
	}

	$result = [
		'jet_show_button' => $jet_show_button,
		'jet_purcent' => $jet_purcent,
		'jet_purcent_card' => $jet_purcent_card,
	];
	return $result;
}

function jet_credit_button() {
	$jet_product = wc_get_product();

	$jet_price = (float) wc_get_price_including_tax( $jet_product );
	if ( 0 === $jet_price ) {
		return null;
	}

	$jet_currency_code = get_woocommerce_currency();
	if ( 'EUR' !== $jet_currency_code && 'BGN' !== $jet_currency_code ) {
		return null;
	}

	$is_active = (int) get_option( 'jet_status_in' );
	if ( 0 === $is_active ) {
		return null;
	}

	$jet_eur         = (int) get_option( 'jet_eur' );
	$jet_sign        = 'лв.';
	$jet_sign_second = 'евро';
	$jet_min_250     = JET_MIN_250;

	switch ( $jet_eur ) {
		case 0:
			$jet_sign        = 'лв.';
			$jet_sign_second = '';
			break;
		case 1:
			if ( 'EUR' === $jet_currency_code ) {
				$jet_price = number_format( $jet_price * 1.95583, 2, '.', '' );
			}
			$jet_sign        = 'лв.';
			$jet_sign_second = 'евро';
			break;
		case 2:
			if ( 'BGN' === $jet_currency_code ) {
				$jet_price = number_format( $jet_price / 1.95583, 2, '.', '' );
			}
			$jet_sign        = 'евро';
			$jet_sign_second = 'лв.';
			$jet_min_250     = JET_MIN_250_EUR;
			break;
		case 3:
			if ( 'BGN' === $jet_currency_code ) {
				$jet_price = number_format( $jet_price / 1.95583, 2, '.', '' );
			}
			$jet_sign        = 'евро';
			$jet_sign_second = '';
			$jet_min_250     = JET_MIN_250_EUR;
			break;
	}

	$_minprice = (float) get_option( 'jet_minprice' );
	$jet_show_button_initial = $jet_price >= $_minprice;

	$jet_gap            = (int) get_option( 'jet_gap' );
	$jet_vnoski_default = get_option( 'jet_vnoski_default' );
	$jet_button_type    = get_option( 'jet_button_type', 'standard' );
	if ( ! in_array( $jet_button_type, array( 'standard', 'wide' ), true ) ) {
		$jet_button_type = 'standard';
	}
	if ( $jet_price < $jet_min_250 ) {
		$jet_vnoski = '9';
	} else {
		$jet_vnoski = $jet_vnoski_default;
	}

	$jet_card_in = (int) get_option( 'jet_card_in' );
	$is_vnoska   = (int) get_option( 'jet_vnoska' );

	$jet_name     = '';
	$jet_lastname = '';
	$jet_email    = '';
	$jet_phone    = '';
	if ( function_exists( 'wp_get_current_user' ) ) {
		$jet_customer = wp_get_current_user();
		$jet_name     = $jet_customer->user_firstname;
		$jet_lastname = $jet_customer->user_lastname;
		$jet_email    = $jet_customer->user_email;
		$jet_user_id  = $jet_customer->ID;
		$jet_phone    = get_user_meta( $jet_user_id, 'phone_number', true );
	}

	$useragent     = array_key_exists( 'HTTP_USER_AGENT', $_SERVER ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$jet_is_mobile = preg_match( '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent ) || preg_match( '/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr( $useragent,0,4 ) );
	if ( $jet_is_mobile ) {
		$jet_PopUp_Detailed_v1 = "jetm_PopUp_Detailed_v1";
		$jet_Mask = "jetm_Mask";
		$jet_column_left = "jetm_column_left";
		$jet_column_right = "jetm_column_right";
		$obshto_credit_text = 'Общо кредит';
		$obshto_plashtania = 'Общо плащания';
		$modalpayment_jet = 'modalpayment_jetm';
	} else {
		$jet_PopUp_Detailed_v1 = "jet_PopUp_Detailed_v1";
		$jet_Mask = "jet_Mask";
		$jet_column_left = "jet_column_left";
		$jet_column_right = "jet_column_right";
		$obshto_credit_text = 'Общ размер на кредита';
		$obshto_plashtania = 'Обща стойност на плащанията';
		$modalpayment_jet = 'modalpayment_jet';
	}
	?>
	<input type="hidden" id="jet_price" value="<?php echo (float)wc_get_price_including_tax($jet_product); ?>" />
	<input type="hidden" id="jet_product_id" value="<?php echo (int)$jet_product->get_Id(); ?>" />
	<input type="hidden" id="jet_variation_id" value="" />
	<input type="hidden" id="jet_card_in" value="<?php echo (int)$jet_card_in; ?>" />
	<div
		id="jet-product-button-container"
		data-jet-minprice="<?php echo esc_attr( number_format( $_minprice, 2, '.', '' ) ); ?>"
		data-jet-eur="<?php echo (int) $jet_eur; ?>"
		data-jet-currency="<?php echo esc_attr( $jet_currency_code ); ?>"
		style="display:<?php echo $jet_show_button_initial ? 'block' : 'none'; ?>;padding-top:<?php echo $jet_gap; ?>px;"
	>
		<div id="jet_alert_overlay" class="jet_alert_overlay"></div>
		<div id="jet_alert_box"></div>
		<?php if ( 'wide' === $jet_button_type ) { ?>
			<div class="jet_wide_button_wrap">
				<button
					type="button"
					id="btn_jet"
					class="jet_wide_button"
				>
					<div class="jet_wide_button_head">
						<span>Купи на изплащане с</span>
						<img src="<?php echo esc_url( JET_IMAGES_URI . '/jet_logo.png' ); ?>" alt="ПБ Лични Финанси" class="jet_wide_button_logo" />
					</div>
					<?php if (1 === (int) $is_vnoska) { ?>
						<div class="jet_wide_button_text">
							<?php if ($jet_sign_second == '') { ?>
								<span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?>
							<?php } else { ?>
								<span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?>
								<span class="jet_wide_button_text_second">(<span id="jet_vnoska_second"></span> <?php echo $jet_sign_second; ?>)</span>
							<?php } ?>
						</div>
					<?php } ?>
				</button>

				<?php if (1 === (int) $jet_card_in) { ?>
					<button
						type="button"
						id="btn_jet_card"
						class="jet_wide_button"
					>
						<div class="jet_wide_button_head">
							<span>На вноски с твоята кредитна карта</span>
							<img src="<?php echo esc_url( JET_IMAGES_URI . '/jet_logo.png' ); ?>" alt="ПБ Лични Финанси" class="jet_wide_button_logo" />
						</div>
						<?php if (1 === (int) $is_vnoska) { ?>
							<div class="jet_wide_button_text">
								<?php if ($jet_sign_second == '') { ?>
									<span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?>
								<?php } else { ?>
									<span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?>
									<span class="jet_wide_button_text_second">(<span id="jet_vnoska_card_second"></span> <?php echo $jet_sign_second; ?>)</span>
								<?php } ?>
							</div>
						<?php } ?>
					</button>
				<?php } ?>
			</div>
		<?php } else { ?>
			<table class="jet_table_img">
				<tr>
					<td class="jet_button_table">
						<img 
							id="btn_jet"
							class="jet_logo" 
							src="<?php echo JET_IMAGES_URI; ?>/jet.png" 
							alt="Кредитен модул ПБ Лични Финанси" 
							title="Кредитен модул ПБ Лични Финанси" 
						/>
					</td>
				</tr>
				<?php if ($is_vnoska == 1) { ?>
				<tr>
					<td class="jet_button_table">
						<?php if ($jet_sign_second == '') { ?>
							<p><span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?></p>
						<?php } else { ?>
							<p>
								<span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?><br />
								<span style="font-size:75%;font-weight:400;">(<span id="jet_vnoska_second"></span> <?php echo $jet_sign_second; ?>)</span>
							</p>
						<?php } ?>
					</td>
				</tr>
				<?php } ?>
			</table>
			 <?php if ($jet_card_in == 1) { ?>
			<table class="jet_table_img">
				<tr>
					<td class="jet_button_table">
						<img 
							id="btn_jet_card"
							class="jet_logo" 
							src="<?php echo JET_IMAGES_URI; ?>/jet_card.png" 
							alt="Специални предложения само за клиенти, които вече имат кредитна карта, издадена от ПБ Лични Финанси." 
							title="Специални предложения само за клиенти, които вече имат кредитна карта, издадена от ПБ Лични Финанси." 
						/>
					</td>
				</tr>
				<?php if ($is_vnoska == 1) { ?>
				<tr>
					<td class="jet_button_table">
						<?php if ($jet_sign_second == '') { ?>
							<p><span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?></p>
						<?php } else { ?>
							<p>
								<span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?><br />
								<span style="font-size:75%;font-weight:400;">(<span id="jet_vnoska_card_second"></span> <?php echo $jet_sign_second; ?>)</span>
							</p>
						<?php } ?>
					</td>
				</tr>
				<?php } ?>
			</table>
			<?php } ?>
		<?php } ?>
	</div>

	<div id="jet-product-popup-container" class="<?php echo $modalpayment_jet; ?>">
		<div class="modalpayment-content_jet">
			<div id="jet_body">
				<div class="<?php echo $jet_PopUp_Detailed_v1; ?>">
					<div class="<?php echo $jet_Mask; ?>">
						<div id="jet_step_1">
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Първоначална вноска (<?php echo $jet_sign; ?>)
								</div>
								<div class="<?php echo $jet_column_right; ?>">
									<input 
										class="jet_input_text_active" 
										type="number" 
										min="0"
										id="jet_parva" 
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										Цена на стоките (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										Цена на стоките (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
								<div class="<?php echo $jet_column_left; ?>">
									Брой погасителни вноски
								</div>
								<div class="jet_column_right">
									<select
										id="jet_vnoski" 
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										<?php echo $obshto_credit_text; ?> (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										<?php echo $obshto_credit_text; ?> (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										Месечна вноска (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										Месечна вноска (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
									<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
										<div class="jet_input_text jet_disable">
											<div><span id="jet_vnoska_popup"></span></div>
											<div></div>
										</div>
									<?php } else { ?>
										<div class="jet_input_text jet_disable">
											<div><span id="jet_vnoska_popup"></span></div>
											<div>
												<span>/</span><span id="jet_vnoska_popup_second"></span>
											</div>
										</div>
									<?php } ?>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Фикс ГПР (%)
								</div>
								<div class="jet_column_right">
									<div class="jet_input_text jet_disable">
										<div><span id="jet_gpr"></span></div>
										<div></div>
									</div>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									ГЛП (%)
								</div>
								<div class="jet_column_right">
									<div class="jet_input_text jet_disable">
										<div><span id="jet_glp"></span></div>
										<div></div>
									</div>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										<?php echo $obshto_plashtania; ?> (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										<?php echo $obshto_plashtania; ?> (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
							<div class="jet_hr"></div>
							<div class="jet_row_footer">
								<div style="padding-bottom: 5px;">
									<input 
										type="checkbox" 
										name="uslovia" 
										value="uslovia" 
										id="uslovia" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/common-conditions-PFBG" 
										class="jet_uslovia_a" 
										title="Условия за кандидатстване на ПБ Лични Финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Запознах се с условията за кандидатстване на ПБ Лични финанси</span>
									</a>
								</div>
								<div>
									<input 
										type="checkbox" 
										name="uslovia1" 
										value="uslovia1" 
										id="uslovia1" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/Personal-Data-PFBG-retailers" 
										class="jet_uslovia_a" 
										title="Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО" 
										target="_blank"
									>
										<span style="font-size: 14px;">"GDPR" означава Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО</span>
									</a>
								</div>
							</div>
							<div class="jet_row_bottom">
								<button 
									type="button" 
									class="jet_btn" 
									id="back_jetcredit"
								>Откажи</button>
								<button 
									type="button" 
									class="jet_btn" 
									id="buy_cart_jetcredit"
								>Добави в количката</button>
								<button 
									type="button" 
									class="jet_btn" 
									id="buy_jetcredit" 
									style="opacity: 0.5;" 
									disabled
								>Купи на изплащане</button>
							</div>
						</div>
						
						<div id="jet_step_2">
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Име *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_name" 
										autocomplete="off"
										value="<?php echo $jet_name; ?>" 
									/>
									<input 
										type="hidden"
										id="jet_lname" 
										autocomplete="off"
										value="" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Фамилия *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_lastname" 
										autocomplete="off"
										value="<?php echo $jet_lastname; ?>" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									ЕГН *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_egn" 
										autocomplete="off"
										value="" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Мобилен телефон *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_phone" 
										autocomplete="off"
										value="<?php echo $jet_phone; ?>" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									E-Mail *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_email" 
										autocomplete="off"
										value="<?php echo $jet_email; ?>" 
									/>
								</div>
							</div>
							<div class="jet_hr"></div>
							<div class="jet_row_footer">
								<div style="padding-bottom: 5px;">
									<input 
										type="checkbox" 
										name="uslovia2" 
										value="uslovia2" 
										id="uslovia2" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/common-conditions-PFBG" 
										class="jet_uslovia_a" 
										title="Условия за кандидатстване на ПБ Лични Финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Запознах се с условията за кандидатстване на ПБ Лични финанси</span>
									</a>
								</div>
								<div style="padding-bottom: 5px;">
									<a 
										href="https://www.postbank.bg/product-information-PBPG-retailers" 
										class="jet_uslovia_a" 
										title="Продуктова Информация на ПБ Лични финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Продуктова Информация на ПБ Лични финанси</span>
									</a>
								</div>
							</div>
							<div class="jet_row_bottom">
								<button 
									type="button" 
									class="jet_btn" 
									id="back2_jetcredit"
								>Назад</button>
								<div style="flex: 1;"></div>
								<button 
									type="button" 
									class="jet_btn" 
									id="close_jetcredit"
								>Откажи</button>
								<button 
									type="button" 
									class="jet_btn" 
									id="buy2_jetcredit" 
									style="opacity: 0.5;" 
									disabled
								>Изпрати</button>
							</div>
						</div>
						
					</div>
				</div>
			</div>
		</div>
	</div>
<?php }
$jet_hook = get_option( 'jet_hook' );
if ( ! empty( $jet_hook ) ) {
	add_action( $jet_hook, 'jet_credit_button' );
}

function jet_credit_button_cart() {
	global $woocommerce;
	
	$jet_cart_show = (int) get_option( 'jet_cart_show' );
	if ( $jet_cart_show === 0 ) {
		return null;
	}
	
	$jet_currency_code = get_woocommerce_currency();
	if ( $jet_currency_code !== 'EUR' && $jet_currency_code !== 'BGN' ) {
		return null;
	}
	
	$jet_price = $woocommerce->cart->get_total('float');
	if ( $jet_price === 0 ) {
		return null;
	}
	
	$is_active = (int) get_option( 'jet_status_in' );
	if ( $is_active === 0 ) {
		return null;
	}
	
	$jet_eur = (int) get_option( 'jet_eur' );
	$jet_sign = 'лв.';
	$jet_sign_second = 'евро';
	$jet_min_250 = JET_MIN_250;
	
	switch ($jet_eur) {
		case 0:
			$jet_sign = 'лв.';
			$jet_sign_second = '';
			break;
		case 1:
			if ( $jet_currency_code === 'EUR' ) {
				$jet_price = number_format($jet_price * 1.95583, 2, ".", "");
			}
			$jet_sign = 'лв.';
			$jet_sign_second = 'евро';
			break;
		case 2:
			if ( $jet_currency_code === 'BGN' ) {
				$jet_price = number_format($jet_price / 1.95583, 2, ".", "");
			}
			$jet_sign = 'евро';
			$jet_sign_second = 'лв.';
			$jet_min_250 = JET_MIN_250_EUR;
			break;
		case 3:
			if ( $jet_currency_code == 'BGN' ) {
				$jet_price = number_format($jet_price / 1.95583, 2, ".", "");
			}
			$jet_sign = 'евро';
			$jet_sign_second = '';
			$jet_min_250 = JET_MIN_250_EUR;
			break;
	}
	
	$_minprice = get_option( "jet_minprice" );
	if ($jet_price < $_minprice) {
		return null;
	}
	
	$jet_gap = (int)get_option("jet_gap");
	$jet_vnoski_default = get_option("jet_vnoski_default");
	if ($jet_price < $jet_min_250) {
		$jet_vnoski = '9';
	} else {
		$jet_vnoski = $jet_vnoski_default;
	}
	
	$jet_card_in = (int)get_option("jet_card_in");
	$is_vnoska = (int)get_option("jet_vnoska");
	
	$jet_name = "";
	$jet_lastname = "";
	$jet_email = "";
	$jet_phone = "";
	if (function_exists("wp_get_current_user")) {
		$jet_customer = wp_get_current_user();
		$jet_name = $jet_customer->user_firstname;
		$jet_lastname = $jet_customer->user_lastname;
		$jet_email = $jet_customer->user_email;
		$jet_user_id = $jet_customer->ID;
		$jet_phone = get_user_meta($jet_user_id, 'phone_number', true);
	}
	
	$useragent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$jet_is_mobile = preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',$useragent)||preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',substr($useragent,0,4));
	if($jet_is_mobile){
		$jet_PopUp_Detailed_v1 = "jetm_PopUp_Detailed_v1";
		$jet_Mask = "jetm_Mask";
		$jet_column_left = "jetm_column_left";
		$jet_column_right = "jetm_column_right";
		$obshto_credit_text = 'Общо кредит';
		$obshto_plashtania = 'Общо плащания';
		$modalpayment_jet = 'modalpayment_jetm';
	}else{
		$jet_PopUp_Detailed_v1 = "jet_PopUp_Detailed_v1";
		$jet_Mask = "jet_Mask";
		$jet_column_left = "jet_column_left";
		$jet_column_right = "jet_column_right";
		$obshto_credit_text = 'Общ размер на кредита';
		$obshto_plashtania = 'Обща стойност на плащанията';
		$modalpayment_jet = 'modalpayment_jet';
	}
	
	$jet_products = '';
	$jet_products_qt = '';
	$jet_products_pr = '';
	$jet_products_vr = '';
	foreach ($woocommerce->cart->get_cart() as $cart_item) {
		$jet_products .= $cart_item['product_id'] . '_';
		$jet_products_qt .= $cart_item['quantity'] . '_';
		// При вариация взимаме продукта по variation_id, за да имаме цена с атрибутите
		$jet_product = ! empty($cart_item['variation_id'])
			? wc_get_product($cart_item['variation_id'])
			: wc_get_product($cart_item['product_id']);
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
		$jet_products_vr .= $cart_item['variation_id'] . '_';
	}
	$jet_products = trim($jet_products, "_");
	$jet_products_qt = substr($jet_products_qt, 0, -1);
	$jet_products_pr = substr($jet_products_pr, 0, -1);
	$jet_products_vr = substr($jet_products_vr, 0, -1);
	?>
	<input type="hidden" id="jet_price" value="<?php echo $woocommerce->cart->get_total('float'); ?>" />
	<input type="hidden" id="jet_card_in" value="<?php echo (int)$jet_card_in; ?>" />
	<input type="hidden" id="jet_products" value="<?php echo $jet_products; ?>" />
	<input type="hidden" id="jet_products_qt" value="<?php echo $jet_products_qt; ?>" />
	<input type="hidden" id="jet_products_pr" value="<?php echo $jet_products_pr; ?>" />
	<input type="hidden" id="jet_products_vr" value="<?php echo $jet_products_vr; ?>" />
	<div id="jet-product-button-container" style="padding-top:<?php echo $jet_gap; ?>px;">
		<div id="jet_alert_overlay" class="jet_alert_overlay"></div>
		<div id="jet_alert_box"></div>
		<table class="jet_table_img">
			<tr>
				<td class="jet_button_table">
					<img 
						id="btn_jet"
						class="jet_logo" 
						src="<?php echo JET_IMAGES_URI; ?>/jet.png" 
						alt="Кредитен модул ПБ Лични Финанси" 
						title="Кредитен модул ПБ Лични Финанси" 
					/>
				</td>
			</tr>
			<?php if ($is_vnoska == 1) { ?>
			<tr>
				<td class="jet_button_table">
					<?php if ($jet_sign_second == '') { ?>
						<p><span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?></p>
					<?php } else { ?>
						<p>
							<span id="jet_vnoski_text"></span> x <span id="jet_vnoska"></span> <?php echo $jet_sign; ?><br />
							<span style="font-size:75%;font-weight:400;">(<span id="jet_vnoska_second"></span> <?php echo $jet_sign_second; ?>)</span>
						</p>
					<?php } ?>
				</td>
			</tr>
			<?php } ?>
		</table>
		 <?php if ($jet_card_in == 1) { ?>
		<table class="jet_table_img">
			<tr>
				<td class="jet_button_table">
					<img 
						id="btn_jet_card"
						class="jet_logo" 
						src="<?php echo JET_IMAGES_URI; ?>/jet_card.png" 
						alt="Специални предложения само за клиенти, които вече имат кредитна карта, издадена от ПБ Лични Финанси." 
						title="Специални предложения само за клиенти, които вече имат кредитна карта, издадена от ПБ Лични Финанси." 
					/>
				</td>
			</tr>
			<?php if ($is_vnoska == 1) { ?>
			<tr>
				<td class="jet_button_table">
					<?php if ($jet_sign_second == '') { ?>
						<p><span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?></p>
					<?php } else { ?>
						<p>
							<span id="jet_vnoski_text_card"></span> x <span id="jet_vnoska_card"></span> <?php echo $jet_sign; ?><br />
							<span style="font-size:75%;font-weight:400;">(<span id="jet_vnoska_card_second"></span> <?php echo $jet_sign_second; ?>)</span>
						</p>
					<?php } ?>
				</td>
			</tr>
			<?php } ?>
		</table>
		<?php } ?>
	</div>
	
	<div id="jet-product-popup-container" class="<?php echo $modalpayment_jet; ?>">
		<div class="modalpayment-content_jet">
			<div id="jet_body">
				<div class="<?php echo $jet_PopUp_Detailed_v1; ?>">
					<div class="<?php echo $jet_Mask; ?>">
						<div id="jet_step_1">
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Първоначална вноска (<?php echo $jet_sign; ?>)
								</div>
								<div class="<?php echo $jet_column_right; ?>">
									<input 
										class="jet_input_text_active" 
										type="number" 
										min="0"
										id="jet_parva" 
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										Цена на стоките (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										Цена на стоките (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
								<div class="<?php echo $jet_column_left; ?>">
									Брой погасителни вноски
								</div>
								<div class="jet_column_right">
									<select
										id="jet_vnoski" 
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										<?php echo $obshto_credit_text; ?> (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										<?php echo $obshto_credit_text; ?> (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										Месечна вноска (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										Месечна вноска (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
									<?php if ($jet_eur == 0 || $jet_eur == 3) { ?>
										<div class="jet_input_text jet_disable">
											<div><span id="jet_vnoska_popup"></span></div>
											<div></div>
										</div>
									<?php } else { ?>
										<div class="jet_input_text jet_disable">
											<div><span id="jet_vnoska_popup"></span></div>
											<div>
												<span>/</span><span id="jet_vnoska_popup_second"></span>
											</div>
										</div>
									<?php } ?>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Фикс ГПР (%)
								</div>
								<div class="jet_column_right">
									<div class="jet_input_text jet_disable">
										<div><span id="jet_gpr"></span></div>
										<div></div>
									</div>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									ГЛП (%)
								</div>
								<div class="jet_column_right">
									<div class="jet_input_text jet_disable">
										<div><span id="jet_glp"></span></div>
										<div></div>
									</div>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									<?php if ($jet_sign_second == '') { ?>
										<?php echo $obshto_plashtania; ?> (<?php echo $jet_sign; ?>)
									<?php } else { ?>
										<?php echo $obshto_plashtania; ?> (<?php echo $jet_sign; ?>
										<span style='font-size:60%;font-weight:400;height:16px;'>&nbsp;/&nbsp;<?php echo $jet_sign_second; ?></span>)
									<?php } ?>
								</div>
								<div class="jet_column_right">
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
							<div class="jet_hr"></div>
							<div class="jet_row_footer">
								<div style="padding-bottom: 5px;">
									<input 
										type="checkbox" 
										name="uslovia" 
										value="uslovia" 
										id="uslovia" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/common-conditions-PFBG" 
										class="jet_uslovia_a" 
										title="Условия за кандидатстване на ПБ Лични Финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Запознах се с условията за кандидатстване на ПБ Лични финанси</span>
									</a>
								</div>
								<div>
									<input 
										type="checkbox" 
										name="uslovia1" 
										value="uslovia1" 
										id="uslovia1" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/Personal-Data-PFBG-retailers" 
										class="jet_uslovia_a" 
										title="Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО" 
										target="_blank"
									>
										<span style="font-size: 14px;">"GDPR" означава Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО</span>
									</a>
								</div>
							</div>
							<div class="jet_row_bottom">
								<button 
									type="button" 
									class="jet_btn" 
									id="back_jetcredit"
								>Откажи</button>
								<button 
									type="button" 
									class="jet_btn" 
									id="buy_jetcredit" 
									style="color: gray;background: #696969;" 
									disabled
								>Купи на изплащане</button>
							</div>
						</div>
						
						<div id="jet_step_2">
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Име *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_name" 
										autocomplete="off"
										value="<?php echo $jet_name; ?>" 
									/>
									<input 
										type="hidden"
										id="jet_lname" 
										autocomplete="off"
										value="" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Фамилия *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_lastname" 
										autocomplete="off"
										value="<?php echo $jet_lastname; ?>" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									ЕГН *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_egn" 
										autocomplete="off"
										value="" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									Мобилен телефон *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_phone" 
										autocomplete="off"
										value="" 
									/>
								</div>
							</div>
							<div class="jet_row">
								<div class="<?php echo $jet_column_left; ?>">
									E-Mail *
								</div>
								<div class="jet_column_right">
									<input 
										class="jet_input_text_active jet_left" 
										type="text"
										id="jet_email" 
										autocomplete="off"
										value="<?php echo $jet_email; ?>" 
									/>
								</div>
							</div>
							<div class="jet_hr"></div>
							<div class="jet_row_footer">
								<div style="padding-bottom: 5px;">
									<input 
										type="checkbox" 
										name="uslovia2" 
										value="uslovia2" 
										id="uslovia2" 
										class="jet_uslovia"
									/>
									&nbsp;&nbsp;&nbsp;
									<a 
										href="https://www.postbank.bg/common-conditions-PFBG" 
										class="jet_uslovia_a" 
										title="Условия за кандидатстване на ПБ Лични Финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Запознах се с условията за кандидатстване на ПБ Лични финанси</span>
									</a>
								</div>
								<div style="padding-bottom: 5px;">
									<a 
										href="https://www.postbank.bg/product-information-PBPG-retailers" 
										class="jet_uslovia_a" 
										title="Продуктова Информация на ПБ Лични финанси" 
										target="_blank"
									>
										<span style="font-size: 14px;">Продуктова Информация на ПБ Лични финанси</span>
									</a>
								</div>
							</div>
							<div class="jet_row_bottom">
								<button 
									type="button" 
									class="jet_btn" 
									id="back2_jetcredit"
								>Назад</button>
								<div style="flex: 1;"></div>
								<button 
									type="button" 
									class="jet_btn" 
									id="close_jetcredit"
								>Откажи</button>
								<button 
									type="button" 
									class="jet_btn" 
									id="buy2_jetcredit" 
									style="color: gray;background: #696969;" 
									disabled
								>Изпрати</button>
							</div>
						</div>
						
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_after_cart_totals', 'jet_credit_button_cart' );


function jet_add_query_vars_filter($vars) {
	$vars[] = "product";
	$vars[] = "products_price";
	$vars[] = "vnoski";
	$vars[] = "redoven";
	$vars[] = "product_q";
	$vars[] = "product_p";
	$vars[] = "product_c";
	$vars[] = "product_m";
	$vars[] = "parva";
	$vars[] = "jet_card";
	return $vars;
}
add_filter( 'query_vars', 'jet_add_query_vars_filter' );

function jet_wordpress_get_params($param = null, $null_return = null) {
	if ($param) {
		$value = (
			!empty($_POST[$param]) ?
			trim(esc_sql($_POST[$param])) : (!empty($_GET[$param]) ?
				trim(esc_sql($_GET[$param])) : $null_return));
		return $value;
	} else {
		$params = array();
		foreach ($_POST as $key => $param) {
			$params[trim(esc_sql($key))] = (!empty($_POST[$key]) ? trim(esc_sql($_POST[$key])) :  $null_return);
		}
		foreach ($_GET as $key => $param) {
			$key = trim(esc_sql($key));
			if (!isset($params[$key])) {
				$params[trim(esc_sql($key))] = (!empty($_GET[$key]) ? trim(esc_sql($_GET[$key])) : $null_return);
			}
		}
		return $params;
	}
}

function jet_addfilter() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Нямате права за тази операция.' ] );
	}

	check_ajax_referer('jet_nonce', 'security');

	$json = array();
	$jet_product_id = sanitize_text_field( $_POST['jet_product_id'] ?? '' );
	$jet_product_percent = (float) ( $_POST['jet_product_percent'] ?? -1.00 );
	$jet_product_meseci = sanitize_text_field( $_POST['jet_product_meseci'] ?? '' );
	$jet_product_price = (float) ( $_POST['jet_product_price'] ?? 0 );
	$jet_product_start = sanitize_text_field( $_POST['jet_product_start'] ?? '' );
	$jet_product_end = sanitize_text_field( $_POST['jet_product_end'] ?? '' );

	global $wpdb;
	$table_name = $wpdb->prefix . 'jet_kop';
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_name WHERE jet_product_id = %s", 
		$jet_product_id
	) );
	if ( $exists ) {
		$json['exist'] = 'Вече имате такъв филтър';
	} else {
		$json['exist'] = '';
		$jet_categories_current = array(
			"jet_product_id" => $jet_product_id,
			"jet_product_percent" => $jet_product_percent,
			"jet_product_meseci" => $jet_product_meseci,
			"jet_product_price" => $jet_product_price,
			"jet_product_start" => $jet_product_start,
			"jet_product_end" => $jet_product_end
		);
		$inserted = $wpdb->insert( 
			$table_name, 
			$jet_categories_current, 
			array( 
				'%s',
				'%f',
				'%s',
				'%f',
				'%s',
				'%s'
			) 
		);
		if ($inserted) {
			$json['success'] = 'success';
		} else {
			$json['success'] = 'unsuccess';
		}
	}

	echo (json_encode($json));
	die();
}
add_action( 'wp_ajax_jet_addfilter', 'jet_addfilter' );
add_action( 'wp_ajax_nopriv_jet_addfilter', 'jet_addfilter' );

function jet_removefilter() {
	check_ajax_referer('jet_nonce', 'security');

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Нямате права за тази операция.' ] );
	}

	$json = array();
	if (isset($_POST['jet_product_id'])) {
		$jet_product_id = $_POST['jet_product_id'];
	} else {
		$jet_product_id = '';
	}
	global $wpdb;
	$table_name = $wpdb->prefix . 'jet_kop';
	$deleted = $wpdb->delete( 
		$table_name, 
		array( 'jet_product_id' => $jet_product_id ),
		array( '%s' )
	);
	if ($deleted) {
		$json['success'] = 'success';
	} else {
		$json['success'] = 'unsuccess';
	}
	echo (json_encode($json));
	die();
}
add_action( 'wp_ajax_jet_removefilter', 'jet_removefilter' );
add_action( 'wp_ajax_nopriv_jet_removefilter', 'jet_removefilter' );
