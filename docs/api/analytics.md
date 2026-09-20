# Analytics & Scheduled Jobs

All endpoints below require `analytics.view` (or `analytics.export` for the
two `/reports/*` endpoints). **Only `Administrator`, `Owner`, and `Auditor`
have any `analytics.*` permission in this build** — the spec's own role
table (§2) never grants it to an operational role (Sales Manager,
Warehouse Manager, etc.), so this build doesn't invent that access either.
If the frontend needs, say, a Sales Manager to see the dashboard, that's a
one-line change to `RolePermissionSeeder`, not a code change.

---

## `GET /api/v1/analytics/dashboard`

The KPI cards from spec §8.

```json
{
  "data": {
    "monthly_sales": "12345.00", "active_customers": 5, "collected_amount": "3000.00",
    "outstanding_ar": "8000.00", "overdue_amount": "1200.00", "inventory_value": "777400.00",
    "critical_expiry_count": 3, "pending_deliveries": 2
  }
}
```
- `monthly_sales`: sum of sales order totals with `order_date` in the current calendar month.
- `collected_amount`: sum of collections with `payment_date` in the current calendar month.
- `outstanding_ar`: sum of invoice `balance` across `outstanding`/`partial`/`overdue`.
- `critical_expiry_count`: batches with `qty_cartons > 0` expiring within **30 days** (not spec-defined — a chosen default, same threshold the expiry-alert job below uses).

## `GET /api/v1/analytics/expiry`

Paginated, ordered soonest-expiring first. `days_left` is signed: positive
= days until expiry, `0` = expires today, negative = already expired.
Excludes exhausted batches (`qty_cartons = 0`).

```json
{ "data": [ { "batch_id": "01m...", "product_id": "01m...", "product_name": "...", "warehouse_id": "01m...", "warehouse_name": "...", "batch_no": "...", "exp_date": "2026-12-20", "qty_cartons": 100, "days_left": 91 } ] }
```

## `GET /api/v1/analytics/stock`

Per-product rollup across every warehouse. Paginated by *product*, not by
row — each product's `warehouses` array can have multiple entries.

```json
{
  "data": [
    {
      "product_id": "01m...", "product_name": "...", "sku": "PRD-00001",
      "total_qty_cartons": 230, "total_stock_value": "20700.00",
      "warehouses": [ { "warehouse_id": "01m...", "warehouse_name": "Main Warehouse", "qty_cartons": 120 } ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 6, "last_page": 1 }
}
```

---

## `GET /api/v1/reports/sales`

Requires `analytics.export` specifically (not just `view`). Same row shape
as `GET /sales-orders`.

`?start_date=&end_date=&warehouse_id=&per_page=` — all optional, filtering
on `order_date` and `warehouse_id`.

## `GET /api/v1/reports/inventory`

Requires `analytics.export`. Batch-level detail (not the `/analytics/stock`
rollup) — same row shape as batches elsewhere.

`?start_date=&end_date=&warehouse_id=&per_page=` — date filters apply to
`rcv_date` (when each batch was received), not `exp_date`.

---

## Scheduled jobs

Both run daily (registered in their module's `configureSchedules()` — an
actual cron entry calling `php artisan schedule:run` is a deployment
concern, not covered here).

### `php artisan invoices:flip-overdue`

Flips `outstanding`/`partial` invoices whose `due_date` has passed to
`overdue` (spec §5.4 — the prototype only ever had this as static seed
data). For each one: updates the invoice, creates a broadcast (`user_id:
null`) notification (`type: "invoice_overdue"`), and records an audit log
entry. Paid invoices and ones not yet due are left alone.

### `php artisan inventory:generate-expiry-alerts`

Creates a broadcast notification (`type: "expiry_alert"`) for every batch
newly within the critical window (30 days, `qty_cartons > 0`) that hasn't
already been alerted on. **Idempotent per batch** — checked by looking for
an existing notification linking to that batch id, so running this daily
never re-notifies for the same batch twice.

Neither job has an HTTP endpoint — they're cron-only. `GET /notifications`
(to actually read what they create) is an Admin-step endpoint, not built
yet.
