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

Requires `purchasing.view` or `accounting.view`.

## `GET /api/v1/suppliers/{id}`

Same permissions as the list. **404** — the supplier belongs to another account.

## `POST /api/v1/suppliers/contracts`

Requires `purchasing.add` or `purchasing.edit`. **Step 1 of attaching a
contract.** `multipart/form-data` with a single `contract` file — `pdf`,
`jpg`, `jpeg`, `png`, `doc` or `docx`, max 10 MB.

**201**
```json
{
  "data": {
    "contract_data": "supplier-contracts/01m.../a1b2c3.pdf",
    "original_name": "egyvet-contract-2026.pdf",
    "mime_type": "application/pdf",
    "size": 482113
  }
}
```

Send `contract_data` on create/update to attach it. A path is only accepted
if it was uploaded by this endpoint for your account, still exists, and isn't
already attached to another supplier — otherwise `422`.

## `POST /api/v1/suppliers`

Requires `purchasing.add`. Only `name` is required.

```json
{
  "name": "EgyVet Pharmaceutical", "country": "Egypt", "city": "Cairo", "address": null,
  "contact": null, "email": null, "phone": null,
  "pay_terms": "Net 30", "currency": "EGP", "rating": null, "status": "active",
  "contract_data": "supplier-contracts/01m.../a1b2c3.pdf"
}
```

`contract_data` is optional — the path from `POST /suppliers/contracts`. The
response includes it plus `contract_url` (the download link below, `null`
when there's no contract).

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

Send a new `contract_data` (from `POST /suppliers/contracts`) to replace the
contract — the old file is deleted — or `null` to remove it. Omit it to keep
the current one.

**200** — the updated supplier. **422** if `name` is taken by another
supplier. `balance` is not editable — it's ignored if sent; only receiving a
PO moves it.

## `GET /api/v1/suppliers/{id}/contract`

Requires `purchasing.view` or `accounting.view`. Downloads the contract file
(`contract-{id}.pdf` etc.) — this is what `contract_url` points at. Send the
bearer token; it isn't a public link.

**404** — the supplier has no contract, or belongs to another account.

---

## `GET /api/v1/purchase-orders`, `GET /api/v1/purchase-orders/{id}`

Requires `purchasing.view`. Includes `lines_count`.

## `GET /api/v1/purchase-orders/{id}/lines`

Requires `purchasing.view`. The PO's lines (each with its `product`) as
their own list. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README. A line's `id` is the `line_id` that receive
expects.

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

**201** — the created PO, `status: "draft"`, with `lines_count`. Get the
line ids from `GET /purchase-orders/{id}/lines`.

---

## `PUT /api/v1/purchase-orders/{id}`

Requires `purchasing.edit`. **Only while not received** — same rule as delete.
Partial update: send only the fields you're changing.

```json
{
  "supplier_id": "01m...", "warehouse_id": "01m...",
  "order_date": "2026-09-19", "expected_date": "2026-10-15",
  "notes": "Call before delivery", "status": "sent",
  "lines": [ { "product_id": "01m...", "qty_cartons": 20, "cost_per_carton": 40 } ]
}
```

- `lines` is optional. **If sent, it replaces every existing line** (line ids
  change) and `subtotal`/`tax_amount`/`total` are recalculated exactly like on
  create. Omit it to keep the current lines and totals.
- `status` accepts `draft`, `sent`, `pending`, `cancelled`. `received` is
  rejected (422) — only `POST .../receive` sets it.

- **200** — the updated PO with `lines`.
- **422** `{"message": "This purchase order has already been received."}` — a
  received PO can't be edited.
- **404** — the PO belongs to another account.

## `DELETE /api/v1/purchase-orders/{id}`

Requires `purchasing.delete` — by default only **Administrator** has it
(`Purchasing` has view/add/edit/approve/print, not delete).

**Only while not received.** Creating a PO has no stock, supplier-balance or
journal side effects, so a draft/sent/pending/cancelled PO is simply removed
along with its lines.

- **204** — deleted (audit-logged as `DELETE`).
- **422** `{"message": "This purchase order has already been received."}` — a
  received PO (`stock_added: true`) can't be deleted; its stock, AP balance
  and journal entry would need reversing, not deleting.
- **404** — the PO belongs to another account.

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
  "invoice_data": {
    "invoice_no": "SUP-INV-4471", "invoice_date": "2026-10-05", "amount": "2280.00",
    "file_path": "purchase-order-invoices/01m.../a1b2c3.pdf"
  },
  "receipts": [
    { "line_id": "01m...", "batch_no": "PO-BATCH-1", "exp_date": "2028-01-01", "mfg_date": null, "rcv_date": null }
  ]
}
```

`invoice_data` is **optional** — the supplier's own invoice that came with the
goods. When sent, `invoice_no` is required; `invoice_date`, `amount` and
`file_path` are optional. `file_path` must come from
`POST /purchase-orders/invoices` (below). It comes back on the PO as:

```json
"supplier_invoice": {
  "invoice_no": "SUP-INV-4471", "invoice_date": "2026-10-05", "amount": "2280.00",
  "file_path": "purchase-order-invoices/01m.../a1b2c3.pdf",
  "file_url": ".../api/v1/purchase-orders/01m.../invoice"
}
```
(`null` on a PO received without one.)

**On success**:
- A batch is created (or topped up, if `batch_no` matches an existing one
  for that product+warehouse) for every line, using that line's
  `cost_per_carton` as the batch's cost.
- `status` → `received`, `stock_added` → `true`, `received_date` set.
- The supplier's `balance` decreases by the PO `total` (more negative — we
  owe more).
- A balanced journal entry posts: Dr Inventory (1300), Cr Accounts Payable
  (2100), both for the PO total.

**200** — the updated PO (with `lines_count` and `supplier_invoice`).

**422** — `invoice_data` without `invoice_no` or with a `file_path` not from
the upload endpoint, a `receipts` entry is missing `exp_date`, the `receipts` array
doesn't exactly cover every PO line, or the PO was already received (a
second `receive` call is rejected, not silently ignored — if you need to
know whether one already happened, check `stock_added` on the PO first).

**403** — user has `purchasing.add` but not `purchasing.approve`.

## `POST /api/v1/purchase-orders/invoices`

Requires `purchasing.approve`. **Step 1 of attaching the supplier's invoice
file.** `multipart/form-data` with a single `invoice` file — `jpg`, `jpeg`,
`png` or `pdf`, max 5 MB.

**201**
```json
{
  "data": {
    "file_path": "purchase-order-invoices/01m.../a1b2c3.pdf",
    "original_name": "SUP-INV-4471.pdf", "mime_type": "application/pdf", "size": 91244
  }
}
```

Send `file_path` as `invoice_data.file_path` on receive.

## `GET /api/v1/purchase-orders/{id}/invoice`

Requires `purchasing.view`. Downloads the supplier invoice file
(`supplier-invoice-{id}.pdf` etc.) — what `supplier_invoice.file_url` points
at. **404** — no file attached, or the PO belongs to another account.

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

- No separate cancel endpoint — set `status: "cancelled"` via `PUT`, or
  delete the PO, while it's unreceived.
- No partial/split receiving — a PO is received all at once, all lines.
- No supplier-payment flow (paying down what we owe) — the spec describes
  collections (customer payments) in detail but nothing equivalent for
  suppliers; `suppliers.balance` only ever moves via PO receipt right now.
  This is also why AP aging above can't be a real date-bucketed report.
