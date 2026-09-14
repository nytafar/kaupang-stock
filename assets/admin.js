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
 *  - filter bar: narrow table rows as you type, no reload    [data-ks-rowfilter]
 *  - filter bar: fetch the page and swap a region, no reload  [data-ks-fetchswap]
 *  - filter bar: submit on change (date pickers)              [data-ks-autosubmit]
 *  - adjust forms: preset reason; text only for "Other",      .ks-adjust-preset
 *    unit cost only for positive deltas                       .ks-adjust-cost
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
		var preset = form.querySelector('select[name="preset"]');
		var ok = preset
			? (preset.value !== '' && (preset.value !== 'other' || (note && note.value.trim() !== '')))
			: !(note && note.value.trim() === '');
		if (!ok) {
			window.alert(i18n.noteRequired || 'A note is required.');
			(preset && preset.value === '' ? preset : note).focus();
			return false;
		}
		return true;
	}

	/* --- adjust form field toggles ---------------------------------------- */
	// Text note only for "Other"; unit cost only when adding stock. Fields are
	// hidden here (not server-side) so a no-JS page still shows everything.
	function initAdjustFields() {
		var forms = document.querySelectorAll('.ks-quick-adjust, .ks-adjust-form');
		Array.prototype.forEach.call(forms, function (form) {
			var preset = form.querySelector('select[name="preset"]');
			var note = form.querySelector('input[name="note"]');
			var delta = form.querySelector('input[name="delta"]');
			var cost = form.querySelector('input[name="unit_cost"]');
			function sync() {
				if (preset && note) {
					note.hidden = preset.value !== 'other';
				}
				if (delta && cost) {
					cost.hidden = !(parseFloat(delta.value) > 0);
				}
			}
			if (preset) { preset.addEventListener('change', sync); }
			if (delta) { delta.addEventListener('input', sync); }
			sync();
		});
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

	/* --- client-side row filter (status table) ---------------------------- */
	// Rows carry data-search (lowercased title+sku), data-cat and data-low as
	// ",id,id," lists so a select value matches with a plain indexOf. The GET
	// form still works without JS; with JS, submit is swallowed.
	function initRowFilter() {
		var form = document.querySelector('form[data-ks-rowfilter]');
		var table = form && document.getElementById(form.getAttribute('data-ks-rowfilter'));
		if (!table) {
			return;
		}
		var rows = table.querySelectorAll('tbody > tr[data-search]');
		var search = form.querySelector('input[name="s"]');
		var cat = form.querySelector('select[name="product_cat"]');
		var low = form.querySelector('select[name="low_loc"]');
		var count = form.querySelector('.ks-rowfilter-count');
		var empty = table.querySelector('tbody > tr.ks-rowfilter-empty');
		if (!empty) {
			empty = document.createElement('tr');
			empty.className = 'ks-rowfilter-empty';
			empty.hidden = true;
			var td = document.createElement('td');
			td.colSpan = table.querySelectorAll('thead th').length;
			td.textContent = i18n.noMatch || 'No products match.';
			empty.appendChild(td);
			table.querySelector('tbody').appendChild(empty);
		}

		function apply() {
			var q = search ? search.value.trim().toLowerCase() : '';
			var c = cat && cat.value !== '0' ? ',' + cat.value + ',' : '';
			var l = low && low.value !== '0' ? ',' + low.value + ',' : '';
			var shown = 0;
			Array.prototype.forEach.call(rows, function (row) {
				var ok = (!q || row.getAttribute('data-search').indexOf(q) !== -1)
					&& (!c || row.getAttribute('data-cat').indexOf(c) !== -1)
					&& (!l || row.getAttribute('data-low').indexOf(l) !== -1);
				row.hidden = !ok;
				if (ok) {
					shown++;
				}
			});
			empty.hidden = shown > 0;
			if (count) {
				count.textContent = (q || c || l) ? shown + ' / ' + rows.length : '';
			}
		}

		form.addEventListener('submit', function (e) { e.preventDefault(); });
		[search, cat, low].forEach(function (el) {
			if (el) {
				el.addEventListener('input', apply);
			}
		});
		apply();
	}

	/* --- fetch-and-swap filter (movements) -------------------------------- */
	// Same GET the form would submit; the response is the full admin page, so
	// pull the matching region out of it and swap. Pagination links inside the
	// region go through the same path. URL kept in sync for reload/share.
	function initFetchSwap() {
		var form = document.querySelector('form[data-ks-fetchswap]');
		var region = form && document.getElementById(form.getAttribute('data-ks-fetchswap'));
		if (!region || !window.fetch || !window.DOMParser) {
			return;
		}
		var timer = null;
		var seq = 0;

		function load(url) {
			var mine = ++seq;
			region.setAttribute('aria-busy', 'true');
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
				.then(function (html) {
					if (mine !== seq) { return; } // a newer request superseded this one
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var fresh = doc.getElementById(region.id);
					if (fresh) {
						region.innerHTML = fresh.innerHTML;
						window.history.replaceState(null, '', url);
					}
				})
				.catch(function () { window.location.href = url; }) // fall back to a real navigation
				.then(function () { if (mine === seq) { region.removeAttribute('aria-busy'); } });
		}

		function fromForm() {
			var params = new URLSearchParams(new FormData(form));
			Array.from(params.keys()).forEach(function (k) {
				if (params.get(k) === '') { params.delete(k); }
			});
			return form.action.split('?')[0] + '?' + params.toString();
		}

		form.addEventListener('submit', function (e) { e.preventDefault(); load(fromForm()); });
		Array.prototype.forEach.call(form.elements, function (el) {
			if (el.name === '' || el.type === 'hidden' || el.type === 'submit') { return; }
			var ev = (el.type === 'search' || el.type === 'text' || el.type === 'number') ? 'input' : 'change';
			el.addEventListener(ev, function () {
				window.clearTimeout(timer);
				timer = window.setTimeout(function () { load(fromForm()); }, ev === 'input' ? 350 : 0);
			});
		});
		region.addEventListener('click', function (e) {
			var a = e.target.closest ? e.target.closest('.tablenav-pages a[href]') : null;
			if (a) { e.preventDefault(); load(a.href); }
		});
	}

	/* --- date range presets ----------------------------------------------- */
	function initDateRange() {
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
		Array.prototype.forEach.call(document.querySelectorAll('select[data-ks-daterange]'), function (sel) {
			var wrap = sel.parentNode;
			var from = wrap.querySelector('input[type="date"]');
			var to = from && from.nextElementSibling && from.nextElementSibling.nextElementSibling;
			if (!from || !to) { return; }
			sel.addEventListener('change', function () {
				var now = new Date(), a = null, b = now;
				switch (sel.value) {
					case 'today': a = now; break;
					case '7d': a = new Date(now); a.setDate(now.getDate() - 6); break;
					case '30d': a = new Date(now); a.setDate(now.getDate() - 29); break;
					case 'month': a = new Date(now.getFullYear(), now.getMonth(), 1); break;
					case 'last_month': a = new Date(now.getFullYear(), now.getMonth() - 1, 1); b = new Date(now.getFullYear(), now.getMonth(), 0); break;
					case 'year': a = new Date(now.getFullYear(), 0, 1); break;
					case 'all': b = null; break;
					default: return;
				}
				from.value = a ? iso(a) : '';
				to.value = b ? iso(b) : '';
				to.dispatchEvent(new Event('change', { bubbles: true }));
			});
			// Typing a date by hand means the preset no longer describes it.
			[from, to].forEach(function (el) { el.addEventListener('input', function () { sel.value = ''; }); });
		});
	}

	function initAutosubmit() {
		var forms = document.querySelectorAll('form[data-ks-autosubmit]');
		Array.prototype.forEach.call(forms, function (form) {
			form.addEventListener('change', function () { form.submit(); });
		});
	}

	ready(function () {
		initRowFilter();
		initFetchSwap();
		initDateRange();
		initAutosubmit();
		initAdjustFields();
		initPanels();
		initAdjustGuards();
		initReverse();
		initProductSearch();
	});
})();
