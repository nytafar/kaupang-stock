=== Kaupang Stock ===
Contributors: lassejellum
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.15.0
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

= 0.15.0 – 2026-10-27 =

**Added**
* Stock counting per location (PRD-04 §2): a count covers every active location, one line per product × location with `expected` = that location's on-hand (0 where the product has never been). Apply stays one `Ledger::recordBatch()` with the same `count_line:{id}:apply` keys, each intent on its line's location; drift is checked per location. In a multi-location count a location with no counted line is one "not counted" issue, and the apply response carries a per-location summary. Counted lines on a location deactivated since the count was created refuse the apply with a message, before the ledger. A one-location count behaves as before.
* Schema v7: `count_lines.location_id` and a `(count_id, product_id, location_id)` unique key. Existing lines (open counts included) backfill to the default location; the old `count_product` key is dropped explicitly (dbDelta keeps keys it does not know). Runs on the next wp-admin load, idempotent.
* REST count-line payload: `location_id`, `location`.
* Varetelling screens per location: capture and review show one column group per location (Forventet / Counted, and Forventet / Counted / Differanse with recount and override per location), display-only location toggles remembered per device and site (localStorage), and a "Count into" picker that the scanner increments (automatic with one location visible). "Movements since" lists the location of each movement. The apply confirmation summarises per location. One-location counts render the v1 markup.
* Count CSV per location: export `sku, product, location, location_id, counted, [expected], product_id`, only the visible locations; import matches product + location (`location_id`, else the name) and rejects a row without a location in a multi-location count. Rejected import rows are now listed after the upload (they were dropped silently). One-location counts keep the v1 CSV, location columns optional.
* Counting JS strings are translated (nb_NO) through `KaupangStockCounting`.
* `tests/prd-04-count-smoke.php`: per-location create/apply/re-apply, store-only count, deactivated location, CSV round trip and rejection, v7 migration on a scratch table, one-location parity.

**Changed**
* `Admin\CountSheet` holds the capture/review sheets (split from `CountsPage`, which was past 600 lines).
* Loading (stock#13, FE-03, FE-07): boot only adds hooks with class-string handlers; a guest page view loads the main file and `Plugin` (was 11 files). Woo stock/order handlers, the COGS bridge and the page-cache purge stay registered in every context and check their flags when they run; the shutdown absorber arms on the first dirty product; REST routes are registered from `Plugin` (the controller loads on dispatch and refuses while the ledger is off).
* The daily reconcile is scheduled from Action Scheduler's `action_scheduler_ensure_recurring_actions`, after settings writes and on activation (unique), no longer on public `init`; a leftover action on a disabled install records nothing.
* `kaupang_stock_settings` and `kaupang_stock_schema_version` are no longer autoloaded (schema v6 migrates existing rows on the next wp-admin load; every write is explicit).
* Admin screens boot only on wp-admin pages, not on admin-ajax.
* `tools/check.php`: loading check (5A, autoload, every Woo event reaching its handler, reconcile scheduling, REST, admin assets on a foreign screen).

= 0.14.0 – 2026-10-06 =

**Added**
* Page cache: purge a product's cached page when its visible stock changes (restocks too), behind a purge-on-stock-change setting.

**Changed**
* Admin styles follow the admin palette: tokens read `--ks-color-*`, then Harmonize `--hat-*`, then the old colour; the printable PO stays paper.
* Suite kit v1: version-sync pre-commit hook (`tools/pre-commit`), this CHANGELOG, standard header (GitHub Plugin URI, WC tested up to 11.1).
* Suite kit v2: `readme.txt` generated from header + README.md + CHANGELOG.md, `tools/release`.

**Fixed**
* tests: admin-ui-check skips the valuation bar assertion when costing is off.
* Order COGS: a paid order that no core path calculated COGS for (bare `wc_add_order_item()` channels such as the Zettle integration) now gets it once, at ledger cost where the ledger has one; an order that already has COGS is never recalculated, and a ledger restamp changes only the lines with ledger value (other lines and refunds keep their stored cost). A line whose consumptions are only partly costed no longer understates: its uncosted quantity is priced at the line's blended costed cost. Check: `tests/cogs-fill-check.php`.

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
