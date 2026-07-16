/**
 * Kaupang Stock — Innkjøp (purchasing) admin behaviour. Vanilla, no jQuery.
 *
 * Three progressive enhancements over the server-rendered page:
 *
 *  1. Product picker — a "Add" button next to a search result fills the add-line
 *     form's product id + label (the form still posts server-side).
 *  2. Inline line edit — "Edit" on a draft line reveals the shared edit form
 *     pre-filled from the row's data-* attributes.
 *  3. Receive flow — the receive grid POSTs to the kaupang-stock/v1 `receive`
 *     route with the wp_rest nonce. The client NEVER decides over-receipt: it
 *     sends the entered quantities, and if the server returns confirm_required it
 *     renders ONE combined dialog from the server's issue rows and re-POSTs with
 *     confirmed=true. The receive token is minted server-side at form load
 *     (data-token) and reused across the confirm round-trip, so a double-submit or
 *     the confirm resend dedupes to a single posting.
 */
(function () {
	'use strict';

	var ROOT = window.KaupangStock || {};
	var PUR = window.KaupangStockPurchasing || {};
	var I18N = PUR.i18n || {};

	function t(key, fallback) {
		return I18N[key] || fallback || key;
	}

	document.addEventListener('DOMContentLoaded', function () {
		wireProductPicker();
		wireLineEdit();
		wireReceive();
		wireSupplierBrreg();
	});

	/* ----------------------------- Product picker ---------------------------- */

	var SEARCH_DEBOUNCE = 250; // ms — mirror admin.js
	var SEARCH_MIN = 2;        // chars — mirror admin.js

	function wireProductPicker() {
		var form = document.querySelector('[data-ks-add-line]');
		if (!form) {
			return;
		}
		var idField = form.querySelector('[data-ks-add-product-id]');
		var skuField = form.querySelector('[data-ks-add-product-sku]');
		var supplierSkuField = form.querySelector('[data-ks-add-supplier-sku]');
		var supplierNameField = form.querySelector('[data-ks-add-supplier-name]');

		// Pick handler via event DELEGATION on the document so BOTH the
		// server-rendered rows and any async-fetched rows work with one binding.
		document.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-pick-product') : null;
			if (!btn) {
				return;
			}
			var id = btn.getAttribute('data-id') || '0';
			var label = btn.getAttribute('data-label') || '';
			if (idField) {
				idField.value = id;
			}
			if (skuField) {
				skuField.value = label;
				skuField.setAttribute('readonly', 'readonly');
			}
			if (supplierSkuField) {
				supplierSkuField.value = btn.getAttribute('data-supplier-sku') || '';
			}
			if (supplierNameField) {
				supplierNameField.value = btn.getAttribute('data-supplier-name') || '';
			}
			form.classList.add('ks-has-product');
			try {
				form.scrollIntoView({ behavior: 'smooth', block: 'center' });
			} catch (e) {
				form.scrollIntoView();
			}
			var qty = form.querySelector('input[name="qty"]');
			if (qty) {
				qty.focus();
				qty.select();
			}
		});

		// Typing a raw SKU/id clears any picked id so the server resolves the text.
		if (skuField) {
			skuField.addEventListener('input', function () {
				if (idField && !skuField.hasAttribute('readonly')) {
					idField.value = '0';
				}
			});
		}

		wireAsyncSearch();
	}

	/**
	 * Progressive enhancement over the GET search form: debounced REST search that
	 * repaints the results <tbody> in place. The GET form + server-rendered rows
	 * remain the no-JS fallback; on any fetch error we fall back silently.
	 */
	function wireAsyncSearch() {
		var input = document.querySelector('[data-ks-product-search]');
		var tbody = document.querySelector('[data-ks-picker-results]');
		if (!input || !tbody || !ROOT.restUrl) {
			return;
		}
		var table = tbody.closest ? tbody.closest('table') : null;
		var status = document.querySelector('[data-ks-picker-status]');
		var searchForm = input.closest ? input.closest('form') : null;
		var timer = null;
		var seq = 0;

		function setStatus(msg) {
			if (!status) {
				return;
			}
			if (msg) {
				status.textContent = msg;
				status.hidden = false;
			} else {
				status.textContent = '';
				status.hidden = true;
			}
		}

		function showTable(show) {
			if (table) {
				table.hidden = !show;
			}
		}

		function paint(results) {
			tbody.innerHTML = '';
			results.forEach(function (r) {
				var tr = document.createElement('tr');

				var tdTitle = document.createElement('td');
				tdTitle.textContent = r.title || '';
				tr.appendChild(tdTitle);

				var tdSku = document.createElement('td');
				var code = document.createElement('code');
				code.textContent = r.sku || '';
				tdSku.appendChild(code);
				tr.appendChild(tdSku);

				var tdAct = document.createElement('td');
				tdAct.className = 'ks-num';
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'button button-small ks-pick-product';
				btn.setAttribute('data-id', String(r.id));
				btn.setAttribute('data-label', r.sku ? (r.title + ' (' + r.sku + ')') : (r.title || ''));
				btn.textContent = t('add', 'Add');
				tdAct.appendChild(btn);
				tr.appendChild(tdAct);

				tbody.appendChild(tr);
			});
		}

		function run(term) {
			var mine = ++seq;
			setStatus(searchingText());
			showTable(false);
			fetch(ROOT.restUrl + 'products?term=' + encodeURIComponent(term), {
				headers: { 'X-WP-Nonce': ROOT.nonce || '' },
				credentials: 'same-origin'
			})
				.then(function (res) {
					return res.ok ? res.json() : null;
				})
				.then(function (data) {
					if (mine !== seq) {
						return; // a newer search superseded this one
					}
					var results = data && Array.isArray(data.results) ? data.results
						: (Array.isArray(data) ? data : null);
					if (!results) {
						setStatus('');
						return; // silent fallback — the GET form still works
					}
					if (results.length === 0) {
						paint([]);
						showTable(false);
						setStatus(noResultsText());
						return;
					}
					paint(results);
					showTable(true);
					setStatus('');
				})
				.catch(function () {
					if (mine !== seq) {
						return;
					}
					setStatus(''); // silent — server fallback remains
				});
		}

		input.addEventListener('input', function () {
			var term = input.value.trim();
			if (timer) {
				window.clearTimeout(timer);
			}
			if (term.length < SEARCH_MIN) {
				seq++; // invalidate any in-flight search
				setStatus('');
				showTable(false);
				return;
			}
			timer = window.setTimeout(function () {
				run(term);
			}, SEARCH_DEBOUNCE);
		});

		// Enter in the box searches asynchronously too (no page reload).
		if (searchForm) {
			searchForm.addEventListener('submit', function (ev) {
				var term = input.value.trim();
				if (term.length < SEARCH_MIN) {
					return; // let the server handle very short/empty terms
				}
				ev.preventDefault();
				if (timer) {
					window.clearTimeout(timer);
				}
				run(term);
			});
		}

		// Roving focus between the search box and result buttons (dependency-free).
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'ArrowDown') {
				var first = tbody.querySelector('.ks-pick-product');
				if (first) {
					ev.preventDefault();
					first.focus();
				}
			}
		});
		tbody.addEventListener('keydown', function (ev) {
			if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') {
				return;
			}
			var btn = ev.target.closest ? ev.target.closest('.ks-pick-product') : null;
			if (!btn) {
				return;
			}
			var buttons = Array.prototype.slice.call(tbody.querySelectorAll('.ks-pick-product'));
			var i = buttons.indexOf(btn);
			ev.preventDefault();
			if (ev.key === 'ArrowDown') {
				if (i < buttons.length - 1) {
					buttons[i + 1].focus();
				}
			} else if (i > 0) {
				buttons[i - 1].focus();
			} else {
				input.focus();
			}
		});
	}

	function searchingText() {
		return (ROOT.i18n && ROOT.i18n.searching) || 'Searching…';
	}

	function noResultsText() {
		return (ROOT.i18n && ROOT.i18n.noResults) || 'No products found.';
	}

	/* --------------------------- Supplier / BRREG ---------------------------- */

	/**
	 * The supplier form: (1) country gates the org-nr + BRREG affordances (Norway
	 * only), and (2) a BRREG typeahead searches by company name or org-nr and fills
	 * the name + org-nr fields on pick. The server-rendered lookup form remains the
	 * no-JS fallback; on any fetch error we fall back silently.
	 */
	function wireSupplierBrreg() {
		var countrySel = document.querySelector('[data-ks-supplier-country]');
		var orgRow = document.querySelector('[data-ks-orgnr-row]');
		var brregBlock = document.querySelector('[data-ks-brreg-block]');

		// Org-nr + BRREG apply to Norway only; toggle their visibility with country.
		function applyCountry() {
			var isNo = !countrySel || countrySel.value === 'NO';
			if (orgRow) {
				orgRow.hidden = !isNo;
			}
			if (brregBlock) {
				brregBlock.hidden = !isNo;
			}
		}
		if (countrySel) {
			countrySel.addEventListener('change', applyCountry);
			applyCountry();
		}

		var input = document.querySelector('[data-ks-brreg-search]');
		var results = document.querySelector('[data-ks-brreg-results]');
		var nameField = document.getElementById('ks-supp-name');
		var orgField = document.getElementById('ks-supp-org');
		if (!input || !results || !ROOT.restUrl) {
			return; // no-JS fallback form still posts to the lookup round-trip
		}
		var searchForm = input.closest ? input.closest('form') : null;
		var timer = null;
		var seq = 0;

		function clear() {
			results.innerHTML = '';
			results.hidden = true;
		}

		function pick(hit) {
			if (nameField && hit.name) {
				nameField.value = hit.name;
			}
			if (orgField) {
				orgField.value = hit.orgnr || '';
			}
			if (countrySel) {
				countrySel.value = 'NO'; // a BRREG hit is a Norwegian entity
				applyCountry();
			}
			clear();
			if (nameField) {
				nameField.focus();
			}
		}

		function paint(hits) {
			results.innerHTML = '';
			if (!hits.length) {
				var empty = document.createElement('li');
				empty.className = 'ks-brreg-empty';
				empty.textContent = t('brregNoResults', 'No companies found.');
				results.appendChild(empty);
				results.hidden = false;
				return;
			}
			hits.forEach(function (h) {
				var li = document.createElement('li');
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'ks-brreg-hit';

				var name = document.createElement('span');
				name.className = 'ks-brreg-hit-name';
				name.textContent = h.name || h.orgnr || '';
				btn.appendChild(name);

				var metaText = [h.orgnr, h.city].filter(Boolean).join(' · ');
				if (metaText) {
					var meta = document.createElement('span');
					meta.className = 'ks-brreg-hit-meta';
					meta.textContent = metaText;
					btn.appendChild(meta);
				}

				btn.addEventListener('click', function () {
					pick(h);
				});
				li.appendChild(btn);
				results.appendChild(li);
			});
			results.hidden = false;
		}

		function run(q) {
			var mine = ++seq;
			fetch(ROOT.restUrl + 'supplier-search?q=' + encodeURIComponent(q), {
				headers: { 'X-WP-Nonce': ROOT.nonce || '' },
				credentials: 'same-origin'
			})
				.then(function (res) {
					return res.ok ? res.json() : null;
				})
				.then(function (data) {
					if (mine !== seq) {
						return; // superseded
					}
					var hits = data && Array.isArray(data.results) ? data.results : null;
					if (!hits) {
						clear(); // silent — the no-JS form still works
						return;
					}
					paint(hits);
				})
				.catch(function () {
					if (mine === seq) {
						clear();
					}
				});
		}

		input.addEventListener('input', function () {
			var q = input.value.trim();
			if (timer) {
				window.clearTimeout(timer);
			}
			if (q.length < SEARCH_MIN) {
				seq++;
				clear();
				return;
			}
			timer = window.setTimeout(function () {
				run(q);
			}, SEARCH_DEBOUNCE);
		});

		if (searchForm) {
			searchForm.addEventListener('submit', function (ev) {
				var q = input.value.trim();
				if (q.length < SEARCH_MIN) {
					return; // let the server handle very short terms
				}
				ev.preventDefault();
				if (timer) {
					window.clearTimeout(timer);
				}
				run(q);
			});
		}

		// Dismiss on Escape or an outside click.
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				clear();
			}
		});
		document.addEventListener('click', function (ev) {
			if (brregBlock && ev.target !== input && !brregBlock.contains(ev.target)) {
				clear();
			}
		});
	}

	/* ------------------------------- Line edit ------------------------------- */

	function wireLineEdit() {
		var editForm = document.querySelector('[data-ks-line-edit]');
		if (!editForm) {
			return;
		}
		var lineField = editForm.querySelector('[data-ks-edit-line]');
		var qtyField = editForm.querySelector('[data-ks-edit-qty]');
		var costField = editForm.querySelector('[data-ks-edit-cost]');
		var supplierSkuField = editForm.querySelector('[data-ks-edit-supplier-sku]');
		var supplierNameField = editForm.querySelector('[data-ks-edit-supplier-name]');
		var cancelBtn = editForm.querySelector('[data-ks-edit-cancel]');

		document.querySelectorAll('.ks-edit-line').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (lineField) {
					lineField.value = btn.getAttribute('data-line') || '0';
				}
				if (qtyField) {
					qtyField.value = btn.getAttribute('data-qty') || '';
				}
				if (costField) {
					costField.value = btn.getAttribute('data-cost') || '';
				}
				if (supplierSkuField) {
					supplierSkuField.value = btn.getAttribute('data-supplier-sku') || '';
				}
				if (supplierNameField) {
					supplierNameField.value = btn.getAttribute('data-supplier-name') || '';
				}
				editForm.hidden = false;
				try {
					editForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
				} catch (e) {
					editForm.scrollIntoView();
				}
				if (qtyField) {
					qtyField.focus();
					qtyField.select();
				}
			});
		});

		if (cancelBtn) {
			cancelBtn.addEventListener('click', function () {
				editForm.hidden = true;
			});
		}
	}

	/* ------------------------------- Receive --------------------------------- */

	function wireReceive() {
		var form = document.querySelector('[data-ks-receive]');
		if (!form) {
			return;
		}
		if (form.getAttribute('data-disabled') === '1') {
			return; // shadow mode: server refuses receipts, UI is read-only
		}

		var poId = parseInt(form.getAttribute('data-po'), 10) || 0;
		var token = form.getAttribute('data-token') || '';
		var feedback = form.querySelector('.ks-receive-feedback');
		var submitBtn = form.querySelector('.ks-receive-submit');
		var occurred = form.querySelector('.ks-occurred');
		var dialog = document.querySelector('[data-ks-confirm]');

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			send(false);
		});

		function collectLines() {
			var lines = {};
			form.querySelectorAll('.ks-receive-qty').forEach(function (input) {
				var lineId = input.getAttribute('data-line');
				var qty = parseFloat(input.value);
				if (lineId && !isNaN(qty) && qty > 0) {
					lines[lineId] = qty;
				}
			});
			return lines;
		}

		// Actual unit costs for THIS delivery (kr → integer øre at the edge).
		// Only lines that also have a qty are sent; blank inputs are omitted so
		// the engine falls back to the PO line cost.
		function collectCosts(lines) {
			var costs = {};
			form.querySelectorAll('.ks-receive-cost').forEach(function (input) {
				var lineId = input.getAttribute('data-line');
				if (!lineId || !(lineId in lines) || input.value === '') {
					return;
				}
				var kr = parseFloat(String(input.value).replace(',', '.'));
				if (!isNaN(kr) && kr >= 0) {
					costs[lineId] = Math.round(kr * 100);
				}
			});
			return costs;
		}

		function setBusy(busy) {
			if (submitBtn) {
				submitBtn.disabled = busy;
			}
		}

		function say(msg, isError) {
			if (!feedback) {
				return;
			}
			feedback.textContent = msg || '';
			feedback.className = 'ks-receive-feedback' + (isError ? ' ks-error' : ' ks-ok');
		}

		function send(confirmed) {
			var lines = collectLines();
			if (Object.keys(lines).length === 0) {
				say(t('nothingToReceive', 'Enter a quantity on at least one line.'), true);
				return;
			}
			setBusy(true);
			say('', false);

			var body = {
				po_id: poId,
				token: token,
				lines: lines,
				confirmed: !!confirmed
			};
			var costs = collectCosts(lines);
			if (Object.keys(costs).length > 0) {
				body.costs = costs;
			}
			if (occurred && occurred.value) {
				// Sent as-is (site-local); the REST controller converts to UTC.
				body.occurred_at = occurred.value;
			}

			fetch(ROOT.restUrl + 'receive', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ROOT.nonce || ''
				},
				body: JSON.stringify(body)
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (out) {
					var data = out.data || {};
					// A WP_Error (e.g. 403/501) comes back as {code, message}.
					if (!out.ok && !data.status) {
						setBusy(false);
						say(data.message || t('genericError', 'Something went wrong.'), true);
						return;
					}
					handleResult(data);
				})
				.catch(function () {
					setBusy(false);
					say(t('genericError', 'Something went wrong.'), true);
				});
		}

		function handleResult(data) {
			if (data.status === 'ok') {
				say(data.message || t('received', 'Goods received.'), false);
				// Reload so derived status / received columns refresh from the ledger.
				window.setTimeout(function () {
					window.location.reload();
				}, 600);
				return;
			}
			if (data.status === 'confirm_required') {
				setBusy(false);
				openConfirm(data.issues || []);
				return;
			}
			// error
			setBusy(false);
			say(data.message || t('genericError', 'Something went wrong.'), true);
		}

		function openConfirm(issues) {
			if (!dialog) {
				// No <dialog> support / not rendered — fall back to a native confirm.
				if (window.confirm(t('confirmIntro', 'Some lines exceed the remaining quantity. Receive anyway?'))) {
					send(true);
				}
				return;
			}
			var titleEl = dialog.querySelector('[data-ks-confirm-title]');
			var introEl = dialog.querySelector('[data-ks-confirm-intro]');
			var rowsEl = dialog.querySelector('[data-ks-confirm-rows]');
			var okBtn = dialog.querySelector('[data-ks-confirm-ok]');
			var cancelBtn = dialog.querySelector('[data-ks-confirm-cancel]');

			if (titleEl) {
				titleEl.textContent = t('confirmTitle', 'Confirm over-receipt');
			}
			if (introEl) {
				introEl.textContent = t('confirmIntro', 'These lines exceed the remaining quantity.');
			}
			if (rowsEl) {
				rowsEl.innerHTML = '';
				var head = document.createElement('tr');
				head.appendChild(th('')); // label col
				head.appendChild(th(t('ordered', 'Ordered')));
				head.appendChild(th(t('alreadyGot', 'Received')));
				head.appendChild(th(t('remaining', 'Remaining')));
				head.appendChild(th(t('entered', 'Entered')));
				rowsEl.appendChild(head);

				issues.forEach(function (issue) {
					var tr = document.createElement('tr');
					tr.appendChild(td(issue.label || ('#' + issue.line_id), false));
					tr.appendChild(td(fmt(issue.ordered), true));
					tr.appendChild(td(fmt(issue.received), true));
					tr.appendChild(td(fmt(issue.remaining), true));
					tr.appendChild(td(fmt(issue.entered), true, 'ks-over'));
					rowsEl.appendChild(tr);
				});
			}
			if (okBtn) {
				okBtn.textContent = t('confirmReceive', 'Receive anyway');
				okBtn.onclick = function () {
					closeDialog(dialog);
					send(true);
				};
			}
			if (cancelBtn) {
				cancelBtn.textContent = t('cancel', 'Cancel');
				cancelBtn.onclick = function () {
					closeDialog(dialog);
				};
			}
			showDialog(dialog);
		}
	}

	/* -------------------------------- Helpers -------------------------------- */

	function th(text) {
		var el = document.createElement('th');
		el.textContent = text;
		return el;
	}

	function td(text, numeric, extraClass) {
		var el = document.createElement('td');
		el.textContent = text;
		if (numeric) {
			el.className = 'ks-num';
		}
		if (extraClass) {
			el.className = (el.className ? el.className + ' ' : '') + extraClass;
		}
		return el;
	}

	function fmt(n) {
		var num = parseFloat(n);
		if (isNaN(num)) {
			return String(n);
		}
		// Integer-only v1; show whole numbers cleanly.
		return Number.isInteger(num) ? String(num) : String(num);
	}

	function showDialog(dialog) {
		if (typeof dialog.showModal === 'function') {
			dialog.showModal();
		} else {
			dialog.setAttribute('open', 'open');
		}
	}

	function closeDialog(dialog) {
		if (typeof dialog.close === 'function' && dialog.open) {
			dialog.close();
		} else {
			dialog.removeAttribute('open');
		}
	}
})();
