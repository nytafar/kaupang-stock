=== Kaupang Stock ===
Contributors: lassejellum
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.13.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ledger-backed stock management for WooCommerce: append-only movement ledger, audit UI, counting and receiving. Part of the Kaupang suite.

== Description ==

Ledger-backed *lagerføring* (stock management) for WooCommerce. Part of the in-house
Kaupang suite (parent theme **Ousia** + child theme **myrvann**). The plugin exposes
seams; the child theme styles them.

An append-only movement **ledger** is the source of truth for on-hand stock. WooCommerce's
`_stock`, the product meta-lookup table, our own balances cache, and (via kaupang-fiken)
Fiken's `product.stock` are all *projections* of that ledger — provably re-derivable and
drift-detected on a schedule, never blindly trusted.

== Changelog ==

= 0.13.0 – 2026-09-15 =

* Admin UI pass: settings cards, shared filter bars, translatable headings; stock status filter bar with live filter and toggleable columns.
* Movements: fetch-and-swap filters, staff-only actor select, date presets, `product_ids` set filter; only staff become movement actors.
* Adjustments use reason presets instead of a mandatory free-text note. nb_NO strings and catalogs rebuilt; admin-ui-check test.

= 0.12.0 – 2026-09-14 =

* `Admin\Screen`, PO lock rule in the model, `Counting\Import`, SKU joins via `ProductSearch`; draft-only save-line gate, import `parse_failed` only for an unreadable/empty file.

= 0.11.0 – 2026-09-14 =

* Costing facade: one entry point for sweeper, verify, valuation, rebuild, opening, stash.

= 0.10.1 – 2026-09-14 =

* `Adjust::withCost`: one adjust+cost policy for UI and CLI.

= 0.10.0 – 2026-09-14 =

* Movements owns export and the created_from/to filter.

= 0.9.1 – 2026-09-14 =

* Consolidated base: `form=` binding fix, brreg guard, README table ownership.

= 0.9.0 – 2026-07-17 =

* Receiving location selector and per-location low-stock status.

= 0.8.2 – 2026-07-17 =

* Async PO line editing: REST add/edit/remove with in-place repaint.

= 0.8.1 – 2026-07-17 =

* Documented the transfer cost-rounding ceiling; blended source covered.

= 0.8.0 – 2026-07-16 =

* Location transfers with FIFO cost continuity.

= 0.7.0 – 2026-07-16 =

* Multi-location core, routing and reconciliation (schema v5).

= 0.6.0 – 2026-07-16 =

* Supplier catalog and printable ordering lists.

= 0.5.0 – 2026-07-12 =

* Lagerverdi: valuation UI, opening-cost entry, drill-down, CSV, COGS report.

= 0.4.0 – 2026-07-12 =

* Order-COGS stamping: øre meta + WC-native COGS from ledger truth.

= 0.3.0 – 2026-07-12 =

* FIFO costing core: cost layers, consumptions, product_cost cache + claim seam.

= 0.2.0 – 2026-07-12 =

* Suppliers: country field + BRREG-backed supplier search; mtime asset versioning.

= 0.1.2 – 2026-07-10 =

* PO editor keeps the Detaljer form inside its grid column; sticky line actions.

= 0.1.1 – 2026-07-10 =

* Admin polish: tablewrap overflow convention, async PO product search, count fixes.

= 0.1.0 – 2026-07-10 =

* Initial release: append-only stock movement ledger, audit UI, varetelling and innkjøp/receiving; nb_NO translations; enable-toggle 500 fixed.
