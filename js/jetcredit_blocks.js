(function (window) {
	'use strict';
	if (window.JetCreditBlocks) return;
	const { createElement: h, useState, useEffect, useRef } = window.wp.element;
	const { useSelect } = window.wp.data;
	const api = window.JetCreditOffers;
	const registered = new Set();
	function register(name) {
		if (registered.has(name)) return;
		registered.add(name);
		const settings = window.wc.wcSettings.getSetting(name + '_data', {});
		const config = settings.config;
		const card = name === 'jetpaymentcard';
		const prefix = card ? 'jet_card_' : 'jet_';
		const label = window.wp.htmlEntities.decodeEntities(settings.title || 'ПБ Лични Финанси');
		const Content = ({ eventRegistration, emitResponse }) => {
			const state = useSelect((select) => {
				const stores = window.wc.wcBlocksData;
				return { cart: select(stores.cartStore || stores.CART_STORE_KEY).getCartData(), active: select(stores.paymentStore || stores.PAYMENT_STORE_KEY).getActivePaymentMethod() };
			}, []);
			const cart = state.cart || {};
			const totals = cart.totals || {};
			const revision = JSON.stringify([totals, cart.items, cart.coupons, cart.shippingRates]);
			const [initial, setInitial] = useState('0');
			const [term, setTerm] = useState(String(settings.jet_vnoski || ''));
			const [egn, setEgn] = useState('');
			const [consent, setConsent] = useState(false);
			const [privacy, setPrivacy] = useState(false);
			const [result, setResult] = useState(null);
			const [message, setMessage] = useState('');
			const [refresh, setRefresh] = useState(0);
			const client = useRef(null);
			const attempt = useRef(null);
			if (!client.current) client.current = new api.Client(config);
			const currency = totals.currency_code;
			const expected = Number(totals.total_price) / Math.pow(10, Number(totals.currency_minor_unit));
			const inputs = { jet_context: 'checkout_blocks', jet_card: card ? '1' : '0', jet_parva: initial || '0', jet_vnoski: term,
				jet_expected_amount: Number.isFinite(expected) ? expected.toFixed(2) : 'unverified', jet_client_revision: revision };
			const inputsKey = JSON.stringify(inputs);
			useEffect(() => {
				client.current.invalidate(); setResult(null);
				if (state.active !== name) return;
				if (currency !== 'EUR') { setMessage(api.message('non_eur_currency', config.rules)); return; }
				let active = true;
				setMessage('Преизчисляване…');
				client.current.calculate(inputs).then((data) => {
					if (!active || !data) return;
					setResult(data);
					if (!term && data.jet_allowed_terms.includes(Number(data.jet_vnoski))) { setTerm(data.jet_vnoski); return; }
					setMessage(data.jet_eligible ? '' : api.message('installment_below_minimum', config.rules));
				}).catch((error) => { if (active) setMessage(api.message(error, config.rules)); });
				return () => { active = false; client.current.invalidate(); };
			}, [inputsKey, state.active, currency, refresh]);
			useEffect(() => {
				const unsubscribe = eventRegistration.onPaymentSetup(() => {
					if (state.active !== name) return;
					const fail = (text) => ({ type: emitResponse.responseTypes.ERROR, message: text, messageContext: emitResponse.noticeContexts.PAYMENTS });
					if (currency !== 'EUR') return fail(api.message('non_eur_currency', config.rules));
					if (!consent || !privacy || !/^[0-9]{10}$/.test(egn)) return fail('Попълнете ЕГН и потвърдете двете условия за кандидатстване.');
					let data;
					if (attempt.current && attempt.current.key === inputsKey) data = attempt.current.data;
					else {
						if (!client.current.current(inputs)) return fail(api.message('missing_offer', config.rules));
						data = api.fields(client.current.data, true, card);
						attempt.current = { key: inputsKey, data };
					}
					return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: Object.assign({}, data, {
						[prefix + 'egn']: egn, [prefix + 'uslovia']: '1', [prefix + 'uslovia1']: '1'
					}) } };
				});
				return unsubscribe;
			}, [inputsKey, state.active, currency, consent, privacy, egn, eventRegistration, emitResponse]);
			const change = (setter) => (event) => {
				client.current.invalidate(); attempt.current = null; setResult(null); setter(event.target.value);
			};
			const allowed = result ? result.jet_allowed_terms : null;
			const row = (title, value, extraClass = '') => h('div', { className: 'jet_row' + (extraClass ? ' ' + extraClass : ''), key: title }, h('div', { className: 'jet_column_left' }, title), h('div', { className: 'jet_column_right' }, value));
			const financialRow = (field, title) => row(title, h('div', { className: 'jet_input_text jet_disable' },
				h('div', {}, h('output', { id: prefix + field, 'aria-label': title }, result ? result['jet_' + field] : '')), h('div', {})));
			const termOptions = [3, 6, 9, 10, 12, 15, 18, 24, 30, 36].map((months) => {
				const permitted = allowed ? allowed.includes(months) : (!([15, 18, 24].includes(months) && expected < Number(config.rules.extended_term_threshold)) && !([30, 36].includes(months) && expected < Number(config.rules.long_term_threshold)));
				return h('option', { key: months, value: String(months), disabled: !permitted }, months + ' месеца');
			});
			return h('div', { className: 'jet_panel jet_blocks_panel' },
				h('p', {}, window.wp.htmlEntities.decodeEntities(settings.description || '')),
				row('Първоначална вноска (евро)', h(window.wp.element.Fragment, {},
					h('input', { id: prefix + 'parva', type: 'number', min: '0', step: '0.01', value: initial, onChange: change(setInitial), className: 'jet_input_text_active' }),
					h('button', { type: 'button', className: 'jet_button_preizcisli', onClick: () => { client.current.invalidate(); attempt.current = null; setResult(null); setRefresh((value) => value + 1); } }, 'Преизчисли')), 'jet_row_parva'),
				financialRow('priceall', 'Цена на стоките (евро)'),
				row('Брой погасителни вноски', h('select', { id: prefix + 'vnoski', value: term, onChange: change(setTerm), className: 'jet_input_text' }, h('option', { value: '' }, 'Изберете срок'), ...termOptions)),
				...['total_credit_price', 'vnoska', 'gpr', 'glp', 'obshto'].map((field, index) => financialRow(field, ['Общо кредит (евро)', 'Месечна вноска (евро)', 'Фикс ГПР (%)', 'ГЛП (%)', 'Общо плащания (евро)'][index])),
				row('ЕГН *', h('input', { id: prefix + 'egn', type: 'text', inputMode: 'numeric', maxLength: 10, value: egn, onChange: (event) => setEgn(event.target.value.replace(/[^0-9]/g, '')), className: 'jet_input_text_active' })),
				h('label', {}, h('input', { type: 'checkbox', checked: consent, onChange: (event) => setConsent(event.target.checked) }), h('a', { href: 'https://www.postbank.bg/common-conditions-PFBG', target: '_blank', rel: 'noopener noreferrer' }, 'Запознах се с условията за кандидатстване на ПБ Лични Финанси')),
				h('label', {}, h('input', { type: 'checkbox', checked: privacy, onChange: (event) => setPrivacy(event.target.checked) }), h('a', { href: 'https://www.postbank.bg/Personal-Data-PFBG-retailers', target: '_blank', rel: 'noopener noreferrer' }, 'Запознах се с информацията за обработка на личните данни')),
				h('div', { role: 'status' }, message)
			);
		};
		window.wc.wcBlocksRegistry.registerPaymentMethod({ name, label, ariaLabel: label,
			content: h(Content), edit: h('span', {}, label),
			canMakePayment: ({ cartTotals }) => {
				if (!config || !config.rules || config.available === false || !cartTotals || cartTotals.currency_code !== 'EUR') return false;
				const amount = Number(cartTotals.total_price) / Math.pow(10, Number(cartTotals.currency_minor_unit));
				return Number.isFinite(amount) && amount >= Number(config.rules.min_principal);
			},
			supports: { features: settings.supports || ['products'] }
		});
	}
	window.JetCreditBlocks = { register };
})(window);
