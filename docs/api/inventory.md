# Inventory

All endpoints below require `Authorization: Bearer <token>` and are paginated
list-side with `?page=&per_page=` (default 15/page) where noted.

**Warehouse scoping**: unless a user holds the `Owner` or `Auditor` role, they
only see warehouses they're assigned to (`user_warehouses`). `GET /warehouses`
silently filters to what they can see; `GET/PUT /warehouses/{id}` returns
`403` for a warehouse they're not assigned to. `Administrator` bypasses every
check.

> 🆕 / 🔄 flags: **🆕 NEW** = a new endpoint or section, **🔄 CHANGED** = an
> existing endpoint that now accepts or returns more. Added in this update:
> packaging **units**, **minimum selling price**, **cost visible to
> Administrators only**, **free-gift stock**, product **availability by
> warehouse**, **import**, and **delete** for warehouses, products and categories.

---

## Units & product packaging 🆕 NEW

Every product is stored by the **carton** and knows its packaging ladder: the
units it is sold or bought in and how they nest. Example — product X where a
*package* holds 10 *packets* and a *carton* holds 3 *packages*:

| Unit | Holds | Base units (packets) |
|---|---|---|
| Packet | — (smallest) | 1 |
| Package | 10 Packets | 10 |
| Carton | 3 Packages | **30** (= the product's `carton_qty`) |

30 packets of stock = **1 carton**. Anywhere a quantity is entered in a unit
(purchase-order lines, sales-order lines, free gifts), the server converts it
through this ladder; stock is always held in whole cartons. Unit **names** are
admin-managed (below); what they mean is set **per product** with `units`.

`UnitSeeder` adds Packet, Package and Carton for every tenant, and
`ProductUnitSeeder` gives every existing product a Packet → Carton ladder using
its current `carton_qty` — so every product already knows it is stored by the
carton. Both are idempotent.

### `GET /api/v1/units` 🆕 NEW

Requires `units.view`, . Paginated (50 per
page); `search` matches `name`.

```json
{ "data": [ { "id": "01m...", "name": "Packet", "active": true } ] }
```

### `GET /api/v1/units/{id}` 🆕 NEW

Same permissions. **404** for another tenant's unit.

### `POST /api/v1/units` 🆕 NEW

Requires `units.create`. `{ "name": "Pallet", "active": true }` — `name` unique
per tenant (**422** otherwise). **201**.

### `PUT /api/v1/units/{id}` 🆕 NEW

Requires `units.update`. Rename or set `active: false`.

### `DELETE /api/v1/units/{id}` 🆕 NEW

Requires `units.delete`. **204**. **409** while any product is packaged in
the unit — deactivate it instead.

### The `units` field on products 🔄 CHANGED

`POST/PUT /products` accept a `units` array, **smallest unit first**; every
larger unit says how many of the previous one it holds:

```json
"units": [
  { "unit_id": "01m...packet" },
  { "unit_id": "01m...package", "contains": 10 },
  { "unit_id": "01m...carton",  "contains": 3 }
]
```

- the first entry must have no `contains`; every other entry needs `contains` ≥ 2
  (**422** on `units.N.contains`); unit ids must be distinct and in your tenant;
  1–6 levels;
- `carton_qty` is **derived** (here 10 × 3 = 30) — with `units` you no longer send
  `carton_qty`/`pack_unit` (`pack_unit` defaults to the smallest unit's name);
- on update, `units` **replaces the whole ladder** and re-derives `carton_qty`.

Product responses include (on show / index / store / update):

```json
"units": [
  { "unit_id": "01m...", "name": "Packet",  "contains": null, "base_qty": 1 },
  { "unit_id": "01m...", "name": "Package", "contains": 10,   "base_qty": 10 },
  { "unit_id": "01m...", "name": "Carton",  "contains": 3,    "base_qty": 30 }
]
```

---

## `GET /api/v1/warehouses`

Requires `warehouses.view`.

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

Requires `warehouses.view` + the warehouse being visible to this user.

**200** — same shape as above, plus `"batches_count": 12`. The batches
themselves are paged by the endpoint below.

**403** — warehouse exists but isn't assigned to this user.

## `GET /api/v1/warehouses/{id}/batches`

Same access as the warehouse show (**403** if it isn't assigned to this
user). Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README. Each batch includes its `product`; `search` matches
`batch_no` and the product's name/SKU.

```json
{
  "data": [
    {
      "id": "01m...", "product": { "id": "01m...", "name": "Oxytetracycline 20% Injectable", "...": "" },
      "batch_no": "PRD-00001-B1", "mfg_date": "2026-06-17", "exp_date": "2028-03-17",
      "rcv_date": "2026-08-18", "qty_cartons": 150, "qty_packs": 0, "cost_per_carton": "900.00"
    }
  ],
  "links": { "...": "" }, "meta": { "...": "" }
}
```

## `POST /api/v1/warehouses`

Requires `warehouses.create`. `manager_name` is derived server-side from
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

**201** — the created warehouse (same shape as show).

## `PUT /api/v1/warehouses/{id}`

Requires `warehouses.update` + visibility. All fields optional (partial update).

## `DELETE /api/v1/warehouses/{id}` 🆕 NEW

Requires `warehouses.delete` + visibility. **204**, audit-logged as `DELETE`.
**409** while the warehouse holds batches or has transfers, purchase orders or
sales orders against it. Another tenant's warehouse is **404**.

## `POST /api/v1/warehouses/import` 🆕 NEW

Requires `warehouses.import`. Multipart `file` (+ optional `update_existing`) — see
[Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
Matched by **name**. Columns: `Name`*, `City`*, `Governorate`*, `Address`,
`Manager Email` (an existing user; their name is copied to `manager_name`),
`Temperature`, `Capacity`, `Phone`, `Status` (`active`/`inactive`/`maintenance`).

## `GET /api/v1/warehouses/import-template` 🆕 NEW

Requires `warehouses.import`. Downloads `warehouses-import-template.xlsx`.

---

## `GET /api/v1/products`

Requires `products.view`. Not warehouse-scoped (catalog-wide).

> 🔄 **CHANGED — cost is administrator-only.** `pack_cost_price` and
> `cost_price` are returned **only to Administrators**; every other user gets the
> selling prices and the new `min_selling_price` instead (same for batch
> `cost_per_carton`, and nested products in orders and lines).

## `GET /api/v1/products/{id}`

Includes `batches_count`; the batches themselves are paged by the endpoint
below.

## `GET /api/v1/products/{id}/batches`

Requires `products.view`. The product's batches as a normal paginated list —
**10 per page by default**, `?per_page=` to change it. Each batch includes its
`warehouse`.

```
GET /api/v1/products/{id}/batches?per_page=10
GET /api/v1/products/{id}/batches?per_page=10&page=2
GET /api/v1/products/{id}/batches?sort_by=exp_date&sort_dir=asc          (earliest expiry first — FEFO)
GET /api/v1/products/{id}/batches?filter[warehouse_id]=01m...&filter[qty_cartons][gt]=0
```

```json
{
  "data": [
    {
      "id": "01m...",
      "warehouse": { "id": "01m...", "name": "Main Warehouse", "...": "full warehouse object" },
      "batch_no": "PRD-00001-B1",
      "mfg_date": "2026-06-30", "exp_date": "2028-03-30", "rcv_date": "2026-08-31",
      "qty_cartons": 150, "qty_packs": 0, "cost_per_carton": "900.00",
      "is_free_gift": false, "source_po_id": null
    }
  ],
  "links": {
    "first": ".../api/v1/products/01m.../batches?per_page=10&page=1",
    "last": ".../api/v1/products/01m.../batches?per_page=10&page=12",
    "prev": null,
    "next": ".../api/v1/products/01m.../batches?per_page=10&page=2"
  },
  "meta": { "current_page": 1, "last_page": 12, "per_page": 10, "total": 120, "...": "" }
}
```

🔄 **CHANGED** — batch fields: `is_free_gift` (🆕 `true` for bonus stock received
through a purchase order's `free_gifts`; it has `cost_per_carton: 0` and is sold
only by gift lines on a sales order) and `source_po_id` (🆕 the purchase order it
came with). `cost_per_carton` is returned to Administrators only.

`links.next` is `null` on the last page. `search` matches `batch_no` and the
warehouse name; sorting and `filter[...]` work as described in the README.
**404** — the product belongs to another account.

## `POST /api/v1/products` 🔄 CHANGED

Requires `products.create`. 🔄 New: `min_selling_price`, `units` (see
[Units & product packaging](#units--product-packaging--new)).

**`min_selling_price`** (🔄 CHANGED, optional, per smallest unit like
`pack_selling_price`): the lowest price a seller may charge. It must not exceed
`pack_selling_price` (**422**). Sales-order lines are rejected below it — see
[sales.md](./sales.md). `null` = no floor. Every user sees it; cost stays
admin-only. The response also carries `min_selling_price_per_carton`.

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

**201/200 response fields** — (`pack_cost_price` / `cost_price` appear for Administrators only) `cost_price` and `selling_price` are computed
server-side (`pack_price × carton_qty`), always present, always in sync:
```json
{
  "id": "01m...", "category_id": "01m...", "supplier_id": null,
  "name": "Oxytetracycline 20% Injectable", "sku": "PRD-00001", "brand": "EgyVet",
  "pack_unit": "Vial 100ml", "carton_qty": 20,
  "pack_cost_price": "45.00", "pack_selling_price": "65.00", "min_selling_price": "50.00",
  "cost_price": "900.00", "selling_price": "1300.00", "min_selling_price_per_carton": "1000.00",
  "discount_pct": "0.00", "tax_pct": "14.00",
  "min_stock_cartons": 10, "reorder_level": 20, "active": true
}
```

> Note: `supplier_id` is accepted but not yet validated against a real
> supplier record — the Purchasing module hasn't shipped. It'll start being
> checked once it does; existing values won't need migrating.

## `PUT /api/v1/products/{id}` 🔄 CHANGED

Requires `products.update`. All fields optional. 🔄 Also accepts
`min_selling_price` and `units` (replaces the whole ladder).

## `DELETE /api/v1/products/{id}` 🆕 NEW

Requires `products.delete`. **204**, audit-logged. **409** while the product has
batches, order lines, transfers or complaints against it — set `active: false`
instead. Another tenant's product is **404**.

## `GET /api/v1/products/{id}/availability` 🆕 NEW

Requires `products.availability` (so a Sales Rep can use it while
ordering). Every active warehouse that holds unexpired stock of the product,
largest first — the list a seller picks from when each sales-order line can draw
from a different warehouse.

```json
{
  "data": {
    "product_id": "01m...", "carton_qty": 30,
    "total_available_cartons": 70, "total_available_base_units": 2100,
    "warehouses": [
      { "id": "01m...", "name": "Main Warehouse", "city": "Cairo", "governorate": "Cairo",
        "available_cartons": 50, "available_base_units": 1500, "reserved_cartons": 10, "batches_count": 3, "nearest_expiry": "2027-03-01" }
    ]
  }
}
```

Counts include free-gift batches (`is_free_gift`); the sales order decides which
kind a line draws from. 🔄 **Reserved stock is not available:** cartons a confirmed
[manufacturing order](./manufacturing.md) has set aside are subtracted from
`available_cartons` (and shown as `reserved_cartons`), and no sales order, transfer,
write-off or purchase return can take them. Another tenant's product is **404**.

## `POST /api/v1/products/import` 🆕 NEW

Requires `products.import`. Multipart `file` (+ optional `update_existing`) — see
[Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
Matched by **SKU**. Columns: `SKU`*, `Name`*, `Category`* (name **or** code, must
exist), `Supplier` (name, must exist), `Brand`, `Pack Unit`*, `Carton Qty`*,
`Pack Cost Price`*, `Pack Selling Price`*, `Min Selling Price`, `Discount Pct`,
`Tax Pct`, `Min Stock Cartons`, `Reorder Level`, `Active` (`yes`/`no`/`1`/`0`).
Packaging ladders are not imported — set them with `PUT /products/{id}`.

## `GET /api/v1/products/import-template` 🆕 NEW

Requires `products.import`. Downloads `products-import-template.xlsx`.

---

## `GET /api/v1/categories`

Requires `categories.view`.

## `GET /api/v1/categories/{id}`

Requires `categories.view`. **404** — the category belongs to another account.

## `POST /api/v1/categories`

Requires `categories.create`. Only `name` is required; unique per account.

```json
{ "name": "Antibiotics", "code": null, "description": null, "active": true }
```

## `PUT /api/v1/categories/{id}`

Requires `categories.update`, and the category must belong to your account
(**403** otherwise). Partial update — send only the fields you're changing;
same fields and rules as `POST`. `name` stays unique per account, but keeping
a category's own current name is fine.

```json
{ "name": "Antibiotics & Antimicrobials", "active": false }
```

**200** — the updated category. **422** if `name` is taken by another category.

## `DELETE /api/v1/categories/{id}` 🆕 NEW

Requires `categories.delete`. **204**. **409** while any product uses the
category — set `active: false` instead.

---

## `POST /api/v1/inventory/grn`

Manual goods receipt — stock that didn't come from a purchase order (that flow
arrives with the Purchasing module). Requires `goods-receipts.create` + the warehouse
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

🔄 **Ledger.** A receipt with a value (`qty_cartons × cost_per_carton` above zero)
posts **Dr Inventory (1300) / Cr Opening Balance Equity (3300)**, so the stock
asset in the books matches what is on the shelf. A receipt from a purchase
order does not use this endpoint — it posts against Accounts Payable instead
(see [purchasing.md](./purchasing.md)). Clear 3300 against capital or the
supplier with a manual journal entry once you know where the stock came from.

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

🔄 **CHANGED — free-gift stock.** FEFO now keeps two pools per product and
warehouse: regular stock and **free-gift** stock (`is_free_gift`). A normal line
draws only from regular stock; a *gift* line (`is_gift: true` on a sales-order
line) draws only from gift stock. So gift stock can never be sold at a price by
accident, and a gift line can never eat paid stock. Transfers keep a batch's
gift flag.

---

## `GET /api/v1/transfers`

Requires `transfers.view`.

## `GET /api/v1/transfers/{id}`

Requires `transfers.view`. Includes the nested `product`, `from_warehouse`,
`to_warehouse` and `created_by`. **404** — the transfer belongs to another
account.

## `POST /api/v1/transfers`

> 🔄 Cartons reserved by a manufacturing order cannot be transferred away: the
> source batch must hold enough **free** (on hand, not reserved) cartons (**422**
> `shortfalls` otherwise).

Requires `transfers.create` + both warehouses visible to this user (unlike
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
