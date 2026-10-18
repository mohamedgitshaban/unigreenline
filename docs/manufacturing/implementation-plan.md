# Manufacturing — implementation plan and checklist

Status legend: `[x]` done and verified by tests · `[~]` partly done · `[ ]` not started.
Tests are run with `php artisan test --compact --filter=...` (narrow) and, at the end of
each phase, the whole suite (`php artisan test --compact`, ~8 min).
Note: `--compact` prints a JSON summary and exits with code 1 even when everything passes —
read the `result`/`failed` fields, not the exit code.

## Phase 1 — Discovery
- [x] Repository + integration points analysed → [architecture-analysis.md](architecture-analysis.md)
- [x] Gap review against the specification, with decisions

## Phase 2 — Foundation (settings, numbering, permissions, schema)
- [x] Migrations: settings, number sequences, BoM 2.0, MO 2.0, components, outputs, scraps, history, idempotency, reservations, work centers/routings/work orders, quality, MRP
- [x] `ManufacturingSetting` + `NumberSequenceService`, `GET/PUT /manufacturing/settings`
- [x] Accounts `1350` WIP and `5200` Manufacturing Variance (seeder, system codes, connection map, seeder test)
- [x] Permission plumbing (`LegacyPermissionMap` actions, cost permissions follow `audit`, settings update explicit-only, role grants)

## Phase 3 — BoM and manufacturing-order core
- [x] BoM: code/version/status, approval, archive/restore, revisions (immutable snapshots), effective dates, priority/alternatives, scrap %, optional lines, unit-based lines, by-products, routing link, instructions — `BomManagementTest` (13)
- [x] Circular-dependency prevention, BoM selection, multi-level explosion
- [x] MO state machine, immutable history, component snapshot, estimates, priority, dates, assignee, source, cancel/reopen — `ProductionOrderLifecycleTest` (15)
- [x] Old `POST /production-orders/{id}/complete` runs on the new engine (the original 9 tests pass unchanged)

## Phase 4 — Inventory integration and costing/accounting
- [x] Batch-level reservations (`inventory_batches.reserved_cartons`, `stock_reservations`); FEFO deduction, transfers, write-offs, purchase returns and the availability endpoint respect them — `StockReservationTest`
- [x] Consumption (backflush / manual), partial production, receipts, by-products, rejects, scrap (+approval), backorders, idempotency keys
- [x] Traceability endpoint; costing engine; cost-on-receipt and WIP postings; variance settlement — `ProductionRecordingTest` (27)

## Phase 5 — Routing, work centers, work orders, quality
- [x] Work centers, calendars, downtimes (+ HR holidays), routings and operations, lock-protected finite-capacity scheduling, work orders, time logs, rejects, default operation duration — `RoutingAndWorkOrderTest` (16)
- [x] Quality definitions, checks, alerts, blocking — `QualityControlTest` (7)

## Phase 6 — MRP
- [x] Planning policy, manual demand/forecast, sales-order demand, multi-level netting, lot sizing, safety stock/reorder point, runs/lines/suggestions, idempotent re-runs, queued job + `mrp:plan` + nightly schedule — `MrpPlanningTest` (28), `LotSizerTest` (4)

## Phase 7 — Purchasing and sales integration
- [x] Suggestion → grouped draft purchase orders (pending pricing) and → draft manufacturing orders; `POST /production-orders/from-sales-order`; cancelled-demand policy — `SalesDemandAndNotificationsTest` (8), `MrpPlanningTest`

## Phase 8 — Reporting, events, attachments
- [x] Eight reports with filters, pagination, export, cost gating — `ManufacturingReportsTest` (10)
- [x] After-commit domain events and in-app notifications (nothing sent for rolled-back work)
- [x] Attachments on recipes, orders, work orders, routings and quality checks — `ManufacturingAttachmentTest` (5)
- [x] Deadlock retries (3 attempts) on the critical transactions

## Phase 9 — Documentation and final validation
- [x] `architecture-analysis.md` (with the acceptance-criteria outcome), `implementation-plan.md`, `database-erd.md`, `business-workflows.md`, `mrp-algorithm.md`, `costing-and-accounting.md`, `permissions.md` (generated table), `api-reference.md`, `testing.md`, `installation-and-operations.md`
- [x] `docs/api/manufacturing.md` rewritten; inventory / recap / sales / accounting pages note reservations and the new accounts; `docs/api/permissions.md` regenerated; both READMEs updated
- [x] Postman: Manufacturing folder rewritten (107 requests), collection regenerated (387 requests)
- [x] Authorization and cross-company tests for every new endpoint — `ManufacturingAuthorizationTest` (4)
- [x] Pint run on every changed file
- [x] Full suite: 881 tests, **855 passed** in the last full run; the 26 that did not were: 24 `Modules/Chat` tests (another developer's module, in progress in the same working tree — they failed with database deadlocks because that run overlapped a Chat test run), plus two expectations of this work that were then fixed (chart-of-accounts count; Auditor read-only action list). One expectation remains that this work does not own: the Auditor test lists `create`/`read` actions that come from the Chat module's permissions.

## Progress log

* Manufacturing: 146 tests across 12 files, all passing. They were last run together with the touched Inventory, Recap, Accounting, Core and docs tests.
* Test DB is shared: never run two test processes at once (a concurrent run by another session causes deadlocks and "table doesn't exist" errors).
* To regenerate the permission table in `permissions.md`, use the snippet in git history of this file's session (tinker over `PermissionCatalogue::all()` filtered to module `manufacturing` against `RolePermissionSeeder::expandedRoles()`), then `php artisan permissions:docs`.
