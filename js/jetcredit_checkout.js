jQuery(document).ready(function($) {
	const debounce = (func, delay) => {
		let timeoutId;
		return function(...args) {
			clearTimeout(timeoutId);
			timeoutId = setTimeout(() => {
				func.apply(this, args);
			}, delay);
		};
	}

	const calculateJet = () => {
		const jetPriceall = parseFloat(document.getElementById('jet_price').value);
		let jet_uslovia_checked;
		if (document.getElementById('jet_uslovia').checked) {
			jet_uslovia_checked = 1;
		}else{
			jet_uslovia_checked = 0;
		}
		let jet_uslovia1_checked;
		if (document.getElementById('jet_uslovia1').checked) {
			jet_uslovia1_checked = 1;
		}else{
			jet_uslovia1_checked = 0;
		}
		const jetFormData = new FormData();
		jetFormData.append('jet_priceall', jetPriceall.toFixed(2));
		jetFormData.append('jet_parva', parseFloat(document.getElementById('jet_parva').value).toFixed(2));
		jetFormData.append('jet_vnoski', parseInt(document.getElementById('jet_vnoski').value));
		jetFormData.append('jet_product_id', document.getElementById('jet_products').value);
		jetFormData.append('jet_products', document.getElementById('jet_products').value);
		jetFormData.append('jet_products_qt', document.getElementById('jet_products_qt').value);
		jetFormData.append('jet_products_pr', document.getElementById('jet_products_pr').value);
		jetFormData.append('jet_products_vr', document.getElementById('jet_products_vr').value);
		jetFormData.append('jet_uslovia', jet_uslovia_checked);
		jetFormData.append('jet_uslovia1', jet_uslovia1_checked);
		jetFormData.append('jet_egn', document.getElementById('jet_egn').value);
		jetFormData.append('security', jet_checkout_js.nonce);
		$.ajax({
			url: jet_checkout_js.ajax_url + '?action=jet_calculate',
			method: 'POST',
			data: jetFormData,
			processData: false,
			contentType: false,
			success: function(json) {
				if (json.success === 'success') {
					if (json.jet_show_button) {
						$('#jet_panel_error').hide();
						$('#jet_panel').show();
					}else{
						$('#jet_panel').hide();
						$('#jet_panel_error').show();
					}
					document.getElementById("jet_vnoska_input").value = json.jet_vnoska;
					document.getElementById("jet_vnoska").textContent = json.jet_vnoska;
					if (document.getElementById("jet_vnoska_second") !== null) {
						document.getElementById("jet_vnoska_second").textContent = json.jet_vnoska_second;
					}
					document.getElementById("jet_priceall_input").value = json.jet_priceall;
					document.getElementById("jet_priceall").textContent = json.jet_priceall;
					if (document.getElementById("jet_priceall_second") !== null) {
						document.getElementById("jet_priceall_second").textContent = json.jet_priceall_second;
					}
					document.getElementById("jet_total_credit_price_input").value = json.jet_total_credit_price;
					document.getElementById("jet_total_credit_price").textContent = json.jet_total_credit_price;
					if (document.getElementById("jet_total_credit_price_second") !== null) {
						document.getElementById("jet_total_credit_price_second").textContent = json.jet_total_credit_price_second;
					}
					document.getElementById("jet_gpr_input").value = json.jet_gpr;
					document.getElementById("jet_gpr").textContent = json.jet_gpr;
					document.getElementById("jet_glp_input").value = json.jet_glp;
					document.getElementById("jet_glp").textContent = json.jet_glp;
					document.getElementById("jet_obshto_input").value = json.jet_obshto;
					document.getElementById("jet_obshto").textContent = json.jet_obshto;
					if (document.getElementById("jet_obshto_second") !== null) {
						document.getElementById("jet_obshto_second").textContent = json.jet_obshto_second;
					}
				}
			},
			error: function(error) {
				console.error('Error:', error);
			}
		});
	}
	
	const calculateJetCard = () => {
		const jetCardPriceall = parseFloat(document.getElementById('jet_card_price').value);
		let jet_card_uslovia_checked;
		if (document.getElementById('jet_card_uslovia').checked) {
			jet_card_uslovia_checked = 1;
		}else{
			jet_card_uslovia_checked = 0;
		}
		let jet_card_uslovia1_checked;
		if (document.getElementById('jet_card_uslovia1').checked) {
			jet_card_uslovia1_checked = 1;
		}else{
			jet_card_uslovia1_checked = 0;
		}
		const jetFormData = new FormData();
		jetFormData.append('jet_priceall', jetCardPriceall.toFixed(2));
		jetFormData.append('jet_parva', parseFloat(document.getElementById('jet_card_parva').value).toFixed(2));
		jetFormData.append('jet_vnoski', parseInt(document.getElementById('jet_card_vnoski').value));
		jetFormData.append('jet_product_id', document.getElementById('jet_card_products').value);
		jetFormData.append('jet_products', document.getElementById('jet_card_products').value);
		jetFormData.append('jet_products_qt', document.getElementById('jet_card_products_qt').value);
		jetFormData.append('jet_products_pr', document.getElementById('jet_card_products_pr').value);
		jetFormData.append('jet_products_vr', document.getElementById('jet_card_products_vr').value);
		jetFormData.append('jet_uslovia', jet_card_uslovia_checked);
		jetFormData.append('jet_uslovia1', jet_card_uslovia1_checked);
		jetFormData.append('jet_egn', document.getElementById('jet_card_egn').value);
		jetFormData.append('security', jet_checkout_js.nonce);
		$.ajax({
			url: jet_checkout_js.ajax_url + '?action=jet_calculate_cart',
			method: 'POST',
			data: jetFormData,
			processData: false,
			contentType: false,
			success: function(json) {
				if (json.success === 'success') {
					if (json.jet_show_button) {
						$('#jet_panel_card_error').hide();
						$('#jet_panel_card').show();
					}else{
						$('#jet_panel_card').hide();
						$('#jet_panel_card_error').show();
					}
					document.getElementById("jet_card_vnoska_input").value = json.jet_vnoska_card;
					document.getElementById("jet_card_vnoska").textContent = json.jet_vnoska_card;
					if (document.getElementById("jet_card_vnoska_second") !== null) {
						if (json.jet_vnoska_card_second == 0) {
							document.getElementById("jet_card_vnoska_second").textContent = json.jet_vnoska_card;
						}else{
							document.getElementById("jet_card_vnoska_second").textContent = json.jet_vnoska_card_second;
						}
					}
					document.getElementById("jet_card_priceall_input").value = json.jet_priceall;
					document.getElementById("jet_card_priceall").textContent = json.jet_priceall;
					if (document.getElementById("jet_card_priceall_second") !== null) {
						if (json.jet_priceall_second == 0) {
							document.getElementById("jet_card_priceall_second").textContent = json.jet_priceall;
						}else{
							document.getElementById("jet_card_priceall_second").textContent = json.jet_priceall_second;
						}
					}
					document.getElementById("jet_card_total_credit_price_input").value = json.jet_total_credit_price;
					document.getElementById("jet_card_total_credit_price").textContent = json.jet_total_credit_price;
					if (document.getElementById("jet_card_total_credit_price_second") !== null) {
						if (json.jet_total_credit_price_second == 0) {
							document.getElementById("jet_card_total_credit_price_second").textContent = json.jet_total_credit_price;
						}else{
							document.getElementById("jet_card_total_credit_price_second").textContent = json.jet_total_credit_price_second;
						}
					}
					document.getElementById("jet_card_gpr_input").value = json.jet_gpr_card;
					document.getElementById("jet_card_gpr").textContent = json.jet_gpr_card;
					document.getElementById("jet_card_glp_input").value = json.jet_glp_card;
					document.getElementById("jet_card_glp").textContent = json.jet_glp_card;
					document.getElementById("jet_card_obshto_input").value = json.jet_obshto_card;
					document.getElementById("jet_card_obshto").textContent = json.jet_obshto_card;
					if (document.getElementById("jet_card_obshto_second") !== null) {
						if (json.jet_obshto_card_second == 0) {
							document.getElementById("jet_card_obshto_second").textContent = json.jet_obshto_card;
						}else{
							document.getElementById("jet_card_obshto_second").textContent = json.jet_obshto_card_second;
						}
					}
				}
			},
			error: function(error) {
				console.error('Error:', error);
			}
		});
	}
	
	function handleJetEgnChange(event) {
		if (event.target.id == "jet_egn") {
			const input = event.target.value;
			const regex = /^[0-9]{0,10}$/;
			if (!regex.test(input)) {
				event.target.value = input.slice(0, -1);
			}
			calculateJet();
		}
		if (event.target.id == "jet_card_egn") {
			const input = event.target.value;
			const regex = /^[0-9]{0,10}$/;
			if (!regex.test(input)) {
				event.target.value = input.slice(0, -1);
			}
			calculateJetCard();
		}
	}
	
	document.addEventListener('input', debounce(handleJetEgnChange, 1000));
	
	document.addEventListener('blur', (event) => {
		if (event.target.id == "jet_egn") {
			calculateJet();
		}
		if (event.target.id == "jet_card_egn") {
			calculateJetCard();
		}
	});

	document.addEventListener('click', (event) => {
		if (event.target.id == 'btn_preizcisli') {
			calculateJet();
		}
		if (event.target.id == 'jet_card_btn_preizcisli') {
			calculateJetCard();
		}
		if (event.target.id == 'radio-control-wc-payment-method-options-jetpayment') {
			if (event.target.checked == true) {
				calculateJet();
			}
		}
		if (event.target.id == 'radio-control-wc-payment-method-options-jetpaymentcard') {
			if (event.target.checked == true) {
				calculateJetCard();
			}
		}
		if (event.target.id == 'jet_uslovia' || event.target.id == 'jet_uslovia1') {
			calculateJet();
		}
		if (event.target.id == 'jet_card_uslovia' || event.target.id == 'jet_card_uslovia1') {
			calculateJetCard();
		}
	});

	document.addEventListener('change', (event) => {
		if (event.target.id == 'jet_vnoski') {
			calculateJet();
		}
		if (event.target.id == 'jet_card_vnoski') {
			calculateJetCard();
		}
	});
	
	const handlePaymentMethodChange = () => {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod === 'jetpayment') {
			calculateJet();
		}
		if (selectedPaymentMethod === 'jetpaymentcard') {
			calculateJetCard();
		}
	}
	
	$('form.checkout').on('change', 'input[name="payment_method"]', function() {
		handlePaymentMethodChange();
	});

	$(document.body).on('updated_checkout', function() {
		handlePaymentMethodChange();
	});

});
