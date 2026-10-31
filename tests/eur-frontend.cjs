/* Offline DOM/React tests. NODE_PATH may point to dependencies installed only in /tmp. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const { rules, quote } = require('./eur-js-fixtures.cjs');
let checks = 0;
const check = (value, message) => { checks++; assert.ok(value, message); };
const tick = () => new Promise(resolve => setTimeout(resolve, 5));
// Timer order under concurrent PHP/Node load is not an application contract.
// Wait for observable async work; synchronous invalidation assertions stay immediate.
async function waitFor(predicate, label, flush = tick) {
	const deadline = Date.now() + 3000;
	while (!predicate()) {
		if (Date.now() >= deadline) throw new Error('Timed out waiting for ' + label);
		await flush();
	}
}
const script = name => fs.readFileSync(path.resolve(__dirname, '../' + name), 'utf8');
async function setup(html = '') {
	const dom = new JSDOM('<!doctype html><html><body>' + html + '</body></html>', { url: 'https://synthetic.example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	w.eval(fs.readFileSync(process.env.JET_TEST_JQUERY || path.resolve(__dirname, '../../../../wp-includes/js/jquery/jquery.min.js'), 'utf8'));
	const $ = w.jQuery;
	w.jet_js = w.jet_cart_js = w.jet_checkout_js = { ajax_url: '/offline', nonce: 'valid', available: true, rules };
	const requests = [];
	const transport = { defer: false, failure: false };
	$.ajax = options => {
		if (options.data.action === 'jet_get_nonce') return $.Deferred().resolve({ success: true, data: { nonce: 'valid' } }).promise();
		const deferred = $.Deferred();
		requests.push({ options, deferred });
		if (!transport.defer) deferred.resolve(transport.failure ? { success: 'unsuccess', code: 'stale_financial_values' } : quote(options.data));
		return deferred.promise();
	};
	w.eval(script('js/jetcredit_offers.js'));
	await new Promise(resolve => $(resolve));
	return { dom, w, $, requests, transport };
}

async function shared() {
	const { dom, w, requests, transport } = await setup('<select id="terms"><option>12</option><option>15</option><option>24</option><option>30</option><option>36</option></select>');
	const api = w.JetCreditOffers;
	const client = new api.Client(w.jet_js);
	transport.defer = true;
	const input = { jet_context: 'product', jet_parva: '0', jet_vnoski: '12' };
	const first = client.calculate(input); await waitFor(() => requests.length === 1, 'first quote request');
	const next = Object.assign({}, input, { jet_parva: '100' });
	const second = client.calculate(next); await waitFor(() => requests.length === 2, 'second quote request');
	requests[1].deferred.resolve(quote(next)); await second;
	requests[0].deferred.resolve(quote(input));
	check(await first === null && client.current(next), 'Late response replaced a newer offer.');
	check(!client.current(input), 'Changed initial payment did not invalidate input identity.');
	client.data.jet_expires = Math.floor(Date.now() / 1000) - 1;
	check(!client.current(next), 'Expired token considered current.');
	assert.throws(() => api.fields({}), /missing_offer/); checks++;
	const select = w.document.getElementById('terms');
	for (const [amount, medium, long] of [[200.99, false, false], [201, true, false], [300.99, true, false], [301, true, true]]) {
		api.terms(select, amount, rules);
		check(select.options[1].disabled !== medium && select.options[3].disabled !== long, 'Frontend threshold boundary ' + amount);
	}
	const fields = api.fields(quote({ jet_context: 'checkout_blocks', jet_card: '1' }), true, true);
	check(fields.jet_card_offer && fields.jet_card_context === 'checkout_blocks' && fields.jet_card_vnoska_input && !fields.jet_offer, 'Card payment_data prefix.');
	check(api.decimalFromText('1 234,56 евро') === '1234.56', 'WAPF expected total parser.');
	dom.window.close();
}

async function classic() {
	const fields = prefix => ['offer', 'context', 'price', 'products', 'products_qt', 'products_pr', 'products_vr', 'priceall_input', 'parva_input', 'vnoski_input', 'vnoska_input', 'total_credit_price_input', 'obshto_input', 'glp_input', 'gpr_input'].map(name => `<input id="${prefix + name}" name="${prefix + name}">`).join('') + `<input id="${prefix}parva" value="0"><select id="${prefix}vnoski"><option selected>12</option><option>9</option><option>24</option><option>36</option></select>`;
	const { dom, w, $, transport, requests } = await setup('<form class="checkout"><input type="radio" name="payment_method" value="jetpayment" checked><input type="radio" name="payment_method" value="jetpaymentcard">' + fields('jet_') + fields('jet_card_') + '</form>');
	w.eval(script('js/jetcredit_checkout.js')); await waitFor(() => $('#jet_offer').val(), 'Classic initial quote');
	check($('#jet_offer').val() && $('form').triggerHandler('checkout_place_order') === true, 'Classic initial offer not submitted.');
	transport.defer = true;
	$('#jet_parva').val('50').trigger('input');
	check(!$('#jet_offer').val() && $('form').triggerHandler('checkout_place_order') === false, 'Classic submit accepted during recalculation.');
	await waitFor(() => requests.at(-1).options.data.jet_parva === '50.00', 'Classic edited quote request');
	const pending = requests.at(-1); pending.deferred.resolve(quote(pending.options.data)); await waitFor(() => $('#jet_parva_input').val() === '50.00', 'Classic edited quote response');
	check($('#jet_parva_input').val() === '50.00', 'Classic down payment update.');
	$('input[value="jetpaymentcard"]').prop('checked', true).trigger('change');
	check(!$('#jet_offer').val() && $('form').triggerHandler('checkout_place_order') === false, 'Gateway switching reused regular offer.');
	await waitFor(() => requests.at(-1).options.data.jet_card === '1', 'Classic card quote request'); const card = requests.at(-1); card.deferred.resolve(quote(card.options.data)); await waitFor(() => $('#jet_card_offer').val(), 'Classic card quote response');
	check($('#jet_card_offer').val() && $('#jet_card_context').val() === 'checkout_classic', 'Classic card offer fields.');
	$(w.document.body).trigger('update_checkout');
	check(!$('#jet_card_offer').val(), 'Checkout update did not clear offer.');
	dom.window.close();
}

async function popup(cart) {
	const { dom, w, $, requests, transport } = await setup(`
		<div class="woocommerce-notices-wrapper"></div><table class="woocommerce-cart-form__contents"><tr class="cart_item"><td>synthetic</td></tr></table>
		<form class="variations_form"><input name="quantity" value="1"></form>
		<div class="wapf-product-totals" style="display:none"><span class="wapf-grand-total">1100 евро</span></div>
		<div id="jet-product-button-container"><button id="btn_jet">Open</button><button id="btn_jet_card">Card</button></div>
		<div id="jet-product-popup-container"><div id="jet_step_1"></div><div id="jet_step_2"></div>
		<input id="jet_product_id" value="42"><input id="jet_variation_id" value="0"><input id="jet_price" value="1000">
		<input id="jet_parva" value="0"><select id="jet_vnoski"><option selected>12</option><option>9</option><option>24</option></select>
		<input type="checkbox" id="uslovia" checked><input type="checkbox" id="uslovia1" checked><input type="checkbox" id="uslovia2" checked>
		<button id="buy_jetcredit">Continue</button><button id="buy2_jetcredit">Send</button><span id="jet_priceall"></span><span id="jet_vnoska_popup"></span>
		</div>`);
	w.eval(script(cart ? 'js/jetcredit_cart.js' : 'js/jetcredit_product.js')); await waitFor(() => $('#jet_vnoska_popup').text(), 'popup initial quote');
	$('#uslovia2').prop('checked', true).triggerHandler('click');
	check(!$('#buy2_jetcredit').prop('disabled'), 'Valid popup offer did not enable submission.');
	transport.defer = true;
	$('#jet_parva').val('100').trigger('input');
	check($('#buy2_jetcredit').prop('disabled'), 'Initial payment change left submit enabled.');
	await waitFor(() => requests.at(-1).options.data.jet_parva === '100', 'popup first edited request'); const old = requests.at(-1);
	$('#jet_parva').val('200').trigger('input'); await waitFor(() => requests.at(-1).options.data.jet_parva === '200', 'popup latest edited request'); const latest = requests.at(-1);
	latest.deferred.resolve(quote(latest.options.data)); await waitFor(() => $('#jet_vnoska_popup').text() === quote(latest.options.data).jet_vnoska, 'popup latest quote response');
	old.deferred.resolve(quote(old.options.data)); await Promise.resolve(old.deferred.promise()); await tick();
	check($('#jet_vnoska_popup').text() === quote(latest.options.data).jet_vnoska, 'Stale popup AJAX response replaced latest result.');
	if (cart) {
		$(w.document.body).trigger('applied_coupon');
		check($('#buy2_jetcredit').prop('disabled'), 'Coupon change left cart offer current.');
	} else {
		$('input[name="quantity"]').val('2').trigger('input');
		check($('#buy2_jetcredit').prop('disabled'), 'Quantity update retained offer.');
		await waitFor(() => requests.at(-1).options.data.jet_quantity === '2', 'quantity request'); check(requests.at(-1).options.data.jet_quantity === '2', 'Quantity missing in server request.');
		$('form.variations_form').trigger('found_variation', [{ variation_id: 77 }]); await waitFor(() => requests.at(-1).options.data.jet_variation_id === '77', 'variation request');
		check(requests.at(-1).options.data.jet_variation_id === '77', 'Variation missing in request.');
		$('form.variations_form').trigger('reset_data'); check($('#buy2_jetcredit').prop('disabled'), 'Reset variation retained offer.');
		transport.defer = false; transport.failure = true;
		$('.wapf-product-totals').show(); $('.wapf-grand-total').text('1200 евро'); await waitFor(() => $('#jet_offer_status').text().includes('не могат да бъдат потвърдени'), 'unsupported WAPF response');
		check($('#buy2_jetcredit').prop('disabled') && $('#jet_offer_status').text().includes('не могат да бъдат потвърдени'), 'Unsupported WAPF total did not fail safely.');
	}
	dom.window.close();
}

async function blocks() {
	const { dom, w, requests, transport } = await setup('<div id="root"></div>');
	global.window = w; global.document = w.document; global.navigator = w.navigator;
	global.IS_REACT_ACT_ENVIRONMENT = true;
	const React = require('react');
	const { createRoot } = require('react-dom/client');
	const { Simulate } = require('react-dom/test-utils');
	const act = React.act;
	const definitions = {};
	let active = 'jetpayment';
	let cart = { totals: { total_price: '100000', currency_minor_unit: 2, currency_code: 'EUR' }, items: [{ id: 42, quantity: 1 }], coupons: [] };
	w.wp = { element: React, htmlEntities: { decodeEntities: value => value }, data: { useSelect: callback => callback(store => store === 'cart' ? { getCartData: () => cart } : { getActivePaymentMethod: () => active }) } };
	w.wc = { wcBlocksData: { cartStore: 'cart', paymentStore: 'payment' }, wcSettings: { getSetting: () => ({ config: w.jet_js, jet_vnoski: '12', title: 'Synthetic' }) }, wcBlocksRegistry: { registerPaymentMethod: definition => { definitions[definition.name] = definition; } } };
	w.eval(script('js/jetcredit_blocks.js')); w.eval(script('checkout.js')); w.eval(script('checkout_card.js'));
	const handlers = {};
	const props = name => ({ eventRegistration: { onPaymentSetup: callback => { handlers[name] = callback; return () => { delete handlers[name]; }; } }, emitResponse: { responseTypes: { ERROR: 'error', SUCCESS: 'success' }, noticeContexts: { PAYMENTS: 'payments' } } });
	const root = createRoot(w.document.getElementById('root'));
	const render = () => root.render(React.createElement(React.Fragment, {}, ...Object.entries(definitions).map(([name, definition]) => React.cloneElement(definition.content, Object.assign({ key: name }, props(name))))));
	const financialFields = ['priceall', 'total_credit_price', 'vnoska', 'gpr', 'glp', 'obshto'];
	const layout = prefix => {
		const input = w.document.getElementById(prefix + 'parva');
		const panel = input.closest('.jet_blocks_panel');
		check(panel && panel.querySelectorAll('button').length === 1, 'Blocks must have one scoped recalculation button.');
		const button = panel.querySelector('.jet_row_parva .jet_column_right > button.jet_button_preizcisli');
		check(button && button.type === 'button' && button.parentElement === input.parentElement, 'Blocks recalculation is not next to its input.');
		for (const field of financialFields) {
			const output = w.document.getElementById(prefix + field);
			check(output.tagName === 'OUTPUT' && output.closest('.jet_input_text.jet_disable') && !output.isContentEditable && !output.hasAttribute('name'), 'Calculated value must remain a styled non-editable output: ' + field);
		}
	};
	const recalculate = async prefix => {
		layout(prefix); transport.defer = true;
		const before = requests.length;
		await act(async () => { Simulate.click(w.document.getElementById(prefix + 'parva').parentElement.querySelector('button')); await tick(); });
		check(w.document.getElementById(prefix + 'priceall').textContent === '', 'Recalculate retained old financial output.');
		await waitFor(() => requests.length === before + 1, 'Blocks recalculation request', () => act(tick));
		const pending = requests.at(-1), data = quote(pending.options.data);
		await act(async () => { pending.deferred.resolve(data); await tick(); });
		await waitFor(() => w.document.getElementById(prefix + 'priceall').textContent, 'Blocks recalculation output', () => act(tick));
		for (const field of financialFields) check(w.document.getElementById(prefix + field).textContent === data['jet_' + field], 'Recalculate did not update ' + field);
	};
	await act(async () => { render(); await tick(); });
	await waitFor(() => w.document.getElementById('jet_priceall')?.textContent, 'Blocks initial quote', () => act(tick));
	await recalculate('jet_');
	check(definitions.jetpayment.canMakePayment({ cartTotals: cart.totals }) && !definitions.jetpayment.canMakePayment({ cartTotals: { currency_code: 'BGN' } }), 'Blocks currency availability.');
	check(!definitions.jetpayment.canMakePayment({ cartTotals: { ...cart.totals, total_price: '7499' } }), 'Blocks bypassed principal floor.');
	check(handlers.jetpayment().type === 'error', 'Blocks accepted missing identity/consents.');
	const fill = async prefix => act(async () => {
		Simulate.change(w.document.getElementById(prefix + 'egn'), { target: { value: '9001010000' } });
		const panel = w.document.getElementById(prefix + 'egn').closest('.jet_panel');
		panel.querySelectorAll('input[type="checkbox"]').forEach(node => Simulate.change(node, { target: { checked: true } }));
	});
	await fill('jet_');
	let response = handlers.jetpayment();
	check(response.type === 'success' && response.meta.paymentMethodData.jet_offer && response.meta.paymentMethodData.jet_egn === '9001010000', 'Blocks regular payment_data incomplete.');
	transport.defer = true;
	await act(async () => { cart = { ...cart, totals: { ...cart.totals, total_price: '90000' }, coupons: [{ code: 'synthetic' }] }; render(); await tick(); });
	check(handlers.jetpayment().type === 'error', 'Blocks cart change reused submitted offer.');
	await waitFor(() => requests.at(-1).options.data.jet_expected_amount === '900.00', 'Blocks updated cart request', () => act(tick));
	const pending = requests.at(-1);
	await act(async () => { pending.deferred.resolve(quote(pending.options.data)); await tick(); });
	await waitFor(() => w.document.getElementById('jet_priceall').textContent === '900.00', 'Blocks updated cart response', () => act(tick));
	check(handlers.jetpayment().meta.paymentMethodData.jet_priceall_input === '900.00', 'Blocks current minor-unit cart total missing.');
	await act(async () => { active = 'jetpaymentcard'; render(); await tick(); });
	await fill('jet_card_');
	check(handlers.jetpaymentcard().type === 'error', 'Blocks method switch reused offer.');
	await waitFor(() => requests.at(-1).options.data.jet_card === '1', 'Blocks card request', () => act(tick));
	const selected = requests.at(-1);
	await act(async () => { selected.deferred.resolve(quote(selected.options.data)); await tick(); });
	await waitFor(() => w.document.getElementById('jet_card_priceall').textContent === '900.00', 'Blocks card response', () => act(tick));
	await recalculate('jet_card_');
	response = handlers.jetpaymentcard();
	check(response.type === 'success' && response.meta.paymentMethodData.jet_card_context === 'checkout_blocks' && response.meta.paymentMethodData.jet_card_uslovia1 === '1', 'Blocks card payment_data incomplete.');
	await act(async () => { Simulate.change(w.document.getElementById('jet_card_parva'), { target: { value: '100' } }); await tick(); });
	check(handlers.jetpaymentcard().type === 'error', 'Blocks edited initial payment retained offer.');
	await act(async () => { cart = { ...cart, totals: { ...cart.totals, currency_code: 'USD' } }; render(); await tick(); });
	check(handlers.jetpaymentcard().type === 'error', 'Blocks allowed a non-EUR context.');
	await act(async () => root.unmount()); dom.window.close();
	delete global.window; delete global.document; delete global.navigator;
}
(async () => {
	await shared(); await classic(); await popup(false); await popup(true); await blocks();
	console.log(`PASS EUR frontend: ${checks} assertions; shared AJAX lifecycle, product/variation/quantity/WAPF, cart, Classic, React Blocks and both gateways.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
