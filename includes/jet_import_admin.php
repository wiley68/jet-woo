<?php
	if ( ! class_exists( 'Jet_Button_Schemes', false ) && defined( 'JET_INCLUDES_DIR' ) ) {
		require_once JET_INCLUDES_DIR . '/class-jet-button-schemes.php';
	}
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
		'jet_vnoska' => 1,
		'jet_button_type' => 'standard',
		'jet_button_scheme' => '0',
		'jet_minprice' => JET_MINPRICE,
		'jet_eur' => 0
	];
	if(array_key_exists('jet_hidden', $_POST) && $_POST['jet_hidden'] == 'Y') {
		check_admin_referer('jetcredit_settings_save', 'jetcredit_nonce');
		foreach ( $jet_settings as $key => $default ) {
			$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( 'jet_button_scheme' === $key && class_exists( 'Jet_Button_Schemes', false ) ) {
				$value = $value !== null ? (string) Jet_Button_Schemes::normalize_index( $value ) : (string) Jet_Button_Schemes::normalize_index( $default );
			}
			update_option( $key, $value !== null ? $value : $default );
		}
		echo '<div class="updated"><p><strong>' . esc_html('Настройките са записани успешно.') . '</strong></p></div>';
	}
	foreach ( $jet_settings as $key => $default ) {
		$$key = get_option( $key, $default );
	}
	if ( class_exists( 'Jet_Button_Schemes', false ) ) {
		$jet_button_scheme = (string) Jet_Button_Schemes::normalize_index( isset( $jet_button_scheme ) ? $jet_button_scheme : '0' );
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
		<div class="jet_tab_wrap">
			<div class="jet_tab_list" role="tablist" aria-label="<?php echo esc_attr('Навигация в настройките'); ?>">
				<button type="button" class="button jet_tab_btn is-active" data-jet-tab="jet-tab-1" id="jet-tab-1-button" role="tab" aria-selected="true" aria-controls="jet-tab-1" tabindex="0">
					<?php echo esc_html('Управление'); ?>
				</button>
				<button type="button" class="button jet_tab_btn" data-jet-tab="jet-tab-2" id="jet-tab-2-button" role="tab" aria-selected="false" aria-controls="jet-tab-2" tabindex="-1">
					<?php echo esc_html('Функционални настройки'); ?>
				</button>
				<button type="button" class="button jet_tab_btn" data-jet-tab="jet-tab-3" id="jet-tab-3-button" role="tab" aria-selected="false" aria-controls="jet-tab-3" tabindex="-1">
					<?php echo esc_html('Визуални настройки'); ?>
				</button>
				<button type="button" class="button jet_tab_btn" data-jet-tab="jet-tab-4" id="jet-tab-4-button" role="tab" aria-selected="false" aria-controls="jet-tab-4" tabindex="-1">
					<?php echo esc_html('Форма за въвеждане на лихвени филтри'); ?>
				</button>
			</div>
			<div class="jet_tab_panels">
				<div
					class="jet_tab_panel is-active"
					id="jet-tab-1"
					role="tabpanel"
					aria-labelledby="jet-tab-1-button"
				>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Включи модула'); ?></div>
						<div class="jet_control">
							<?php
							echo Jet_Admin_Toggle::render(
								array(
									'name'       => 'jet_status_in',
									'value'      => $jet_status_in,
									'aria_label' => 'Включи модула',
									'label_on'   => 'Включен',
									'label_off'  => 'Изключен',
								)
							);
							?>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутоните за закупуване на кредит през ПБ Лични Финанси'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи бутона в количката'); ?></div>
						<div class="jet_control">
							<?php
							echo Jet_Admin_Toggle::render(
								array(
									'name'       => 'jet_cart_show',
									'value'      => $jet_cart_show,
									'aria_label' => 'Покажи бутона в количката',
									'label_on'   => 'Да',
									'label_off'  => 'Не',
								)
							);
							?>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутона за закупуване на кредит през ПБ Лични Финанси в количката'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи бутона за изпращане чрез кредитна карта'); ?></div>
						<div class="jet_control">
							<?php
							echo Jet_Admin_Toggle::render(
								array(
									'name'       => 'jet_card_in',
									'value'      => $jet_card_in,
									'aria_label' => 'Покажи бутона за изпращане чрез кредитна карта',
									'label_on'   => 'Да',
									'label_off'  => 'Не',
								)
							);
							?>
							<span class="jet_form_controll_text"><?php echo esc_html('Показвай бутона за закупуване на кредит през ПБ Лични Финанси със заявката за лизинг направена по метода с кредитна карта'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Покажи вноска'); ?></div>
						<div class="jet_control">
							<?php
							echo Jet_Admin_Toggle::render(
								array(
									'name'       => 'jet_vnoska',
									'value'      => $jet_vnoska,
									'aria_label' => 'Покажи вноска',
									'label_on'   => 'Да',
									'label_off'  => 'Не',
								)
							);
							?>
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
				<div
					class="jet_tab_panel"
					id="jet-tab-2"
					role="tabpanel"
					aria-labelledby="jet-tab-2-button"
					hidden
				>
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
				<div
					class="jet_tab_panel"
					id="jet-tab-3"
					role="tabpanel"
					aria-labelledby="jet-tab-3-button"
					hidden
				>
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
								foreach ( $jet_hooks as $value => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $value ),
										selected( $jet_hook, $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('Място за показване на бутона на ПБ Лични Финанси в продуктовата страница (hook)'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Празно място над бутона'); ?></div>
						<div class="jet_control">
							<input type="text" name="jet_gap" class="jet_form_control" value="<?php echo esc_attr( $jet_gap ); ?>">
							<span class="jet_form_controll_text"><?php echo esc_html('Празно място над бутона в px. Използва се за подредба на бутоните, когато са повече от един'); ?></span>
						</div>
					</div>
					<div class="jet_form_group">
						<div class="jet_control_label"><?php echo esc_html('Вид на бутона'); ?></div>
						<div class="jet_control">
							<select name="jet_button_type" id="jet_button_type" class="jet_form_control">
								<option value="standard" <?php selected($jet_button_type, 'standard'); ?>><?php echo esc_html('Стандартен бутон'); ?></option>
								<option value="wide" <?php selected($jet_button_type, 'wide'); ?>><?php echo esc_html('Персонализиран дизайн'); ?></option>
							</select>
							<span class="jet_form_controll_text"><?php echo esc_html('От тук можете да си изберете вида на бутоните които ще се показват в продуктовата и в страницата количка.'); ?></span>
							<div style="margin-top:12px;">
								<img
									id="jet_button_type_preview"
									src="<?php echo esc_url( JET_IMAGES_URI . ( $jet_button_type === 'wide' ? '/jet_new.png' : '/jet.png' ) ); ?>"
									alt="<?php echo esc_attr('Преглед на вид бутон'); ?>"
									style="max-width:100%;height:auto;border:1px solid #ddd;padding:6px;background:#fff;"
								/>
							</div>
							<?php
							$jet_scheme_list = class_exists( 'Jet_Button_Schemes', false ) ? Jet_Button_Schemes::get_schemes() : array();
							$jet_cur_scheme  = class_exists( 'Jet_Button_Schemes', false ) ? Jet_Button_Schemes::get_scheme( (int) $jet_button_scheme ) : null;
							?>
							<div
								id="jet_button_scheme_block"
								class="jet_button_scheme_block"
								role="radiogroup"
								aria-label="<?php echo esc_attr( 'Визуална схема на персонализирания бутон' ); ?>"
								<?php echo 'wide' !== $jet_button_type ? 'hidden' : ''; ?>
							>
								<p class="jet_scheme_block_title"><?php echo esc_html( 'Цветова схема на бутона' ); ?></p>
								<div class="jet_scheme_grid_wrap">
									<div class="jet_scheme_grid">
										<?php foreach ( $jet_scheme_list as $i => $sch ) : ?>
											<label class="jet_scheme_card">
												<input type="radio" name="jet_button_scheme" value="<?php echo esc_attr( (string) $i ); ?>" class="jet_scheme_radio" <?php checked( (int) $jet_button_scheme, (int) $i ); ?> />
												<span class="jet_scheme_preview" style="<?php
												printf(
													'background:%1$s;border:2px solid %2$s;border-radius:16px;',
													esc_attr( $sch['background'] ),
													esc_attr( $sch['border'] )
												);
												?>"><span class="jet_scheme_preview_text" style="<?php
												printf( 'color:%s;', esc_attr( $sch['color'] ) );
												?>"><?php echo esc_html( $sch['short'] ); ?></span></span>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
								<p class="jet_scheme_summary" id="jet_scheme_selected_summary"><?php
								echo esc_html( 'Избрана визуална схема: ' . ( $jet_cur_scheme ? $jet_cur_scheme['label'] : '' ) );
								?></p>
							</div>
						</div>
					</div>
				</div>
				<div
					class="jet_tab_panel"
					id="jet-tab-4"
					role="tabpanel"
					aria-labelledby="jet-tab-4-button"
					hidden
				>
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
					<div class="jet_row jet_tab_filters_row">
						<div class="jet_panel jet_tab_filters_panel">
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
								<?php foreach ( $jet_schemes as $jet_schema ) { ?>
									<div class="jet_flex">
										<div class="jet_col">
											<div class="jet_col_info"><?php echo esc_html( $jet_schema->jet_product_id ); ?></div>
										</div>
										<div class="jet_col">
											<div class="jet_col_info"><?php echo ( -1 == $jet_schema->jet_product_percent ? esc_html( 'Не показвай бутона' ) : esc_html( $jet_schema->jet_product_percent ) ); ?></div>
										</div>
										<div class="jet_col">
											<div class="jet_col_info"><?php echo esc_html( $jet_schema->jet_product_meseci ); ?></div>
										</div>
										<div class="jet_col">
											<div class="jet_col_info"><?php echo esc_html( $jet_schema->jet_product_price ); ?></div>
										</div>
										<div class="jet_col">
											<div class="jet_col_info"><?php echo esc_html( $jet_schema->jet_product_start ); ?></div>
										</div>
										<div class="jet_col">
											<div class="jet_col_info"><?php echo esc_html( $jet_schema->jet_product_end ); ?></div>
										</div>
										<div class="jet_col">
											<button type="button" class="button" onclick="deleteSchemaJet('<?php echo esc_js( $jet_schema->jet_product_id ); ?>');" data-toggle="tooltip" title="<?php echo esc_attr( 'Изтрий филтъра.' ); ?>"><i class="fa fa-trash"></i>&nbsp;<?php echo esc_html( 'Изтрий' ); ?></button>
										</div>
									</div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
	const jetButtonTypeSelect = document.getElementById('jet_button_type');
	const jetButtonTypePreview = document.getElementById('jet_button_type_preview');
	const jetSchemeBlock = document.getElementById('jet_button_scheme_block');
	const jetSchemeSummary = document.getElementById('jet_scheme_selected_summary');
	const jetSchemeLabels = <?php echo class_exists( 'Jet_Button_Schemes', false ) ? wp_json_encode( array_column( Jet_Button_Schemes::get_schemes(), 'label' ) ) : '[]'; ?>;
	const jetToggleSchemeBlock = function() {
		if (!jetSchemeBlock || !jetButtonTypeSelect) {
			return;
		}
		if (jetButtonTypeSelect.value === 'wide') {
			jetSchemeBlock.removeAttribute('hidden');
		} else {
			jetSchemeBlock.setAttribute('hidden', 'hidden');
		}
	};
	const jetUpdateSchemeSummary = function() {
		if (!jetSchemeSummary || !Array.isArray(jetSchemeLabels)) {
			return;
		}
		const checked = document.querySelector('input[name="jet_button_scheme"]:checked');
		if (!checked) {
			return;
		}
		const idx = parseInt(checked.value, 10);
		const label = jetSchemeLabels[idx] || '';
		jetSchemeSummary.textContent = '<?php echo esc_js( 'Избрана визуална схема: ' ); ?>' + label;
	};
	if (jetButtonTypeSelect && jetButtonTypePreview) {
		const jetPreviewImages = {
			standard: '<?php echo esc_js( JET_IMAGES_URI . '/jet.png' ); ?>',
			wide: '<?php echo esc_js( JET_IMAGES_URI . '/jet_new.png' ); ?>'
		};
		const updatePreview = function() {
			const selectedType = jetButtonTypeSelect.value;
			jetButtonTypePreview.src = jetPreviewImages[selectedType] || jetPreviewImages.standard;
			jetToggleSchemeBlock();
		};
		jetButtonTypeSelect.addEventListener('change', updatePreview);
		updatePreview();
	} else if (jetButtonTypeSelect) {
		jetButtonTypeSelect.addEventListener('change', jetToggleSchemeBlock);
		jetToggleSchemeBlock();
	}
	document.querySelectorAll('input[name="jet_button_scheme"]').forEach(function(r) {
		r.addEventListener('change', jetUpdateSchemeSummary);
	});
	jetUpdateSchemeSummary();

	const jetTabButtons = document.querySelectorAll('.jet_tab_btn');
	const jetTabPanels = document.querySelectorAll('.jet_tab_panel');
	if (jetTabButtons.length && jetTabPanels.length) {
		const jetStorageKey = 'jetcreditAdminActiveTab';

		const jetGetValidTabId = (id) => {
			if (!id) {
				return null;
			}
			return document.getElementById(id) ? id : null;
		};

		const activateJetTab = (targetId) => {
			const nextId = jetGetValidTabId(targetId) || 'jet-tab-1';
			jetTabButtons.forEach((btn) => {
				const isTarget = btn.getAttribute('data-jet-tab') === nextId;
				btn.classList.toggle('is-active', isTarget);
				btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
				btn.setAttribute('tabindex', isTarget ? '0' : '-1');
			});
			jetTabPanels.forEach((panel) => {
				const isTarget = panel.id === nextId;
				panel.classList.toggle('is-active', isTarget);
				if (isTarget) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', 'hidden');
				}
			});
			try {
				window.localStorage.setItem(jetStorageKey, nextId);
			} catch (e) {
				// ignore storage errors
			}
		};

		jetTabButtons.forEach((btn) => {
			btn.addEventListener('click', function() {
				activateJetTab(btn.getAttribute('data-jet-tab'));
			});
		});

		const jetForm = document.querySelector('form[name="jet_form"]');
		if (jetForm) {
			jetForm.addEventListener('submit', function() {
				const active = document.querySelector('.jet_tab_panel.is-active');
				if (active && active.id) {
					try {
						window.localStorage.setItem(jetStorageKey, active.id);
					} catch (e) {
						// ignore
					}
				}
			});
		}

		let initial = null;
		if (window.location.hash && window.location.hash.length > 1) {
			initial = jetGetValidTabId(window.location.hash.replace('#', ''));
		}
		if (!initial) {
			try {
				initial = jetGetValidTabId(window.localStorage.getItem(jetStorageKey));
			} catch (e) {
				initial = null;
			}
		}
		// URL hash панелите (jet-tab-*) съвпадат с id-та; премахваме го, за да няма скрол към таба.
		if (window.location.hash) {
			if (window.history && typeof window.history.replaceState === 'function') {
				const jetUrl = new URL(window.location.href);
				jetUrl.hash = '';
				window.history.replaceState(null, '', jetUrl.toString());
			}
		}
		window.scrollTo(0, 0);
		activateJetTab(initial);
	}
});
</script>