# Recap (stock write-offs) 🆕 NEW

> 🆕 **NEW module.** Every endpoint on this page is new.

A **recap** is a stock write-off document: stock that expired, was damaged, lost
or recalled leaves the books. It is **connected to the chart of accounts** —
approving a recap removes the cartons from their batches *and* posts

```
Dr 6300  Inventory Write-off Expense      (income statement)
Cr 1300  Inventory                         (balance sheet)
```

for the batches' cost, so the loss shows on the income statement and the
inventory asset drops by the same amount. Accounts `6300` and `1300` are
looked up by code — run `AccountSeeder` so `6300` exists.

🔄 Stock that a manufacturing order has **reserved** cannot be written off: approving
is refused (**422**) when the batch's free cartons — on hand less reserved — are
fewer than the line asks for. Release the reservation (or cancel the order) first.

Status flow: `draft → approved` or `draft → rejected`. A draft changes no stock
and posts nothing. Only drafts can be edited, deleted, approved or rejected —
anything else returns **422** `{"message": "Only draft recaps can be changed."}`.

| Role | Permissions |
|---|---|
| `Warehouse Manager` | `recaps.view/create/update/generate/approve/reject/export` |
| `Accountant` | `recaps.view`, `recaps.export` |
| `Owner` | `recaps.view/approve/reject/export` |
| `Auditor` | `recaps.view/export` |
| `Administrator` | everything, including `recaps.delete` (an admin can grant it to any role via [Roles](./admin.md#roles--permissions)) |

Every recap belongs to the caller's tenant (another tenant's is **404**). The
warehouse must be visible to the caller (assigned warehouses, unless
Administrator/Owner/Auditor) — otherwise **422** on `warehouse_id`.

## Recap object

```json
{
  "id": "01m...",
  "warehouse": { "id": "01m...", "name": "Main Warehouse" },
  "reason": "damaged",
  "status": "draft",
  "recap_date": "2026-10-11",
  "notes": "Damaged in transit",
  "total_value": "200.00",
  "lines": [
    { "id": "01m...", "batch_id": "01m...", "batch_no": "BATCH-001",
      "product": { "id": "01m...", "name": "Ivermectin 1%", "sku": "PRD-00002" },
      "exp_date": "2026-09-01", "qty_cartons": 4, "cost_per_carton": "50.00", "line_value": "200.00" }
  ],
  "created_by": { "id": "01m...", "name": "Khalid" },
  "approved_by": null, "approved_at": null, "rejection_reason": null,
  "journal_entry_id": null,
  "created_at": "2026-10-11T09:00:00.000000Z"
}
```

`reason` is one of `expired`, `damaged`, `lost`, `recall`, `other`. Lines
snapshot the batch number, expiry and cost; the cost is **re-read at approval**.

### `GET /api/v1/recaps` 🆕 NEW

Requires `recaps.view`. Paginated. `search` matches id, notes and warehouse name.
Export (`?export=csv|xlsx`) needs `recaps.export`.

### `GET /api/v1/recaps/{id}` 🆕 NEW

Requires `recaps.view`. Returns the recap with its lines.

### `POST /api/v1/recaps` 🆕 NEW

Requires `recaps.create`.

```json
{
  "warehouse_id": "01m...",
  "reason": "damaged",
  "recap_date": "2026-10-11",
  "notes": "Damaged in transit",
  "lines": [ { "batch_id": "01m...", "qty_cartons": 4 } ]
}
```

`warehouse_id` and `lines` (at least one) are required. Each `batch_id` must be a
batch **of that warehouse** and may appear once. **201** — the draft recap.

### `PUT /api/v1/recaps/{id}` 🆕 NEW

Requires `recaps.update`. Draft only. Partial update; `lines`, if sent, **replace
every line**. Changing `warehouse_id` requires re-sending `lines` (batches belong
to a warehouse).

### `DELETE /api/v1/recaps/{id}` 🆕 NEW

Requires `recaps.delete`. Draft only (an approved recap already moved stock and
posted a journal entry; a rejected one is kept as the record). **204**; lines
cascade; audit-logged as `DELETE`.

### `POST /api/v1/recaps/{id}/approve` 🆕 NEW

Requires `recaps.approve`. One transaction: each batch is locked and its cartons
removed, the lines are re-costed, the warehouse stock value is refreshed and
`Dr 6300 / Cr 1300` is posted for the total (dated `recap_date`). Response is the
recap with `status: approved`, `total_value` and `journal_entry_id`.

**422** `{"message": "Batch BATCH-001 has only 3 carton(s) left; the recap writes off 8."}`
if a batch no longer holds enough — nothing is changed.

### `POST /api/v1/recaps/{id}/reject` 🆕 NEW

Requires `recaps.reject`. Body `{ "reason": "Counted wrong" }` (optional).
Draft → rejected; stock and ledger untouched.

### `GET /api/v1/recaps/expired-batches` 🆕 NEW

Requires `recaps.view`. Optional `?warehouse_id=`. Batches past their expiry date
that still hold stock — what a write-off would pick up:

```json
{ "data": [ { "batch_id": "01m...", "batch_no": "B-7", "product": { "id": "...", "name": "...", "sku": "..." },
              "warehouse": { "id": "...", "name": "Main" }, "exp_date": "2026-09-01",
              "qty_cartons": 7, "cost_per_carton": "10.00", "value": 70 } ] }
```

### `POST /api/v1/recaps/from-expired` 🆕 NEW

Requires `recaps.generate`. Body `{ "warehouse_id": null }` (optional). Creates **one
draft recap per warehouse** covering the full remaining quantity of every expired
batch (reason `expired`). Batches already on a draft are skipped, so repeating
the call never double-lists stock. **201** with the new drafts, or **200** with an
empty `data` list when nothing new has expired. Drafts still need approving.

## `GET /api/v1/recaps/{id}/print` 🆕 NEW

Requires `recaps.print`. The write-off slip: warehouse, reason, each batch with its expiry and cartons, and signature boxes. Values and the total are included for the Administrator only. `?format=json` for the data. Approving a recap writes `write_off` lines in the [stock ledger](./inventory.md#stock-movement-ledger--new); the manager of the warehouse is notified of write-offs.

## Scheduled job 🆕 NEW

```
php artisan recap:generate-expired
```

Runs daily (scheduler) for every tenant and does what `from-expired` does, with
no acting user. Idempotent. It only drafts — nothing is deducted or posted until
someone approves.
