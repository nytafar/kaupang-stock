/**
 * Varetelling (stock counting) — capture + review behaviour.
 *
 * Reads the shell-localized `KaupangStock` global ({restUrl, nonce, activeMode,
 * i18n}); every fetch sends `X-WP-Nonce: KaupangStock.nonce` (suite gotcha #1 — a
 * per-user REST call without it runs logged-out). Vanilla JS, no jQuery.
 *
 * Capture: one focused scan input. A keyboard-wedge scanner emits an Enter-
 * terminated SKU; we resolve the line client-side from a per-row data-sku map,
 * INCREMENT it via REST (the scanner path — lost updates are the inFlow anti-
 * pattern), and return focus. An identical scan value within 300 ms is dropped
 * (double-read debounce). Manual per-row entry saves the absolute value. The store
 * keeps selling throughout — counting locks nothing.
 *
 * Review: apply (with ONE combined drift/uncounted confirm dialog and a single
 * "Bruk likevel"), override a recount-flagged line (note required), re-count it, and
 * an expandable "movements since count started" drill-down per line.
 */
(function () {
	'use strict';

	var KS = window.KaupangStock || {};
	var I18N = KS.i18n || {};
	var DEBOUNCE_MS = 300;

	function t(key, fallback) {
		return (I18N && I18N[key]) || fallback;
	}

	function restUrl(path) {
		var base = (KS.restUrl || '').replace(/\/$/, '');
		return base + '/' + path.replace(/^\//, '');
	}

	function api(path, method, body) {
		return fetch(restUrl(path), {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': KS.nonce || ''
			},
			body: body ? JSON.stringify(body) : undefined
		}).then(function (res) {
			return res.json().then(function (data) {
				return { ok: res.ok, status: res.status, data: data };
			}).catch(function () {
				return { ok: res.ok, status: res.status, data: null };
			});
		});
	}

	function fmt(n) {
		if (n === null || n === undefined || n === '') {
			return '';
		}
		var num = Number(n);
		if (Math.abs(num - Math.round(num)) < 1e-9) {
			return String(Math.round(num));
		}
		return String(num);
	}

	function fmtSigned(n) {
		var num = Number(n);
		if (num > 0) { return '+' + fmt(num); }
		if (num < 0) { return '−' + fmt(Math.abs(num)); }
		return '0';
	}

	/* ------------------------------ Create form --------------------------- */

	function initCreateForm() {
		var select = document.querySelector('.ks-scope-select');
		if (!select) {
			return;
		}
		var rows = {
			category: document.querySelector('.ks-scope-row--category'),
			manual: document.querySelector('.ks-scope-row--manual')
		};
		function sync() {
			var v = select.value;
			if (rows.category) { rows.category.style.display = (v === 'category') ? '' : 'none'; }
			if (rows.manual) { rows.manual.style.display = (v === 'manual') ? '' : 'none'; }
		}
		select.addEventListener('change', sync);
		sync();

		var filter = document.getElementById('ks-product-filter');
		if (filter) {
			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				var items = document.querySelectorAll('.ks-picker__item');
				for (var i = 0; i < items.length; i++) {
					var hay = items[i].getAttribute('data-search') || '';
					items[i].style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
				}
			});
		}
	}

	/* ------------------------------ Capture ------------------------------- */

	function initCapture() {
		var root = document.querySelector('.ks-capture');
		if (!root) {
			return;
		}
		var scanInput = document.getElementById('ks-scan-input');
		var statusEl = document.getElementById('ks-scan-status');
		var showExpected = document.getElementById('ks-show-expected');

		var skuMap = {};
		try {
			skuMap = JSON.parse(root.getAttribute('data-sku-map') || '{}');
		} catch (e) {
			skuMap = {};
		}

		var lastScan = { value: '', at: 0 };

		function setStatus(msg, kind) {
			if (!statusEl) { return; }
			statusEl.textContent = msg || '';
			statusEl.className = 'ks-scanbar__status' + (kind ? ' ks-scanbar__status--' + kind : '');
		}

		function rowFor(lineId) {
			return root.querySelector('tr[data-line-id="' + lineId + '"]');
		}

		function flashRow(row) {
			if (!row) { return; }
			row.classList.remove('ks-flash');
			// Force reflow so the animation restarts on rapid repeats.
			void row.offsetWidth;
			row.classList.add('ks-flash');
		}

		function applyLine(line) {
			var row = rowFor(line.id);
			if (!row) { return; }
			var input = row.querySelector('.ks-count-input');
			if (input) {
				input.value = (line.counted === null || line.counted === undefined) ? '' : fmt(line.counted);
			}
			row.classList.toggle('ks-row--recount', !!line.recount);
			flashRow(row);
		}

		function saveAbsolute(lineId, value) {
			return api('count-lines/' + lineId, 'POST', { counted: value }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					applyLine(resp.data.line);
					return true;
				}
				setStatus((resp.data && resp.data.message) || t('saveFailed', 'Could not save.'), 'error');
				return false;
			});
		}

		function incrementBySku(sku) {
			var lineId = skuMap[sku];
			if (!lineId) {
				setStatus(t('unknownSku', 'Unknown SKU: ') + sku, 'warn');
				return;
			}
			api('count-lines/' + lineId, 'POST', { increment: 1 }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					applyLine(resp.data.line);
					setStatus(resp.data.line.label + ' → ' + fmt(resp.data.line.counted), 'ok');
				} else {
					setStatus((resp.data && resp.data.message) || t('saveFailed', 'Could not save.'), 'error');
				}
			});
		}

		// Scanner: Enter terminates a scan; identical value within 300 ms dropped.
		if (scanInput) {
			scanInput.addEventListener('keydown', function (ev) {
				if (ev.key !== 'Enter') {
					return;
				}
				ev.preventDefault();
				var value = scanInput.value.trim();
				scanInput.value = '';
				scanInput.focus();
				if (value === '') {
					return;
				}
				var now = Date.now();
				if (value === lastScan.value && (now - lastScan.at) < DEBOUNCE_MS) {
					lastScan.at = now;
					return; // double-read
				}
				lastScan = { value: value, at: now };
				incrementBySku(value);
			});

			// Focus auto-returns to the scan input after a manual entry commit.
			document.addEventListener('click', function (ev) {
				if (ev.target && ev.target.closest && ev.target.closest('.ks-count-input')) {
					return; // don't steal focus mid-edit
				}
			});
		}

		// Manual per-row entry: save the absolute value on change/blur.
		var inputs = root.querySelectorAll('.ks-count-input');
		for (var i = 0; i < inputs.length; i++) {
			(function (input) {
				input.addEventListener('change', function () {
					var row = input.closest('tr');
					var lineId = row && row.getAttribute('data-line-id');
					if (!lineId) { return; }
					var v = input.value.trim();
					if (v === '') {
						return; // blank leaves the line uncounted; no destructive clear op
					}
					saveAbsolute(lineId, Number(v)).then(function () {
						if (scanInput) { scanInput.focus(); }
					});
				});
			})(inputs[i]);
		}

		// "Vis forventet": toggle the blind CSS class.
		if (showExpected) {
			showExpected.addEventListener('change', function () {
				root.classList.toggle('ks-capture--blind', !showExpected.checked);
			});
		}

		if (scanInput) {
			scanInput.focus();
		}
	}

	/* ------------------------------ Review -------------------------------- */

	function initReview() {
		var root = document.querySelector('.ks-review');
		if (!root) {
			return;
		}
		var countId = root.getAttribute('data-count-id');
		var since = root.getAttribute('data-since') || '';

		// Drill-down: "movements since count started" per line.
		root.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-drill-btn') : null;
			if (!btn) { return; }
			var row = btn.closest('tr');
			var drillRow = row && row.nextElementSibling;
			if (!drillRow || !drillRow.classList.contains('ks-drill-row')) { return; }
			var productId = row.getAttribute('data-product-id');

			var expanded = btn.getAttribute('aria-expanded') === 'true';
			if (expanded) {
				drillRow.hidden = true;
				btn.setAttribute('aria-expanded', 'false');
				return;
			}
			btn.setAttribute('aria-expanded', 'true');
			drillRow.hidden = false;
			var cell = drillRow.querySelector('.ks-drill-cell');
			cell.textContent = t('loading', 'Loading…');

			var q = 'movements?product_id=' + encodeURIComponent(productId) +
				'&since=' + encodeURIComponent(since) + '&per_page=100';
			api(q, 'GET').then(function (resp) {
				if (!resp.ok || !resp.data) {
					cell.textContent = t('loadFailed', 'Could not load movements.');
					return;
				}
				cell.innerHTML = renderMovements(resp.data.rows || []);
			});
		});

		// Override a recount-flagged line (note required).
		root.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-override-btn') : null;
			if (!btn) { return; }
			var row = btn.closest('tr');
			var lineId = row && row.getAttribute('data-line-id');
			if (!lineId) { return; }
			var note = window.prompt(t('overridePrompt', 'Accept this counted value. Enter a note (required):'));
			if (note === null) { return; }
			note = note.trim();
			if (note === '') {
				window.alert(t('noteRequired', 'A note is required.'));
				return;
			}
			api('count-lines/' + lineId + '/override', 'POST', { note: note }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					clearRecountUi(row);
					refreshApplyGate();
				} else {
					window.alert((resp.data && resp.data.message) || t('saveFailed', 'Could not save.'));
				}
			});
		});

		// Re-counting a flagged line is done by reopening the count for counting
		// (the toolbar button) and re-scanning — the review UI only offers the
		// non-destructive override here, so what was counted is never edited in
		// place.

		// Apply.
		var applyBtn = root.querySelector('.ks-apply-btn');
		if (applyBtn) {
			applyBtn.addEventListener('click', function () {
				runApply(false);
			});
		}

		function runApply(confirmed) {
			if (applyBtn) { applyBtn.disabled = true; }
			api('counts/' + countId + '/apply', 'POST', { confirmed: confirmed }).then(function (resp) {
				var data = resp.data || {};
				if (data.status === 'ok') {
					window.location.reload();
					return;
				}
				if (data.status === 'confirm_required') {
					if (showConfirmDialog(data)) {
						runApply(true);
						return;
					}
					if (applyBtn) { applyBtn.disabled = false; }
					return;
				}
				// error
				window.alert(data.message || t('applyFailed', 'Could not apply the count.'));
				if (applyBtn) { applyBtn.disabled = false; }
			});
		}

		function refreshApplyGate() {
			var stillFlagged = root.querySelectorAll('.ks-chip--recount').length;
			if (applyBtn) {
				applyBtn.disabled = stillFlagged > 0;
			}
		}

		function clearRecountUi(row) {
			var chip = row.querySelector('.ks-chip--recount');
			if (chip) { chip.parentNode.removeChild(chip); }
			var actions = row.querySelectorAll('.ks-override-btn');
			for (var i = 0; i < actions.length; i++) {
				actions[i].parentNode.removeChild(actions[i]);
			}
		}
	}

	/**
	 * ONE combined summary dialog for drift + uncounted lines with a single "Bruk
	 * likevel" confirm (§6.3) — the acknowledgment is informational; confirming
	 * re-applies with the same relative deltas.
	 */
	function showConfirmDialog(data) {
		var lines = [];
		var issues = data.issues || [];
		lines.push(data.message || '');
		lines.push('');
		for (var i = 0; i < issues.length; i++) {
			var it = issues[i];
			var counted = (it.counted === null || it.counted === undefined) ? '—' : fmt(it.counted);
			lines.push(
				'• ' + it.label +
				' (' + t('expectedShort', 'exp') + ' ' + fmt(it.expected) +
				', ' + t('onHandShort', 'on hand') + ' ' + fmt(it.on_hand) +
				', ' + t('countedShort', 'counted') + ' ' + counted +
				') [' + it.kind + ']'
			);
		}
		lines.push('');
		lines.push(t('applyAnyway', 'Bruk likevel?'));
		return window.confirm(lines.join('\n'));
	}

	function renderMovements(rows) {
		if (!rows.length) {
			return '<p class="ks-drill-empty">' + t('noMovements', 'No movements since the count started.') + '</p>';
		}
		var html = '<table class="ks-drill-table"><thead><tr>' +
			'<th>' + t('when', 'When') + '</th>' +
			'<th>' + t('change', 'Change') + '</th>' +
			'<th>' + t('reason', 'Reason') + '</th>' +
			'<th>' + t('note', 'Note') + '</th>' +
			'</tr></thead><tbody>';
		for (var i = 0; i < rows.length; i++) {
			var r = rows[i];
			var delta = Number(r.delta);
			var cls = delta > 0 ? 'ks-pos' : (delta < 0 ? 'ks-neg' : '');
			html += '<tr>' +
				'<td>' + escapeHtml(r.created_at || r.occurred_at || '') + '</td>' +
				'<td class="' + cls + '">' + escapeHtml(fmtSigned(delta)) + '</td>' +
				'<td>' + escapeHtml(r.reason || '') + '</td>' +
				'<td>' + escapeHtml(r.note || '') + '</td>' +
				'</tr>';
		}
		html += '</tbody></table>';
		return html;
	}

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = String(s === null || s === undefined ? '' : s);
		return div.innerHTML;
	}

	/* ------------------------------ Boot ---------------------------------- */

	function boot() {
		initCreateForm();
		initCapture();
		initReview();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
