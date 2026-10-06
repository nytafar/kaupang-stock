# Changelog

All notable changes to Kaupang Stock. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions: semver
(before 1.0.0 a minor bump may break). Entries up to 0.13.0 are backfilled from commit subjects.

## [Unreleased]

### Added
- Page cache: purge a product's cached page when its visible stock changes (restocks too), behind a purge-on-stock-change setting.

### Changed
- Admin styles follow the admin palette: tokens read `--ks-color-*`, then Harmonize `--hat-*`, then the old colour; the printable PO stays paper.
- Suite kit v1: version-sync pre-commit hook (`tools/pre-commit`), this CHANGELOG, standard header (GitHub Plugin URI, WC tested up to 11.1).
- Suite kit v2: `readme.txt` generated from header + README.md + CHANGELOG.md, `tools/release`.

### Fixed
- tests: admin-ui-check skips the valuation bar assertion when costing is off.
- Order COGS: a paid order that no core path calculated COGS for (bare `wc_add_order_item()` channels such as the Zettle integration) now gets it once, at ledger cost where the ledger has one; an order that already has COGS is never recalculated, and a ledger restamp changes only the lines with ledger value (other lines and refunds keep their stored cost). A line whose consumptions are only partly costed no longer understates: its uncosted quantity is priced at the line's blended costed cost. Check: `tests/cogs-fill-check.php`.

## [0.13.0] - 2026-09-15
- Admin UI pass: settings cards, shared filter bars, translatable headings; stock status filter bar with live filter and toggleable columns.
- Movements: fetch-and-swap filters, staff-only actor select, date presets, `product_ids` set filter; only staff become movement actors.
- Adjustments use reason presets instead of a mandatory free-text note. nb_NO strings and catalogs rebuilt; admin-ui-check test.

## [0.12.0] - 2026-09-14
- `Admin\Screen`, PO lock rule in the model, `Counting\Import`, SKU joins via `ProductSearch`; draft-only save-line gate, import `parse_failed` only for an unreadable/empty file.

## [0.11.0] - 2026-09-14
- Costing facade: one entry point for sweeper, verify, valuation, rebuild, opening, stash.

## [0.10.1] - 2026-09-14
- `Adjust::withCost`: one adjust+cost policy for UI and CLI.

## [0.10.0] - 2026-09-14
- Movements owns export and the created_from/to filter.

## [0.9.1] - 2026-09-14
- Consolidated base: `form=` binding fix, brreg guard, README table ownership.

## [0.9.0] - 2026-07-17
- Receiving location selector and per-location low-stock status.

## [0.8.2] - 2026-07-17
- Async PO line editing: REST add/edit/remove with in-place repaint.

## [0.8.1] - 2026-07-17
- Documented the transfer cost-rounding ceiling; blended source covered.

## [0.8.0] - 2026-07-16
- Location transfers with FIFO cost continuity.

## [0.7.0] - 2026-07-16
- Multi-location core, routing and reconciliation (schema v5).

## [0.6.0] - 2026-07-16
- Supplier catalog and printable ordering lists.

## [0.5.0] - 2026-07-12
- Lagerverdi: valuation UI, opening-cost entry, drill-down, CSV, COGS report.

## [0.4.0] - 2026-07-12
- Order-COGS stamping: øre meta + WC-native COGS from ledger truth.

## [0.3.0] - 2026-07-12
- FIFO costing core: cost layers, consumptions, product_cost cache + claim seam.

## [0.2.0] - 2026-07-12
- Suppliers: country field + BRREG-backed supplier search; mtime asset versioning.

## [0.1.2] - 2026-07-10
- PO editor keeps the Detaljer form inside its grid column; sticky line actions.

## [0.1.1] - 2026-07-10
- Admin polish: tablewrap overflow convention, async PO product search, count fixes.

## [0.1.0] - 2026-07-10
- Initial release: append-only stock movement ledger, audit UI, varetelling and innkjøp/receiving; nb_NO translations; enable-toggle 500 fixed.
