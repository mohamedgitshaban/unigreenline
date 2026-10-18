# Manufacturing — installation and operations

## Upgrading an existing installation

```bash
php artisan migrate
php artisan db:seed --class="Modules\\Accounting\\Database\\Seeders\\AccountSeeder"        # new accounts 1350 Work in Progress, 5200 Manufacturing Variance
php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\RolePermissionSeeder"      # new per-endpoint permissions and role grants
```

Both seeders are idempotent. Migrations are additive and run on a populated database:

* `inventory_batches` gains `reserved_cartons` (default 0) and a new `stock_reservations` table.
* `boms` gains code / version / status / priority / dates / routing / instructions; **existing recipes are numbered** (`BOM00001` …, in the
  order they were created), are `approved` and keep working. `bom_lines`, `bom_by_products`, `bom_revisions` are added or extended.
* `production_orders` gains number, priority, dates, quantities, source, cost columns and the new statuses; **existing orders are numbered**
  (`MO00001` …), completed ones get `produced_qty_cartons = output_qty_cartons`, and `exp_date` becomes optional. Their stored costs, batches and journal entries are untouched.
* New tables: settings and sequences, work centers / calendars / downtimes, routings, work orders, components, outputs, scraps, order history, idempotency, quality, planning.
* `down()` reverses all of it (status values added by this work fall back to `draft`).

Nothing changes for existing clients: the old endpoints and fields are unchanged; new fields are additive. The one behaviour change is that
stock another document has **reserved** can no longer be taken by sales, transfers, write-offs or purchase returns — which only matters once a
confirmed manufacturing order holds a reservation.

No new composer or npm dependency is needed.

## Configuration

Everything is stored per company in `manufacturing_settings` and edited with `PUT /api/v1/manufacturing/settings` (`manufacturing-settings.update`).
Defaults make the module behave like the original one-step flow plus the new workflow:

| Setting | Default | Meaning |
|---|---|---|
| `mo_prefix`, `bom_prefix`, `work_order_prefix`, `scrap_prefix`, `mrp_prefix`, `number_padding` | `MO`, `BOM`, `WO`, `SCR`, `MRP`, 5 | document numbers |
| `default_warehouse_id` | none | used when an order or planning run names no warehouse (**set it before the nightly planning run**) |
| `lead_time_days` | 1 | lead time of a made product with no policy of its own |
| `reservation_policy` | `on_confirm` | `manual` leaves stock free until `reserve-materials` is called |
| `consumption_policy` | `backflush` | `manual` requires materials to be issued with `consume-materials` first |
| `backorder_policy` | `ask` | what completing short does: `ask` / `always` / `never` |
| `allow_partial_production` | true | false requires receiving the whole remaining quantity at once |
| `allow_over_consumption` | false | issue more than the order requires |
| `scrap_requires_approval` / `scrap_cost_policy` | false / `expense` | scrap approval; expense it or let the product carry it |
| `require_bom_approval` | false | new and edited recipes wait for `boms.approve` |
| `auto_create_work_orders` | true | `plan` creates operations from the routing |
| `allow_reopen_cancelled` | false | `reopen` |
| `wip_accounting` | false | book through account 1350 (fixed per order at confirmation) |
| `overhead_rate_pct` | 0 | extra overhead as % of material |
| `by_product_cost_method` | `fixed_pct` | `none` / `fixed_pct` / `sales_value` |
| `mrp_horizon_days` | 30 | how far ahead planning looks |
| `cancel_unstarted_on_demand_cancel` | false | planning cancels unstarted orders of cancelled sales orders |
| `allowed_transitions` | null | a subset of the order state machine |

## Queue worker and scheduler

| Need | Command | Notes |
|---|---|---|
| Queued planning runs (`POST /mrp/plan` with `async: true`) | `php artisan queue:work` | the job claims its run atomically; a duplicate delivery does nothing. `tries = 1` — a failed run is recorded as failed, ask for a new one |
| Nightly planning | `php artisan schedule:run` every minute (cron) | `mrp:plan` runs at 02:00 for each company that has a default warehouse; it queues a run and only **proposes** |
| Manual run | `php artisan mrp:plan --sync [--tenant=<id>]` | runs now, in the foreground |

After-commit notifications (shortages, failed quality checks, finished orders, finished planning runs) are plain in-app `notifications` rows created
by a listener; they do not need the queue.

## Operating notes

* **Where the stock is.** Production uses the warehouse on the order. Output is received into the same warehouse (a by-product can name another).
* **Closing a stuck order.** An order that has moved stock cannot be cancelled. Complete it with what was made (`produce_remaining: false`), record
  what was lost with `record-scrap`, and say whether to backorder the rest.
* **Reservations.** If an order is abandoned (never cancelled or completed) it keeps its reservation. Release it with `release-materials`, or cancel
  the order. `GET /products/{id}/availability` shows `reserved_cartons` per warehouse.
* **Changing a recipe.** Orders already confirmed keep their components and recipe revision; draft orders pick up the recipe when they are confirmed.
  Changing a routing makes a new version of the recipes that use it.
* **Switching `wip_accounting`.** Takes effect for orders confirmed afterwards; orders under way finish on the policy they were confirmed with.
  `GET /reports/manufacturing/wip-valuation` reconciles the open WIP orders with the balance of account 1350.
* **Logs.** A failed planning run logs `MRP planning run failed` with the run id and the exception; the API shows only a safe message.
* **Attachments.** Files attached to recipes, orders and the like are stored on the default filesystem disk under `manufacturing-attachments/{company}/`; back that disk up with the database.
* **Deadlocks.** The critical write paths (confirm, reserve, record production, consume, scrap, complete, cancel, start) run their transaction with up to three attempts, so a database deadlock between two busy requests is retried instead of surfacing as an error. Each is safe to repeat.
* **Audit.** Every action writes the order's history and the tamper-evident audit log (module `manufacturing`).
* **Performance.** Heavy work is bounded: planning reads by aggregation and runs on the queue; reports aggregate in SQL and paginate; the
  scheduler holds a lock only on the work center being booked.

## Rolling back

`php artisan migrate:rollback --step=…` reverses the manufacturing migrations in reverse order. Rolling back after orders have used the new
statuses sets those orders to `draft`. Take a backup first; the permission conversion migration of the earlier release is one-way and not part of this.
