# Inventory

All endpoints below require `Authorization: Bearer <token>` and are paginated
list-side with `?page=&per_page=` (default 15/page) where noted.

**Warehouse scoping**: unless a user holds the `Owner` or `Auditor` role, they
only see warehouses they're assigned to (`user_warehouses`). `GET /warehouses`
silently filters to what they can see; `GET/PUT /warehouses/{id}` returns
`403` for a warehouse they're not assigned to. `Administrator` bypasses every
check.

---

## `GET /api/v1/warehouses`

Requires `inventory.view`.

**200**
```json
{
  "data": [
    {
      "id": "01m2r6k2789bd1c1k91z4mtzf4",
      "name": "Main Warehouse",
      "city": "Cairo",
      "governorate": "Cairo",
      "address": null,
      "manager_id": "01m2r6k1qpjr54v3949x7w97g1",
      "manager_name": "Khalid Omar",
      "temperature": "ambient",
      "capacity": 10000,
      "phone": null,
      "status": "active",
      "stock_value": "525400.00"
    }
  ],
  "links": { "...": "standard Laravel paginator links" },
  "meta": { "...": "standard Laravel paginator meta" }
}
```

## `GET /api/v1/warehouses/{id}`

Requires `inventory.view` + the warehouse being visible to this user. Includes
nested `batches`.

**200** — same shape as above, plus:
```json
{
  "data": {
    "...": "warehouse fields",
    "batches": [
      {
        "id": "01m...", "product_id": "01m...", "warehouse_id": "01m...",
        "batch_no": "PRD-00001-B1", "mfg_date": "2026-06-17", "exp_date": "2028-03-17",
        "rcv_date": "2026-08-18", "qty_cartons": 150, "qty_packs": 0, "cost_per_carton": "900.00"
      }
    ]
  }
}
```

**403** — warehouse exists but isn't assigned to this user.

## `POST /api/v1/warehouses`

Requires `inventory.add`. `manager_name` is derived server-side from
`manager_id` — don't send it, it's ignored if you do.

**Request**
```json
{
  "name": "Main Warehouse", "city": "Cairo", "governorate": "Cairo",
  "address": null, "manager_id": null, "temperature": "ambient",
  "capacity": 10000, "phone": null, "status": "active"
}
```
Only `name`, `city`, `governorate` are required.

**201** — the created warehouse (same shape as show, without batches).

## `PUT /api/v1/warehouses/{id}`

Requires `inventory.edit` + visibility. All fields optional (partial update).

---

## `GET /api/v1/products`

Requires `inventory.view`. Not warehouse-scoped (catalog-wide).

## `GET /api/v1/products/{id}`

Includes nested `batches` (same shape as the warehouse show endpoint's batches).

## `POST /api/v1/products`

Requires `inventory.add`.

**Request**
```json
{
  "category_id": "01m...", "supplier_id": null, "name": "Oxytetracycline 20% Injectable",
  "sku": "PRD-00001", "brand": "EgyVet", "pack_unit": "Vial 100ml", "carton_qty": 20,
  "pack_cost_price": 45.00, "pack_selling_price": 65.00,
  "discount_pct": 0, "tax_pct": 14, "min_stock_cartons": 10, "reorder_level": 20
}
```
`sku` is unique. `category_id` must be a real category.

**201/200 response fields** — `cost_price` and `selling_price` are computed
server-side (`pack_price × carton_qty`), always present, always in sync:
```json
{
  "id": "01m...", "category_id": "01m...", "supplier_id": null,
  "name": "Oxytetracycline 20% Injectable", "sku": "PRD-00001", "brand": "EgyVet",
  "pack_unit": "Vial 100ml", "carton_qty": 20,
  "pack_cost_price": "45.00", "pack_selling_price": "65.00",
  "cost_price": "900.00", "selling_price": "1300.00",
  "discount_pct": "0.00", "tax_pct": "14.00",
  "min_stock_cartons": 10, "reorder_level": 20, "active": true
}
```

> Note: `supplier_id` is accepted but not yet validated against a real
> supplier record — the Purchasing module hasn't shipped. It'll start being
> checked once it does; existing values won't need migrating.

## `PUT /api/v1/products/{id}`

Requires `inventory.edit`. All fields optional.

---

## `GET /api/v1/categories`

Requires `inventory.view`.

## `POST /api/v1/categories`

Requires `inventory.add`. Only `name` is required; unique per account.

```json
{ "name": "Antibiotics", "code": null, "description": null, "active": true }
```

## `PUT /api/v1/categories/{id}`

Requires `inventory.edit`, and the category must belong to your account
(**403** otherwise). Partial update — send only the fields you're changing;
same fields and rules as `POST`. `name` stays unique per account, but keeping
a category's own current name is fine.

```json
{ "name": "Antibiotics & Antimicrobials", "active": false }
```

**200** — the updated category. **422** if `name` is taken by another category.

---

## `POST /api/v1/inventory/grn`

Manual goods receipt — stock that didn't come from a purchase order (that flow
arrives with the Purchasing module). Requires `inventory.add` + the warehouse
being visible to this user.

**Request**
```json
{
  "product_id": "01m...", "warehouse_id": "01m...", "batch_no": "BATCH-001",
  "qty_cartons": 50, "cost_per_carton": 120.00,
  "exp_date": "2027-12-31", "mfg_date": null, "rcv_date": null
}
```
`exp_date` is **required** — there is no fallback/fabricated expiry.
Re-receiving the same `batch_no` for the same product+warehouse tops up that
batch's quantity instead of creating a duplicate row; a different `batch_no`
always creates a new one, even for the same product+warehouse.

**201** — the resulting batch (created or topped-up):
```json
{
  "data": {
    "id": "01m...", "product_id": "01m...", "warehouse_id": "01m...",
    "batch_no": "BATCH-001", "mfg_date": null, "exp_date": "2027-12-31",
    "rcv_date": "2026-09-17", "qty_cartons": 50, "qty_packs": 0, "cost_per_carton": "120.00"
  }
}
```

**422** — missing `exp_date`, or `product_id`/`warehouse_id` don't exist.
**403** — warehouse not assigned to this user.

---

## FEFO stock deduction

The First-Expired-First-Out deduction algorithm (spec §5.1) is a service
(`FefoStockService`) used by Sales Orders — see
[sales.md](./sales.md#put-apiv1sales-ordersidstatus) for where it's wired
up (`PUT /sales-orders/{id}/status`). Any endpoint that uses it can return
`422` with this shortfall shape if stock is insufficient:
```json
{
  "message": "Insufficient stock to satisfy every line.",
  "shortfalls": [
    { "product_id": "01m...", "warehouse_id": "01m...", "needed": 16, "available": 15 }
  ]
}
```

---

## `GET /api/v1/transfers`

Requires `inventory.view`.

## `POST /api/v1/transfers`

Requires `inventory.add` + both warehouses visible to this user (unlike
Sales Orders — a transfer is warehouse-staff work, so the same assignment
check as GRN and the warehouse endpoints applies to *both* sides).
Executes immediately and atomically — there's no separate "complete"
step; `status` is always `"completed"` in the response.

**Request**
```json
{
  "product_id": "01m...", "from_warehouse_id": "01m...", "to_warehouse_id": "01m...",
  "batch_no": "PRD-00001-B1", "qty_cartons": 20, "transfer_date": "2026-09-20", "notes": null
}
```
`from_warehouse_id` and `to_warehouse_id` must differ (`422` otherwise,
enforced at the DB level too). The batch's expiry date carries over if a
new batch has to be created in the destination warehouse; if a batch with
the same `batch_no` already exists there, its quantity is topped up
instead. Both warehouses' `stock_value` are recalculated afterward.

**201** — the created transfer.
**422** — insufficient stock in the source batch (same shortfall shape as
above, one entry), or `from_warehouse_id === to_warehouse_id`.
