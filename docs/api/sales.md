# Sales Orders, Invoices, Deliveries & Collections

> **Flags:** 🆕 NEW = new endpoint, 🔄 CHANGED = existing endpoint that now
> accepts or returns more. Added in this update: per-line warehouse, free-gift
> lines, ordering in packaging units, **minimum selling price**, edit/delete of
> draft sales orders, **invoice void**, and **invoice import**.

**Ownership scoping**: a `Sales Rep` only sees/creates their own orders
(`sales_rep_id`), and only sees invoices/deliveries/collections tied back to
one of their own orders. Any other role with the sales permissions (`sales-orders.view`/`create`... or,
for invoices/collections, the `invoices.*` / `collections.*` ones the `Accountant` role holds — see below)
sees/acts on everything. Warehouse assignment (`user_warehouses`) is
unrelated to any of this — a Sales Rep or Manager is never assigned to a
warehouse, so `warehouse_id` on an order can be any real warehouse.

**Invoices and collections are also accessible to Accounting.** An
`Accountant` (who has `accounting.*` permissions but no `sales.*` ones per
spec §2's role table) can view invoices and record collections — recording
a payment is their job. They cannot see or touch sales orders themselves.

---

## `GET /api/v1/sales-orders`

Requires `sales-orders.view`. Paginated, newest `order_date` first.

## `GET /api/v1/sales-orders/{id}`

Includes `lines_count` and `invoices_count`. `403` if a Sales Rep requests
another rep's order.

## `GET /api/v1/sales-orders/{id}/lines`, `/invoices`

The order's lines (each with its `product`) and invoices, each as its own
list. Same access as the order show. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

## `POST /api/v1/sales-orders` 🔄 CHANGED

> 🔄 **CHANGED.** Order lines gained four things, all optional and backward
> compatible: a **warehouse per line**, an **`is_gift` flag**, ordering in any
> **packaging unit**, and a **minimum selling price** check. Details right after
> the request example below.

Requires `sales-orders.create`. Validates stock availability (FEFO, no deduction yet)
and, for `pay_type: "credit"`, that the order wouldn't push the customer
over their `credit_limit` — **before writing anything**.

**Request**
```json
{
  "customer_id": "01m...",
  "sales_rep_id": null,
  "warehouse_id": "01m...",
  "pay_type": "credit",
  "grace_period": 30,
  "invoice_discount": 0,
  "notes": null,
  "order_date": "2026-09-17",
  "lines": [
    { "product_id": "01m...", "batch_no": null, "qty": 5, "unit": "Carton", "unit_price": 65, "discount_pct": 5, "free_qty": 0 }
  ]
}
```
- `lines.N.warehouse_id` 🆕 (optional): draw this line from a different
  warehouse than the order's `warehouse_id` (the default). One order can be fed
  from several warehouses — stock is validated, and later deducted, **per line
  against that line's warehouse**. Pick warehouses with
  `GET /products/{id}/availability`
  ([inventory.md](./inventory.md#get-apiv1productsidavailability--new)).
  A shortfall names the line's warehouse in `shortfalls[].warehouse_id`.
- `lines.N.unit_id` 🆕 (optional): order in any packaging unit of the product
  instead of `unit` (`unit` becomes optional). Stock need = `(qty + free_qty) ×
  unit base size ÷ carton size`, rounded **up** to whole cartons. `unit_price` is
  then **per that unit**.
- `lines.N.is_gift` 🆕 (optional, default `false`): a **free-gift line**. It is
  sold at **0** (`unit_price` is ignored and not required), adds nothing to the
  totals, and draws **only from free-gift stock** (bonus stock received through a
  purchase order's `free_gifts`) — never from paid stock; ordinary lines never
  touch gift stock. A gift line asking for more than the gift stock holds is the
  usual 422 `shortfalls`.
- **Minimum selling price** 🆕: for every non-gift line, `unit_price × (1 −
  discount_pct/100)` must be **at least** the product's `min_selling_price` for
  that unit (`min_selling_price × the unit's base size`; per carton for
  `unit: Carton`). Below it is **422** on `lines.N.unit_price`. Products without
  a minimum have no floor. The seller can price anywhere at or above it.
- `sales_rep_id`: omit to default to the authenticated user. A Sales Rep may
  only submit their own id (or omit it) — submitting someone else's is `422`.
- `unit`: `"Carton"` or `"Pack"`. A Pack-unit line's stock need is rounded
  **up** to whole cartons (`ceil(qty / product.carton_qty)`) since batches
  only track carton-level quantities — ordering a handful of packs still
  reserves a full carton.
- `free_qty`: shipped but not billed — contributes to the stock check, not
  to `subtotal`.
- `batch_no` on a line is informational only. Actual deduction (when it
  happens, on the status transition) always follows FEFO regardless of what
  was suggested here.

**Line math**: `line.subtotal = qty × unit_price × (1 − discount_pct/100)`,
rounded to 2dp. `line.tax = line.subtotal × product.tax_pct/100`.
`order.subtotal = Σ line.subtotal`, `order.tax_amount = Σ line.tax`,
`order.total = subtotal + tax_amount − invoice_discount`.

**201** — the created order with lines (see shapes above). 🔄 Each line now also
returns `warehouse_id` (its own or the order's), `unit_id`, `base_qty` and `is_gift`.

**422 — below minimum selling price** 🆕:
```json
{
  "message": "The price after discount cannot be below the minimum selling price of 80 for this unit.",
  "errors": { "lines.0.unit_price": ["The price after discount cannot be below the minimum selling price of 80 for this unit."] }
}
```

**422 — insufficient stock**:
```json
{
  "message": "Insufficient stock to satisfy every line.",
  "shortfalls": [
    { "product_id": "01m...", "warehouse_id": "01m...", "needed": 5, "available": 2 }
  ]
}
```

**422 — credit limit exceeded**:
```json
{
  "message": "This order would push the customer over their credit limit.",
  "customer_id": "01m...",
  "credit_limit": 1000,
  "current_balance": 900,
  "order_total": 351.98
}
```
Only checked for `pay_type: "credit"` — cash orders never check this. When a
credit order crosses **85%** utilization without exceeding it, a
`credit_warning` notification is created for the order's sales rep (visible
once the notifications endpoint ships).

**422 — validation** (missing fields, unknown `customer_id`/`product_id`,
a Sales Rep naming another rep as `sales_rep_id`).

**403** — user lacks `sales-orders.create`, e.g. `Customer Service`.

## `POST /api/v1/sales-orders/deliver` 🆕 NEW

**Create the order and deliver it in one call** — for a counter / van sale where
the goods leave with the customer, so there is no separate "create" then
"deliver" round trip. It is exactly `POST /sales-orders` followed by
`PUT /sales-orders/{id}/status` → `delivered`, done atomically.

Requires **`sales-orders.quick-deliver`** (**403** otherwise, e.g. Customer
Service). A Sales Rep creates it under their own name.

**Request** — everything `POST /sales-orders` takes (same lines, per-line
`warehouse_id`, `is_gift`, `unit_id`, minimum-price and credit rules), plus an
optional `delivery` block:

```json
{
  "customer_id": "01m...", "warehouse_id": "01m...", "pay_type": "cash",
  "lines": [ { "product_id": "01m...", "qty": 4, "unit": "Carton", "unit_price": 100 } ],
  "delivery": {
    "driver": "Hassan", "delivery_date": "2026-10-12",
    "notes": "Left at the gate",
    "invoice_data": "delivery-invoices/01m.../a1b2c3.jpg"
  }
}
```

`delivery.invoice_data` is the signed / delivered invoice copy, a path from
`POST /deliveries/invoices` (**422** `delivery.invoice_data` otherwise). Every
`delivery` field is optional; `delivery_date` defaults to today.

**What happens, in one transaction**

1. stock is checked and the order created (credit limit, FEFO per line warehouse,
   minimum price — the usual 422s apply);
2. the order moves to `delivered`: stock is deducted FEFO, the invoice and its
   journal entry (Dr Receivable / Cr Revenue + VAT) are created, a credit
   order raises the customer balance, and the delivery is created already
   `delivered`;
3. the `delivery` details are written onto that delivery (audit-logged).

If any step fails — insufficient stock, credit limit, price below the minimum, a
bad file path — **nothing is saved**: no order, no stock movement, no invoice.

**201** — the order exactly as `PUT /sales-orders/{id}/status` returns it for
`delivered`: `status: "delivered"`, `invoices_count: 1` and the `delivery`
object (`status: "delivered"`, `driver`, `delivery_date`, ...). Use
`GET /sales-orders/{id}/invoices` for the invoice id.

## `PUT /api/v1/sales-orders/{id}` 🆕 NEW

Requires `sales-orders.update` (+ ownership if Sales Rep). **Draft orders only** — once an
order is picking/invoiced/delivered/cancelled its stock, invoice and journal
entry were created from the old values: **422**
`{"message": "Only draft sales orders can be changed or deleted."}`.

Partial update with the create fields. Header fields update in place; `lines`,
if sent, **replace every line** and the order is re-priced and re-validated
exactly as on create (credit limit, per-line-warehouse FEFO availability,
minimum selling price, gift rules). If `lines` is omitted but the customer,
warehouse, `pay_type` or `invoice_discount` change, totals and availability are
re-checked against the existing lines. Audit-logged. **200** — the order. Another
tenant's order is **404**.

## `DELETE /api/v1/sales-orders/{id}` 🆕 NEW

Requires `sales-orders.delete` (+ ownership if Sales Rep). **Draft orders only** (same
**422**) — a draft has no stock, invoice or journal side effects, so the order
and its lines (cascade) simply go. Anything further along: set the status to
`cancelled`. **204**; audit-logged as `DELETE`.

---

## `PUT /api/v1/sales-orders/{id}/status`

Requires `sales-orders.change-status` (+ ownership if Sales Rep). Drives the whole
lifecycle — this is where stock actually leaves the warehouse.

**Request**
```json
{ "status": "invoiced" }
```
Valid values: `picking`, `invoiced`, `delivered`, `cancelled`. Allowed
transitions:

| From | May go to |
|---|---|
| `draft` | `picking`, `invoiced`, `delivered`, `cancelled` |
| `picking` | `invoiced`, `delivered`, `cancelled` |
| `invoiced` | `delivered` |
| `delivered` | *(terminal)* |
| `cancelled` | *(terminal)* |

Anything else (e.g. `delivered` → `picking`, or re-`cancelled`) is `422`.

**What happens on the first transition to `invoiced` or `delivered`**
(idempotent — re-running the same transition, or advancing further, never
repeats these):
1. FEFO stock deduction across every line, exactly like the pre-flight
   check at order creation but now actually decrementing batches. If stock
   has become insufficient since the order was created (e.g. taken by
   another order), this returns the same `422` shortfall shape as order
   creation and **nothing changes** — status included.
2. An invoice is auto-created from the order's totals (skipped if one
   already exists for this order — going `invoiced` → `delivered` does not
   create a second one).
3. A balanced journal entry posts: Dr Accounts Receivable (1200) for the
   invoice total, Cr Sales Revenue (4100) for the subtotal, Cr VAT Payable
   (2300) for the tax (a zero-value side, e.g. tax-exempt, is simply
   omitted rather than posted as a $0 line).
4. For `pay_type: "credit"` orders, the customer's AR `balance` increases by
   the invoice total.
5. 🔄 A second balanced entry books the **cost of goods sold**: Dr Cost of
   Goods Sold (5100) / Cr Inventory (1300) for the cost of the batches the
   FEFO deduction actually took (free-gift stock costs nothing, so adds
   nothing). Posted once per order, together with the stock deduction.

**Additionally, on transition to `delivered`**: the order's one delivery
record is created (or updated) with `status: "delivered"` and a real
`delivered_at` timestamp.

**200** — the order, with `invoices_count` (0 until the first
`invoiced`/`delivered` transition — the invoice itself is at
`GET /sales-orders/{id}/invoices`) and `delivery` (`null` until the order
actually reaches `delivered`). This is the only sales-order endpoint that
includes `delivery`.
**422** — invalid transition, or insufficient stock (same shortfall shape
as order creation).
**403** — Sales Rep on someone else's order, or missing `sales-orders.change-status`.

---

## Invoices

### `GET /api/v1/invoices`, `GET /api/v1/invoices/{id}`

Requires `invoices.view`. Includes `lines_count` (lines
are mirrored from the originating sales order — there's no separate
invoice-lines table) and `collections_count`.

```json
{
  "data": {
    "id": "01m...", "so_id": "01m...", "customer_id": "01m...",
    "issued_date": "2026-09-18", "due_date": null,
    "subtotal": "650.00", "tax_amount": "91.00", "total": "741.00",
    "paid": "300.00", "balance": "441.00", "status": "partial",
    "lines_count": 3,
    "collections_count": 1
  }
}
```
`status`: `outstanding` (paid = 0) → `partial` (0 < paid < total) → `paid`
(paid ≥ total). There's no `overdue` job yet — that's a scheduled command,
still to come. Invoices are never deleted, only voided — see
`POST /invoices/{id}/void` below (`void` is a status).

### `GET /api/v1/invoices/{id}/lines`, `/collections`

The invoice's lines (from its sales order, each with its `product`) and the
collections recorded against it, each as its own list. Same access as the
invoice show. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

There's no `POST /invoices` — they only come from the status-transition
endpoint above (or [import](#post-apiv1invoicesimport--new)).

### `POST /api/v1/invoices/{id}/void` 🆕 NEW

Invoices are never hard-deleted; this is their "delete". Requires `invoices.void`
(+ ownership if Sales Rep).

- sets `status: void` and `balance: 0`;
- posts the **reversing journal entry**: Dr Sales Revenue (4100) + Dr VAT
  Payable (2300) / Cr Accounts Receivable (1200);
- gives the receivable back off the customer's `balance` (for credit orders and
  imported invoices; floored at 0).

**422** if the invoice is already void, or if any money was collected against it
(`paid > 0` / collections exist — deal with those first). **200** — the invoice.
Another tenant's invoice is **404**.

### `POST /api/v1/invoices/import` 🆕 NEW

Requires `invoices.import`. Brings in historical / opening invoices. Multipart `file` —
see [Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
**Invoices have no natural key, so re-importing the same file creates
duplicates** (`update_existing` has no effect here).

Columns: `Customer`* (exact customer name, must exist), `Issued Date`*,
`Due Date`, `Subtotal`*, `Tax Amount`, `Paid`. Per row:

- the invoice is created with `total = subtotal + tax`, `balance = total − paid`
  and status `paid` / `partial` / `overdue` (due date passed and unpaid) /
  `outstanding`;
- it posts the same journal entry a live invoice does — Dr Receivable (1200) /
  Cr Sales Revenue (4100) + VAT Payable (2300) — and, if `Paid > 0`, a receipt
  Dr Cash (1110) / Cr Receivable (1200), so the books stay consistent;
- the customer's `balance` rises by what is still owed.

A Sales Rep can only import invoices for their own customers. `paid` may not
exceed the total.

### `GET /api/v1/invoices/import-template` 🆕 NEW

Requires `invoices.import`. Downloads `invoices-import-template.xlsx`.

---

## Deliveries

### `GET /api/v1/deliveries`, `GET /api/v1/deliveries/{id}`

Requires `deliveries.view` (+ ownership if Sales Rep).

### `PUT /api/v1/deliveries/{id}/deliver`

Requires `deliveries.deliver` (+ ownership if Sales Rep). Marks a delivery
delivered directly — for one not already completed by its order's own
status transition to `delivered`.

**Request** (optional body)
```json
{ "invoice_data": "delivery-invoices/01m.../a1b2c3.jpg" }
```

`invoice_data` is the delivered invoice file (e.g. the copy signed by the
customer), uploaded first via `POST /deliveries/invoices`. A new path
replaces the previous file (which is deleted); omit it to keep the current
one. A path not from the upload endpoint is `422`.

```json
{
  "data": {
    "id": "01m...", "so_id": "01m...", "invoice_id": "01m...", "customer_id": "01m...",
    "driver": null, "delivery_date": "2026-09-18", "status": "delivered",
    "delivered_at": "2026-09-18T10:15:00+00:00", "notes": null,
    "invoice_data": "delivery-invoices/01m.../a1b2c3.jpg",
    "invoice_url": ".../api/v1/deliveries/01m.../invoice"
  }
}
```

### `POST /api/v1/deliveries/invoices`

Requires `deliveries.upload-invoice`. **Step 1 of attaching the delivered invoice.**
`multipart/form-data` with a single `invoice` file — `jpg`, `jpeg`, `png` or
`pdf`, max 5 MB.

**201**
```json
{
  "data": {
    "invoice_data": "delivery-invoices/01m.../a1b2c3.jpg",
    "original_name": "signed-invoice.jpg", "mime_type": "image/jpeg", "size": 204811
  }
}
```

### `GET /api/v1/deliveries/{id}/invoice`

Requires `deliveries.view` (+ ownership if Sales Rep). Downloads the delivered
invoice file (`delivery-invoice-{id}.jpg` etc.) — what `invoice_url` points
at. **404** — no file attached, or the delivery belongs to another account.

---

## Collections

### `GET /api/v1/collections`

Requires `collections.view` (+ ownership if Sales Rep, via
the invoice's originating order).

### `GET /api/v1/collections/{id}`

Same permissions as the list. Includes the nested `invoice`, `customer` and
`collected_by` user. `403` for a Sales Rep requesting a collection on
another rep's order. **404** — the collection belongs to another account.

### `POST /api/v1/collections`

Requires `collections.create`. Records a payment against an
invoice.

**Request**
```json
{ "invoice_id": "01m...", "amount": 300, "method": "Bank Transfer", "reference": null, "payment_date": "2026-09-18", "notes": null }
```
`method`: `Cash`, `Bank Transfer`, `Cheque`, `Credit Card`, `Other`.

On success: the invoice's `paid`/`balance`/`status` update, the customer's
AR `balance` decreases by `amount` (floored at 0 — never goes negative even
if it's already less than the payment for some other reason), and a
balanced journal entry posts: Dr Cash (1110) if `method` is `"Cash"`,
otherwise Dr Bank (1120); Cr Accounts Receivable (1200).

**201** — the created collection.
**422** — `amount` ≤ 0, `amount` exceeds the invoice's remaining balance,
or the invoice is already `paid`.

---

## Returns

`Purchasing` role can use this endpoint too (no `sales.*` needed) — a
Purchase Return is squarely their job. Creating needs `returns.create`, listing
`returns.view` — both held by the sales roles and by `Purchasing`.

### `GET /api/v1/returns`

### `GET /api/v1/returns/{id}`

Requires `returns.view`. Includes the nested `invoice`,
`customer`, `supplier`, `product` and `warehouse`. **404** — the return belongs to another account.

### `POST /api/v1/returns`

🔄 **Posts to the ledger.** `amount` is the credit-note value, net of tax.

- **Customer returns** (Sales Return / Damaged / Expired Return / Wrong Item): **Dr Sales Revenue (4100) / Cr Accounts Receivable (1200)** for `amount`, and the customer's `balance` drops by `amount` (floored at zero). If `restocked` is true and the goods go back into stock, also **Dr Inventory (1300) / Cr Cost of Goods Sold (5100)** for what that stock cost, reversing the cost charged at delivery.
- **Purchase Return**: **Dr Accounts Payable (2100) / Cr Inventory (1300)** for `amount`, and what is owed the supplier drops by `amount`.
- The credit applies to the customer's / supplier's balance, **not to a specific invoice** — an invoice's own `paid`/`balance` are untouched.

Stock rules are unchanged: only a Purchase Return, or a restocked customer return, moves stock. 🔄 A Purchase Return cannot send back cartons that a manufacturing order has reserved (**422** with the free quantity).

**Request**
```json
{
  "invoice_id": null, "customer_id": null, "supplier_id": null,
  "product_id": "01m...", "warehouse_id": "01m...", "batch_no": "PRD-00001-B1",
  "type": "Sales Return", "qty": 3, "unit": "Carton", "amount": 60,
  "restocked": true, "reason": null, "return_date": "2026-09-20"
}
```
`type`: `Sales Return`, `Damaged`, `Expired Return`, `Wrong Item`, or
`Purchase Return`.

**Stock movement rule** — this is the one place the two return directions
genuinely differ, so read it carefully:
- **`Purchase Return`** (stock leaving, back to the supplier): **always**
  deducts `qty` from the matching batch, regardless of `restocked`.
  `supplier_id`, `product_id`, `warehouse_id`, and `batch_no` are all
  required for this type.
- **The other four types** (customer-facing): stock only moves when
  `restocked: true`, and it *adds* `qty` back to the matching batch.
  `restocked: false` (or omitted) means the returned goods were written
  off — no stock movement, no batch needed.
- A `Pack`-unit `qty` rounds up to whole cartons for the batch update,
  same rule as everywhere else (`ceil(qty / product.carton_qty)`).
- **The batch is never fabricated.** Unlike GRN, `returns` has no expiry
  field — if `product_id`/`warehouse_id`/`batch_no` point at a batch that
  doesn't exist, the request fails rather than guessing an expiry.

**201** — the created return, `status: "completed"`.
**422** — the required fields for the type's stock movement are missing,
the source batch doesn't have enough stock (Purchase Return), or no
matching batch exists to restock into.
**403** — e.g. `Customer Service`, which lacks `returns.create`.

---

## `GET /api/v1/reports/ar-aging`

Requires `ar-aging.view`. Every customer with an
outstanding/partial/overdue invoice balance, bucketed by days overdue
(`current`, `days_1_30`, `days_31_60`, `days_61_90`, `days_90_plus`) —
`current` covers not-yet-due and undated invoices.

```json
{
  "data": {
    "as_of": "2026-09-20",
    "customers": [
      {
        "customer_id": "01m...", "customer_name": "Nile Valley Veterinary Clinic",
        "current": "741.00", "days_1_30": "0.00", "days_31_60": "0.00",
        "days_61_90": "0.00", "days_90_plus": "0.00", "total": "741.00"
      }
    ],
    "grand_total": "741.00"
  }
}
```
