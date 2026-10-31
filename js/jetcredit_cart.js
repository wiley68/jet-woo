jQuery(document).ready(function ($) {
	if (window.jetCartOfferInitialized) return;
	window.jetCartOfferInitialized = true;
	let card;
	card = 0;
	// Share the lock if a theme or script optimizer initializes this file twice.
	const jetSubmission = window.jetCartSubmissionState || {
		locked: false,
		alert: null,
		id: null,
		previousFocus: null,
		submitWasDisabled: false
	};
	window.jetCartSubmissionState = jetSubmission;
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
		changeBtnJetcreditBuy();
		if (jetSubmission.previousFocus && jetSubmission.previousFocus.isConnected) {
			jetSubmission.previousFocus.focus();
		}
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

	const jetLoad = () => {
		if (jetSubmission.locked) {
			return;
		}
		if (jetSubmission.attempt) { changeBtnJetcreditBuy(); return; }
		if ($('#btn_jet').length !== 0) {
			$('#jet_step_1').css('display', 'block');
			$('#jet_step_2').css('display', 'none');

			$('#btn_jet').off('click.jetCart').on('click.jetCart', function (event) {
				card = 0;
				jetCalculate();
				$('#jet-product-popup-container').css('display', 'block');
			});

			if ($('#btn_jet_card').length !== 0) {
				$('#btn_jet_card').off('click.jetCart').on('click.jetCart', function (event) {
					card = 1;
					jetCalculate();
					$('#jet-product-popup-container').css('display', 'block');
				});
			}

			$('#back_jetcredit').off('click.jetCart').on('click.jetCart', function (event) {
				jetClose();
			});

			$('#close_jetcredit').off('click.jetCart').on('click.jetCart', function (event) {
				jetClose();
			});

			$('#buy_jetcredit').off('click.jetCart').on('click.jetCart', function (event) {
				let _taxa = parseFloat($('#jet_vnoska_popup').text());
				if (card == 1 && $('#jet_vnoska_popup').length !== 0) {
					_taxa = parseFloat($('#jet_vnoska_popup').text());
				}
				if (offers.current(offerInputs()) && _taxa >= Number(rules.min_installment)) {
					$('#jet_step_1').css('display', 'none');
					$('#jet_step_2').css('display', 'block');
				} else {
					$("#jet_alert_overlay").addClass("show");
					jetShowCustomAlert(window.JetCreditOffers.message('installment_below_minimum', rules), false);
				}
			});

			$('#back2_jetcredit').off('click.jetCart').on('click.jetCart', function (event) {
				$('#jet_step_2').css('display', 'none');
				$('#jet_step_1').css('display', 'block');
			});

			$('#jet_egn').off('input.jetCart').on('input.jetCart', function (event) {
				const input = $(this).val();
				const regex = /^[0-9]{0,10}$/;
				if (!regex.test(input)) {
					$(this).val(input.slice(0, -1));
				}
			});

			$('#jet_phone').off('input.jetCart').on('input.jetCart', function (event) {
				const input = $(this).val();
				const regex = /^[+0-9]+$/;
				if (!regex.test(input)) {
					$(this).val(input.slice(0, -1));
				}
			});

			$('#buy2_jetcredit').off('click.jetCart').on('click.jetCart', function (event) {
				event.preventDefault();
				if (!jetSubmission.locked && checkForm()) {
					jetSend();
				}
			});

			$('.jet_input_text_active').off('click.jetCart').on('click.jetCart', function (event) {
				if ($(this).hasClass('error')) {
					$(this).removeClass('error');
				}
			});

			jetCalculate();
		}
	}

	const offers = new window.JetCreditOffers.Client(jet_cart_js);
	const rules = jet_cart_js.rules;
	let cartRevision = 0;
	const offerInputs = () => {
		jetSubmission.id = jetSubmission.id || jetCreateSubmissionId();
		return {
			jet_context: 'cart', jet_card: String(card), jet_submission_id: jetSubmission.id,
			jet_parva: String($('#jet_parva').val() || '0'), jet_vnoski: String($('#jet_vnoski').val() || ''),
			jet_client_revision: String(cartRevision)
		};
	};
	const canRetryAttempt = () => {
		if (!jetSubmission.attempt) return false;
		const inputs = offerInputs();
		return ["jet_card", "jet_vnoski", "jet_parva"].every((key) => inputs[key] === jetSubmission.attempt[key] || (inputs[key] !== undefined && jetSubmission.attempt[key] !== undefined && Number(inputs[key]) === Number(jetSubmission.attempt[key])));
	};
	const offerStatus = (text) => {
		if (!$('#jet_offer_status').length) $('<div>', { id: 'jet_offer_status', role: 'status' }).prependTo('#jet_step_1');
		$('#jet_offer_status').text(text);
	};
	const invalidateOffer = () => {
		offers.invalidate();
		if (!jetSubmission.locked) {
			$('#buy_jetcredit, #buy2_jetcredit').prop('disabled', true);
			if (jetSubmission.attempt) changeBtnJetcreditBuy();
		}
	};
	const jetCalculate = async () => {
		if (jetSubmission.locked) return;
		if (jetSubmission.attempt) { invalidateOffer(); changeBtnJetcreditBuy(); return; }
		invalidateOffer(); offerStatus('Преизчисляване…');
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
			offerStatus(json.jet_eligible ? '' : window.JetCreditOffers.message('installment_below_minimum', rules));
			changeBtnJetcredit(); changeBtnJetcreditBuy();
		} catch (error) {
			if (error.result && error.result.jet_allowed_terms) window.JetCreditOffers.terms(document.getElementById('jet_vnoski'), Number(error.result.jet_priceall), rules);
			offerStatus(window.JetCreditOffers.message(error, rules));
		}
	};

	const jetClose = () => {
		$('#jet-product-popup-container').css('display', 'none');
		$('#jet_step_1').css('display', 'block');
		$('#jet_step_2').css('display', 'none');
		$('#uslovia').prop('checked', false);
		$('#uslovia1').prop('checked', false);
		$('#uslovia2').prop('checked', false);
		const $jet_parva = $('#jet_parva');
		if (parseFloat($('#jet_parva').val()) !== 0) {
			$('#jet_parva').val(0);
			jetCalculate();
		}
		changeBtnJetcredit();
	}

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
				// Keep the completed application locked until navigation finishes.
				jetSubmission.alert = null;
				jetOverlay.textContent = 'Пренасочване…';
				jetOverlay.focus();
				window.location.href = window.location.origin;
				return;
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

	const jetSend = () => {
		if (jetSubmission.locked) {
			return;
		}
		if (jetSubmission.attempt ? !canRetryAttempt() : !offers.current(offerInputs())) {
			offerStatus(window.JetCreditOffers.message('missing_offer', rules)); changeBtnJetcreditBuy(); return;
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
			jetFormSendData.append('security', jet_cart_js.nonce);

			$.ajax({
				url: jet_cart_js.ajax_url + '?action=jet_send_card',
				method: 'POST',
				data: jetFormSendData,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function (json) {
					if (json && json.success === 'success') {
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

	const changeBtnJetcredit = () => {
		const enabled = $('#uslovia').is(':checked') && $('#uslovia1').is(':checked') && offers.current(offerInputs());
		$('#buy_jetcredit').prop('disabled', !enabled).css({ opacity: enabled ? 1 : 0.5 });
	};

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

	const jetDebounce = (func, delay) => {
		let inDebounce;
		return function () {
			const context = this;
			const args = arguments;
			clearTimeout(inDebounce);
			inDebounce = setTimeout(() => func.apply(context, args), delay);
		}
	}

	const jetIsCartEmpty = () => {
		const cartTable = document.querySelector('.woocommerce-cart-form__contents');
		if (cartTable !== null) {
			const cartItems = cartTable.querySelectorAll('.cart_item');
			return cartItems.length === 0;
		} else {
			return true;
		}
	}

	const changeBtnJetcreditBuy = () => {
		if (jetSubmission.locked) return;
		const enabled = $('#uslovia2').is(':checked') && (jetSubmission.attempt ? canRetryAttempt() : offers.current(offerInputs()));
		$('#buy2_jetcredit').prop('disabled', !enabled).css({ opacity: enabled ? 1 : 0.5 });
	};

	document.addEventListener('click', (event) => {
		if (event.target.id == "uslovia" || event.target.id == "uslovia1") {
			changeBtnJetcredit();
		}
		if (event.target.id == "uslovia2") {
			changeBtnJetcreditBuy();
		}
		if (event.target.id == "btn_preizcisli") {
			jetCalculate();
		}
	});

	document.addEventListener('change', (event) => {
		if (event.target.id == "jet_vnoski") {
			jetCalculate();
		}
	});

	const targetNode = document.querySelector('div.woocommerce-notices-wrapper');
	if (targetNode !== null) {
		if (targetNode instanceof Node) {
			const observer = new MutationObserver(mutationCallback);
			const config = {
				childList: true,
				subtree: true
			};
			function mutationCallback(mutationsList, observer) {
				++cartRevision; invalidateOffer();
				if (!jetIsCartEmpty()) {
					jetLoad();
				}
			}
			observer.observe(targetNode, config);
		}
	}

	$(document.body).on('updated_wc_div.jetOffers updated_cart_totals.jetOffers removed_from_cart.jetOffers applied_coupon.jetOffers removed_coupon.jetOffers', () => { ++cartRevision; invalidateOffer(); jetLoad(); });
	$(document).on('input.jetOffers change.jetOffers', '#jet_parva', () => { jetCalculate(); });
	jetLoad();
});
