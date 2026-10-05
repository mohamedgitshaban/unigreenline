# Sales Orders, Invoices, Deliveries & Collections

**Ownership scoping**: a `Sales Rep` only sees/creates their own orders
(`sales_rep_id`), and only sees invoices/deliveries/collections tied back to
one of their own orders. Any other role with `sales.view`/`sales.add` (or,
for invoices/collections, `accounting.view`/`accounting.add` — see below)
sees/acts on everything. Warehouse assignment (`user_warehouses`) is
unrelated to any of this — a Sales Rep or Manager is never assigned to a
warehouse, so `warehouse_id` on an order can be any real warehouse.

**Invoices and collections are also accessible to Accounting.** An
`Accountant` (who has `accounting.*` permissions but no `sales.*` ones per
spec §2's role table) can view invoices and record collections — recording
a payment is their job. They cannot see or touch sales orders themselves.

---

## `GET /api/v1/sales-orders`

Requires `sales.view`. Paginated, newest `order_date` first.

## `GET /api/v1/sales-orders/{id}`

Includes nested `lines`. `403` if a Sales Rep requests another rep's order.

## `POST /api/v1/sales-orders`

Requires `sales.add`. Validates stock availability (FEFO, no deduction yet)
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

**201** — the created order with lines (see shapes above).

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

**403** — user lacks `sales.add`, e.g. `Customer Service`.

---

## `PUT /api/v1/sales-orders/{id}/status`

Requires `sales.edit` (+ ownership if Sales Rep). Drives the whole
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

**Additionally, on transition to `delivered`**: the order's one delivery
record is created (or updated) with `status: "delivered"` and a real
`delivered_at` timestamp.

**200** — the order, with `invoices` (array, empty until the first
`invoiced`/`delivered` transition) and `delivery` (`null` until the order
actually reaches `delivered`) refreshed. This is the only sales-order
endpoint that includes these two — `GET /sales-orders`/`GET
/sales-orders/{id}` don't, to avoid the extra joins on every list request.
**422** — invalid transition, or insufficient stock (same shortfall shape
as order creation).
**403** — Sales Rep on someone else's order, or missing `sales.edit`.

---

## Invoices

### `GET /api/v1/invoices`, `GET /api/v1/invoices/{id}`

Requires `sales.view` or `accounting.view`. Show includes `lines` (mirrored
from the originating sales order — there's no separate invoice-lines table)
and `collections`.

```json
{
  "data": {
    "id": "01m...", "so_id": "01m...", "customer_id": "01m...",
    "issued_date": "2026-09-18", "due_date": null,
    "subtotal": "650.00", "tax_amount": "91.00", "total": "741.00",
    "paid": "300.00", "balance": "441.00", "status": "partial",
    "lines": [ "...sales order lines..." ],
    "collections": [ "...collections below..." ]
  }
}
```
`status`: `outstanding` (paid = 0) → `partial` (0 < paid < total) → `paid`
(paid ≥ total). There's no `overdue` job yet — that's a scheduled command,
still to come. Invoices are never deleted, only voided (voiding isn't
built yet either).

There's no `POST /invoices` — they only come from the status-transition
endpoint above.

---

## Deliveries

### `GET /api/v1/deliveries`, `GET /api/v1/deliveries/{id}`

Requires `sales.view` (+ ownership if Sales Rep).

### `PUT /api/v1/deliveries/{id}/deliver`

Requires `sales.edit` (+ ownership if Sales Rep). Marks a delivery
delivered directly — for one not already completed by its order's own
status transition to `delivered`.

```json
{
  "data": {
    "id": "01m...", "so_id": "01m...", "invoice_id": "01m...", "customer_id": "01m...",
    "driver": null, "delivery_date": "2026-09-18", "status": "delivered",
    "delivered_at": "2026-09-18T10:15:00+00:00", "notes": null
  }
}
```

---

## Collections

### `GET /api/v1/collections`

Requires `sales.view` or `accounting.view` (+ ownership if Sales Rep, via
the invoice's originating order).

### `GET /api/v1/collections/{id}`

Same permissions as the list. Includes the nested `invoice`, `customer` and
`collected_by` user. `403` for a Sales Rep requesting a collection on
another rep's order.

### `POST /api/v1/collections`

Requires `sales.add` or `accounting.add`. Records a payment against an
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
Purchase Return is squarely their job. Requires `sales.add` **or**
`purchasing.add` to create, `sales.view` **or** `purchasing.view` to list.

### `GET /api/v1/returns`

### `POST /api/v1/returns`

**No financial side effect** — this only ever moves stock (or doesn't). It
never touches an invoice's `paid`/`balance` or a customer's AR balance;
the spec describes only the stock-movement rule for returns.

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
**403** — e.g. `Customer Service`, which has neither `sales.add` nor
`purchasing.add`.

---

## `GET /api/v1/reports/ar-aging`

Requires `sales.view` **or** `accounting.view`. Every customer with an
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
