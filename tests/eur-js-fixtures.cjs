// Synthetic AJAX replies for DOM tests only. PHP suites exercise actual signed offers.
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../includes/jet-financial-rules.php'), 'utf8');
const central = (name) => Number(source.match(new RegExp("const " + name + " = ([0-9.]+);"))[1]);
const rules = { currency: 'EUR', min_principal: central('JET_MIN_PRINCIPAL_EUR'), initial_term_threshold: central('JET_INITIAL_TERM_THRESHOLD_EUR'), extended_term_threshold: central('JET_EXTENDED_TERM_THRESHOLD_EUR'), long_term_threshold: central('JET_LONG_TERM_THRESHOLD_EUR'), min_installment: central('JET_MIN_INSTALLMENT_EUR') };
function quote(input, overrides = {}) {
	const amount = Number(input.jet_expected_amount || 1000);
	const initial = Number(input.jet_parva || 0);
	const term = Number(input.jet_vnoski || 12);
	const rate = input.jet_card === '1' ? 1 : 1.4;
	const total = (amount - initial) * (1 + term * rate / 100);
	return Object.assign({ success: 'success', jet_eligible: true, jet_context: input.jet_context,
		jet_card: input.jet_card || '0', jet_offer: 'synthetic-dom-offer', jet_expires: Math.floor(Date.now() / 1000) + 1800,
		jet_allowed_terms: [3, 6, 9, 10, 12, 15, 18, 24, 30, 36], nonce: 'valid',
		jet_priceall: amount.toFixed(2), jet_parva: initial.toFixed(2), jet_vnoski: String(term),
		jet_total_credit_price: (amount - initial).toFixed(2), jet_vnoska: (total / term).toFixed(2),
		jet_vnoska_regular: (total / term).toFixed(2), jet_vnoska_card: (total / term).toFixed(2),
		jet_obshto: total.toFixed(2), jet_gpr: '30.00', jet_glp: '25.00',
		jet_products: '42_43', jet_products_qt: '2_1', jet_products_pr: '500.00_250.00', jet_products_vr: '0_77'
	}, overrides);
}
module.exports = { rules, quote };
