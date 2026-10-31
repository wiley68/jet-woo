jQuery(document).ready(function ($) {
	'use strict';
	const form = $('form.checkout');
	if (!form.length || window.jetClassicOffersInitialized) return;
	window.jetClassicOffersInitialized = true;
	const api = window.JetCreditOffers;
	const config = jet_checkout_js;
	const states = new Map();
	let revision = 0;
	const selected = () => $('input[name="payment_method"]:checked').val();
	const state = (name) => {
		if (!states.has(name)) states.set(name, { name, prefix: name === 'jetpaymentcard' ? 'jet_card_' : 'jet_', client: new api.Client(config), attempt: null });
		return states.get(name);
	};
	const inputs = (s) => ({ jet_context: 'checkout_classic', jet_card: s.name === 'jetpaymentcard' ? '1' : '0',
		jet_parva: api.money($('#' + s.prefix + 'parva').val() || '0'), jet_vnoski: String($('#' + s.prefix + 'vnoski').val() || ''), jet_client_revision: String(revision) });
	const retryKey = (s) => JSON.stringify([inputs(s).jet_parva, inputs(s).jet_vnoski, api.money($('#' + s.prefix + 'price').val() || '0'), $('#' + s.prefix + 'products').val(), $('#' + s.prefix + 'products_qt').val(), $('#' + s.prefix + 'products_pr').val(), $('#' + s.prefix + 'products_vr').val()]);
	const status = (s, text) => {
		let node = $('#' + s.prefix + 'offer_status');
		if (!node.length) node = $('<div>', { id: s.prefix + 'offer_status', role: 'status' }).insertBefore('#' + s.prefix + 'parva');
		node.text(text);
	};
	const restriction = (s, visible) => $(s.name === 'jetpaymentcard' ? '#jet_panel_card_error' : '#jet_panel_error').prop('hidden', !visible).toggle(visible);
	const invalidate = (s) => { s.client.invalidate(); $('#' + s.prefix + 'offer').val(''); };
	const populate = (s, data) => {
		for (const [name, value] of Object.entries(data)) form.find('[name="' + name + '"]').val(value);
	};
	const calculate = async (s) => {
		invalidate(s); restriction(s, false); status(s, 'Преизчисляване…');
		try {
			const result = await s.client.calculate(inputs(s));
			if (!result || selected() !== s.name) return;
			api.terms(document.getElementById(s.prefix + 'vnoski'), Number(result.jet_priceall), config.rules);
			if (!$('#' + s.prefix + 'vnoski').val() && result.jet_allowed_terms.includes(Number(result.jet_vnoski))) {
				$('#' + s.prefix + 'vnoski').val(result.jet_vnoski); s.client.inputs = inputs(s);
			}
			for (const name of ['priceall', 'total_credit_price', 'vnoska', 'gpr', 'glp', 'obshto']) $('#' + s.prefix + name).text(result['jet_' + name]);
			if (result.jet_eligible) populate(s, api.fields(result, true, s.name === 'jetpaymentcard'));
			restriction(s, !result.jet_eligible);
			status(s, result.jet_eligible ? '' : api.message('installment_below_minimum', config.rules));
		} catch (error) {
			if (error.result) api.terms(document.getElementById(s.prefix + 'vnoski'), Number(error.result.jet_priceall), config.rules);
			restriction(s, true);
			status(s, api.message(error, config.rules));
		}
	};
	const refresh = () => {
		for (const s of states.values()) invalidate(s);
		const name = selected();
		if (name === 'jetpayment' || name === 'jetpaymentcard') calculate(state(name));
	};
	form.on('input.jetOffers change.jetOffers', '#jet_parva, #jet_card_parva, #jet_vnoski, #jet_card_vnoski', function () {
		const s = state(selected());
		if (s.client.current(inputs(s)) || (s.attempt && s.attempt.key === retryKey(s))) return;
		s.attempt = null; calculate(s);
	});
	form.on('click.jetOffers', '#btn_preizcisli, #jet_card_btn_preizcisli', () => {
		const s = state(selected()); s.attempt = null; calculate(s);
	});
	form.on('change.jetOffers', 'input[name="payment_method"]', refresh);
	$(document.body).on('update_checkout.jetOffers', () => { ++revision; for (const s of states.values()) invalidate(s); });
	$(document.body).on('updated_checkout.jetOffers', () => { ++revision; refresh(); });
	form.on('checkout_place_order.jetOffers', () => {
		const name = selected();
		if (name !== 'jetpayment' && name !== 'jetpaymentcard') return true;
		const s = state(name);
		if (s.attempt && s.attempt.key === retryKey(s)) { populate(s, s.attempt.fields); return true; }
		if (!s.client.current(inputs(s))) { status(s, api.message('missing_offer', config.rules)); calculate(s); return false; }
		const fields = api.fields(s.client.data, true, name === 'jetpaymentcard');
		populate(s, fields); s.attempt = { key: retryKey(s), fields };
		return true;
	});
	$(document.body).on('checkout_error.jetOffers', () => {
		// Only explicit pre-delivery failure releases the pending financial attempt.
		const errors = $('.woocommerce-error').text();
		if (errors.includes('не беше подадена') || errors.includes('Условията за финансиране са невалидни')) {
			const s = states.get(selected()); if (s) { s.attempt = null; calculate(s); }
		}
	});
	refresh();
});
