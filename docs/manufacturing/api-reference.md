# Manufacturing — API reference (index)

The complete reference — every endpoint with its request, response, status codes, permission and validation rules — is
**[docs/api/manufacturing.md](../api/manufacturing.md)** (also served at `/docs/manufacturing`), and the Postman collection
(`docs/postman/VetPharma-ERP.postman_collection.json`, folder *Manufacturing*) has a ready request for each. This page is the index and the
rules the reports use to calculate their numbers.

URL prefix `/api/v1`, bearer token, JSON. The illustrative paths in the original specification (`/api/manufacturing/boms`, …) map to this
project's conventions as shown below.

## Endpoint index

| Area | Endpoints |
|---|---|
| Settings | `GET/PUT manufacturing/settings` |
| Bills of materials | `GET/POST boms` · `GET/PUT/DELETE boms/{id}` · `POST boms/{id}/approve` (activate) · `…/archive` · `…/restore` · `GET boms/{id}/components` · `GET/POST boms/{id}/revisions` · `GET boms/{id}/revisions/{revision}` |
| Production orders | `GET/POST production-orders` · `GET/PUT/DELETE production-orders/{id}` · `POST production-orders/from-sales-order` · `POST …/{id}/confirm` `plan` `reserve-materials` `release-materials` `start` `pause` `resume` `record-production` `consume-materials` `record-scrap` `complete` `cancel` `reopen` `create-backorder` · `GET …/{id}/costs` `traceability` `history` |
| Scrap | `GET production-scraps` · `GET production-scraps/{id}` · `POST …/{id}/approve` `reject` |
| Work centers | `GET/POST work-centers` · `GET/PUT/DELETE work-centers/{id}` · `PUT …/{id}/calendar` · `GET …/{id}/schedule` · `GET/POST …/{id}/downtimes` · `DELETE …/{id}/downtimes/{downtime}` |
| Routings | `GET/POST routings` · `GET/PUT/DELETE routings/{id}` · `POST routings/{id}/operations` · `PUT/DELETE routings/{id}/operations/{operation}` |
| Work orders | `GET work-orders` · `GET work-orders/{id}` · `POST …/{id}/start` `pause` `resume` `complete` `reschedule` `assign` |
| Quality | `GET/POST quality-check-definitions` · `GET/PUT/DELETE …/{id}` · `GET/POST quality-checks` · `GET quality-checks/{id}` · `POST …/{id}/record` · `GET quality-alerts` · `POST quality-alerts/{id}/resolve` |
| Planning | `GET/POST product-planning` · `GET/PUT/DELETE product-planning/{id}` · `GET/POST mrp-demands` · `GET/PUT/DELETE mrp-demands/{id}` · `POST mrp/plan` · `GET mrp/runs` `mrp/runs/{id}` `mrp/requirements` `mrp/shortages` `mrp/suggestions` `mrp/suggestions/{id}` · `POST mrp/suggestions/{id}/convert` `…/dismiss` · `POST mrp/suggestions/convert-purchases` |
| Attachments | `GET/POST manufacturing/attachments` · `GET manufacturing/attachments/{id}/download` · `DELETE manufacturing/attachments/{id}` |
| Reports | `GET reports/manufacturing/` `production` `material-consumption` `scrap` `work-center-utilization` `lead-times` `traceability` `cost-variance` `wip-valuation` |

| Specification path | This project |
|---|---|
| `POST …/boms/{id}/activate` | `POST boms/{id}/approve` |
| `POST …/orders/{id}/release` | not a separate step: `start` releases the first operations, and reservation is `reserve-materials` |
| `GET …/mrp/requirements`, `…/shortages` | `GET mrp/requirements`, `mrp/shortages` |
| `PATCH` | the project uses `PUT` for partial updates throughout |

## Responses and errors

* Lists: `{ "data": [...], "links": {...}, "meta": { "current_page", "last_page", "per_page", "total" } }` (resource collections) or `{ "data", "meta" }` (report and planning lists).
* Validation: **422** `{ "message", "errors": { "field": ["…"] } }`. Business rules: **422** `{ "message", … }` with details where useful. Permission: **403**. Another company's record, or a missing one: **404**. In use: **409**.
* Internal errors never reach the client with SQL or stack traces; planning failures are stored on the run with a safe message and logged in full.

## Report calculation rules

All reports are database aggregations filtered by `from` / `to` (dates, inclusive) and the filters listed with them. Company scope is always applied.

| Report | Date basis | Calculation |
|---|---|---|
| `production` | order `production_date`; cancelled orders excluded unless `status=cancelled` | per group: orders; `planned_qty` = Σ `output_qty_cartons`; `produced_qty` = Σ `produced_qty_cartons`; `rejected_qty` = Σ `rejected_qty_cartons`; `completion_pct` = produced ÷ planned; `yield_pct` = produced ÷ (produced + rejected). Reconcile with stock: Σ produced = cartons received into batches by `production_outputs` of kind `finished` |
| `material-consumption` | `production_consumptions.created_at` | per component: `used_qty` = Σ cartons of kind `consume`; `scrap_qty` = Σ of kind `scrap`; values (cost permission) = Σ `line_value` — the value of the batches at their own cost, which is what the ledger credited to inventory |
| `scrap` | `production_scraps.created_at`, approved only | by kind / reason / product: records, cartons, cost (cost permission). `rejection_rate_pct` = rejected ÷ (produced + rejected) over the orders of the period |
| `work-center-utilization` | scheduled by `scheduled_start`; worked by `actual_end` | `available_minutes` = the center's shifts less holidays and downtime in the period × capacity; `scheduled_minutes` = Σ planned minutes of non-cancelled operations starting in the period; `actual_minutes` = Σ logged minutes of operations completed in the period; `schedule_load_pct` = scheduled ÷ available; `utilization_pct` = actual ÷ available; `duration_variance_minutes` = actual − planned of the completed ones |
| `lead-times` | `completed_at` | per completed order: `lead_minutes` = actual finish − actual start; `delay_minutes` = actual finish − planned finish (negative = early); summary: average lead time, `on_time_pct` = orders finished by their planned finish ÷ orders with a plan |
| `traceability` | – | for a `batch_no`: orders that made it (with the material batches they used) and orders that used it (with the batches they made) |
| `cost-variance` | order `completed_at` | `planned` = (planned material + operation + overhead) × (produced + rejected) ÷ planned output; `actual` = material cost + actual operation + actual overhead; `variance` = actual − planned; `unit_cost` = capitalized ÷ produced. The sum of `variance_written_off` of closed orders equals the debit balance of account 5200 for manufacturing |
| `wip-valuation` | now | per open order booking through 1350: material issued + operation cost + overhead applied − carried to stock; `ledger_balance_1350` is the account's balance to compare with the total |

Reconciling with inventory and accounting:

* **Stock:** every carton issued is a `production_consumptions` row and a decrement of exactly one batch; every carton received is a `production_outputs` row and a batch. Σ issued value = Σ `line_value`.
* **Ledger:** every entry has `ref` = the order id. On the default policy, the debits to 1300 less the credits to 1300 equal the credits to 6400, and both equal the labour, machine and overhead the finished goods carry (`capitalized_cost − capitalized_material`); the credits to 1300 equal the material they carry (`capitalized_material`). `GET production-orders/{id}/costs` lists the entries.
