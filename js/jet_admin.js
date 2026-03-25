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

document.addEventListener("DOMContentLoaded", function() {
	document.getElementById("jet_product_id").addEventListener("input", function(event) {
		var input = event.target.value;
		var regex = /^[0-9*]+$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});
	
	document.getElementById("jet_product_meseci").addEventListener("input", function(event) {
		var input = event.target.value;
		var regex = /^[0-9_]+$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});
	
	document.getElementById("jet_product_price").addEventListener("input", function(event) {
		var input = event.target.value;
		var regex = /^[0-9]+$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});

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
						if (json['exist'] != ''){
							alert(json['exist']);
						}else{
							alert("Успешно записахте филтъра.");
						}
						window.location.reload();
					}
				})
				.catch(error => console.error('Error:', error));
			}else{
				alert("Имате непопълнени данни!");
			}
		});
		
	}
});
