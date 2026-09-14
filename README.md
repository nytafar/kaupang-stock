# Kaupang Stock

Ledger-backed *lagerføring* (stock management) for WooCommerce. Part of the in-house
Kaupang suite (parent theme **Ousia** + child theme **myrvann**). The plugin exposes
seams; the child theme styles them.

An append-only movement **ledger** is the source of truth for on-hand stock. WooCommerce's
`_stock`, the product meta-lookup table, our own balances cache, and (via spis-fiken)
Fiken's `product.stock` are all *projections* of that ledger — provably re-derivable and
drift-detected on a schedule, never blindly trusted.

## What it is (ledger principles)

- **The ledger is the truth; every other number is a projection.** On hand is
  `SUM(movements.delta)`; the `balances` table is a cache the write path keeps in the same
  transaction, so the two can never diverge.
- **One write path.** Every mutation the suite owns — adjustment, receipt, count apply,
  reversal — goes through `Ledger::record()`/`recordBatch()`. No second code path touches
  the tables.
- **Mirror WooCommerce on the sale path, never fight it.** Core executes sale/restock
  writes; an observer records them synchronously with full attribution, and a shutdown
  residual-absorber catches everything else (admin edits, imports, REST, third-party CRUD).
- **Movements are immutable.** Corrections are reversing entries that reference the
  original — no `UPDATE`/`DELETE` on the movements table, ever.
- **Everything is idempotent and re-derivable.** Owned writes carry an idempotency key;
  PO received-quantity and status are *derived* from movements, never stored counters that
  can drift.

v1 is **integer-only** (WooCommerce's default `woocommerce_stock_amount → intval` filter
truncates every mediated value, so a fractional delta would poison the ledger). Quantity
columns are `DECIMAL(15,3)` as headroom; fractional stock is a deferred, ready-to-lift path.

## Rollout ritual (shadow → active)

The plugin ships **inert**. Every flag defaults `false`/safe; a fresh activation records
nothing and shows only the settings screen.

1. **Activate.** `Activation` runs `dbDelta` (custom tables — first in the suite, justified
   for an append-only log) and seeds the single default location `Hovedlager`.
   Dev and staging share code by symlink but have **separate DBs** — activate on each so
   `dbDelta` runs.
2. **Enable in shadow.** *Lager → Innstillinger*: turn on `stock_enabled` with `mode = shadow`.
   Then prime the catalog: `wp kaupang-stock seed`. The observer now records every
   WooCommerce stock change, but nothing writes back to `_stock`.
3. **Prove reconciliation is clean.** Let real traffic run (≥ ~1 week on staging), then
   `wp kaupang-stock verify` — `SUM(ledger) == balances == _stock == lookup` for every
   stock-managed product, with zero *unexplained* `external` movements.
4. **Flip to active.** Set `mode = active`. Only now may owned operations (quick adjust,
   receiving, count apply, reversal) write through to WooCommerce. The observer runs
   identically in both modes.

Purge caches after flag changes: `wp --path=… nginx-helper purge-all --allow-root`.

## Admin screens (menu: "Lager", `manage_woocommerce`)

- **Lagerstatus** — one row per stock-managed product: on hand / reserved / available /
  incoming, last movement, status chips; inline quick adjust (required note).
- **Bevegelser** — the ledger. Filterable movement log (product, reason, ref, actor, via,
  date), signed colored delta, ref deep-links to the source document, CSV export.
- **Varetelling** — two-phase counting (capture → review → apply). Blind by default;
  applies **relative variance** so a sale mid-count survives the apply.
- **Innkjøp** — suppliers (brreg org-nr lookup when kaupang-brreg is active), purchase
  orders, and receiving (2-click happy path, partial/over-receipt, derived status).

Embedded: a "Lager" panel on the product edit screen and a read-only movements meta box on
the order edit screen — provenance is always one click away.

## CLI

The command is registered whether or not the plugin is enabled, so `status`/`verify` work
on any install and `seed` can prime the ledger before shadow mode is switched on.

```
wp kaupang-stock status [--format=json]
    Plugin state (enabled? mode? PO/counting flags), managed-product / balance-row /
    movement counts, last movement time, last reconcile summary.

wp kaupang-stock verify [--heal]
    Run the reconciliation invariant check. Prints a table of drifting products
    (product, sum, on_hand, _stock, lookup, has_tail) and out-of-scope rows.
    --heal repairs each issue direction-aware (unwritten owned tail → replayed into
    Woo; mismatch with no tail → compensating `external` movement), re-verifies, and
    exits 1 if anything is still drifting.

wp kaupang-stock adjust <product-id> <delta> --note=<note> [--date=<Y-m-d>]
    Record an operator adjustment. Note is mandatory. --date backdates occurred_at
    (site-local midday → UTC, capped 90 days). Refused in shadow mode — that is the
    correct behavior: owned writes require mode=active.

wp kaupang-stock export [--product=<id>] [--reason=<reason>] [--from=<Y-m-d>]
                        [--to=<Y-m-d>] [--file=<path>]
    CSV of movements (default STDOUT). Columns: id, occurred_at, created_at,
    product_id, product, delta, balance_after, reason, ref_type, ref_id, ref_line,
    batch, actor_id, via, note.

wp kaupang-stock seed
    Enable-time sweep: a balance row per stock-managed product, an `initial` movement
    where _stock ≠ 0. Idempotent.
```

## Seams (`kaupang/stock/*`)

The plugin exposes contracts; the theme (and other plugins) consume them. No hard
dependency on any other suite plugin — soft deps are `class_exists`-guarded.

| Contract | Kind | Meaning |
|---|---|---|
| `kaupang/stock/movement_recorded` | action (`Movement` DTO) | fired after commit; for site glue/exports. spis-fiken does **not** use it — it listens to core `woocommerce_product_set_stock` instead. |
| `kaupang/stock/reasons` | filter | extend the movement reason registry. |
| `kaupang/stock/allow_negative` | filter | refuse negative on-hand (default: allow, matching Woo oversell/backorder). |
| `kaupang/stock/can_manage` | filter | capability override (default `manage_woocommerce`). |

**Tables** (`{prefix}kaupang_stock_*`): read-stable after 1.0. `movements` and `balances` are
written via `Ledger` only — never `INSERT`/`UPDATE`/`DELETE` those directly. The remaining
tables are owned by their own modules, which write them directly: Counting, Purchasing,
Costing and Locations. `Kaupang\Brreg\Client` is used for supplier
org-nr lookup when kaupang-brreg is active.

The seam to **spis-fiken's** Fiken stock-push module is WooCommerce itself
(`woocommerce_product_set_stock` + `_stock`) — there is no direct edge between the two
plugins, so each ships and works standalone.

## Uninstall & data retention

Deleting the plugin removes only its options (`kaupang_stock_settings`,
`kaupang_stock_schema_version`, `kaupang_stock_last_reconcile`,
`kaupang_stock_activated_without_wc`) and unschedules the `kaupang_stock_reconcile` action.

**All `{prefix}kaupang_stock_*` tables are kept** — the ledger is an accounting record under
**Bokføringsloven §13** (the same posture as spis-fiken). To drop them by hand once
retention has lapsed (or on a throwaway dev DB):

```sql
DROP TABLE IF EXISTS
  {prefix}kaupang_stock_movements,
  {prefix}kaupang_stock_balances,
  {prefix}kaupang_stock_locations,
  {prefix}kaupang_stock_suppliers,
  {prefix}kaupang_stock_purchase_orders,
  {prefix}kaupang_stock_purchase_order_lines,
  {prefix}kaupang_stock_counts,
  {prefix}kaupang_stock_count_lines;
```

## Non-goals (v1)

Deliberately out of scope, each with a documented re-entry point in the plan:

- **Decimal / fractional stock** (sold-by-weight) — flip the `woocommerce_stock_amount`
  filter to `floatval` store-wide and lift the ledger's integer guard; columns are ready.
- **Multi-location UI** — schema is location-ready (`location_id` everywhere + a locations
  table); no UI in v1.
- **Stock valuation / COGS** — PO line unit cost is captured (`unit_cost_ore`), but no
  valuation is computed.
- **Transfers** between locations, **lot/serial/expiry** tracking, **reorder-point**
  automation, **supplier returns** as a first-class movement type (use a negative `adjust`
  + note), **supplier emails / PDF POs**.
- **Fiken purchase (kjøp) booking** from POs, and **any storefront change** — the plugin is
  admin/CLI-only.

---

*Author: Lasse Jellum / Nyta. Requires PHP 8.1, WordPress 6.7, WooCommerce 9.0 (HPOS-compatible).
English source strings with an nb_NO catalog.*
