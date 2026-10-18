# Manufacturing — architecture analysis and gap review

Phase 1 of the manufacturing/MRP work: what the repository already has, how
manufacturing integrates with it, and what the master specification asks for
that the system did **not** have.

## 1. The platform

| Aspect | Finding |
|---|---|
| Framework | Laravel 13.32, PHP 8.3, nwidart/laravel-modules (`Modules/<Name>`), Sanctum bearer auth, Spatie permission |
| Database | MySQL/MariaDB; tests run on a real MySQL (`vetpharma_testing`) with `RefreshDatabase` |
| Tenancy | Single `tenant_id` column on every business table; **tenant is the only scope** (there is no company/branch dimension). Warehouse visibility is a second, per-user scope (`user_warehouses`) |
| Keys | ULIDs (`HasUlids`) everywhere |
| API | `/api/v1/<resource>`, JSON, `Resource` classes, `Filterable` / `Sortable` / `Exportable` list traits (`?search`, `?sort_by`, `?export=csv|xlsx`) |
| Authorisation | **One permission per endpoint**, `{resource}.{action}`, declared on the route (`->can('boms.view')`, `->exportable('boms.export')`). `PermissionCatalogue` derives the whole list from the routes; `docs/api/permissions.md` is generated from it; a test fails if an endpoint has no permission. `Gate::before` gives `Administrator` everything. Record-level rules (tenant 404, warehouse visibility) live in controllers/requests |
| Layering | Controller → FormRequest → Service (`DB::transaction` + `lockForUpdate`) → Eloquent model; audit via `AuditLogService` (hash-chained); no repository layer |
| Settings | **No settings subsystem exists** (documented in `docs/api/admin.md`); configuration is module `config/*.php` |
| Queue/events | Database queue available, scheduler used by Recap/HR/Sales/Inventory commands. No domain-event layer yet |
| Notifications | `Core\Models\Notification` rows created directly by commands/services |

## 2. Inventory — the source of truth

* Stock lives in `inventory_batches` (`product_id`, `warehouse_id`, `batch_no`, `exp_date`, `qty_cartons`, `cost_per_carton`, `is_free_gift`, `source_po_id`). **There is no stock-movement ledger**: stock changes by decrementing/incrementing the batch row inside a transaction, and each *document* (transfer, goods receipt, recap, production order, sales order) is its own movement record.
* **Quantities are whole cartons** (`unsignedInteger`). Packaging units (`units`, `product_units`, `UnitConverter`) exist; any quantity in a smaller unit must convert to a whole number of cartons (Sales rounds a Pack line *up*; Purchasing refuses fractions). This is a platform constraint manufacturing inherits: fractional requirements are calculated exactly and **rounded up to whole cartons only when stock is issued or ordered**.
* `FefoStockService` is the single stock gate for deduction (`checkAvailability`, `deduct`: lock batches ordered by expiry, decrement). `TransferStockService` and `RecapApprovalService` decrement batches directly.
* **No reservation concept exists.** Nothing stops two flows spending the same stock between check and use other than the batch row locks.
* Valuation: batch `cost_per_carton`; `Warehouse::recalculateStockValue()` uses the product's *standard* cost; asset account `1300`.
* No lot-sizing/lead-time/planning data on `Product` (only `min_stock_cartons`, `reorder_level`, `supplier_id`). No serial numbers (batch = lot). No locations below warehouse level.

## 3. Accounting

`JournalPostingService::post(tenant, ref, description, lines[account_code, debit|credit], createdBy, date, type, reversalOf)` — balanced entries by account **code**; unknown code → exception. `ManualJournalEntryService` + reversal exist. A config map (`accounting.connections`) must list every seeded account and is enforced by `AccountConnectionsTest`, which also fails if code posts to an unseeded account. Existing manufacturing accounts: `1300` Inventory, `6300` Inventory Write-off Expense, `6400` Manufacturing Overhead Absorbed. **No WIP account and no manufacturing variance account.**

## 4. Purchasing, Sales, HR, quality

* **Purchasing**: `CreatePurchaseOrderService` (a PO with unpriced lines becomes `pending_pricing`; only an admin sets costs), receipt through `ReceivePurchaseOrderService`, supplier on the product (`products.supplier_id`). No supplier lead times.
* **Sales**: `sales_orders` (+ lines) in `draft → picking → invoiced → delivered | cancelled`; stock is deducted on first `invoiced/delivered`. Open orders (`draft`, `picking`) are the natural *demand*.
* **HR**: `Holiday` / `WorkCalendar` (company holidays), employees. Reusable for working calendars.
* **Quality**: **no Quality module exists.**

## 5. What Manufacturing was before this work

`Modules/Manufacturing`: `boms` (+`bom_lines`, integer cartons, optional flat `conversion_cost`), `production_orders` (`draft → completed | cancelled`), `production_consumptions`. One call, `POST /production-orders/{id}/complete`, consumed every component FEFO in one transaction, created the finished batch and posted `Dr 1300 / Cr 1300 / Cr 6400`. 9 feature tests. Permissions `boms.*`, `production-orders.*`.

## 6. Gap review against the master specification

| Spec area | Before | Decision |
|---|---|---|
| A. Configuration (numbering, defaults, policies, rounding, transitions) | none; no settings subsystem | **Add** `manufacturing_settings` (one row per tenant) + number sequences; endpoints `GET/PUT /manufacturing/settings` |
| B. BoM: revisions, effective dates, approval, alternatives, multi-level, cycle check, scrap %, optional lines, by-products, UoM, operations link, instructions | header + integer lines only | **Add** all of it (revisions as immutable JSON snapshots; MO keeps its own component snapshot) |
| C. MO lifecycle (confirm/plan/start/pause/complete/cancel/backorder/reopen), numbering, priority, dates, assignee, source document, history | `draft → completed/cancelled`, no number | **Add** a validated state machine, immutable history table, backorders, estimates; keep the old single-call `complete` working on top of the new engine |
| D. MRP | none | **Add** engine, persisted runs/lines/suggestions, lot sizing, idempotent re-runs, queued job, scheduler |
| E. Work centers / routings / calendars / downtimes / scheduling | none | **Add** with capacity-aware, lock-protected scheduling |
| F. Work orders / shop floor | none | **Add** states, dependencies, time logs, planned vs actual, rejected qty, operator |
| G. Inventory: reservations, partial consumption, partial receipts, by-products, scrap, traceability, idempotency | consume-all-at-once only | **Add** batch-level `stock_reservations` honoured by FEFO deduction, transfers and recap; idempotency keys; traceability endpoint. *No serials, no sub-warehouse locations* (platform has neither) — documented |
| H. Purchasing integration | none | **Add** MRP purchase suggestions → grouped draft POs through `CreatePurchaseOrderService`; open POs count as incoming supply |
| I. Sales integration | none | **Add** sales orders as MRP demand; MOs carry `source_type/source_id`; "create from sales order"; policy for demand cancelled |
| J. Costing + accounting (planned vs actual, labour/machine/overhead, scrap, by-product allocation, WIP, variance, idempotent posting) | one entry at completion | **Add** costing engine and posting service with two policies (cost-on-receipt = previous behaviour; WIP accounting), accounts `1350` WIP and `5200` Manufacturing Variance |
| K. Quality | none | **Add** a quality extension inside Manufacturing (definitions, checks, alerts, blocking). Incoming-material checks are supported as manually raised checks; Purchasing is *not* hooked |
| L. Scrap and by-products | none | **Add** |
| M. Reports | none | **Add** production, consumption, cost variance, work-centre utilisation, scrap, traceability, MRP, WIP; DB-side aggregation, pagination, export |
| N. Events / jobs | none | **Add** after-commit domain events, in-app notifications, queued MRP job, scheduled planning command |
| Permissions | `boms.*`, `production-orders.*` | **Add** per-endpoint permissions for every new route; role grants in the seeder; permission docs regenerated |
| Tests | 9 | **Add** per phase; full suite stays green |

### Constraints deliberately kept

* Whole-carton stock → exact fractional math, `ceil` at issue/order time (documented in the MRP and costing docs).
* Tenant is the only company scope; warehouse visibility is enforced like everywhere else.
* No new composer dependencies; no change to existing endpoints' contracts (new fields are additive).
* The `production_orders` legacy columns (`material_cost`, `cost_per_carton`, `conversion_cost`, `output_batch_id`, `journal_entry_id`) keep their meaning.

### Not implemented, because the platform has no such concept

* Serial numbers (only batch/lot tracking).
* Sub-warehouse stock locations (a warehouse is the location; "production" and "component" locations are the MO's warehouse).
* Forecast module (a `forecast` kind of manual MRP demand stands in).
* Company/branch scope beyond tenant.
* Incoming-material inspection triggered automatically from Purchasing receipts.
