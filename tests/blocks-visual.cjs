/* Offline browser layout checks. Requires Playwright, React and jsdom-test dependencies.
 * Optional: JET_TEST_PLAYWRIGHT, JET_TEST_BROWSER, JET_TEST_ARTIFACTS, JET_TEST_BASELINE.
 * No WordPress bootstrap, HTTP server, checkout request or external resource loading.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.JET_TEST_PLAYWRIGHT || 'playwright');
const { rules, quote } = require('./eur-js-fixtures.cjs');
const root = path.resolve(__dirname, '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const artifacts = process.env.JET_TEST_ARTIFACTS || fs.mkdtempSync(path.join(os.tmpdir(), 'jet-blocks-visual-'));
fs.mkdirSync(artifacts, { recursive: true });
const baseline = process.env.JET_TEST_BASELINE ? JSON.parse(fs.readFileSync(process.env.JET_TEST_BASELINE, 'utf8')) : null;
const classic = execFileSync(process.env.JET_TEST_PHP || 'php', [path.join(__dirname, 'classic-form.php')], { encoding: 'utf8' });
const originalCSS = execFileSync('git', ['show', 'adb74f355cd3384ac2a41644f12097c84aebe3be:css/jetcredit_checkout.css'], { cwd: root, encoding: 'utf8' });
const fields = ['priceall', 'total_credit_price', 'vnoska', 'gpr', 'glp', 'obshto'];
const fontCSS = read('css/jetcredit_fonts.css').replace(/url\('([^']+)'\)/g, (_, url) => "url('data:font/ttf;base64," + fs.readFileSync(path.join(root, 'fonts', path.basename(url))).toString('base64') + "')");
const themeCSS = fs.readFileSync(path.join(root, '../../themes/storefront/style.css'), 'utf8');
const blocksCSS = fs.readFileSync(path.join(root, '../woocommerce/assets/client/blocks/checkout.css'), 'utf8');
const react = fs.readFileSync(path.join(path.dirname(require.resolve('react/package.json')), 'umd/react.development.js'), 'utf8');
const reactDOM = fs.readFileSync(path.join(path.dirname(require.resolve('react-dom/package.json')), 'umd/react-dom.development.js'), 'utf8');
const jquery = fs.readFileSync(path.join(root, '../../../wp-includes/js/jquery/jquery.min.js'), 'utf8');
let checks = 0;
const check = (value, message) => { checks++; assert.ok(value, message); };

(async () => {
	const browser = await chromium.launch({ headless: true, executablePath: process.env.JET_TEST_BROWSER || undefined,
		args: ['--no-sandbox', '--disable-dev-shm-usage', '--host-resolver-rules=MAP * ~NOTFOUND'] });
	const context = await browser.newContext({ offline: true, serviceWorkers: 'block', viewport: { width: 1280, height: 1000 } });
	await context.route('**/*', route => route.abort());
	const errors = [];
	context.on('page', page => page.on('pageerror', error => errors.push(error.message)));
	async function pageFor(css, method, useClassic = false, source = read('js/jetcredit_blocks.js')) {
		const page = await context.newPage();
		await page.setContent('<!doctype html><html lang="bg"><meta charset="utf-8"><style>' + themeCSS + blocksCSS + fontCSS + css.replace(/@import[^;]+;/g, '')
			+ '</style><style>body{margin:0;background:#fff}main{width:calc(100% - 32px);max-width:560px;margin:16px auto}</style><main id="root" class="wc-block-components-radio-control-accordion-content"></main></html>');
		if (useClassic) {
			await page.evaluate(({ html, method, values }) => {
				const doc = new DOMParser().parseFromString(html, 'text/html');
				const prefix = method === 'jetpaymentcard' ? 'jet_card_' : 'jet_';
				document.getElementById('root').append(doc.getElementById(method === 'jetpaymentcard' ? 'jet_panel_card' : 'jet_panel'));
				for (const field of Object.keys(values)) { const node = document.getElementById(prefix + field); if (node) node.textContent = values[field]; }
			}, { html: classic, method, values: Object.fromEntries(fields.map(field => [field, quote({})['jet_' + field]])) });
		} else {
			await page.addScriptTag({ content: jquery });
			await page.addScriptTag({ content: react });
			await page.addScriptTag({ content: reactDOM });
			await page.evaluate(({ method, rules, quoteSource }) => {
				const makeQuote = (0, eval)('(' + quoteSource + ')');
				window.quoteRequests = [];
				window.jQuery.ajax = options => {
					const pending = window.jQuery.Deferred();
					if (options.data.action === 'jet_get_nonce') return pending.resolve({ success: true, data: { nonce: 'valid' } }).promise();
					window.quoteRequests.push(options.data);
					setTimeout(() => pending.resolve(makeQuote(options.data)), 10);
					return pending.promise();
				};
				window.wp = { element: window.React, htmlEntities: { decodeEntities: value => value }, data: { useSelect: callback => callback(store => store === 'cart'
					? { getCartData: () => ({ totals: { currency_code: 'EUR', currency_minor_unit: 2, total_price: '100000' }, items: [{ id: 42, quantity: 1 }], coupons: [] }) }
					: { getActivePaymentMethod: () => method }) } };
				window.wc = { wcBlocksData: { cartStore: 'cart', paymentStore: 'payment' }, wcSettings: { getSetting: () => ({ title: 'Synthetic', description: '', jet_vnoski: '12', config: { ajax_url: '/offline', available: true, rules } }) },
					wcBlocksRegistry: { registerPaymentMethod: definition => { window.definition = definition; } } };
			}, { method, rules, quoteSource: quote.toString() });
			await page.addScriptTag({ content: read('js/jetcredit_offers.js') });
			await page.addScriptTag({ content: source });
			await page.evaluate(method => {
				window.JetCreditBlocks.register(method);
				window.ReactDOM.createRoot(document.getElementById('root')).render(window.React.cloneElement(window.definition.content, {
					eventRegistration: { onPaymentSetup: () => () => {} }, emitResponse: { responseTypes: {}, noticeContexts: {} }
				}));
			}, method);
			await page.waitForFunction(prefix => document.getElementById(prefix + 'priceall')?.textContent === '1000.00', method === 'jetpaymentcard' ? 'jet_card_' : 'jet_');
		}
		await page.evaluate(() => document.fonts.ready);
		return page;
	}
	async function appearance(page, prefix) {
		return page.evaluate(prefix => {
			const box = document.getElementById(prefix + 'priceall').closest('.jet_input_text.jet_disable');
			const style = getComputedStyle(box), value = getComputedStyle(box.firstElementChild);
			return { border: style.border, background: style.backgroundColor, padding: style.padding, radius: style.borderRadius,
				height: box.getBoundingClientRect().height, textAlign: value.textAlign, fontSize: value.fontSize, fontWeight: value.fontWeight };
		}, prefix);
	}
	try {
		for (const method of ['jetpayment', 'jetpaymentcard']) {
			const prefix = method === 'jetpaymentcard' ? 'jet_card_' : 'jet_';
			const reference = await pageFor(originalCSS, method, true);
			const classicNow = await pageFor(read('css/jetcredit_checkout.css'), method, true);
			const expected = await appearance(reference, prefix);
			assert.deepEqual(await appearance(classicNow, prefix), expected, 'Classic appearance changed'); checks++;
			const page = await pageFor(read('css/jetcredit_checkout.css'), method);
			assert.deepEqual(await appearance(page, prefix), expected, method + ' differs from Classic financial controls'); checks++;
			for (const width of [1280, 768, 375, 320, 260, 220]) {
				await page.setViewportSize({ width, height: 1000 });
				const geometry = await page.evaluate(prefix => {
					const input = document.getElementById(prefix + 'parva'), panel = input.closest('.jet_panel');
					const button = panel.querySelector('button'), i = input.getBoundingClientRect(), b = button.getBoundingClientRect();
					return { width: innerWidth, panelWidth: panel.clientWidth, panelScroll: panel.scrollWidth, documentScroll: document.documentElement.scrollWidth,
						input: { x: i.x, y: i.y, right: i.right, height: i.height }, button: { x: b.x, y: b.y, width: b.width, height: b.height } };
				}, prefix);
				check(geometry.documentScroll <= width && geometry.panelScroll <= geometry.panelWidth + 1, method + ' horizontal overflow at ' + width);
				check(geometry.button.height === 32 && geometry.input.height === 32 && geometry.button.width < 120, method + ' non-compact controls at ' + width);
				if (width >= 320) check(Math.abs(geometry.input.y - geometry.button.y) <= 1 && geometry.button.x >= geometry.input.right, method + ' button is not beside input at ' + width);
				else check(geometry.button.y >= geometry.input.y + geometry.input.height, method + ' narrow controls did not stack');
				if ([1280, 375, 220].includes(width)) await page.screenshot({ path: path.join(artifacts, method + '-' + width + '.png'), fullPage: true });
			}
			await page.setViewportSize({ width: 1280, height: 1000 });
			const before = await page.evaluate(() => window.quoteRequests.length);
			await page.getByRole('button', { name: 'Преизчисли', exact: true }).click();
			await page.waitForFunction(before => window.quoteRequests.length > before, before);
			await page.waitForFunction(prefix => document.getElementById(prefix + 'priceall').textContent === '1000.00', prefix);
			await page.locator('#' + prefix + 'parva').fill('50');
			await page.waitForFunction(prefix => document.getElementById(prefix + 'total_credit_price').textContent === '950.00', prefix);
			const actual = await page.evaluate(({ prefix, fields }) => fields.map(field => document.getElementById(prefix + field).textContent), { prefix, fields });
			const data = quote({ jet_parva: '50', jet_vnoski: '12', jet_card: method === 'jetpaymentcard' ? '1' : '0' });
			assert.deepEqual(actual, fields.map(field => data['jet_' + field])); checks++;
			check(await page.locator('output').count() === 6 && await page.locator('[contenteditable="true"]').count() === 0, method + ' editable calculated values');
			if (baseline) {
				const beforePage = await pageFor(baseline.css, method, false, baseline.js);
				check(await beforePage.locator('.jet_panel > button').count() === 1 && await beforePage.locator('.jet_input_text.jet_disable').count() === 0, 'Pre-UI-01 regression not reproduced');
				await beforePage.screenshot({ path: path.join(artifacts, method + '-before.png'), fullPage: true });
				await beforePage.close();
			}
			await reference.screenshot({ path: path.join(artifacts, method + '-classic-reference.png'), fullPage: true });
			await page.close(); await reference.close(); await classicNow.close();
		}
		assert.deepEqual(errors, [], 'Browser runtime errors'); checks++;
		console.log(`PASS Blocks visual: ${checks} assertions; both methods, Classic parity, six viewport widths, recalculation and updated outputs. Chromium ${browser.version()}. Artifacts: ${artifacts}`);
	} finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
