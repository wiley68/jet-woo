<?php
	$jet_settings = [
		'jet_status_in' => 1,
		'jet_hook' => 'woocommerce_after_add_to_cart_button',
		'jet_email' => 'pf-online.shop@postbank.bg',
		'jet_id' => '',
		'jet_purcent' => 1.40,
		'jet_vnoski_default' => 12,
		'jet_cart_show' => 1,
		'jet_card_in' => 1,
		'jet_purcent_card' => 1.00,
		'jet_count' => '',
		'jet_gap' => 0,
		'jet_z1' => '',
		'jet_vnoska' => 1,
		'jet_minprice' => JET_MINPRICE,
		'jet_eur' => 0
	];
	if(array_key_exists('jet_hidden', $_POST) && $_POST['jet_hidden'] == 'Y') {
		check_admin_referer('jetcredit_settings_save', 'jetcredit_nonce');
		foreach ( $jet_settings as $key => $default ) {
			$value = filter_input(INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			update_option($key, $value !== null ? $value : $default);
		}
		echo '<div class="updated"><p><strong>' . esc_html('Настройките са записани успешно.') . '</strong></p></div>';
	}
	foreach ( $jet_settings as $key => $default ) {
		$$key = get_option($key, $default);
	}
	/* read filters */
	$jet_schemes = jet_read_kop();
	/* read filters */
?>
<div class="jet_container">
	<form name="jet_form" method="post" enctype="multipart/form-data" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
		<input type="hidden" name="jet_hidden" value="Y">
		<?php wp_nonce_field('jetcredit_settings_save', 'jetcredit_nonce'); ?>
		<div class="jet_page_header">
			<?php echo esc_html('ПБ Лични Финанси - кредитен калкулатор'); ?>
			<input type="submit" name="Submit" class="button-primary" value="<?php echo esc_html('Запиши промените'); ?>" />
		</div>
		<div class="jet_row">
		
			<div class="jet_panel">
				<div class="jet_panel_heading">
					<?php echo esc_html('Визуални настройки'); ?>
				</div>
				<div class="jet_panel_body">
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Включи модула'); ?></div>
						<div class="jet_control">
							<select name="jet_status_in" class="jet_form_control">
								<option value=0 <?php selected($jet_status_in, 0); ?>><?php echo esc_html('Изключен'); ?></option>
								<option value=1 <?php selected($jet_status_in, 1); ?>><?php echo esc_html('Включен'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутоните за закупуване на кредит през ПБ Лични Финанси'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Място на бутона'); ?></div>
						<div class="jet_control">
							<select name="jet_hook" class="jet_form_control">
								<?php
								$jet_hooks = [
									'woocommerce_after_add_to_cart_button' => 'Под бутона Купи',
									'woocommerce_before_single_product' => 'В началото',
									'woocommerce_before_single_product_summary' => 'Преди информацията за продукта',
									'woocommerce_single_product_summary' => 'До информацията за продукта',
									'woocommerce_before_add_to_cart_button' => 'Над бутона Купи',
									'woocommerce_before_add_to_cart_form' => 'Над бутона Купи',
									'woocommerce_after_single_product_summary' => 'Над формата за купуване на продукта',
									'woocommerce_after_add_to_cart_form' => 'Под формата за купуване на продукта',
									'woocommerce_product_meta_start' => 'Над допълнителната информация',
									'woocommerce_product_meta_end' => 'Под допълнителната информация',
									'woocommerce_share' => 'До споделената информация',
									'woocommerce_after_single_product' => 'В края'
								];
								foreach ($jet_hooks as $value => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($value),
										selected($jet_hook, $value, false),
										esc_html($label)
									);
								}
								?>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Място за показване на бутона на ПБ Лични Финанси в продуктовата страница (hook)'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи бутона в количката'); ?></div>
						<div class="jet_control">
							<select name="jet_cart_show" class="jet_form_control">
								<option value=0 <?php selected($jet_cart_show, 0); ?>><?php echo esc_html('Не'); ?></option>
								<option value=1 <?php selected($jet_cart_show, 1); ?>><?php echo esc_html('Да'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутона за закупуване на кредит през ПБ Лични Финанси в количката'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи бутона за изпращане чрез кредитна карта'); ?></div>
						<div class="jet_control">
							<select name="jet_card_in" class="jet_form_control">
								<option value=0 <?php selected($jet_card_in, 0); ?>><?php echo esc_html('Не'); ?></option>
								<option value=1 <?php selected($jet_card_in, 1); ?>><?php echo esc_html('Да'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутона за закупуване на кредит през ПБ Лични Финанси със заявката за лизинг направена по метода с кредитна карта'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Празно място над бутона'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_gap" class="jet_form_control" value="<?php echo esc_attr($jet_gap); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Празно място над бутона в px. Използва се за подредба на бутоните, когато са повече от един'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Заглавие на блока'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_z1" class="jet_form_control" value="<?php echo esc_attr($jet_z1); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Удебеления текст над бутона. Ако оставите празно, няма да се визуализира текста'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи вноска'); ?></div>
						<div class="jet_control">
							<select name="jet_vnoska" class="jet_form_control">
								<option value=0 <?php selected($jet_vnoska, 0); ?>><?php echo esc_html('Не'); ?></option>
								<option value=1 <?php selected($jet_vnoska, 1); ?>><?php echo esc_html('Да'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Дали да се показва текст, в дясно от бутона указващ месечната вноска за избрания период на лизинг?'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Избор на режим на работа с валути'); ?></div>
						<div class="jet_control">
							<select name="jet_eur" class="jet_form_control">
								<option value=0 <?php selected($jet_eur, 0); ?>><?php echo esc_html('Единична визуализация в лева и изпращане на исканията в лева'); ?></option>
								<option value=1 <?php selected($jet_eur, 1); ?>><?php echo esc_html('Двойна визуализация лева/евро и изпращане на исканията в лева'); ?></option>
								<option value=2 <?php selected($jet_eur, 2); ?>><?php echo esc_html('Двойна визуализация евро/лева и изпращане на исканията в евро'); ?></option>
								<option value=3 <?php selected($jet_eur, 3); ?>><?php echo esc_html('Единична визуализация в евро и изпращане на исканията в евро'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Избор на режим на работа с валути. Възможност за показване в евро или лева. Изпращане на исканията в евро или лева с превалутиране ако е необходимо'); ?></span>
						</div>
					</div>
				</div>
			</div>
			<div class="jet_panel">
				<div class="jet_panel_heading">
					<?php echo esc_html('Функционални настройки'); ?>
				</div>
				<div class="jet_panel_body">
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Избери email за изпращане'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_email" class="jet_form_control" value="<?php echo esc_attr($jet_email); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Избери email адрес на който ще се изпращат заявките от клиента. Използвайте "pf-online.shop@postbank.bg" за изпращане на заявките към ПБ Лични Финанси. Можете да въведете повече от един мейл адрес, като ги разделите помежду си със запетая. (Ако оставите празно ще се използва email-а по-подразбиране за системата. Препоръчва се използването на email извън Вашия домейн за да се избегне ауто спан защитата.)'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Избери идентификатор на магазина за изпращане'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_id" class="jet_form_control" value="<?php echo esc_attr($jet_id); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Избери идентификатор на магзина, който да се изпраща към Банката заедно със заявката за лизинг. По този идентификатор Банката ще разпознава Вашите заявки'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Избор на брой вноски по-подразбиране'); ?></div>
						<div class="jet_control">
							<select name="jet_vnoski_default" class="jet_form_control">
								<?php
								$jet_vnoski = [
									'3' => '3 месеца',
									'6' => '6 месеца',
									'9' => '9 месеца',
									'12' => '12 месеца',
									'15' => '15 месеца',
									'18' => '18 месеца',
									'24' => '24 месеца',
									'30' => '30 месеца',
									'36' => '36 месеца'
								];
								foreach ($jet_vnoski as $value => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($value),
										selected($jet_vnoski_default, $value, false),
										esc_html($label)
									);
								}
								?>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Избор на брой вноски по-подразбиране в продуктовата страница'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Избор на процент лихва'); ?></div>
						<div class="jet_control">
							<select name="jet_purcent" class="jet_form_control">
								<?php
								$jet_purcent_arr = [
									'0.00' => '0.00% за целия период',
									'0.80' => '0.80% за целия период',
									'0.99' => '0.99% за целия период',
									'1.00' => '1.00% за целия период',
									'1.10' => '1.10% за целия период',
									'1.20' => '1.20% за целия период',
									'1.40' => '1.40% за целия период'
								];
								foreach ($jet_purcent_arr as $value => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($value),
										selected($jet_purcent, $value, false),
										esc_html($label)
									);
								}
								?>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Избор на процент лихва'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Процент за лихва чрез кредитна карта'); ?></div>
						<div class="jet_control">
							<select name="jet_purcent_card" class="jet_form_control">
								<?php
								$jet_purcent_card_arr = [
									'0.00' => '0.00% за целия период',
									'0.80' => '0.80% за целия период',
									'0.90' => '0.90% за целия период',
									'0.99' => '0.99% за целия период',
									'1.00' => '1.00% за целия период',
									'1.10' => '1.10% за целия период',
									'1.20' => '1.20% за целия период',
									'1.40' => '1.40% за целия период'
								];
								foreach ($jet_purcent_card_arr as $value => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($value),
										selected($jet_purcent_card, $value, false),
										esc_html($label)
									);
								}
								?>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Избор на таблица за процент за лихва за заявката за лизинг направена по метода с кредитна карта'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('№ Поръчка'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_count" readonly class="jet_form_control" value="<?php echo esc_attr($jet_count); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Номер на текущата поръчка за лизинг'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Минимална сума'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_minprice" class="jet_form_control" value="<?php echo esc_attr($jet_minprice); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Минимално възможната сума на стоките за закупуване на кредит през ПБ Лични Финанси'); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>
	<!-- custom percent for product -->
	<div class="jet_row">
		<div class="jet_panel">
			<div class="jet_panel_heading">
				<?php echo esc_html('Форма за въвеждане на лихвени филтри'); ?>
			</div>
			<div class="jet_panel_body">
				<div class="jet_flex">
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_id"><?php echo esc_html('Продуктов ID номер. Идентификатора на даден продукт в системата на WooCommerce. Id #. Филтъра действа за всички продукти ако използвате глобалния символ *:'); ?></label>
						</div>
						<input type="text" name="jet_product_id" id="jet_product_id" />
					</div>
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_percent"><?php echo esc_html('Избери процент оскъпяване. Избирате процента оскъпяване който да действа за филтъра. Ако изберете "Не показвай бутона", бутона няма да се показва в продуктовата страница.:'); ?></label>
						</div>
						<select name="jet_product_percent" id="jet_product_percent">
							<?php
								$jet_product_percent_arr = [
									'-1.00' => 'Не показвай бутона',
									'0.00' => '0.00% за целия период',
									'0.80' => '0.80% за целия период',
									'0.99' => '0.99% за целия период',
									'1.00' => '1.00% за целия период',
									'1.10' => '1.10% за целия период',
									'1.20' => '1.20% за целия период',
									'1.40' => '1.40% за целия период'
								];
								foreach ($jet_product_percent_arr as $value => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($value),
										selected('0.00', $value, false),
										esc_html($label)
									);
								}
								?>
						</select>
					</div>
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_meseci"><?php echo esc_html('За кои месечни вноски действа филтъра. За разделител между цифрите за месеци се използва подчертаващо тире.:'); ?></label>
						</div>
						<input type="text" name="jet_product_meseci" id="jet_product_meseci" />
					</div>
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_price"><?php echo esc_html('Цена на продукта над която да се задейства този филтър. Ако оставите 0 филтъра ще действа за всяка цена.:'); ?></label>
						</div>
						<input type="number" name="jet_product_price" id="jet_product_price" value="0" />
					</div>
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_start"><?php echo esc_html('Начало за действие на филтъра. Датата от която започва да действа този филтър.:'); ?></label>
						</div>
						<input type="date" name="jet_product_start" id="jet_product_start" />
					</div>
					<div class="jet_col">
						<div class="jet_div_label">
							<label for="jet_product_end"><?php echo esc_html('Край за действие на филтъра. Датата на която приклчва действието на филтъра.:'); ?></label>
						</div>
						<input type="date" name="jet_product_end" id="jet_product_end" />
					</div>
				</div>
			</div>
			<div class="jet_panel_footer">
				<button type="button" id="jet_btn_add" class="button-primary"><?php echo esc_html('Добави филтъра'); ?></button>
			</div>
		</div>
	</div>
	
	<div class="jet_row">
		<div class="jet_panel">
			<div class="jet_panel_heading">
				<?php echo esc_html('Съществуващи филтри'); ?>
			</div>
			<div class="jet_panel_body">
				<div class="jet_flex">
					<div class="jet_col"><?php echo esc_html('Продуктов ID номер'); ?></div>
					<div class="jet_col"><?php echo esc_html('Процент оскъпяване'); ?></div>
					<div class="jet_col"><?php echo esc_html('Месечни вноски'); ?></div>
					<div class="jet_col"><?php echo esc_html('Цена на продуктa'); ?></div>
					<div class="jet_col"><?php echo esc_html('Начало'); ?></div>
					<div class="jet_col"><?php echo esc_html('Край'); ?></div>
					<div class="jet_col"></div>
				</div>
				<?php foreach ($jet_schemes as $jet_schema){ ?>
					<div class="jet_flex">
						<div class="jet_col">
							<div class="jet_col_info"><?php echo esc_html($jet_schema->jet_product_id); ?></div>
						</div>
						<div class="jet_col">
							<div class="jet_col_info"><?php echo $jet_schema->jet_product_percent == -1 ? esc_html('Не показвай бутона') : esc_html($jet_schema->jet_product_percent) ?></div>
						</div>
						<div class="jet_col">
							<div class="jet_col_info"><?php echo esc_html($jet_schema->jet_product_meseci); ?></div>
						</div>
						<div class="jet_col">
							<div class="jet_col_info"><?php echo esc_html($jet_schema->jet_product_price); ?></div>
						</div>
						<div class="jet_col">
							<div class="jet_col_info"><?php echo esc_html($jet_schema->jet_product_start); ?></div>
						</div>
						<div class="jet_col">
							<div class="jet_col_info"><?php echo esc_html($jet_schema->jet_product_end); ?></div>
						</div>
						<div class="jet_col">
							<button type="button" class="button" onclick="deleteSchemaJet('<?php echo esc_js($jet_schema->jet_product_id); ?>');" data-toggle="tooltip" title="<?php echo esc_attr('Изтрий филтъра.'); ?>"><i class="fa fa-trash"></i>&nbsp;<?php echo esc_html('Изтрий'); ?></button>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>
	</div>
</div>