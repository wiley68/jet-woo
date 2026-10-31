/* First generate: php tests/classic-form.php > /tmp/jet-classic-form.html */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const { rules, quote } = require('./eur-js-fixtures.cjs');
const tick = () => new Promise(resolve => setTimeout(resolve, 5));
async function until(condition) { const end = Date.now() + 3000; while (!condition()) { if (Date.now() > end) throw Error('Classic lifecycle did not settle'); await tick(); } }
(async () => {
	const html = fs.readFileSync(process.env.JET_CLASSIC_HTML || '/tmp/jet-classic-form.html', 'utf8');
	const dom = new JSDOM(html, { url: 'https://synthetic.example.test/', runScripts: 'outside-only' });
	const w = dom.window;
	w.eval(fs.readFileSync(path.resolve(__dirname, '../../../../wp-includes/js/jquery/jquery.min.js'), 'utf8'));
	const $ = w.jQuery; const requests = [], checkouts = [];
	let failure = false, defer = false, amount = 1000;
	w.jet_checkout_js = { ajax_url: '/offline', rules, available: true };
	$.ajax = options => {
		if (options.url === '/checkout-offline') { checkouts.push(options); return $.Deferred().promise(); }
		if (options.data.action === 'jet_get_nonce') return $.Deferred().resolve({ success: true, data: { nonce: 'valid' } }).promise();
		const pending = $.Deferred(); requests.push({ options, pending });
		if (!defer) pending.resolve(failure ? { success: 'unsuccess', code: 'principal_below_minimum' } : quote({ ...options.data, jet_expected_amount: amount }));
		return pending.promise();
	};
	w.eval(fs.readFileSync(path.resolve(__dirname, '../js/jetcredit_offers.js'), 'utf8'));
	w.eval(fs.readFileSync(path.resolve(__dirname, '../js/jetcredit_checkout.js'), 'utf8'));
	await until(() => $('#jet_offer').val());
	// Execute the installed WC submit gate against actual PHP-rendered gateway fields.
	const wc = fs.readFileSync(path.resolve(__dirname, '../../woocommerce/assets/js/frontend/checkout.js'), 'utf8');
	const marker = '\n\t\tsubmit: ';
	const found = wc.indexOf(marker);
	const start = found + marker.length;
	const end = wc.indexOf('submit_error:', start);
	assert(found >= 0 && end > start, 'Installed WC checkout submit method unavailable');
	const submitSource = wc.slice(start, end).trim().replace(/,$/, '');
	w.wc_checkout_params = { checkout_url: '/checkout-offline' };
	w.mockCheckout = { reset_update_checkout_timer() {}, blockOnSubmit() {}, attachUnloadEventsOnSubmit() {}, get_payment_method: () => $('input[name="payment_method"]:checked').val() };
	const submit = w.eval('(function(){const $=window.jQuery;const wc_checkout_form=window.mockCheckout;return (' + submitSource + ');})()');
	let checks = 0;
	for (const [value, expected] of [['0', '0.00'], ['-00.000', '0.00'], ['.50', '0.50'], ['000.5000', '0.50'], ['0.001', 'invalid:0.001'], ['9007199254740993.00', '9007199254740993.00'], ['.', 'invalid:.'], ['bogus', 'invalid:bogus']]) {
		assert.equal(w.JetCreditOffers.money(value), expected); checks++;
	}
	for (const [method, prefix, panel] of [['jetpayment', 'jet_', '#jet_panel_error'], ['jetpaymentcard', 'jet_card_', '#jet_panel_card_error']]) {
		$('form.checkout').removeClass('processing');
		$('input[value="' + method + '"]').prop('checked', true).trigger('change');
		await until(() => $('#' + prefix + 'offer').val());
		assert.equal($('#' + prefix + 'parva').attr('name'), prefix + 'parva_input'); checks++;
		assert.equal($('#' + prefix + 'parva').val(), '0.00'); checks++;
		assert($(panel).prop('hidden') && $(panel).css('display') === 'none'); checks++;
		const before = requests.length;
		for (const value of ['0', '0.00', '00.000']) $('#' + prefix + 'parva').val(value).trigger('input');
		await tick(); assert.equal(requests.length, before, 'Equivalent money values recalculated'); checks++;
		$('#' + prefix + 'egn').val('0000000000');
		$('#' + prefix + 'uslovia, #' + prefix + 'uslovia1').prop('checked', true);
		const sent = checkouts.length;
		submit.call($('form.checkout')[0]);
		assert.equal(checkouts.length, sent + 1, 'Valid actual form needed a second submit'); checks++;
		const data = new w.URLSearchParams(checkouts.at(-1).data);
		assert(data.get(prefix + 'offer') && data.get(prefix + 'parva_input') === '0.00' && data.get(prefix + 'uslovia') === '1'); checks++;
		$('form.checkout').removeClass('processing');
		defer = true;
		$('#' + prefix + 'parva').val('50').trigger('input');
		assert.equal($('#' + prefix + 'offer').val(), '', 'Real money change retained token'); checks++;
		await until(() => requests.at(-1).options.data.jet_parva === '50.00');
		const old = requests.at(-1);
		$('#' + prefix + 'parva').val('60').trigger('input');
		await until(() => requests.at(-1).options.data.jet_parva === '60.00');
		const current = requests.at(-1); current.pending.resolve(quote(current.options.data));
		await until(() => $('#' + prefix + 'parva').val() === '60.00' && $('#' + prefix + 'offer').val());
		old.pending.resolve(quote(old.options.data)); await Promise.resolve(old.pending.promise()); await tick();
		assert.equal($('#' + prefix + 'parva').val(), '60.00', 'Late response restored previous down payment'); checks++;
		defer = false; failure = true;
		$('#' + prefix + 'parva').val('950').trigger('input');
		await until(() => !$(panel).prop('hidden'));
		assert.equal($('#' + prefix + 'offer').val(), ''); checks++;
		failure = false; $('#' + prefix + 'parva').val('0').trigger('input');
		await until(() => $('#' + prefix + 'offer').val());
		assert($(panel).prop('hidden') && $(panel).css('display') === 'none'); checks++;
		$('#' + prefix + 'vnoski').val('9').trigger('change');
		assert.equal($('#' + prefix + 'offer').val(), '', 'Changed term retained an old offer'); checks++;
		await until(() => $('#' + prefix + 'offer').val() && $('[name="' + prefix + 'vnoski_input"]').val() === '9');
		$(w.document.body).trigger('update_checkout');
		assert.equal($('#' + prefix + 'offer').val(), '', 'Changed checkout totals retained an old offer'); checks++;
		amount -= 100; $(w.document.body).trigger('updated_checkout');
		await until(() => $('#' + prefix + 'offer').val() && $('[name="' + prefix + 'priceall_input"]').val() === amount.toFixed(2));
		assert.equal($('[name="' + prefix + 'priceall_input"]').val(), amount.toFixed(2)); checks++;
	}
	dom.window.close();
	console.log(`PASS IMP-03C Classic: ${checks} assertions; actual PHP fields with empty descriptions, installed WC submit gate, money normalization, error visibility and stale AJAX.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
