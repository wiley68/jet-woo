(function (window, $) {
	'use strict';
	if (window.JetCreditOffers) return;
	const nonces = new Map();
	const request = (config, data) => $.ajax({ url: config.ajax_url, method: 'POST', data, dataType: 'json' });
	const nonce = (config, refresh = false) => {
		if (refresh) nonces.delete(config.ajax_url);
		if (!nonces.has(config.ajax_url)) {
			nonces.set(config.ajax_url, Promise.resolve(request(config, { action: 'jet_get_nonce' })).then((result) => {
				if (!result || !result.success || !result.data || !result.data.nonce) throw new Error('offer_session_required');
				return result.data.nonce;
			}).catch((error) => { nonces.delete(config.ajax_url); throw error; }));
		}
		return nonces.get(config.ajax_url).then((value) => { config.nonce = value; return value; });
	};
	class Client {
		constructor(config) {
			this.config = config;
			this.sequence = 0;
			this.data = null;
			this.inputs = null;
		}
		invalidate() { this.data = null; this.inputs = null; return ++this.sequence; }
		current(inputs) {
			return !!(this.data && this.data.jet_eligible && this.data.jet_offer && Number(this.data.jet_expires) * 1000 > Date.now()
				&& JSON.stringify(this.inputs) === JSON.stringify(inputs));
		}
		async calculate(inputs) {
			const sequence = this.invalidate();
			if (!this.config || !this.config.rules || this.config.available === false) throw new Error('non_eur_currency');
			const snapshot = Object.assign({}, inputs);
			try {
				let security = await nonce(this.config);
				if (sequence !== this.sequence) return null;
				let result;
				try { result = await request(this.config, Object.assign({ action: 'jet_calculate', security }, snapshot)); }
				catch (error) {
					if (error.status !== 403 || sequence !== this.sequence) throw error;
					security = await nonce(this.config, true);
					if (sequence !== this.sequence) return null;
					result = await request(this.config, Object.assign({ action: 'jet_calculate', security }, snapshot));
				}
				if (sequence !== this.sequence) return null;
				if (!result || result.success !== 'success') {
					const error = new Error(result && result.code || 'invalid_offer');
					error.result = result;
					throw error;
				}
				if (result.nonce) this.config.nonce = result.nonce;
				this.inputs = snapshot;
				this.data = result;
				return result;
			} catch (error) {
				if (sequence !== this.sequence) return null;
				throw error;
			}
		}
	}
	const fields = (data, checkout = false, card = false) => {
		if (!data || !data.jet_offer || !data.jet_eligible) throw new Error('missing_offer');
		const prefix = checkout && card ? 'jet_card_' : 'jet_';
		const result = { [prefix + 'offer']: data.jet_offer, [prefix + 'context']: data.jet_context };
		for (const name of ['priceall', 'parva', 'vnoski', 'vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp']) {
			result[prefix + name + (checkout ? '_input' : '')] = data['jet_' + name];
		}
		for (const name of ['products', 'products_qt', 'products_pr', 'products_vr']) result[prefix + name] = data['jet_' + name];
		if (!checkout) result.jet_card = data.jet_card;
		return result;
	};
	const terms = (select, amount, rules) => {
		if (!select || !rules) return;
		for (const option of select.options) {
			const term = Number(option.value);
			option.disabled = ([15, 18, 24].includes(term) && amount < Number(rules.extended_term_threshold))
				|| ([30, 36].includes(term) && amount < Number(rules.long_term_threshold));
		}
		if (select.selectedOptions.length && select.selectedOptions[0].disabled) select.value = '';
	};
	// Exact decimal normalization: formatting changes are equivalent; extra nonzero cents are not.
	const money = (value) => {
		const text = String(value).trim();
		const match = /^([+-]?)([0-9]*)(?:\.([0-9]*))?$/.exec(text);
		if (!match || !(match[2] || match[3])) return 'invalid:' + text;
		const whole = (match[2] || '0').replace(/^0+(?=\d)/, '');
		const fraction = (match[3] || '').replace(/0+$/, '');
		if (fraction.length > 2) return 'invalid:' + text;
		const number = whole + '.' + fraction.padEnd(2, '0');
		return match[1] === '-' && number !== '0.00' ? '-' + number : number;
	};
	const message = (error, rules) => {
		const code = typeof error === 'string' ? error : error && error.message;
		if (code === 'installment_below_minimum') return 'Минималната месечна вноска е ' + Number(rules.min_installment).toFixed(2) + ' евро.';
		if (code === 'principal_below_minimum') return 'Минималният размер на кредита е ' + Number(rules.min_principal).toFixed(2) + ' евро.';
		if (code === 'invalid_financing_term') return 'Изберете допустим срок за изплащане.';
		if (code === 'non_eur_currency' || code === 'non_eur_order_currency') return 'Финансирането е достъпно само в евро.';
		if (code === 'stale_financial_values' || code === 'stale_financial_products') return 'Цената или избраните опции не могат да бъдат потвърдени. Преизчислете или се свържете с магазина.';
		return 'Няма актуална потвърдена оферта. Моля, преизчислете условията.';
	};
	const id = () => {
		const bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
	};
	const decimalFromText = (value) => {
		let text = String(value).replace(/[^\d.,]/g, '');
		if (text.includes(',') && text.includes('.')) text = text.lastIndexOf(',') > text.lastIndexOf('.') ? text.replace(/\./g, '').replace(',', '.') : text.replace(/,/g, '');
		else if (text.includes(',')) text = text.replace(',', '.');
		const number = Number(text);
		return text && Number.isFinite(number) ? number.toFixed(2) : null;
	};
	window.JetCreditOffers = { Client, fields, terms, money, message, id, decimalFromText };
})(window, jQuery);
