jQuery(document).ready(function ($) {
	if (window.jetProductOfferInitialized) return;
	window.jetProductOfferInitialized = true;
	let jetVariationId = null;
	let card;
	card = 0;
	// Share the lock if a theme or script optimizer initializes this file twice.
	const jetSubmission = window.jetProductSubmissionState || {
		locked: false,
		alert: null,
		id: null,
		succeeded: false,
		previousFocus: null,
		submitWasDisabled: false
	};
	window.jetProductSubmissionState = jetSubmission;
	const jetOverlay = document.getElementById('jet_alert_overlay') || document.createElement('div');
	jetOverlay.id = 'jet_alert_overlay';
	jetOverlay.classList.add('jet_alert_overlay');
	// Escape stacking contexts, hidden containers and pointer rules inherited from the theme.
	document.body.appendChild(jetOverlay);
	jetOverlay.style.setProperty('pointer-events', 'auto', 'important');

	const jetBlockInteraction = (event) => {
		if (!jetSubmission.locked || (jetSubmission.alert && jetSubmission.alert.contains(event.target))) {
			return;
		}
		if (event.cancelable) {
			event.preventDefault();
		}
		event.stopImmediatePropagation();
	};
	// Capture events before theme handlers; this does not depend on theme selectors or z-index.
	const jetBlockedEvents = ['click', 'dblclick', 'auxclick', 'pointerdown', 'pointerup',
		'mousedown', 'mouseup', 'touchstart', 'touchend', 'touchmove', 'keydown', 'keyup',
		'keypress', 'submit', 'contextmenu', 'wheel', 'dragstart', 'drop'];
	jetBlockedEvents.forEach((type) => {
		window.addEventListener(type, jetBlockInteraction, { capture: true, passive: false });
	});
	window.addEventListener('focusin', (event) => {
		if (jetSubmission.locked && (!jetSubmission.alert || !jetSubmission.alert.contains(event.target))) {
			jetBlockInteraction(event);
			(jetSubmission.alert ? jetSubmission.alert.querySelector('button') : jetOverlay).focus();
		}
	}, true);

	const jetLockSubmission = () => {
		jetSubmission.locked = true;
		jetSubmission.previousFocus = document.activeElement;
		jetSubmission.submitWasDisabled = $('#buy2_jetcredit').prop('disabled');
		$('#buy2_jetcredit').prop('disabled', true).attr('aria-busy', 'true');
		jetOverlay.classList.add('show');
		jetOverlay.tabIndex = -1;
		jetOverlay.setAttribute('role', 'status');
		jetOverlay.textContent = 'Изпращане на заявката. Моля, изчакайте…';
		jetOverlay.focus();
	};

	const jetUnlockSubmission = () => {
		jetSubmission.locked = false;
		jetSubmission.alert = null;
		jetOverlay.textContent = '';
		jetOverlay.removeAttribute('role');
		$('#buy2_jetcredit').prop('disabled', jetSubmission.submitWasDisabled).removeAttr('aria-busy');
		if (jetSubmission.succeeded) {
			jetSubmission.id = null;
			jetSubmission.attempt = null;
			invalidateOffer();
			jetSubmission.succeeded = false;
			jetCalculate();
			changeBtnJetcreditBuy();
		} else if (jetSubmission.previousFocus && jetSubmission.previousFocus.isConnected) {
			jetSubmission.previousFocus.focus();
		}
		changeBtnJetcreditBuy();
	};

	const jetCreateSubmissionId = () => {
		if (window.crypto && window.crypto.getRandomValues) {
			const bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
		}
		return Date.now().toString(16) + Math.random().toString(16).slice(2);
	};

	const jetMovePopupToBodyStart = () => {
		const popup = document.getElementById('jet-product-popup-container');
		if (!popup || !document.body) {
			return;
		}
		if (popup.parentNode !== document.body) {
			document.body.insertBefore(popup, document.body.firstChild);
			return;
		}
		if (document.body.firstChild !== popup) {
			document.body.insertBefore(popup, document.body.firstChild);
		}
	};
	jetMovePopupToBodyStart();

	const offers = new window.JetCreditOffers.Client(jet_js);
	const rules = jet_js.rules;
	const offerInputs = () => {
		jetSubmission.id = jetSubmission.id || jetCreateSubmissionId();
		const inputs = {
			jet_context: 'product', jet_card: String(card), jet_submission_id: jetSubmission.id,
			jet_product_id: String($('#jet_product_id').val() || ''),
			jet_variation_id: String($('#jet_variation_id').val() || '0'),
			jet_quantity: String($('input[name="quantity"]').first().val() || '1'),
			jet_parva: String($('#jet_parva').val() || '0'), jet_vnoski: String($('#jet_vnoski').val() || '')
		};
		const wapf = document.querySelector('.wapf-product-totals .wapf-grand-total');
		if (wapf && window.getComputedStyle(wapf.closest('.wapf-product-totals')).display !== 'none') {
			inputs.jet_expected_amount = window.JetCreditOffers.decimalFromText(wapf.textContent) || 'invalid';
		}
		return inputs;
	};
	const canRetryAttempt = () => {
		if (!jetSubmission.attempt) return false;
		const inputs = offerInputs();
		return ["jet_card", "jet_product_id", "jet_variation_id", "jet_quantity", "jet_vnoski", "jet_parva", "jet_expected_amount"].every((key) => inputs[key] === jetSubmission.attempt[key] || (inputs[key] !== undefined && jetSubmission.attempt[key] !== undefined && Number(inputs[key]) === Number(jetSubmission.attempt[key])));
	};
	const offerStatus = (text) => {
		if (!$('#jet_offer_status').length) $('<div>', { id: 'jet_offer_status', role: 'status' }).prependTo('#jet_step_1');
		$('#jet_offer_status').text(text);
	};
	const invalidateOffer = () => {
		offers.invalidate();
		if (!jetSubmission.locked) $('#buy_jetcredit, #buy2_jetcredit').prop('disabled', true);
	};
	const jetCalculate = async () => {
		if (jetSubmission.locked) return;
		if (jetSubmission.attempt) { invalidateOffer(); changeBtnJetcreditBuy(); return; }
		invalidateOffer();
		offerStatus('Преизчисляване…');
		try {
			const json = await offers.calculate(offerInputs());
			if (!json) return;
			window.JetCreditOffers.terms(document.getElementById('jet_vnoski'), Number(json.jet_priceall), rules);
			if (!$('#jet_vnoski').val() && json.jet_allowed_terms.includes(Number(json.jet_vnoski))) { $('#jet_vnoski').val(json.jet_vnoski); offers.inputs = offerInputs(); }
			$('#jet_vnoska').text(json.jet_vnoska_regular);
			$('#jet_vnoska_card').text(json.jet_vnoska_card || '');
			$('#jet_vnoski_text, #jet_vnoski_text_card').text(json.jet_vnoski);
			for (const name of ['priceall', 'total_credit_price', 'gpr', 'glp', 'obshto']) $('#jet_' + name).text(json['jet_' + name]);
			$('#jet_vnoska_popup').text(json.jet_vnoska);
			$('#jet-product-button-container').toggle(Number(json.jet_priceall) >= Number(rules.min_principal));
			offerStatus(json.jet_eligible ? '' : window.JetCreditOffers.message('installment_below_minimum', rules));
			changeBtnJetcredit();
			changeBtnJetcreditBuy();
		} catch (error) {
			if (error.result && error.result.jet_allowed_terms) window.JetCreditOffers.terms(document.getElementById('jet_vnoski'), Number(error.result.jet_priceall), rules);
			offerStatus(window.JetCreditOffers.message(error, rules));
		}
	};

	const jetClose = () => {
		$('#jet-product-popup-container').hide();
		$('#jet_step_1').show();
		$('#jet_step_2').hide();
		$('#uslovia').prop('checked', false);
		$('#uslovia1').prop('checked', false);
		$('#uslovia2').prop('checked', false);

		const jet_parva = $('#jet_parva');
		if (parseFloat(jet_parva.val()) !== 0) {
			jet_parva.val(0);
			jetCalculate();
		}
		changeBtnJetcredit();
	}


	const changeBtnJetcredit = () => {
		const enabled = $('#uslovia').is(':checked') && $('#uslovia1').is(':checked') && offers.current(offerInputs());
		$('#buy_jetcredit').prop('disabled', !enabled).css({ opacity: enabled ? 1 : 0.5 });
	};
	const changeBtnJetcreditBuy = () => {
		if (jetSubmission.locked) return;
		const enabled = $('#uslovia2').is(':checked') && (jetSubmission.attempt ? canRetryAttempt() : offers.current(offerInputs()));
		$('#buy2_jetcredit').prop('disabled', !enabled).css({ opacity: enabled ? 1 : 0.5 });
	};

	const jetShowCustomAlert = (message, exit) => {
		const jetAlertBox = $('<div></div>', {
			id: 'jet_alert_box',
			css: {
				position: 'fixed',
				top: '50%',
				left: '50%',
				transform: 'translate(-50%, -50%)',
				backgroundColor: '#fff',
				padding: '20px',
				borderRadius: '5px',
				boxShadow: '0 0 10px rgba(0, 0, 0, 0.1)',
				zIndex: '2147483647',
				width: '300px',
				textAlign: 'center'
			}
		});
		const jetMessageText = $('<p></p>', {
			text: message,
			css: {
				fontFamily: '"Roboto Condensed", sans-serif',
				color: '#14532d'
			}
		});
		jetAlertBox.append(jetMessageText);
		const jetCloseButton = $('<button></button>', {
			text: 'Добре',
			css: {
				fontFamily: '"Roboto Condensed", sans-serif',
				fontWeight: '500',
				marginTop: '20px',
				padding: '10px 20px',
				border: 'none',
				backgroundColor: '#166534',
				color: '#fff',
				borderRadius: '3px',
				cursor: 'pointer'
			}
		});
		jetCloseButton.on('click', function () {
			jetAlertBox.remove();
			if (exit) {
				jetClose();
			}
			if (jetSubmission.alert === jetAlertBox[0]) {
				jetUnlockSubmission();
			}
			$('#jet_alert_overlay').removeClass('show');
		});
		jetAlertBox.append(jetCloseButton);
		$('body').append(jetAlertBox);
		if (jetSubmission.locked) {
			jetSubmission.alert = jetAlertBox[0];
			jetOverlay.textContent = '';
			jetAlertBox.attr({ role: 'alertdialog', 'aria-modal': 'true', 'aria-label': message });
			jetAlertBox[0].style.setProperty('z-index', '2147483647', 'important');
			jetCloseButton[0].focus();
		}
	}

	const checkForm = () => {
		let check = true;
		const jet_name = document.getElementById('jet_name').value.trim();
		if (jet_name === '') {
			document.getElementById('jet_name').classList.add('error');
			check = false;
		}
		const jet_lastname = document.getElementById('jet_lastname').value.trim();
		if (jet_lastname === '') {
			document.getElementById('jet_lastname').classList.add('error');
			check = false;
		}
		const egnRe = /^[0-9]{2}((0[1-9]|1[0-2])|(4[1-9]|5[0-2]))(0[0-9]|1[0-9]|2[0-9]|3[0-1])[0-9]{4}$/;
		const jet_egn = document.getElementById('jet_egn').value.trim();
		if (jet_egn === '' || !egnRe.test(jet_egn)) {
			document.getElementById('jet_egn').classList.add('error');
			check = false;
		}
		const phoneRe = /^[+0-9]+$/;
		const jet_phone = document.getElementById('jet_phone').value.trim();
		if (jet_phone === '' || jet_phone.length < 10 || !phoneRe.test(jet_phone)) {
			document.getElementById('jet_phone').classList.add('error');
			check = false;
		}
		const re = /^[a-zA-Z0-9.!#$%&'*+/=?^_'{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
		const jet_email = document.getElementById('jet_email').value.trim().toLowerCase();
		const position = jet_email.indexOf('@') + 1;
		const ostatak = jet_email.substr(position);
		if (jet_email === '' || !re.test(jet_email) || ostatak.indexOf('.') === -1) {
			document.getElementById('jet_email').classList.add('error');
			check = false;
		}
		return check;
	}

	const jetSend = () => {
		if (jetSubmission.locked) {
			return;
		}
		if (jetSubmission.attempt ? !canRetryAttempt() : !offers.current(offerInputs())) {
			offerStatus(window.JetCreditOffers.message('missing_offer', rules));
			changeBtnJetcreditBuy();
			return;
		}
		jetLockSubmission();
		try {
			if (!jetSubmission.attempt) {
				jetSubmission.attempt = Object.assign(offerInputs(), window.JetCreditOffers.fields(offers.data), {
					jet_name: $('#jet_name').val(), jet_lastname: $('#jet_lastname').val(), jet_egn: $('#jet_egn').val(),
					jet_email: $('#jet_email').val(), jet_phone: $('#jet_phone').val(), jet_lname: $('#jet_lname').val()
				});
			}
			const jetFormSendData = new FormData();
			for (const [key, value] of Object.entries(jetSubmission.attempt)) jetFormSendData.append(key, value);
			jetFormSendData.append('security', jet_js.nonce);

			$.ajax({
				url: jet_js.ajax_url + '?action=jet_send',
				method: 'POST',
				data: jetFormSendData,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function (json) {
					if (json && json.success === 'success') {
						jetSubmission.succeeded = true;
						jetShowCustomAlert(json.code === 'manual_registered' ? json.message : 'Успешно изпратихте Вашата заявка за лизинг към ПБ Лични Финанси. Очаквайте контакт за потвърждаване на направената от Вас заявка.', true);
					} else if (json && json.code === 'manual_registration_failed') {
						jetShowCustomAlert('Регистрацията в магазина изисква проверка. Не е направен опит за изпращане до банката. Моля, свържете се с магазина.', false);
					} else if (json && (json.code === 'processing' || json.code === 'uncertain')) {
						jetShowCustomAlert('Заявката вече е приета за обработка, но резултатът още не е потвърден. Моля, свържете се с магазина за проверка, преди да правите нова заявка.', false);
					} else {
						jetSubmission.attempt = null;
						invalidateOffer();
						jetShowCustomAlert('Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.', false);
					}
				},
				error: function (error) {
					console.error('Error:', error);
					jetShowCustomAlert('Връзката прекъсна или получихме неочакван отговор. Заявката може да е приета. При повторно натискане на „Изпрати“ ще проверим същата заявка.', false);
				}
			});
		} catch (error) {
			console.error('Error:', error);
			jetShowCustomAlert('Възникна грешка при изпращането. Моля, опитайте отново.', false);
		}
	}

	$('form.variations_form').on('woocommerce_variation_select_change', function () {
		jetVariationId = null;
		$('#jet_variation_id').val('');
		invalidateOffer();
	});

	$('form.variations_form').on('found_variation', function (event, variation) {
		jetVariationId = variation.variation_id;
		$('#jet_variation_id').val(jetVariationId);
		jetCalculate();
	});

	$('form.variations_form').on('reset_data', function () {
		jetVariationId = null;
		$('#jet_variation_id').val('');
		invalidateOffer();
	});

	$('#jet_step_1').show();
	$('#jet_step_2').hide();

	$('#btn_jet').on('click', event => {
		card = 0;
		if (!$('button.single_add_to_cart_button').hasClass('disabled')) {
			jetCalculate();
			$('#jet-product-popup-container').show();
		} else {
			$('#jet_alert_overlay').addClass('show');
			jetShowCustomAlert('Моля, изберете първо опция от възможните за продукта!', false);
		}
	});

	if ($('#btn_jet_card').length) {
		$('#btn_jet_card').on('click', event => {
			card = 1;
			if (!$('button.single_add_to_cart_button').hasClass('disabled')) {
				jetCalculate();
				$('#jet-product-popup-container').show();
			} else {
				$('#jet_alert_overlay').addClass('show');
				jetShowCustomAlert('Моля, изберете първо опция от възможните за продукта!', false);
			}
		});
	}

	$('#btn_preizcisli').on('click', event => {
		jetCalculate();
	});

	$('#jet_parva').on('input change', () => { jetCalculate(); });
	$('#jet_vnoski').on('change', event => {
		jetCalculate();
	});

	$('#back_jetcredit').on('click', event => {
		jetClose();
	});

	$('#close_jetcredit').on('click', event => {
		jetClose();
	});

	$('#buy_jetcredit').on('click', event => {
		let _taxa = parseFloat($('#jet_vnoska_popup').text());
		if (card == 1 && $('#jet_vnoska_popup').length) {
			_taxa = parseFloat($('#jet_vnoska_popup').text());
		}
		if (offers.current(offerInputs()) && _taxa >= Number(rules.min_installment)) {
			$('#jet_step_1').hide('slow');
			$('#jet_step_2').show('slow');
		} else {
			$('#jet_alert_overlay').addClass("show");
			jetShowCustomAlert(window.JetCreditOffers.message('installment_below_minimum', rules), false);
		}
	});

	$('#buy_cart_jetcredit').on('click', event => {
		const jet_buy_buttons_submit = $('button[type="submit"].single_add_to_cart_button');
		if (jet_buy_buttons_submit.length) {
			jet_buy_buttons_submit.eq(0).click();
		}
		jetClose();
	});

	$('#back2_jetcredit').on('click', event => {
		$('#jet_step_2').hide('slow');
		$('#jet_step_1').show('slow');
	});

	$('#jet_egn').on('input', event => {
		const input = event.target.value;
		const regex = /^[0-9]{0,10}$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});

	$('#jet_phone').on('input', event => {
		const input = event.target.value;
		const regex = /^[+0-9]+$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});

	$('#buy2_jetcredit').on('click', event => {
		event.preventDefault();
		if (!jetSubmission.locked && checkForm()) {
			jetSend();
		}
	});

	$('.jet_input_text_active').on('click', (event) => {
		if (event.target.classList.contains('error')) {
			event.target.classList.remove('error');
		}
	});

	$('#uslovia').on('click', () => {
		changeBtnJetcredit();
	});

	$('#uslovia1').on('click', () => {
		changeBtnJetcredit();
	});

	$('#uslovia2').on('click', () => {
		changeBtnJetcreditBuy();
	});

	if ($('[name="quantity"]').length) {
		$('[name="quantity"]').eq(0).on('input change', () => {
			jetCalculate();
		});
	}

	const targetNode = document.querySelector('div.woocommerce-variation.single_variation');
	if (targetNode !== null) {
		if (targetNode instanceof Node) {
			const observer = new MutationObserver(mutationCallback);
			const config = {
				childList: true,
				subtree: true
			};
			function mutationCallback(mutationsList, observer) {
				jetCalculate();
			}
			observer.observe(targetNode, config);
		}
	}

	/* WAPF: при промяна на опции/тотал DOM не минава през .single_variation */
	const wapfTotalsNode = document.querySelector('.wapf-product-totals');
	if (wapfTotalsNode instanceof Node) {
		let wapfDebounce;
		const wapfObserver = new MutationObserver(() => {
			invalidateOffer();
			clearTimeout(wapfDebounce);
			wapfDebounce = setTimeout(jetCalculate, 80);
		});
		wapfObserver.observe(wapfTotalsNode, {
			childList: true,
			subtree: true,
			characterData: true,
			attributes: true,
			attributeFilter: ['style', 'class']
		});
	}

	jetCalculate();
});
