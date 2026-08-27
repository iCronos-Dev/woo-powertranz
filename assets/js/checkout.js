(function() {
	function formatExpiry(input) {
		var digits = input.value.replace(/\D/g, '').substring(0, 4);
		if (digits.length >= 3) {
			input.value = digits.substring(0, 2) + ' / ' + digits.substring(2);
		} else {
			input.value = digits;
		}
	}

	function bind() {
		var el = document.getElementById('woo_powertranz-card-expiry');
		if (el && !el.dataset.formatted) {
			el.dataset.formatted = '1';
			el.addEventListener('input', function() { formatExpiry(this); });
		}
	}

	bind();
	jQuery(document.body).on('updated_checkout', bind);
})();
