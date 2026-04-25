const settings_jet = window.wc.wcSettings.getSetting('jetpayment_data', {});
const label_jet = window.wp.htmlEntities.decodeEntities(settings_jet.title) || 'ПБ Лични Финанси';
var createElement = window.wp.element.createElement;
const Content_jet = () => {
	const description = window.wp.htmlEntities.decodeEntities(settings_jet.description);
	const jet_price = window.wp.htmlEntities.decodeEntities(settings_jet.jet_price);
	const jet_products = window.wp.htmlEntities.decodeEntities(settings_jet.jet_products);
	const jet_products_qt = window.wp.htmlEntities.decodeEntities(settings_jet.jet_products_qt);
	const jet_products_pr = window.wp.htmlEntities.decodeEntities(settings_jet.jet_products_pr);
	const jet_products_vr = window.wp.htmlEntities.decodeEntities(settings_jet.jet_products_vr);
	const jet_sign = window.wp.htmlEntities.decodeEntities(settings_jet.jet_sign);
	const jet_sign_second = window.wp.htmlEntities.decodeEntities(settings_jet.jet_sign_second);
	const jet_eur = window.wp.htmlEntities.decodeEntities(settings_jet.jet_eur);
	const jet_vnoski = parseInt(window.wp.htmlEntities.decodeEntities(settings_jet.jet_vnoski));
	const jet_sign_second_span = createElement('span', { style: { fontSize: "70%", fontWeight: "400", height: "14px" } }, '/' + jet_sign_second);
	let jet_priceall_title;
	if (jet_sign_second === '') {
		jet_priceall_title = createElement('div', { className: 'jet_column_left' }, 'Цена на стоките (' + jet_sign + ')');
	} else {
		jet_priceall_title = createElement('div', { className: 'jet_column_left' }, 'Цена на стоките (' + jet_sign, jet_sign_second_span, ')');
	}
	let jet_priceall_body;
	if (jet_eur == 0 || jet_eur == 3) {
		jet_priceall_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_priceall' }),
			),
			createElement('div', {}),
		);
	} else {
		jet_priceall_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_priceall' }),
			),
			createElement('div', {},
				createElement('span', {}, '/'),
				createElement('span', { id: 'jet_priceall_second' }),
			),
		);
	}
	const jet_vnoski_title = createElement('div', { className: 'jet_column_left' }, 'Брой погасителни вноски');
	let jet_vnoski_option_3;
	let jet_vnoski_option_6;
	let jet_vnoski_option_9;
	let jet_vnoski_option_10;
	let jet_vnoski_option_12;
	let jet_vnoski_option_15;
	let jet_vnoski_option_18;
	let jet_vnoski_option_24;
	let jet_vnoski_option_30;
	let jet_vnoski_option_36;
	if (jet_vnoski === 3) {
		jet_vnoski_option_3 = createElement('option', { value: '3', selected: 'selected' }, '3 месеца');
	} else {
		jet_vnoski_option_3 = createElement('option', { value: '3' }, '3 месеца');
	}
	if (jet_vnoski === 6) {
		jet_vnoski_option_6 = createElement('option', { value: '6', selected: 'selected' }, '6 месеца');
	} else {
		jet_vnoski_option_6 = createElement('option', { value: '6' }, '6 месеца');
	}
	if (jet_vnoski === 9) {
		jet_vnoski_option_9 = createElement('option', { value: '9', selected: 'selected' }, '9 месеца');
	} else {
		jet_vnoski_option_9 = createElement('option', { value: '9' }, '9 месеца');
	}
	if (jet_vnoski === 10) {
		jet_vnoski_option_10 = createElement('option', { value: '10', selected: 'selected' }, '10 месеца');
	} else {
		jet_vnoski_option_10 = createElement('option', { value: '10' }, '10 месеца');
	}
	if (jet_vnoski === 12) {
		jet_vnoski_option_12 = createElement('option', { value: '12', selected: 'selected' }, '12 месеца');
	} else {
		jet_vnoski_option_12 = createElement('option', { value: '12' }, '12 месеца');
	}
	if (jet_vnoski === 15) {
		jet_vnoski_option_15 = createElement('option', { value: '15', selected: 'selected' }, '15 месеца');
	} else {
		jet_vnoski_option_15 = createElement('option', { value: '15' }, '15 месеца');
	}
	if (jet_vnoski === 18) {
		jet_vnoski_option_18 = createElement('option', { value: '18', selected: 'selected' }, '18 месеца');
	} else {
		jet_vnoski_option_18 = createElement('option', { value: '18' }, '18 месеца');
	}
	if (jet_vnoski === 24) {
		jet_vnoski_option_24 = createElement('option', { value: '24', selected: 'selected' }, '24 месеца');
	} else {
		jet_vnoski_option_24 = createElement('option', { value: '24' }, '24 месеца');
	}
	if (jet_vnoski === 30) {
		jet_vnoski_option_30 = createElement('option', { value: '30', selected: 'selected' }, '30 месеца');
	} else {
		jet_vnoski_option_30 = createElement('option', { value: '30' }, '30 месеца');
	}
	if (jet_vnoski === 36) {
		jet_vnoski_option_36 = createElement('option', { value: '36', selected: 'selected' }, '36 месеца');
	} else {
		jet_vnoski_option_36 = createElement('option', { value: '36' }, '36 месеца');
	}
	const jet_vnoski_body = createElement('select', { id: 'jet_vnoski', name: 'jet_vnoski_input', className: 'jet_input_text' },
		jet_vnoski_option_3,
		jet_vnoski_option_6,
		jet_vnoski_option_9,
		jet_vnoski_option_10,
		jet_vnoski_option_12,
		jet_vnoski_option_15,
		jet_vnoski_option_18,
		jet_vnoski_option_24,
		jet_vnoski_option_30,
		jet_vnoski_option_36,
	);
	let jet_total_credit_price_title;
	if (jet_sign_second === '') {
		jet_total_credit_price_title = createElement('div', { className: 'jet_column_left' }, 'Общо кредит (' + jet_sign + ')');
	} else {
		jet_total_credit_price_title = createElement('div', { className: 'jet_column_left' }, 'Общо кредит (' + jet_sign, jet_sign_second_span, ')');
	}
	let jet_total_credit_price_body;
	if (jet_eur == 0 || jet_eur == 3) {
		jet_total_credit_price_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_total_credit_price' }),
			),
			createElement('div', {}),
		);
	} else {
		jet_total_credit_price_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_total_credit_price' }),
			),
			createElement('div', {},
				createElement('span', {}, '/'),
				createElement('span', { id: 'jet_total_credit_price_second' }),
			),
		);
	}
	let jet_vnoska_title;
	if (jet_sign_second === '') {
		jet_vnoska_title = createElement('div', { className: 'jet_column_left' }, 'Месечна вноска (' + jet_sign + ')');
	} else {
		jet_vnoska_title = createElement('div', { className: 'jet_column_left' }, 'Месечна вноска (' + jet_sign, jet_sign_second_span, ')');
	}
	let jet_vnoska_body;
	if (jet_eur == 0 || jet_eur == 3) {
		jet_vnoska_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_vnoska' }),
			),
			createElement('div', {}),
		);
	} else {
		jet_vnoska_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_vnoska' }),
			),
			createElement('div', {},
				createElement('span', {}, '/'),
				createElement('span', { id: 'jet_vnoska_second' }),
			),
		);
	}
	let jet_gpr_title;
	jet_gpr_title = createElement('div', { className: 'jet_column_left' }, 'Фикс ГПР (%)');
	let jet_gpr_body;
	jet_gpr_body = createElement('div', { className: 'jet_input_text jet_disable' },
		createElement('div', {},
			createElement('span', { id: 'jet_gpr' }),
		),
		createElement('div', {}),
	);
	let jet_glp_title;
	jet_glp_title = createElement('div', { className: 'jet_column_left' }, 'ГЛП (%)');
	let jet_glp_body;
	jet_glp_body = createElement('div', { className: 'jet_input_text jet_disable' },
		createElement('div', {},
			createElement('span', { id: 'jet_glp' }),
		),
		createElement('div', {}),
	);
	let jet_obshto_title;
	if (jet_sign_second === '') {
		jet_obshto_title = createElement('div', { className: 'jet_column_left' }, 'Общо плащания (' + jet_sign + ')');
	} else {
		jet_obshto_title = createElement('div', { className: 'jet_column_left' }, 'Общо плащания (' + jet_sign, jet_sign_second_span, ')');
	}
	let jet_obshto_body;
	if (jet_eur == 0 || jet_eur == 3) {
		jet_obshto_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_obshto' }),
			),
			createElement('div', {}),
		);
	} else {
		jet_obshto_body = createElement('div', { className: 'jet_input_text jet_disable' },
			createElement('div', {},
				createElement('span', { id: 'jet_obshto' }),
			),
			createElement('div', {},
				createElement('span', {}, '/'),
				createElement('span', { id: 'jet_obshto_second' }),
			),
		);
	}
	let jet_egn_title;
	jet_egn_title = createElement('div', { className: 'jet_column_left' }, 'ЕГН *');
	let jet_egn_body;
	jet_egn_body = createElement('input', { className: 'jet_input_text_active jet_left', type: 'text', id: 'jet_egn', name: 'jet_egn', maxLength: '10' },);
	return createElement('div', {},
		createElement('p', {}, description),
		createElement('input', { type: 'hidden', id: 'jet_price', name: 'jet_price', value: jet_price }),
		createElement('input', { type: 'hidden', id: 'jet_products', name: 'jet_products', value: jet_products }),
		createElement('input', { type: 'hidden', id: 'jet_products_qt', name: 'jet_products_qt', value: jet_products_qt }),
		createElement('input', { type: 'hidden', id: 'jet_products_pr', name: 'jet_products_pr', value: jet_products_pr }),
		createElement('input', { type: 'hidden', id: 'jet_products_vr', name: 'jet_products_vr', value: jet_products_vr }),
		createElement('div', { id: 'jet_panel', className: 'jet_panel' },
			createElement('div', { className: 'jet_row' },
				createElement('div', { className: 'jet_column_left' }, 'Първоначална вноска (' + jet_sign + ')'),
				createElement('div', { className: 'jet_column_right' },
					createElement('input', {
						type: 'number',
						className: 'jet_input_text_active',
						min: '0',
						id: 'jet_parva',
						name: 'jet_parva'
					}),
					createElement('button', {
						type: 'button',
						className: 'jet_button_preizcisli',
						id: 'btn_preizcisli'
					}, 'Преизчисли'),
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_priceall_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_priceall_input', name: 'jet_priceall_input' }),
					jet_priceall_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_vnoski_title,
				createElement('div', { className: 'jet_column_right' },
					jet_vnoski_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_total_credit_price_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_total_credit_price_input', name: 'jet_total_credit_price_input' }),
					jet_total_credit_price_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_vnoska_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_vnoska_input', name: 'jet_vnoska_input' }),
					jet_vnoska_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_gpr_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_gpr_input', name: 'jet_gpr_input' }),
					jet_gpr_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_glp_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_glp_input', name: 'jet_glp_input' }),
					jet_glp_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_obshto_title,
				createElement('div', { className: 'jet_column_right' },
					createElement('input', { type: 'hidden', id: 'jet_obshto_input', name: 'jet_obshto_input' }),
					jet_obshto_body,
				),
			),
			createElement('div', { className: 'jet_row' },
				jet_egn_title,
				createElement('div', { className: 'jet_column_right' },
					jet_egn_body,
				),
			),
			createElement('div', { className: 'jet_hr' },),
			createElement('div', { className: 'jet_row_footer' },
				createElement('div', { style: { paddingBottom: '5px' } },
					createElement('input', { className: 'jet_uslovia', type: 'checkbox', id: 'jet_uslovia', name: 'jet_uslovia' }),
					createElement('span', {}, '\u00A0\u00A0\u00A0'),
					createElement('a', {
						className: 'jet_uslovia_a',
						href: 'https://dw-file.eu/%D0%A3%D1%81%D0%BB%D0%BE%D0%B2%D0%B8%D1%8F%20%D0%B7%D0%B0%20%D0%BA%D0%B0%D0%BD%D0%B4%D0%B8%D0%B4%D0%B0%D1%82%D1%81%D1%82%D0%B2%D0%B0%D0%BD%D0%B5%20%D0%B7%D0%B0%20%D0%BA%D1%80%D0%B5%D0%B4%D0%B8%D1%82.pdf',
						title: 'Условия за кандидатстване на ПБ Лични Финанси',
						target: '_blank'
					},
						createElement('span', { style: { fontSize: '12px' } }, 'Прочетох и съм съгласен с Условия за кандидатстване на ПБ Лични Финанси'),
					),
				),
				createElement('div', {},
					createElement('input', { className: 'jet_uslovia', type: 'checkbox', id: 'jet_uslovia1', name: 'jet_uslovia1' }),
					createElement('span', {}, '\u00A0\u00A0\u00A0'),
					createElement('a', {
						className: 'jet_uslovia_a',
						href: 'http://dw-file.eu/%D0%98%D0%BD%D1%84%D0%BE%D1%80%D0%BC%D0%B0%D1%86%D0%B8%D1%8F%20%D0%B7%D0%B0%20%D0%B7%D0%B0%D1%89%D0%B8%D1%82%D0%B0%20%D0%BD%D0%B0%20%D0%BB%D0%B8%D1%87%D0%BD%D0%B8%D1%82%D0%B5%20%D0%B4%D0%B0%D0%BD%D0%BD%D0%B8.pdf',
						title: 'Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО',
						target: '_blank'
					},
						createElement('span', { style: { fontSize: '12px' } }, '"GDPR" Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО'),
					),
				),
			),
		),
		createElement(
			'div',
			{ id: 'jet_panel_error', className: 'jet_panel' },
			createElement('span', {}, 'Не можете да поръчвате този продукт с ПБ Лични Финанси!')
		)
	);
};
const Block_Gateway_Jet = {
	name: 'jetpayment',
	label: label_jet,
	ariaLabel: label_jet,
	content: Object(window.wp.element.createElement)(Content_jet, null),
	edit: Object(window.wp.element.createElement)(Content_jet, null),
	canMakePayment: () => true,
	supports: {
		features: settings_jet.supports,
	},
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway_Jet);
