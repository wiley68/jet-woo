const deleteSchemaJet = (id) => {
	const jetDeleteFormData = new FormData();
	jetDeleteFormData.append('jet_product_id', id);
	jetDeleteFormData.append('security', jet_admin.nonce);

	fetch(jet_admin.ajax_url + '?action=jet_removefilter', {
		method: 'POST',
		body: jetDeleteFormData
	})
		.then(response => response.json())
		.then(json => {
			if (json.success === 'success') {
				alert("Успешно изтрихте филтъра.");
				window.location.reload();
			}
		})
		.catch(error => console.error('Error:', error));
}

document.addEventListener("click", function (e) {
	if (typeof e.target.closest !== "function") {
		return;
	}
	var btn = e.target.closest(".jet_switch[data-jet-toggle-for]");
	if (!btn || !document.contains(btn)) {
		return;
	}
	var inputId = btn.getAttribute("data-jet-toggle-for");
	if (!inputId) {
		return;
	}
	var hidden = document.getElementById(inputId);
	if (!hidden || hidden.getAttribute("type") !== "hidden" || !hidden.getAttribute("name")) {
		return;
	}
	e.preventDefault();
	var nextOn = hidden.value !== "1";
	hidden.value = nextOn ? "1" : "0";
	btn.setAttribute("aria-checked", nextOn ? "true" : "false");
	btn.classList.toggle("is-on", nextOn);
	var state = btn.querySelector(".jet_switch__state");
	if (state) {
		var lOn = btn.getAttribute("data-jet-label-on") || "Включен";
		var lOff = btn.getAttribute("data-jet-label-off") || "Изключен";
		state.textContent = nextOn ? lOn : lOff;
	}
}, false);

document.addEventListener("DOMContentLoaded", function () {
	var jetProductId = document.getElementById("jet_product_id");
	if (jetProductId) {
		jetProductId.addEventListener("input", function (event) {
			var input = event.target.value;
			var regex = /^[0-9*]+$/;
			if (!regex.test(input)) {
				event.target.value = input.slice(0, -1);
			}
		});
	}

	var jetProductMeseci = document.getElementById("jet_product_meseci");
	if (jetProductMeseci) {
		jetProductMeseci.addEventListener("input", function (event) {
			var input = event.target.value;
			var regex = /^[0-9_]+$/;
			if (!regex.test(input)) {
				event.target.value = input.slice(0, -1);
			}
		});
	}

	var jetProductPrice = document.getElementById("jet_product_price");
	if (jetProductPrice) {
		jetProductPrice.addEventListener("input", function (event) {
			var input = event.target.value;
			var regex = /^[0-9]+$/;
			if (!regex.test(input)) {
				event.target.value = input.slice(0, -1);
			}
		});
	}

	const jet_btn_add = document.getElementById("jet_btn_add");
	if (jet_btn_add !== null) {

		jet_btn_add.addEventListener('click', event => {
			event.preventDefault();
			const jet_product_id = document.getElementById("jet_product_id");
			const jet_product_percent = document.querySelector("#jet_product_percent option:checked");
			const jet_product_meseci = document.getElementById("jet_product_meseci");
			const jet_product_price = document.getElementById("jet_product_price");
			const jet_product_start = document.getElementById("jet_product_start");
			const jet_product_end = document.getElementById("jet_product_end");
			if (
				jet_product_id.value != '' &&
				jet_product_meseci.value != '' &&
				jet_product_price.value != '' &&
				jet_product_start.value != '' &&
				jet_product_end.value != ''
			) {
				const jetAddFormData = new FormData();
				jetAddFormData.append('jet_product_id', jet_product_id.value);
				jetAddFormData.append('jet_product_percent', jet_product_percent.value);
				jetAddFormData.append('jet_product_meseci', jet_product_meseci.value);
				jetAddFormData.append('jet_product_price', jet_product_price.value);
				jetAddFormData.append('jet_product_start', jet_product_start.value);
				jetAddFormData.append('jet_product_end', jet_product_end.value);
				jetAddFormData.append('security', jet_admin.nonce);

				fetch(jet_admin.ajax_url + '?action=jet_addfilter', {
					method: 'POST',
					body: jetAddFormData
				})
					.then(response => response.json())
					.then(json => {
						if (json.success === 'success') {
							if (json['exist'] != '') {
								alert(json['exist']);
							} else {
								alert("Успешно записахте филтъра.");
							}
							window.location.reload();
						}
					})
					.catch(error => console.error('Error:', error));
			} else {
				alert("Имате непопълнени данни!");
			}
		});

	}
});
