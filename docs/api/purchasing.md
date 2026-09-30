# Purchasing

`Purchasing` role has `purchasing.view/add/edit/approve/print`. Receiving a
PO specifically requires `purchasing.approve` (spec §5.2/§6) — `add` alone
is not enough.

**Note:** the spec's own endpoint table (§6) never actually lists a
`/suppliers` endpoint, even though `purchase_orders.supplier_id` needs one
to point at and §7's seed data names three suppliers. Filling that gap here
with the same `GET/POST` shape every other reference entity gets
(categories, etc.) — flagging it since it's not literally in the spec table.

---

## `GET /api/v1/suppliers`

Requires `purchasing.view`.

## `POST /api/v1/suppliers`

Requires `purchasing.add`. Only `name` is required.

```json
{
  "name": "EgyVet Pharmaceutical", "country": "Egypt", "city": "Cairo",
  "contact": null, "email": null, "phone": null,
  "pay_terms": "Net 30", "currency": "EGP", "rating": null, "status": "active"
}
```

**201** — includes `balance`: the AP balance, **negative** means we owe
them (per spec §4.5) — it starts at 0 and moves further negative every time
a PO from this supplier is received.

## `PUT /api/v1/suppliers/{id}`

Requires `purchasing.edit`, and the supplier must belong to your account
(**403** otherwise). Partial update — send only the fields you're changing;
same fields and rules as `POST`. `name` stays unique per account, but keeping
a supplier's own current name is fine.

```json
{ "contact": "Ahmed Hassan", "rating": 5, "status": "inactive" }
```

**200** — the updated supplier. **422** if `name` is taken by another
supplier. `balance` is not editable — it's ignored if sent; only receiving a
PO moves it.

---

## `GET /api/v1/purchase-orders`, `GET /api/v1/purchase-orders/{id}`

Requires `purchasing.view`. Show includes `lines`.

## `POST /api/v1/purchase-orders`

Requires `purchasing.add`.

**Request**
```json
{
  "supplier_id": "01m...", "warehouse_id": "01m...",
  "expected_date": null, "notes": null, "order_date": "2026-09-19",
  "lines": [
    { "product_id": "01m...", "qty_cartons": 50, "cost_per_carton": 40 }
  ]
}
```

**Line math** (mirrors sales orders): `line.total = qty_cartons ×
cost_per_carton`. `order.subtotal = Σ line.total`, `order.tax_amount = Σ
(line.total × product.tax_pct/100)`, `order.total = subtotal + tax_amount`.
No discount field at this level — POs don't have one in the spec.

**201** — the created PO, `status: "draft"`.

---

## `POST /api/v1/purchase-orders/{id}/receive`

Requires `purchasing.approve`. Adds stock for every line — this is where
inventory actually increases, exactly like manual GRN (same
product+warehouse+batch_no create-or-topup rule, and the same requirement
for a real expiry date per line, never fabricated).

**Every PO line must have exactly one matching entry** in `receipts`,
matched by `line_id` — partial receiving isn't supported in this build.

**Request**
```json
{
  "receipts": [
    { "line_id": "01m...", "batch_no": "PO-BATCH-1", "exp_date": "2028-01-01", "mfg_date": null, "rcv_date": null }
  ]
}
```

**On success**:
- A batch is created (or topped up, if `batch_no` matches an existing one
  for that product+warehouse) for every line, using that line's
  `cost_per_carton` as the batch's cost.
- `status` → `received`, `stock_added` → `true`, `received_date` set.
- The supplier's `balance` decreases by the PO `total` (more negative — we
  owe more).
- A balanced journal entry posts: Dr Inventory (1300), Cr Accounts Payable
  (2100), both for the PO total.

**200** — the PO with refreshed `lines`.

**422** — a `receipts` entry is missing `exp_date`, the `receipts` array
doesn't exactly cover every PO line, or the PO was already received (a
second `receive` call is rejected, not silently ignored — if you need to
know whether one already happened, check `stock_added` on the PO first).

**403** — user has `purchasing.add` but not `purchasing.approve`.

---

## `GET /api/v1/reports/ap-aging`

Requires `purchasing.view` **or** `accounting.view`. Every supplier with a
negative `balance` (i.e. money we owe them), sorted by amount owed —
**not** a date-bucketed aging report like AR's; see
[accounting.md](./accounting.md#get-apiv1reportsap-aging) for why.

```json
{
  "data": {
    "as_of": "2026-09-20",
    "suppliers": [ { "supplier_id": "01m...", "supplier_name": "ArabiVet Industries", "balance": "-2280.00" } ],
    "grand_total_owed": "2280.00"
  }
}
```

---

## Not built in this step

- No `PUT /purchase-orders/{id}` (edit) or cancel — not in the spec's
  endpoint table for POs either.
- No partial/split receiving — a PO is received all at once, all lines.
- No supplier-payment flow (paying down what we owe) — the spec describes
  collections (customer payments) in detail but nothing equivalent for
  suppliers; `suppliers.balance` only ever moves via PO receipt right now.
  This is also why AP aging above can't be a real date-bucketed report.
