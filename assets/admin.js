/**
 * Kaupang Stock — admin UI glue. Vanilla JS, no jQuery. Every handler guards for
 * a missing DOM so the script is inert on screens that don't use it.
 *
 * Handles:
 *  - collapsible panels (the "New adjustment" form)          [data-ks-target]
 *  - inline quick-adjust: block submit until a note is typed  .ks-quick-adjust
 *  - "New adjustment" form: same note guard                   .ks-adjust-form
 *  - "Reverse" row action: prompt for a required note         [data-ks-reverse]
 *  - product search field: live results into a datalist       .ks-product-input
 */
(function () {
	'use strict';

	var cfg = window.KaupangStock || {};
	var i18n = cfg.i18n || {};

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	/* --- collapsible panels ------------------------------------------------ */
	function initPanels() {
		var toggles = document.querySelectorAll('.ks-panel-toggle[data-ks-target]');
		Array.prototype.forEach.call(toggles, function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-ks-target');
				var body = id ? document.getElementById(id) : null;
				if (!body) {
					return;
				}
				var open = !body.hasAttribute('hidden');
				if (open) {
					body.setAttribute('hidden', 'hidden');
				} else {
					body.removeAttribute('hidden');
				}
				btn.setAttribute('aria-expanded', open ? 'false' : 'true');
			});
		});
	}

	/* --- note guard on adjust forms --------------------------------------- */
	function requireNote(form) {
		var note = form.querySelector('input[name="note"]');
		if (note && note.value.trim() === '') {
			window.alert(i18n.noteRequired || 'A note is required.');
			note.focus();
			return false;
		}
		return true;
	}

	function initAdjustGuards() {
		var forms = document.querySelectorAll('.ks-quick-adjust, .ks-adjust-form');
		Array.prototype.forEach.call(forms, function (form) {
			form.addEventListener('submit', function (e) {
				// Skip disabled (shadow-mode) forms — the button is disabled anyway.
				var submit = form.querySelector('[type="submit"]');
				if (submit && submit.disabled) {
					e.preventDefault();
					return;
				}
				if (!requireNote(form)) {
					e.preventDefault();
				}
			});
		});
	}

	/* --- reverse row action: prompt for a note ---------------------------- */
	function initReverse() {
		var forms = document.querySelectorAll('form[data-ks-reverse]');
		Array.prototype.forEach.call(forms, function (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var note = window.prompt(i18n.reversePrompt || 'Reverse this movement? Enter a note:');
				if (note === null) {
					return; // cancelled
				}
				if (note.trim() === '') {
					window.alert(i18n.confirmReverse || 'A note is required to reverse a movement.');
					return;
				}
				var field = form.querySelector('input[name="note"]');
				if (field) {
					field.value = note.trim();
				}
				form.submit();
			});
		});
	}

	/* --- product search field (datalist) ---------------------------------- */
	function initProductSearch() {
		var inputs = document.querySelectorAll('.ks-product-input');
		if (!inputs.length || !cfg.restUrl) {
			return;
		}
		Array.prototype.forEach.call(inputs, function (input) {
			var listId = 'ks-products-' + Math.random().toString(36).slice(2, 8);
			var list = document.createElement('datalist');
			list.id = listId;
			input.setAttribute('list', listId);
			input.parentNode.appendChild(list);

			var timer = null;
			input.addEventListener('input', function () {
				var term = input.value.trim();
				if (timer) {
					window.clearTimeout(timer);
				}
				if (term.length < 2) {
					return;
				}
				timer = window.setTimeout(function () {
					fetchProducts(term, list);
				}, 250);
			});
		});
	}

	function fetchProducts(term, list) {
		var url = cfg.restUrl + 'products?term=' + encodeURIComponent(term);
		var headers = {};
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		fetch(url, { headers: headers, credentials: 'same-origin' })
			.then(function (r) {
				return r.ok ? r.json() : null;
			})
			.then(function (data) {
				// The endpoint returns {results:[…]}; accept a bare array too, defensively.
				var items = data && Array.isArray(data.results) ? data.results
					: (Array.isArray(data) ? data : null);
				if (!items) {
					return;
				}
				list.innerHTML = '';
				items.forEach(function (item) {
					var opt = document.createElement('option');
					// Value is what lands in the field; the SKU/title label is the hint.
					opt.value = item.sku ? item.sku : String(item.id);
					opt.label = item.title + (item.sku ? ' (' + item.sku + ')' : '');
					list.appendChild(opt);
				});
			})
			.catch(function () {
				/* silent — the field still accepts a plain ID/SKU */
			});
	}

	ready(function () {
		initPanels();
		initAdjustGuards();
		initReverse();
		initProductSearch();
	});
})();
