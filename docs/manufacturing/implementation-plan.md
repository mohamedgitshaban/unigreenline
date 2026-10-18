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
- [ ] Migrations: settings, number sequences, BoM 2.0, MO 2.0, components, outputs, scraps, history, reservations, work centers/routings/work orders, quality, MRP
- [ ] `ManufacturingSettings` + `NumberSequence` services, `GET/PUT /manufacturing/settings`
- [ ] Accounts `1350` WIP and `5200` Manufacturing Variance (seeder, system codes, connection map)
- [ ] Permission plumbing (`LegacyPermissionMap` action mapping, role grants)

## Phase 3 — BoM and manufacturing-order core
- [ ] BoM: code/version/status, approval, archive, revisions (snapshots), effective dates, priority/alternatives, scrap %, optional lines, unit-based lines, by-products, routing link, instructions
- [ ] Circular-dependency prevention; BoM selection; multi-level explosion service
- [ ] MO: number, state machine (draft → confirmed → planned → in progress ⇄ paused → completed / cancelled), immutable history, component snapshot, estimates, priority, dates, assignee, source document, cancellation reason, reopen (cancelled only)
- [ ] Old `POST /production-orders/{id}/complete` still works (runs on the new engine)

## Phase 4 — Inventory integration and costing/accounting
- [ ] Batch-level `stock_reservations`; FEFO deduction, transfers and recap respect them; reserve/release with row locks
- [ ] Consumption (backflush / manual), partial production, finished receipts, by-products, rejected quantity, component scrap (+approval), backorders
- [ ] Idempotency keys on consumption / receipt / scrap / posting
- [ ] Traceability (batch ↔ MO ↔ component batches)
- [ ] Costing engine (planned vs actual: material, operations, overhead, scrap, by-product allocation, unit cost, variance)
- [ ] Posting service: cost-on-receipt and WIP policies, variance settlement, no duplicate entries

## Phase 5 — Routing, work centers, work orders
- [ ] Work centers, weekly calendars, downtimes (+ company holidays), routings and operations
- [ ] Lock-protected finite-capacity scheduling; conflict detection; reschedule
- [ ] Work orders: generation, dependencies, start/pause/resume/complete, time logs, planned vs actual, rejected qty, operator, MO progress
- [ ] Quality: definitions, checks, alerts, blocking completion

## Phase 6 — MRP
- [ ] Product planning parameters, manual demand/forecast, sales-order demand
- [ ] Multi-level netting with stock, reservations, open POs, open MOs, lead times, lot sizing, safety stock
- [ ] Persisted runs/lines/suggestions, idempotent re-runs, queued job, command + schedule
- [ ] Shortage and requirements endpoints

## Phase 7 — Purchasing and sales integration
- [ ] Suggestion → grouped purchase orders; suggestion → manufacturing order
- [ ] Sales order → manufacturing orders (make-to-order), traceability to demand, cancelled-demand policy

## Phase 8 — Reporting, events, notifications
- [ ] Reports (production, consumption, cost variance, work-center utilisation, scrap, traceability, MRP, WIP)
- [ ] After-commit domain events + notification listeners

## Phase 9 — Documentation and final validation
- [ ] `database-erd.md`, `business-workflows.md`, `api-reference.md`, `mrp-algorithm.md`, `costing-and-accounting.md`, `permissions.md`, `testing.md`, `installation-and-operations.md`
- [ ] `docs/api/manufacturing.md` + Postman + generated `permissions.md`
- [ ] Pint, full test suite, route/permission checks, diff review

## Progress log

_(updated at the end of every phase)_
