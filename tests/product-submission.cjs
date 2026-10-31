/* Standalone DOM regression test. Requires jsdom and a local WordPress jquery.min.js. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { rules, quote } = require('./eur-js-fixtures.cjs');

(async () => {
	const cart = !!process.env.JET_TEST_CART;
	const navigationErrors = [];
	const virtualConsole = new VirtualConsole();
	virtualConsole.on('jsdomError', (error) => {
		if (error.message.includes('navigation')) navigationErrors.push(error);
		else throw error;
	});
	const dom = new JSDOM(`<!doctype html><html><body>
		<div class="woocommerce-notices-wrapper"></div>
		<table class="woocommerce-cart-form__contents"><tbody><tr class="cart_item"><td>Product</td></tr></tbody></table>
		<div id="theme-wrapper" style="transform:translateZ(0);z-index:1;position:relative">
			<button id="theme-action">Theme action</button><a id="theme-link" href="#changed">Link</a>
			<form id="theme-form"><input id="theme-input"><button type="submit">Submit</button></form>
			<div id="jet-product-button-container" data-jet-minprice="75">
				<button id="btn_jet">Open application</button>
				<div id="jet_alert_overlay" class="jet_alert_overlay"></div>
			</div>
			<div id="jet-product-popup-container">
				<div id="jet_step_1"></div><div id="jet_step_2">
					<input id="jet_name" value="Test"><input id="jet_lastname" value="Customer">
					<input id="jet_egn" value="9001010000"><input id="jet_phone" value="0888123456">
					<input id="jet_email" value="customer@example.test"><input id="jet_lname" value="">
					<input type="checkbox" id="uslovia2" checked><button id="buy2_jetcredit">Send</button>
					<button id="close_jetcredit">Cancel</button><button id="back2_jetcredit">Back</button>
				</div>
				<input id="jet_price" value="1000"><input id="jet_product_id" value="42">
				<input id="jet_parva" value="0"><input id="jet_vnoski" value="12">
				<input id="jet_products" value="42_43"><input id="jet_products_qt" value="2_1">
				<input id="jet_products_pr" value="500.00_250.00"><input id="jet_products_vr" value="0_77">
				<span id="jet_priceall">1000</span><span id="jet_vnoska_popup">90</span>
			</div>
		</div>
	</body></html>`, { url: 'https://shop.example.test/product', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
	const { window } = dom;
	const { document } = window;
	window.console.error = () => {};
	window.eval(fs.readFileSync(process.env.JET_TEST_JQUERY || path.resolve(__dirname, '../../../../wp-includes/js/jquery/jquery.min.js'), 'utf8'));
	const $ = window.jQuery;
	window.jet_js = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'valid', rules, available: true };
	window.jet_cart_js = window.jet_js;
	const requests = [];
	let calculateRequests = 0;
	let ajaxThrows = false;
	$.ajax = (options) => {
		if (options.url.includes('action=jet_send')) {
			if (ajaxThrows) throw new Error('Simulated synchronous failure');
			requests.push(options);
			return $.Deferred().promise();
		}
		calculateRequests++;
		return $.Deferred().resolve(options.data.action === 'jet_get_nonce'
			? { success: true, data: { nonce: 'valid' } } : quote(options.data)).promise();
	};
	const source = fs.readFileSync(path.resolve(__dirname, cart ? '../js/jetcredit_cart.js' : '../js/jetcredit_product.js'), 'utf8');
	window.eval(fs.readFileSync(path.resolve(__dirname, '../js/jetcredit_offers.js'), 'utf8'));
	window.eval(source);
	if (process.env.JET_TEST_DUPLICATE_SCRIPT) window.eval(source);
	await new Promise((resolve) => $(resolve));
	await new Promise((resolve) => window.setTimeout(resolve, 10));
	const overlay = document.getElementById('jet_alert_overlay');
	const send = document.getElementById('buy2_jetcredit');
	const pageAction = document.getElementById('theme-action');
	const click = (element) => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
	const acknowledge = () => click(document.querySelector('[role="alertdialog"] button'));
	const readyForm = () => {
		$('#jet-product-popup-container, #jet_step_2').show();
		$('#uslovia2').prop('checked', false)[0].click();
		send.focus();
	};
	const updateNotice = async () => {
		document.querySelector('.woocommerce-notices-wrapper').appendChild(document.createTextNode('Updated'));
		await new Promise((resolve) => window.setTimeout(resolve, 0));
	};
	if (cart) {
		for (let i = 0; i < 5; i++) await updateNotice();
		const before = calculateRequests;
		click(document.getElementById('btn_jet'));
		await new Promise((resolve) => window.setTimeout(resolve, 10));
		assert.equal(calculateRequests, before + 1, 'Cart updates must not accumulate button handlers');
	}
	readyForm();
	assert.equal(overlay.parentNode, document.body, 'Overlay must escape the theme wrapper');
	send.focus();
	click(send);
	assert.equal(requests.length, 1);
	assert.equal(send.disabled, true, 'Submit must disable immediately');
	assert.equal(document.activeElement, overlay, 'Focus should leave the underlying form');
	for (let i = 0; i < 10; i++) {
		click(send);
		$(send).triggerHandler('click'); // Bypass DOM capture to exercise the in-flight guard itself.
	}
	assert.equal(requests.length, 1, 'Repeated clicks must not send another request');
	let actions = 0;
	pageAction.addEventListener('click', () => actions++);
	for (const type of ['click', 'dblclick', 'auxclick', 'pointerdown', 'pointerup', 'mousedown', 'mouseup',
		'touchstart', 'touchend', 'touchmove', 'keydown', 'keyup', 'keypress', 'submit', 'contextmenu', 'wheel', 'dragstart', 'drop']) {
		const event = new window.Event(type, { bubbles: true, cancelable: true });
		pageAction.dispatchEvent(event);
		assert.equal(event.defaultPrevented, true, `${type} must be intercepted without theme selectors`);
	}
	assert.equal(actions, 0);
	document.getElementById('theme-input').focus();
	assert.equal(document.activeElement, overlay, 'Pending focus must remain blocked');
	$('#uslovia2').triggerHandler('click');
	assert.equal(send.disabled, true, 'Checkbox handlers must not unlock submission');
	const firstId = requests[0].data.get('jet_submission_id');
	assert.match(firstId, /^[a-f0-9]{16,64}$/);
	if (cart) {
		assert.equal(requests[0].data.get('jet_products'), '42_43');
		assert.equal(requests[0].data.get('jet_products_qt'), '2_1');
		assert.equal(requests[0].data.get('jet_products_pr'), '500.00_250.00');
		assert.equal(requests[0].data.get('jet_products_vr'), '0_77');
		await updateNotice();
		assert.notEqual($('#jet_step_2').css('display'), 'none', 'Cart refresh must not reset an in-flight application');
		assert.equal(send.disabled, true);
		requests[0].error(new Error('Simulated lost cart response'));
		assert.equal(send.disabled, true);
		acknowledge();
		assert.equal(send.disabled, false);
		assert.equal(document.activeElement, send);
		$('#jet_parva').val('100').trigger('input');
		assert.equal(send.disabled, true, 'Editing a pending attempt must block retry with changed inputs');
		$('#jet_parva').val('0').trigger('input');
		assert.equal(send.disabled, false, 'Restoring pending inputs must allow the same retry');

		click(pageAction);
		assert.equal(actions, 1, 'Cart error acknowledgement must release the page');
		await updateNotice();
		assert.notEqual($('#jet_step_2').css('display'), 'none', 'A pending retry must survive later cart fragments');
		assert.equal(send.disabled, false, 'Cart fragments must permit checking the pending submission');
		click(send);
		assert.equal(requests[1].data.get('jet_submission_id'), firstId, 'Cart retry must keep its identity');
		requests[1].success({ success: 'unsuccess', code: process.env.JET_TEST_MANUAL ? 'manual_registration_failed' : 'processing' });
		assert.match(document.querySelector('[role="alertdialog"]').textContent, /проверка/);
		acknowledge();
		ajaxThrows = true;
		click(send);
		assert.ok(document.querySelector('[role="alertdialog"]'), 'Cart synchronous failure needs an error dialog');
		acknowledge();
		ajaxThrows = false;
		click(send);
		assert.equal(requests[2].data.get('jet_submission_id'), firstId);
		requests[2].success(process.env.JET_TEST_MANUAL ? { success: 'success', code: 'manual_registered', message: 'Заявката е регистрирана в магазина за ръчна обработка. Не е изпратена до банката.' } : { success: 'success' });
		assert.match(document.querySelector('[role="alertdialog"]').textContent, process.env.JET_TEST_MANUAL ? /ръчна обработка.*Не е изпратена/ : /Успешно изпратихте/);
		click(pageAction);
		assert.equal(actions, 1, 'Cart result must remain modal');
		acknowledge();
		assert.equal(navigationErrors.length, 1, 'Cart success must attempt the existing redirect');
		assert.equal(overlay.classList.contains('show'), true, 'Keep overlay during navigation');
		assert.equal(send.disabled, true, 'Keep submit disabled during navigation');
		$(send).triggerHandler('click');
		click(pageAction);
		assert.equal(actions, 1);
		assert.equal(requests.length, 3, 'Navigation must not allow a new application');
		dom.window.close();
		process.stdout.write('PASS (cart): rapid clicks, repeated initialization, cart refresh, input blocking, focus, retry identity, errors, redirect lock.\n');
		return;
	}

	requests[0].success(process.env.JET_TEST_MANUAL ? { success: 'success', code: 'manual_registered', message: 'Заявката е регистрирана в магазина за ръчна обработка. Не е изпратена до банката.' } : { success: 'success' });
	assert.match(document.querySelector('[role="alertdialog"]').textContent, process.env.JET_TEST_MANUAL ? /ръчна обработка.*Не е изпратена/ : /Успешно изпратихте/);
	assert.ok(document.querySelector('[role="alertdialog"]'));
	assert.equal(send.disabled, true, 'Lock must remain until result is acknowledged');
	click(pageAction);
	assert.equal(actions, 0);
	document.getElementById('theme-input').focus();
	assert.equal(document.activeElement, document.querySelector('[role="alertdialog"] button'));
	acknowledge();
	assert.equal(overlay.classList.contains('show'), false);
	assert.equal($('#jet-product-popup-container').css('display'), 'none');
	assert.equal(send.disabled, true, 'Consent resets after a successful application');
	click(pageAction);
	assert.equal(actions, 1, 'Page interactions must resume');

	await new Promise((resolve) => window.setTimeout(resolve, 10));
	// A network failure must unlock after acknowledgement and keep its retry identity.
	readyForm();
	click(send);
	const retryId = requests[1].data.get('jet_submission_id');
	assert.notEqual(retryId, firstId, 'A new deliberate application must have a new identity');
	requests[1].error(new Error('Simulated lost response'));
	assert.equal(send.disabled, true);
	acknowledge();
	assert.equal(send.disabled, false);
	assert.equal(document.activeElement, send, 'Focus should return after an error');
	$('#jet_parva').val('100').trigger('input');
	assert.equal(send.disabled, true, 'Changed pending inputs must block retry');
	$('#jet_parva').val('0').trigger('input');
	assert.equal(send.disabled, false, 'Unchanged pending inputs must allow retry');
	click(send);
	assert.equal(requests[2].data.get('jet_submission_id'), retryId, 'Network retry must reuse the original identity');
	requests[2].success({ success: 'unsuccess', code: process.env.JET_TEST_MANUAL ? 'manual_registration_failed' : 'processing' });
	assert.match(document.querySelector('[role="alertdialog"]').textContent, /проверка/);
	acknowledge();
	click(send);
	assert.equal(requests[3].data.get('jet_submission_id'), retryId);
	requests[3].success({ success: 'success' });
	acknowledge();

	await new Promise((resolve) => window.setTimeout(resolve, 10));
	readyForm();
	ajaxThrows = true;
	click(send);
	assert.ok(document.querySelector('[role="alertdialog"]'), 'Synchronous failure should show an actionable error');
	acknowledge();
	assert.equal(send.disabled, false);
	dom.window.close();
	process.stdout.write('PASS: rapid clicks, programmatic repeat, mouse/touch/keyboard/form blocking, focus, acknowledgement, network retry identity, synchronous failure.\n');
})().catch((error) => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
