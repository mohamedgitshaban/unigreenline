# Purchasing

The `Purchasing` role holds the view / create / update permissions of suppliers and purchase orders plus
`purchase-orders.receive` and `purchase-orders.upload-invoice`. Receiving a PO
specifically requires `purchase-orders.receive` (spec §5.2/§6) — `purchase-orders.create` alone is not enough.

**Note:** the spec's own endpoint table (§6) never actually lists a
`/suppliers` endpoint, even though `purchase_orders.supplier_id` needs one
to point at and §7's seed data names three suppliers. Filling that gap here
with the same `GET/POST` shape every other reference entity gets
(categories, etc.) — flagging it since it's not literally in the spec table.

---

## `GET /api/v1/suppliers`

Requires `suppliers.view`.

## `GET /api/v1/suppliers/{id}`

Same permissions as the list. **404** — the supplier belongs to another account.

## `POST /api/v1/suppliers/contracts`

Requires `suppliers.upload-contract`. **Step 1 of attaching a
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

Requires `suppliers.create`. Only `name` is required.

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

Requires `suppliers.update`, and the supplier must belong to your account
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

## `DELETE /api/v1/suppliers/{id}` 🆕 NEW

Requires `suppliers.delete`. **204**, audit-logged as `DELETE`. **409** while
the supplier has purchase orders or expenses — set `status: inactive` instead.
The contract file on disk is not removed by this call. Another tenant's supplier
is **404**.

## `POST /api/v1/suppliers/import` 🆕 NEW

Requires `suppliers.import`. Multipart `file` (+ optional `update_existing`) — see
[Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
Matched by **name**. Columns: `Name`*, `Country`, `City`, `Address`, `Contact`,
`Email`, `Phone`, `Pay Terms`, `Currency` (3 letters), `Rating` (1–5), `Status`.
Contract files cannot be imported — attach them afterwards with
`POST /suppliers/contracts`.

## `GET /api/v1/suppliers/import-template` 🆕 NEW

Requires `suppliers.import`. Downloads `suppliers-import-template.xlsx`.

## `GET /api/v1/suppliers/{id}/contract`

Requires `suppliers.view`. Downloads the contract file
(`contract-{id}.pdf` etc.) — this is what `contract_url` points at. Send the
bearer token; it isn't a public link.

**404** — the supplier has no contract, or belongs to another account.

---

## `GET /api/v1/purchase-orders`, `GET /api/v1/purchase-orders/{id}`

Requires `purchase-orders.view`. Includes `lines_count`.

## `GET /api/v1/purchase-orders/{id}/print` 🆕 NEW

Requires `purchase-orders.print`. The purchase order as it is sent to the supplier: supplier and delivery warehouse, dates, lines with cost per carton and amounts (shown as `—` while the order is pending pricing) and totals, with *Prepared by / Approved by / Supplier* signature boxes. `?format=json` for the data. See [Printing documents](./README.md#printing-documents--new).

> 🆕 The **warehouse** receiving an order sees it, without prices, in [`GET /warehouses/{id}/incoming`](./inventory.md#get-apiv1warehousesidincoming--new) and its manager is notified when it is created, priced or received. Receiving writes `purchase_receipt` lines in the [stock ledger](./inventory.md#stock-movement-ledger--new).

## `GET /api/v1/purchase-orders/{id}/lines`

Requires `purchase-orders.view`. The PO's lines (each with its `product`) as
their own list. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README. A line's `id` is the `line_id` that receive
expects.

## Pending pricing: who sets the cost 🆕 NEW

> 🆕 **NEW flow.** A buyer no longer types `cost_per_carton`. They raise the order
> with quantities only; it waits in the status **`pending_pricing`** until an
> **administrator** sets the cost of each line.

```
buyer creates (no costs) ──► pending_pricing ──► admin prices ──► draft ──► sent/pending ──► received
```

- **Who may price:** the permission **`purchase-orders.pricing`** — held by
  `Administrator` only. An admin can grant it to any role through
  [Roles](./admin.md#roles--permissions); it is not part of the usual
  view/add/edit/... grid. (Re-run `RolePermissionSeeder` to create it.)
- **Creating:** `lines[].cost_per_carton` / `cost_per_unit` are now **optional**
  and may only be sent by a user with `purchase-orders.pricing` — anyone else sending
  a cost gets **422** on `lines.N.cost_per_carton`. An order with any unpriced
  line is created with `status: "pending_pricing"`, `subtotal`/`tax_amount`/`total`
  `0.00` and line costs `0.00`. A pricer who sends a cost for every line gets a
  normal `draft`, exactly as before.
- **Waiting orders can't move on:** a `pending_pricing` order cannot be received
  (**422** `This purchase order is pending pricing — an administrator must set the
  line costs first.`), and its `status` can't be changed through `PUT` except to
  `cancelled`. Everything else (notes, expected date, the lines' quantities) can
  still be edited by the buyer; replacing the lines of a priced order drops the
  costs again and sends it back to `pending_pricing`.
- `pending_pricing` is a normal filter value: `GET /purchase-orders?filter[status]=pending_pricing`.

### `GET /api/v1/purchase-orders/pending-pricing` 🆕 NEW

Requires `purchase-orders.pricing` (**403** otherwise). The admin's queue: the
tenant's orders still in `pending_pricing`, newest first, same shape as
`GET /purchase-orders` (with `lines_count`). Supports `search`
(id, notes, supplier, warehouse), `sort_by` and pagination.

### `PUT /api/v1/purchase-orders/{id}/pricing` 🆕 NEW

Requires `purchase-orders.pricing`. Sets the cost of **every** line in one call:

```json
{
  "lines": [
    { "line_id": "01m...", "cost_per_carton": 90 },
    { "line_id": "01m...", "cost_per_unit": 5 }
  ]
}
```

- every line of the order must appear exactly once (**422** on `lines`);
- each entry has **exactly one** of `cost_per_carton` or `cost_per_unit`;
  `cost_per_unit` is only valid for a line that was ordered in a packaging unit
  (its total is `unit_qty × cost_per_unit`), otherwise send `cost_per_carton`;
- subtotal, tax (`product.tax_pct`) and total are recomputed the same way as on
  create;
- a `pending_pricing` order is released to `draft`; an order that was already
  priced and not yet received can be **re-priced** the same way (status unchanged);
- **422** for a received order (`This purchase order has already been received.`)
  or a cancelled one; **404** for another tenant's order.

**200** — the purchase order. Audit-logged with the before/after status and total.

## `POST /api/v1/purchase-orders` 🔄 CHANGED

Requires `purchase-orders.create`. 🔄 Costs are now optional — see [Pending pricing](#pending-pricing-who-sets-the-cost--new). A line can now be ordered **in any packaging unit**
of its product, not only cartons (see
[Units & product packaging](./inventory.md#units--product-packaging--new)).

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

**🔄 CHANGED — lines in a unit.** Instead of `qty_cartons` (+ `cost_per_carton`),
send `unit_id` + `qty` (+ `cost_per_unit`):

```json
{ "product_id": "01m...", "unit_id": "01m...package", "qty": 6, "cost_per_unit": 50 }
```

For a product where Package = 10 packets and Carton = 30 packets: 6 packages =
60 packets = **2 cartons**; the line total is `qty × cost_per_unit` = 300, so
`cost_per_carton` is stored as 150. The quantity **must convert to a whole number
of cartons** (45 packets of a 30-packet carton is **422** on `lines.N.qty`), and
`unit_id` must be one of the product's units (**422** on `lines.N.unit_id`).
Either form works per line; lines come back with `unit_id`, `unit_qty`,
`cost_per_unit` (null for carton lines) next to `qty_cartons` / `cost_per_carton`,
and stock is always received in cartons.

**Line math** (mirrors sales orders): `line.total = qty_cartons ×
cost_per_carton`. `order.subtotal = Σ line.total`, `order.tax_amount = Σ
(line.total × product.tax_pct/100)`, `order.total = subtotal + tax_amount`.
No discount field at this level — POs don't have one in the spec.

**201** — the created PO, `status: "draft"`, with `lines_count`. Get the
line ids from `GET /purchase-orders/{id}/lines`.

---

## `PUT /api/v1/purchase-orders/{id}`

Requires `purchase-orders.update`. 🔄 `lines` accept the same unit form as create. **Only while not received** — same rule as delete.
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

Requires `purchase-orders.delete` — by default only **Administrator** has it
(`Purchasing` has view/add/edit/approve/print, not delete).

**Only while not received.** Creating a PO has no stock, supplier-balance or
journal side effects, so a draft/sent/pending/cancelled PO is simply removed
along with its lines.

- **204** — deleted (audit-logged as `DELETE`).
- **422** `{"message": "This purchase order has already been received."}` — a
  received PO (`stock_added: true`) can't be deleted; its stock, AP balance
  and journal entry would need reversing, not deleting.
- **404** — the PO belongs to another account.

## `POST /api/v1/purchase-orders/{id}/receive` 🔄 CHANGED

> 🔄 **CHANGED — free gifts.** The delivery can also carry bonus stock the
> supplier threw in. Send it as `free_gifts` (below); it goes into stock at zero
> cost, flagged as a free gift.

Requires `purchase-orders.receive`. Adds stock for every line — this is where
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
  ],
  "free_gifts": [
    { "product_id": "01m...", "unit_id": "01m...package", "qty": 3, "batch_no": "GIFT-1", "exp_date": "2028-01-01", "mfg_date": null }
  ]
}
```

**`free_gifts`** (🆕 optional): each entry is a product the supplier gave for
free. `qty` is in the chosen `unit_id` (or in **cartons** when `unit_id` is
omitted) and must convert to whole cartons. `batch_no` and `exp_date` are
required. The gift products need not be on the PO. Effects:

- a batch is added to the PO's warehouse with `is_free_gift: true`,
  `cost_per_carton: 0` and `source_po_id` = this PO;
- the supplier balance, the PO total and the journal entry are **not** affected
  (gifts have no cost);
- only **gift lines** on a sales order can sell this stock — see
  [sales.md](./sales.md);
- a gift batch number may not equal a paid batch's number for the same product
  and warehouse (**422**), and the whole receipt is rolled back on any error.

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
- 🔄 A balanced journal entry posts: **Dr Inventory (1300)** for the order's
  subtotal, **Dr VAT Receivable (1400)** for its tax, **Cr Accounts Payable
  (2100)** for the total. Stock is booked at cost excluding tax (matching the
  batch costs); the tax is input VAT, to be offset against VAT Payable.

**200** — the updated PO (with `lines_count` and `supplier_invoice`).

**422** — `invoice_data` without `invoice_no` or with a `file_path` not from
the upload endpoint, a `receipts` entry is missing `exp_date`, the `receipts` array
doesn't exactly cover every PO line, or the PO was already received (a
second `receive` call is rejected, not silently ignored — if you need to
know whether one already happened, check `stock_added` on the PO first).

**403** — user has `purchase-orders.create` but not `purchase-orders.receive`.

## `POST /api/v1/purchase-orders/invoices`

Requires `purchase-orders.upload-invoice`. **Step 1 of attaching the supplier's invoice
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

Requires `purchase-orders.view`. Downloads the supplier invoice file
(`supplier-invoice-{id}.pdf` etc.) — what `supplier_invoice.file_url` points
at. **404** — no file attached, or the PO belongs to another account.

---

## `GET /api/v1/reports/ap-aging`

Requires `ap-aging.view`. Every supplier with a
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
- 🔄 Supplier payments now exist (below), but AP aging is still a balance
  listing, not date-bucketed — payments are against the supplier, not against a
  specific purchase order.
