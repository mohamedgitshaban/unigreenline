# Manufacturing — testing

Tests are PHPUnit feature tests against a real MySQL database (`vetpharma_testing`, `RefreshDatabase`), plus a few pure unit tests. They share
`Modules/Manufacturing/tests/ManufacturingTestCase.php`, which sets up a company, a warehouse, the seeded roles and the chart of accounts, and has
short helpers for products, stock, recipes and the ledger (`ledger($orderId)` totals the entries an order made per account; `entries($orderId)` lists them in order).

## Running them

```bash
# the manufacturing module
php artisan test --compact Modules/Manufacturing

# one file or one test
php artisan test --compact --filter=ProductionRecordingTest
php artisan test --compact --filter=test_partial_production_issues_materials_in_step_and_values_each_receipt

# the parts of other modules this work touched
php artisan test --compact --filter='StockReservation|Fefo|Transfer|Recap|ProcessReturn|Availability|AccountSeeder|AccountConnections|EveryEndpointIsGuarded|PermissionDocs|LegacyPermissionMap|ApiDocs'

# everything (about 9 minutes)
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

`--compact` prints a JSON summary and exits with status 1 even when everything passed — read the `result` / `failed` fields.
**Do not run two test processes at once** — not even from two terminals or sessions: they share one database and fail with deadlocks or "table doesn't exist" errors halfway through each other's migrations.

## What is covered

| File | Covers |
|---|---|
| `BomManagementTest` | numbering and prefixes; revisions as immutable snapshots; new version on structural edits only; approval required mode; archive / restore; alternatives by priority and dates; unit-based lines; **circular recipes** (direct and through other recipes, on create and on edit); by-product rules; multi-level explosion with scrap; permissions |
| `ProductionOrderLifecycleTest` | numbering; quantity or runs; draft-BoM refusal; confirm (components, estimate, revision, reservation); **invalid transitions** and the status not being settable; company-restricted transitions; recipe edits not moving a confirmed order; soft-field edits only; **reservations blocking sales, transfers and a second order**; partial reservation and re-reserving; manual reservation; expired stock never reserved; cancel releases and is refused after stock moved; reopen; append-only history; list filters |
| `ProductionRecordingTest` | **partial production with hand-worked costs** (4 then 6 of 10: 80.00 then 130.00, entries line by line); the same under **WIP accounting** ending with a zero 1350 balance; rejects absorbed by good cartons; no over-production; partial production switched off; expiry date required; **stock shortfall rolling everything back**; expired batches; **idempotent retries** (one batch, one entry); by-products (fixed share and selling value); scrap (expense / approval / reject / absorb); manual consumption and over-consumption; **variance write-off** in both policies; backorders (ask / always / never / later); completing short; cost breakdown; traceability; warehouse stock value |
| `RoutingAndWorkOrderTest` | routing operations and dependency rules; routing change versions the recipe; planning creates and books work orders (known Sunday, known shifts); a second order waiting for a single-capacity center; capacity 2 running two at once; downtime and holidays pushing the schedule; a 10-hour operation spanning shifts and a holiday; sequential enforcement; pause / resume time logs; cost of time; receipt limited by the last operation; rejects narrowing the chain; the order not completing until operations are done; order pause; rescheduling conflicts and `auto_shift`; center schedule; WIP operation-cost posting; work center permissions |
| `QualityControlTest` | checks raised at confirmation; blocking completion; measured limits; alerts; supersession by a later passing check; operation-level checks; incoming inspections; definitions and permissions |
| `MrpPlanningTest` | netting with stock, **reservations**, expired stock, incoming POs and open orders; **multi-level explosion** with scrap and lead times; lot rules; make/buy policy; late orders; safety stock and reorder point; make-to-order; **re-runs without duplicates** (update / withdraw); converted and dismissed suggestions; sales-order demand and cancelled demand (report / cancel by policy, never when started); horizon; overdue demand; queue + duplicate delivery; one run per warehouse; **failure handling without leaking internals**; run records; **80-product query-count bound**; permissions |
| `LotSizerTest` (unit) | the four lot rules, including splitting by a maximum |
| `ManufacturingReportsTest` | the eight reports against known orders: planned/produced/rejected by product, month and warehouse; consumption and scrap with cost hidden from those without the cost permission; work-center utilization on a known Sunday; lead time and delay; traceability both ways; cost variance with a real price difference; WIP valuation reconciled with account 1350; export permission; other companies' orders excluded; a query-count bound for receiving an order with 25 components |
| `SalesDemandAndNotificationsTest` | make-to-order from an open sales order (stock netted, never duplicated, gifts and recipe-less products skipped, closed or foreign orders refused, role checks); notifications to the creator and to managers for shortages, failed checks, completion, cancellation and finished planning; **no notification for something rolled back** |
| `ManufacturingAuthorizationTest` | every new endpoint returns 404 for another company's record and its lists show only this company's; a role × endpoint matrix (12 roles, 24 endpoints) asserting exactly what is allowed and that everything else is 403; cost visibility; settings validation, audit and use of the new prefix |
| `ManufacturingAttachmentTest` | attach / list / download / delete, safe types and sizes, the owner must be this company's, permissions |
| `StockReservationTest` (Inventory) | reserve (earliest-expiring, unexpired, only what is free), release (all or part), consume, sales deduction / write-off / purchase return unable to touch reserved cartons, availability endpoint |
| `ManufacturingTest` | the original nine tests of the one-step flow, **unchanged**: they pass on the new engine |
| Inventory / Sales / Recap tests | FEFO deduction, transfers, write-offs and returns respect reservations; the availability endpoint reports `reserved_cartons` |
| `EveryEndpointIsGuardedTest`, `PermissionDocsTest` | every new route has a permission, names follow the convention, `docs/api/permissions.md` is not stale |
| `AccountConnectionsTest` | accounts 1350 and 5200 are in the connection map and every posted code is seeded |

## How the hard properties are tested

| Property | Test |
|---|---|
| No double reservation | the second order gets only what the first left; `reserved_cartons` on the batch equals the sum of the orders'; sales/transfer paths see only the free stock |
| Concurrency | the reservation counter is read and written under the batch row lock (`lockForUpdate`) by every flow, the work center row is locked while bookings are read with a locking read, the planning run is claimed with one atomic update, and number sequences lock their row. PHPUnit runs one process, so these are asserted as *outcomes* (second request sees the first) and by the idempotency / claim tests; true parallelism is not simulated |
| Retry safety | the same `Idempotency-Key` twice produces one batch, one entry and the original answer; a duplicate queue delivery plans once |
| Rollback | a shortfall in the middle of a receipt (herb issued, bottle short) leaves stock, consumptions, outputs and the ledger exactly as before; a failed planning run leaves no lines or suggestions |
| Ledger balance | every entry is built by the existing `JournalPostingService`, which refuses an unbalanced entry; tests assert the exact lines and that the WIP account nets to zero |
| Costs | the worked example (21.00 a carton) is checked per receipt and in total, with and without rejects, by-products and variance |

## Known limits of the suite

* Real simultaneous requests are not exercised (single PHP process).
* No browser or queue-worker process tests; the queue is `sync` in tests and the job is invoked directly.
* Static analysis (PHPStan / Larastan) is not configured in this repository; formatting is checked with Pint.
