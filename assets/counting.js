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
 *
 * Multi-location counts (root has .ks-multi, PRD-04 §2): one column group per
 * location. The location toggles are display-only and remembered per device and
 * site (localStorage); a hidden location's lines simply stay uncounted. "Teller i"
 * picks which visible location a scan increments: automatic when one is visible;
 * with several visible it starts unchosen on a device that never picked, and a
 * scan is refused (not recorded) until it is chosen. The pick is remembered with
 * the toggles. A line goes back to uncounted by emptying its field (capture) or
 * "Mark uncounted" (review); the location heading clears a whole location. The
 * CSV export link carries the visible locations. One-location counts have none
 * of this and behave as before.
 */
(function () {
	'use strict';

	var KS = window.KaupangStock || {};
	var KSC = window.KaupangStockCounting || {};
	var I18N = Object.assign({}, KS.i18n || {}, KSC.i18n || {});
	var DEBOUNCE_MS = 300;
	var STORE_KEY = 'kaupangStock.countLocations.' + (KSC.siteKey || window.location.host);

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

	/* ------------------------------ Locations ----------------------------- */

	function readLocations(root) {
		try {
			return JSON.parse(root.getAttribute('data-locations') || '[]') || [];
		} catch (e) {
			return [];
		}
	}

	function loadPrefs() {
		try {
			var raw = window.localStorage.getItem(STORE_KEY);
			var prefs = raw ? JSON.parse(raw) : {};
			return {
				hidden: Array.isArray(prefs.hidden) ? prefs.hidden.map(String) : [],
				countIn: prefs.countIn ? String(prefs.countIn) : ''
			};
		} catch (e) {
			return { hidden: [], countIn: '' };
		}
	}

	function savePrefs(prefs) {
		try {
			window.localStorage.setItem(STORE_KEY, JSON.stringify(prefs));
		} catch (e) { /* private mode: the toggles still work for this page */ }
	}

	/**
	 * Location visibility + "Teller i" for a multi-location sheet. Returns
	 * {multi, locations, countIn(), name(id)}; for a one-location count it only
	 * reports that single location.
	 */
	function initLocations(root, onChange) {
		var locations = readLocations(root);
		var names = {};
		for (var i = 0; i < locations.length; i++) {
			names[String(locations[i].id)] = locations[i].name;
		}
		var multi = root.classList.contains('ks-multi') && locations.length > 1;
		var api = {
			multi: multi,
			locations: locations,
			name: function (id) { return names[String(id)] || ''; },
			countIn: function () { return locations.length ? String(locations[0].id) : ''; }
		};
		if (!multi) {
			return api;
		}

		var prefs = loadPrefs();
		var toggles = root.querySelectorAll('.ks-loc-toggle');
		var select = document.getElementById('ks-count-in');

		function visibleIds() {
			var ids = [];
			for (var i = 0; i < toggles.length; i++) {
				if (toggles[i].checked) { ids.push(toggles[i].value); }
			}
			return ids;
		}

		// Restore the device's choice; never leave this count with nothing shown.
		for (var t = 0; t < toggles.length; t++) {
			toggles[t].checked = prefs.hidden.indexOf(toggles[t].value) === -1;
		}
		if (!visibleIds().length) {
			for (var u = 0; u < toggles.length; u++) { toggles[u].checked = true; }
		}

		function apply() {
			var visible = visibleIds();
			var cells = root.querySelectorAll('[data-loc]');
			for (var i = 0; i < cells.length; i++) {
				cells[i].hidden = visible.indexOf(cells[i].getAttribute('data-loc')) === -1;
			}
			if (select) {
				for (var o = 0; o < select.options.length; o++) {
					var opt = select.options[o];
					if (opt.value === '') {
						continue; // the "Choose location…" placeholder
					}
					opt.hidden = opt.disabled = visible.indexOf(opt.value) === -1;
				}
				if (visible.length < 2) {
					// One visible location counts automatically.
					select.value = visible[0] || '';
				} else if (visible.indexOf(select.value) === -1) {
					// Several visible: this device's pick, or nothing — never a
					// silent default that sends scans to the wrong location.
					select.value = visible.indexOf(prefs.countIn) !== -1 ? prefs.countIn : '';
				}
				select.disabled = visible.length < 2;
				syncNeeded();
			}
			var exportBtn = root.querySelector('.ks-export-btn');
			if (exportBtn) {
				var url = new URL(exportBtn.href, window.location.href);
				url.searchParams.set('locations', visible.join(','));
				exportBtn.href = url.toString();
			}
			if (onChange) { onChange(visible); }
		}

		// Several locations visible and no "Teller i" yet: mark the picker.
		function syncNeeded() {
			if (!select) { return; }
			var needed = !select.disabled && select.value === '';
			var wrap = select.closest('.ks-count-in');
			if (wrap) { wrap.classList.toggle('ks-count-in--needed', needed); }
		}

		function persist() {
			var hidden = prefs.hidden.slice();
			for (var i = 0; i < toggles.length; i++) {
				var id = toggles[i].value;
				var at = hidden.indexOf(id);
				if (toggles[i].checked && at !== -1) { hidden.splice(at, 1); }
				if (!toggles[i].checked && at === -1) { hidden.push(id); }
			}
			prefs.hidden = hidden;
			if (select && select.value) { prefs.countIn = select.value; }
			savePrefs(prefs);
		}

		for (var k = 0; k < toggles.length; k++) {
			toggles[k].addEventListener('change', function (ev) {
				if (!visibleIds().length) {
					ev.target.checked = true; // at least one location stays visible
					return;
				}
				apply();
				persist();
			});
		}
		if (select) {
			if (prefs.countIn) { select.value = prefs.countIn; }
			select.addEventListener('change', function () {
				persist();
				syncNeeded();
				var scan = document.getElementById('ks-scan-input');
				if (scan) { scan.focus(); }
			});
		}
		apply();

		api.countIn = function () {
			return select ? select.value : (visibleIds()[0] || '');
		};
		api.focusCountIn = function () {
			if (select && !select.disabled) { select.focus(); }
		};
		return api;
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

		// Blind mode hides every Forventet column, so each location header spans
		// one column instead of two.
		function syncSpans() {
			var blind = root.classList.contains('ks-capture--blind');
			var heads = root.querySelectorAll('th.ks-loc-head[data-span]');
			for (var i = 0; i < heads.length; i++) {
				heads[i].colSpan = blind ? 1 : Number(heads[i].getAttribute('data-span'));
			}
		}
		var locs = initLocations(root);

		function setStatus(msg, kind) {
			if (!statusEl) { return; }
			statusEl.textContent = msg || '';
			statusEl.className = 'ks-scanbar__status' + (kind ? ' ks-scanbar__status--' + kind : '');
		}

		function inputFor(lineId) {
			return root.querySelector('.ks-count-input[data-line-id="' + lineId + '"]');
		}

		function flashRow(row) {
			if (!row) { return; }
			row.classList.remove('ks-flash');
			// Force reflow so the animation restarts on rapid repeats.
			void row.offsetWidth;
			row.classList.add('ks-flash');
		}

		function applyLine(line) {
			var input = inputFor(line.id);
			var row = input && input.closest('tr');
			if (!row) { return; }
			input.value = (line.counted === null || line.counted === undefined) ? '' : fmt(line.counted);
			if (locs.multi) {
				input.closest('td').classList.toggle('ks-cell--recount', !!line.recount);
			} else {
				row.classList.toggle('ks-row--recount', !!line.recount);
			}
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

		function clearLine(lineId) {
			api('count-lines/' + lineId, 'POST', { clear: true }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					applyLine(resp.data.line);
					setStatus(resp.data.line.label + ' · ' + (resp.data.line.location || '') + ': ' + t('cleared', 'Marked uncounted.'), 'ok');
				} else {
					setStatus((resp.data && resp.data.message) || t('saveFailed', 'Could not save.'), 'error');
				}
				if (scanInput) { scanInput.focus(); }
			});
		}

		function incrementBySku(sku) {
			// skuMap: SKU → {location id → line id}; a scan lands on "Teller i".
			var byLoc = skuMap[sku];
			if (!byLoc) {
				setStatus(t('unknownSku', 'Unknown SKU: ') + sku, 'warn');
				return;
			}
			var target = locs.countIn();
			if (locs.multi && !target) {
				setStatus(t('pickCountIn', 'Scan not recorded: choose where you are counting (Count into) first.'), 'error');
				locs.focusCountIn();
				return;
			}
			var lineId = byLoc[target];
			if (!lineId) {
				setStatus(t('noLine', 'No line for this location: ') + sku, 'warn');
				return;
			}
			api('count-lines/' + lineId, 'POST', { increment: 1 }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					applyLine(resp.data.line);
					var where = locs.multi ? ' · ' + (resp.data.line.location || locs.name(resp.data.line.location_id)) : '';
					setStatus(resp.data.line.label + where + ' → ' + fmt(resp.data.line.counted), 'ok');
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
					var lineId = input.getAttribute('data-line-id') || (row && row.getAttribute('data-line-id'));
					if (!lineId) { return; }
					var v = input.value.trim();
					if (v === '') {
						// One location: blank is ignored, as before. Several: emptying
						// the field sets the line back to uncounted (a mis-scan into
						// the wrong location is undone here).
						if (locs.multi) {
							clearLine(lineId);
						}
						return;
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
				syncSpans();
			});
		}
		syncSpans();
		bindClearLocation(root, function (msg, kind) { setStatus(msg, kind); });

		if (scanInput) {
			scanInput.focus();
		}
		if (locs.multi && !locs.countIn()) {
			setStatus(t('chooseCountIn', 'Several locations are shown: choose where you are counting (Count into) before scanning.'), 'warn');
		}
	}

	/**
	 * The location heading's "Mark uncounted": every counted line of that
	 * location back to uncounted (confirm first), then reload the sheet.
	 */
	function bindClearLocation(root, report) {
		var countId = root.getAttribute('data-count-id');
		root.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-clear-loc-btn') : null;
			if (!btn) { return; }
			ev.preventDefault();
			var name = btn.getAttribute('data-loc-name') || '';
			if (!window.confirm(t('clearLocation', 'Mark every counted line at %1$s as uncounted?').replace('%1$s', name))) {
				return;
			}
			btn.disabled = true;
			api('counts/' + countId + '/clear', 'POST', { location_id: Number(btn.getAttribute('data-loc-id')) }).then(function (resp) {
				if (resp.ok && resp.data) {
					window.location.reload();
					return;
				}
				btn.disabled = false;
				var msg = (resp.data && resp.data.message) || t('saveFailed', 'Could not save.');
				if (report) { report(msg, 'error'); } else { window.alert(msg); }
			});
		});
	}

	/* ------------------------------ Review -------------------------------- */

	function initReview() {
		var root = document.querySelector('.ks-review');
		if (!root) {
			return;
		}
		var countId = root.getAttribute('data-count-id');
		var since = root.getAttribute('data-since') || '';
		var locs = initLocations(root);

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
				cell.innerHTML = renderMovements(resp.data.rows || [], locs.multi ? locs : null);
			});
		});

		// Override a recount-flagged line (note required).
		root.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-override-btn') : null;
			if (!btn) { return; }
			// The line is the row (one location) or the location's cell (several).
			var row = btn.closest('[data-line-id]');
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

		// Mark one counted line (a location's cell) uncounted, then reload so the
		// counters, chips and apply gate are recomputed server-side.
		root.addEventListener('click', function (ev) {
			var btn = ev.target.closest ? ev.target.closest('.ks-clear-btn') : null;
			if (!btn) { return; }
			var cell = btn.closest('[data-line-id]');
			var lineId = cell && cell.getAttribute('data-line-id');
			if (!lineId || !window.confirm(t('clearLine', 'Mark this line as uncounted?'))) { return; }
			btn.disabled = true;
			api('count-lines/' + lineId, 'POST', { clear: true }).then(function (resp) {
				if (resp.ok && resp.data && resp.data.line) {
					window.location.reload();
					return;
				}
				btn.disabled = false;
				window.alert((resp.data && resp.data.message) || t('saveFailed', 'Could not save.'));
			});
		});
		bindClearLocation(root, null);

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
	function issueLine(it) {
		var counted = (it.counted === null || it.counted === undefined) ? '—' : fmt(it.counted);
		return '• ' + it.label +
			' (' + t('expectedShort', 'exp') + ' ' + fmt(it.expected) +
			', ' + t('onHandShort', 'on hand') + ' ' + fmt(it.on_hand) +
			', ' + t('countedShort', 'counted') + ' ' + counted +
			') [' + it.kind + ']';
	}

	function showConfirmDialog(data) {
		var lines = [];
		var issues = data.issues || [];
		lines.push(data.message || '');
		lines.push('');
		if (data.multi && data.locations) {
			// Per location: a location with nothing counted is ONE row; one with
			// counted lines lists its drift/uncounted lines as before.
			for (var l = 0; l < data.locations.length; l++) {
				var loc = data.locations[l];
				var own = issues.filter(function (it) { return String(it.location_id) === String(loc.location_id); });
				if (loc.counted === 0) {
					lines.push(loc.location + ': ' + t('notCounted', 'not counted — no change'));
					continue;
				}
				lines.push(loc.location + ': ' + t('locationSummary', '%1$s of %2$s counted, %3$s change(s)')
					.replace('%1$s', loc.counted).replace('%2$s', loc.lines).replace('%3$s', loc.movements));
				for (var j = 0; j < own.length; j++) {
					lines.push('  ' + issueLine(own[j]));
				}
			}
		} else {
			for (var i = 0; i < issues.length; i++) {
				lines.push(issueLine(issues[i]));
			}
		}
		lines.push('');
		lines.push(t('applyAnyway', 'Bruk likevel?'));
		return window.confirm(lines.join('\n'));
	}

	function renderMovements(rows, locs) {
		if (!rows.length) {
			return '<p class="ks-drill-empty">' + t('noMovements', 'No movements since the count started.') + '</p>';
		}
		// Wrap in .ks-tablewrap so the drill table's min-width scrolls inside the
		// colspan cell instead of pushing the page.
		var html = '<div class="ks-tablewrap"><table class="ks-drill-table"><thead><tr>' +
			'<th>' + t('when', 'When') + '</th>' +
			(locs ? '<th>' + t('location', 'Location') + '</th>' : '') +
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
				(locs ? '<td>' + escapeHtml(locs.name(r.location_id) || ('#' + r.location_id)) + '</td>' : '') +
				'<td class="' + cls + '">' + escapeHtml(fmtSigned(delta)) + '</td>' +
				'<td>' + escapeHtml(r.reason || '') + '</td>' +
				'<td>' + escapeHtml(r.note || '') + '</td>' +
				'</tr>';
		}
		html += '</tbody></table></div>';
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
