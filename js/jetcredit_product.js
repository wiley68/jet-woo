jQuery(document).ready(function ($) {
	let jetVariationId = null;
	let card;
	card = 0;

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

	const getJetNonce = () => {
		return $.ajax({
			url: jet_js.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: { action: 'jet_get_nonce' }
		}).then((res) => {
			if (res && res.success && res.data && res.data.nonce) {
				jet_js.nonce = res.data.nonce;
				return res.data.nonce;
			}
			throw new Error('Could not fetch nonce');
		});
	};

	const postJetCalculate = (jetFormData) => {
		return $.ajax({
			url: jet_js.ajax_url,
			method: 'POST',
			data: jetFormData,
			processData: false,
			contentType: false,
			dataType: 'json'
		});
	};

	const jetGetButtonContainer = () => {
		return $('#jet-product-button-container');
	};

	const jetConvertPriceToCalculatorCurrency = (price) => {
		const jetButtonContainer = jetGetButtonContainer();
		const jetEur = parseInt(jetButtonContainer.data('jet-eur'), 10);
		const jetCurrency = String(jetButtonContainer.data('jet-currency') || '');
		let jetNormalizedPrice = parseFloat(price);

		if (Number.isNaN(jetNormalizedPrice)) {
			return 0;
		}

		switch (jetEur) {
			case 1:
				if (jetCurrency === 'EUR') {
					jetNormalizedPrice *= 1.95583;
				}
				break;
			case 2:
			case 3:
				if (jetCurrency === 'BGN') {
					jetNormalizedPrice /= 1.95583;
				}
				break;
		}

		return jetNormalizedPrice;
	};

	const jetShouldShowButtonImmediately = (jetPriceall) => {
		const jetButtonContainer = jetGetButtonContainer();
		const jetMinprice = parseFloat(jetButtonContainer.data('jet-minprice'));
		const jetParvaRaw = parseFloat($('#jet_parva').val());
		const jetParva = Number.isNaN(jetParvaRaw) ? 0 : jetParvaRaw;
		const jetTotalCreditPrice = jetConvertPriceToCalculatorCurrency(jetPriceall) - jetParva;

		if (Number.isNaN(jetMinprice)) {
			return true;
		}

		return jetTotalCreditPrice >= jetMinprice;
	};

	const jetCalculate = async () => {
		let jet_price1 = $('#jet_price').val();
		let jet_quantity = 1;
		/** WAPF „Общо“ вече е пълна стойност за текущо количество/опции — не я умножаваме по брой */
		let jetFromWapfGrandTotal = false;

		const variationDiv = document.getElementsByClassName('woocommerce-variation-price');
		if (typeof variationDiv[0] !== 'undefined') {
			const variationSpan1 = variationDiv[0].getElementsByTagName('span');
			if (typeof variationSpan1[0] !== 'undefined') {
				const variationSpan2 = variationSpan1[0].getElementsByTagName('span');
				if (typeof variationSpan2[0] !== 'undefined') {
					const tps = variationSpan2[0].innerHTML.split('&');
					jet_price1 = tps[0];
				}
				const variationIns = variationSpan1[0].getElementsByTagName('ins');
				if (typeof variationIns[0] !== 'undefined') {
					const variationSpan3 = variationIns[0].getElementsByTagName('span');
					if (typeof variationSpan3[0] !== 'undefined') {
						const tps = variationSpan3[0].innerHTML.split('&');
						jet_price1 = tps[0];
					}
				}
			}
		}

		/* Advanced Product Fields (WAPF): когато темата/плъгинът пълнят тотала тук, а не .woocommerce-variation-price */
		const wapfGrand = document.querySelector('.wapf-product-totals .wapf-grand-total');
		if (wapfGrand) {
			const wapfTotals = wapfGrand.closest('.wapf-product-totals');
			if (wapfTotals) {
				const wapfStyle = window.getComputedStyle(wapfTotals);
				if (wapfStyle.display !== 'none' && wapfStyle.visibility !== 'hidden') {
					const wapfText = wapfGrand.textContent && wapfGrand.textContent.trim();
					if (wapfText) {
						jet_price1 = wapfText;
						jetFromWapfGrandTotal = true;
					}
				}
			}
		}

		jet_price1 = jet_price1.replace(/[^\d.,]/g, '');
		jet_price1 = jetConvertToDotDecimal(jet_price1);

		if ($('input[name="quantity"]').length) {
			jet_quantity = parseInt($('input[name="quantity"]').val(), 10) || 1;
		}

		const rawPrice = parseFloat(jet_price1) || 0;
		const jetPriceall = jetFromWapfGrandTotal
			? rawPrice
			: rawPrice * jet_quantity;

		if (jetShouldShowButtonImmediately(jetPriceall)) {
			jetGetButtonContainer().show();
		} else {
			jetGetButtonContainer().hide();
		}

		jetHideOptions401();
		jetHideOptions601();
		if (jetPriceall >= 401) jetShowOptions401();
		if (jetPriceall >= 601) jetShowOptions601();

		if ($('#jet_parva').val().trim() == '' || parseFloat($('#jet_parva').val()) < jetPriceall) {
			try {
				const nonce = await getJetNonce();

				const jetFormData = new FormData();
				jetFormData.append('action', 'jet_calculate');
				jetFormData.append('security', nonce);
				jetFormData.append('jet_priceall', jetPriceall.toFixed(2));
				jetFormData.append('jet_parva', parseFloat($('#jet_parva').val()).toFixed(2));
				jetFormData.append('jet_vnoski', parseInt($('#jet_vnoski').val()));
				jetFormData.append('jet_product_id', parseInt($('#jet_product_id').val()));

				let json = await postJetCalculate(jetFormData);

				if (json && json.success === false && json.data && json.data.reason === 'bad_nonce') {
					const nonce2 = await getJetNonce();
					jetFormData.set('security', nonce2);
					json = await postJetCalculate(jetFormData);
				}

				if (json.success === 'success') {
					if (json.jet_show_button) {
						$('#jet-product-button-container').show();
					} else {
						$('#jet-product-button-container').hide();
					}

					$('#jet_vnoska').text(json.jet_vnoska);
					$('#jet_vnoska_second').text(json.jet_vnoska_second);
					$('#jet_vnoski_text').text(parseInt($('#jet_vnoski').val()));
					$('#jet_priceall').text(json.jet_priceall);
					$('#jet_priceall_second').text(json.jet_priceall_second);
					$('#jet_total_credit_price').text(json.jet_total_credit_price);
					$('#jet_total_credit_price_second').text(json.jet_total_credit_price_second);
					$('#jet_vnoska_popup').text(json.jet_vnoska);
					$('#jet_vnoska_popup_second').text(json.jet_vnoska_second);
					$('#jet_gpr').text(json.jet_gpr);
					$('#jet_glp').text(json.jet_glp);
					$('#jet_obshto').text(json.jet_obshto);
					$('#jet_obshto_second').text(json.jet_obshto_second);

					if (parseInt($('#jet_card_in').val()) === 1) {
						$('#jet_vnoska_card').text(json.jet_vnoska_card);
						$('#jet_vnoska_card_second').text(json.jet_vnoska_card_second);
						$('#jet_vnoski_text_card').text(parseInt($('#jet_vnoski').val()));

						if (card === 1) {
							$('#jet_vnoska_popup').text(json.jet_vnoska_card);
							$('#jet_vnoska_popup_second').text(json.jet_vnoska_card_second);
							$('#jet_gpr').text(json.jet_gpr_card);
							$('#jet_glp').text(json.jet_glp_card);
							$('#jet_obshto').text(json.jet_obshto_card);
							$('#jet_obshto_second').text(json.jet_obshto_card_second);
						}
					}
				}
			} catch (err) {
				console.error('Error:', err);
			}
		} else {
			$('#jet_alert_overlay').addClass('show');
			jetShowCustomAlert('Първоначалната вноска трябва да бъде по-малка от цената на стоките!', false);
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

	const jetConvertToDotDecimal = (price) => {
		price = price.trim();
		if (price.includes('.') && price.includes(',')) {
			if (price.lastIndexOf(',') < price.lastIndexOf('.')) {
				price = price.replace(/,/g, '');
			} else {
				price = price.replace(/\./g, '').replace(/,/g, '.');
			}
		} else if (price.includes(',')) {
			if (price.split(',').length - 1 === 1) {
				price = price.replace(/,/g, '.');
			} else {
				price = price.replace(/,/g, '');
			}
		}
		return price;
	}

	const jetHideOptions401 = () => {
		$('#jet_vnoski').find('option').each(function () {
			if ($(this).val() === '15' || $(this).val() === '18' || $(this).val() === '24') {
				$(this).prop('disabled', true);
			}
		});
	}

	const jetShowOptions401 = () => {
		$('#jet_vnoski').find('option').each(function () {
			if ($(this).val() === '15' || $(this).val() === '18' || $(this).val() === '24') {
				$(this).prop('disabled', false);
			}
		});
	}

	const jetHideOptions601 = () => {
		$('#jet_vnoski').find('option').each(function () {
			if ($(this).val() === '30' || $(this).val() === '36') {
				$(this).prop('disabled', true);
			}
		});
	}

	const jetShowOptions601 = () => {
		$('#jet_vnoski').find('option').each(function () {
			if ($(this).val() === '30' || $(this).val() === '36') {
				$(this).prop('disabled', false);
			}
		});
	}

	const changeBtnJetcredit = () => {
		if ($('#uslovia').is(':checked') && $('#uslovia1').is(':checked')) {
			let _taxa = parseFloat($('#jet_vnoska_popup').text());
			if (card == 1 && $('#jet_vnoska_popup').length) {
				_taxa = parseFloat($('#jet_vnoska_popup').text());
			}
			if (_taxa >= 20) {
				$('#buy_jetcredit').prop('disabled', false);
				$('#buy_jetcredit').css({
					opacity: 1.00
				});
			} else {
				$('#jet_alert_overlay').addClass('show');
				jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 лв.!', false);
				$('#uslovia').prop('checked', false);
				$('#uslovia1').prop('checked', false);
			}
		} else {
			$('#buy_jetcredit').prop('disabled', true);
			$('#buy_jetcredit').css({
				opacity: 0.50
			});
		}
	}

	const changeBtnJetcreditBuy = () => {
		if ($('#uslovia2').is(':checked')) {
			$('#buy2_jetcredit').prop('disabled', false);
			$('#buy2_jetcredit').css({
				opacity: 1.00
			});
		} else {
			$('#buy2_jetcredit').prop('disabled', true);
			$('#buy2_jetcredit').css({
				opacity: 0.50
			});
		}
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
				zIndex: '5000001',
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
			$('#jet_alert_overlay').removeClass('show');
		});
		jetAlertBox.append(jetCloseButton);
		$('body').append(jetAlertBox);
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
		$('#jet_alert_overlay').addClass('show');
		let jet_quantity = 1;
		if ($('input[name="quantity"]').length !== 0) {
			jet_quantity = parseInt($('input[name="quantity"]').first().val());
		}
		const jetFormSendData = new FormData();
		jetFormSendData.append('jet_priceall', $('#jet_priceall').text());
		jetFormSendData.append('jet_vnoski', $('#jet_vnoski').val());
		jetFormSendData.append('jet_vnoska', $('#jet_vnoska_popup').text());
		jetFormSendData.append('jet_parva', $('#jet_parva').val());
		jetFormSendData.append('jet_total_credit_price', $('#jet_total_credit_price').text());
		jetFormSendData.append('jet_obshto', $('#jet_obshto').text());
		jetFormSendData.append('jet_gpr', $('#jet_gpr').text());
		jetFormSendData.append('jet_glp', $('#jet_glp').text());
		jetFormSendData.append('jet_name', $('#jet_name').val());
		jetFormSendData.append('jet_lastname', $('#jet_lastname').val());
		jetFormSendData.append('jet_egn', $('#jet_egn').val());
		jetFormSendData.append('jet_email', $('#jet_email').val());
		jetFormSendData.append('jet_phone', $('#jet_phone').val());
		jetFormSendData.append('jet_lname', $('#jet_lname').val());
		jetFormSendData.append('jet_card', card);
		jetFormSendData.append('jet_product_id', $('#jet_product_id').val());
		jetFormSendData.append('jet_variation_id', $('#jet_variation_id').val());
		jetFormSendData.append('jet_quantity', jet_quantity);
		jetFormSendData.append('security', jet_js.nonce);

		$.ajax({
			url: jet_js.ajax_url + '?action=jet_send',
			method: 'POST',
			data: jetFormSendData,
			processData: false,
			contentType: false,
			success: function (json) {
				if (json.success === 'success') {
					jetShowCustomAlert('Успешно изпратихте Вашата заявка за лизинг към ПБ Лични Финанси. Очаквайте контакт за потвърждаване на направената от Вас заявка.', true);
				} else {
					jetShowCustomAlert('Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.', false);
				}
			},
			error: function (error) {
				console.error('Error:', error);
			}
		});
	}

	$('form.variations_form').on('woocommerce_variation_select_change', function () {
		jetVariationId = null;
	});

	$('form.variations_form').on('found_variation', function (event, variation) {
		jetVariationId = variation.variation_id;
		$('#jet_variation_id').val(jetVariationId);
	});

	$('form.variations_form').on('reset_data', function () {
		jetVariationId = null;
		$('#jet_variation_id').val('');
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
		if (_taxa >= 20) {
			$('#jet_step_1').hide('slow');
			$('#jet_step_2').show('slow');
		} else {
			$('#jet_alert_overlay').addClass("show");
			jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 лв.!', false);
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
		if (checkForm()) {
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
		$('[name="quantity"]').eq(0).on('change', () => {
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
	} else {
		jetCalculate();
	}

	/* WAPF: при промяна на опции/тотал DOM не минава през .single_variation */
	const wapfTotalsNode = document.querySelector('.wapf-product-totals');
	if (wapfTotalsNode instanceof Node) {
		let wapfDebounce;
		const wapfObserver = new MutationObserver(() => {
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
