/**
 * Runs assets/counting.js against a minimal DOM stub (no dependencies) and
 * reports how "Teller i" (Count into) is chosen and remembered. Called by
 * tests/prd-04-count-smoke.php:  node count-in-harness.js <path to counting.js>
 * Prints one JSON object {check: boolean}; exits 0 (the PHP test asserts).
 */
'use strict';
var fs = require('fs');
var vm = require('vm');
var src = fs.readFileSync(process.argv[2], 'utf8');
var storage = {};
var STORE_KEY = 'kaupangStock.countLocations.kstest';

function classList() {
	var set = {};
	return {
		contains: function (c) { return !!set[c]; },
		add: function (c) { set[c] = true; },
		remove: function (c) { delete set[c]; },
		toggle: function (c, on) { if (on === undefined) { on = !set[c]; } if (on) { set[c] = true; } else { delete set[c]; } return on; }
	};
}

function node(props) {
	var listeners = {};
	var el = {
		hidden: false,
		disabled: false,
		attrs: {},
		classList: classList(),
		addEventListener: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
		fire: function (type) { (listeners[type] || []).forEach(function (fn) { fn({ target: el, preventDefault: function () {} }); }); },
		getAttribute: function (n) { return Object.prototype.hasOwnProperty.call(el.attrs, n) ? el.attrs[n] : null; },
		closest: function () { return null; },
		querySelector: function () { return null; },
		querySelectorAll: function () { return []; },
		focus: function () {}
	};
	return Object.assign(el, props || {});
}

/** One page load of a multi-location capture sheet with locations 1 and 2. */
function boot() {
	var toggles = [node({ value: '1', checked: true }), node({ value: '2', checked: true })];
	var wrap = node();
	var value = '';
	var select = node({
		options: [{ value: '' }, { value: '1' }, { value: '2' }],
		closest: function () { return wrap; }
	});
	Object.defineProperty(select, 'value', {
		get: function () { return value; },
		set: function (v) { value = select.options.some(function (o) { return o.value === String(v); }) ? String(v) : ''; }
	});
	var root = node({
		attrs: {
			'data-locations': JSON.stringify([{ id: 1, name: 'Lager' }, { id: 2, name: 'Butikk' }]),
			'data-sku-map': '{}',
			'data-count-id': '1'
		},
		querySelectorAll: function (sel) { return sel === '.ks-loc-toggle' ? toggles : []; }
	});
	root.classList.add('ks-capture');
	root.classList.add('ks-multi');
	var byId = { 'ks-count-in': select };
	var ctx = {
		document: {
			readyState: 'complete',
			querySelector: function (sel) { return sel === '.ks-capture' ? root : null; },
			getElementById: function (id) { return byId[id] || null; },
			addEventListener: function () {}
		},
		localStorage: {
			getItem: function (k) { return Object.prototype.hasOwnProperty.call(storage, k) ? storage[k] : null; },
			setItem: function (k, v) { storage[k] = String(v); }
		},
		location: { host: 'kstest', href: 'https://kstest/' },
		KaupangStock: {},
		KaupangStockCounting: { siteKey: 'kstest' },
		URL: URL,
		fetch: function () { return new Promise(function () {}); },
		confirm: function () { return true; },
		alert: function () {},
		prompt: function () { return null; }
	};
	ctx.window = ctx;
	vm.runInNewContext(src, ctx);
	return {
		select: select,
		needed: function () { return wrap.classList.contains('ks-count-in--needed'); },
		show: function (i, on) { toggles[i].checked = on; toggles[i].fire('change'); },
		pick: function (v) { select.value = v; select.fire('change'); }
	};
}

function storedCountIn() {
	var raw = storage[STORE_KEY];
	return raw ? (JSON.parse(raw).countIn || '') : '';
}

var out = {};
var p = boot();
out.fresh_unchosen = p.select.value === '' && p.needed();
p.show(1, false); // hide Butikk: one visible, chosen automatically
out.auto_selects_single = p.select.value === '1' && !p.needed();
out.auto_not_persisted = storedCountIn() === '';
p.show(1, true); // several again: the automatic choice is not kept
out.auto_dropped_when_several = p.select.value === '' && p.needed();
out.still_not_persisted = storedCountIn() === '';
p = boot(); // a later page load on the same device
out.reload_unchosen = p.select.value === '' && p.needed();
p.pick('2'); // an explicit pick
out.pick_persisted = storedCountIn() === '2';
p.show(1, false); // Butikk hidden: Lager automatic, the pick stays remembered
out.auto_keeps_pick = p.select.value === '1' && storedCountIn() === '2';
p.show(1, true);
out.pick_restored = p.select.value === '2' && !p.needed();
p = boot();
out.reload_keeps_pick = p.select.value === '2' && !p.needed();
process.stdout.write(JSON.stringify(out));
