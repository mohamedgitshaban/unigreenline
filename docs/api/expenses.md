# Expenses

Operating expenses (rent, utilities, transport, etc.) recorded as drafts and
then approved or rejected. **Nothing reaches the journal until approval.**

| Role | Permissions |
|---|---|
| `Accountant` | `expenses.view/add/edit/print/export` + `expenses.approve` |
| `Purchasing` | `expenses.view/add/edit` — records expenses, can't approve |
| `Owner` | `expenses.view/print/export/audit` — **not** `expenses.approve` |
| `Administrator` | everything, including `expenses.delete` (no other role has it) |

Every expense belongs to the caller's tenant; another tenant's expense or
category returns **404**.

---

## Expense categories

Each category points at one **Expense-type** chart-of-accounts account
(e.g. `6200` General & Administrative Expense) — approving an expense in
that category debits it. There is no delete: retire a category with
`active: false` (inactive categories can't take new expenses, existing ones
keep it).

### `GET /api/v1/expense-categories`

Requires `expenses.view`. Paginated. `search` matches name, description and
the account's name/code.

```json
{
  "data": [
    {
      "id": "01m...", "name": "Office Rent", "description": null, "active": true,
      "account": { "id": "01m...", "code": "6200", "name": "General & Administrative Expense" }
    }
  ]
}
```

### `POST /api/v1/expense-categories`

Requires `expenses.add`.

```json
{ "name": "Office Rent", "account_id": "01m...", "description": null, "active": true }
```

`name` (unique per tenant) and `account_id` are required. `account_id` must
be an `Expense`-type account in the caller's tenant — anything else is `422`.
`active` defaults to `true`.

**201** — the created category.

### `PUT /api/v1/expense-categories/{id}`

Requires `expenses.edit`. All fields optional, same rules as create.

---

## Expenses

Status flow: `draft → approved` or `draft → rejected`. Only drafts can be
edited, deleted, approved or rejected — doing any of that to an approved or
rejected expense returns:

**422** `{"message": "Only draft expenses can be changed."}`

### Expense object

```json
{
  "id": "01m...",
  "category": { "id": "01m...", "name": "Office Rent", "description": null, "active": true,
                "account": { "id": "01m...", "code": "6200", "name": "General & Administrative Expense" } },
  "warehouse": { "id": "01m...", "name": "Main Warehouse" },
  "supplier": null,
  "payee": "Landlord",
  "status": "draft",
  "payment_method": "cash",
  "expense_date": "2026-10-01",
  "amount": "1250.50",
  "reference": "INV-77",
  "description": "Office rent — October",
  "receipt_path": "expense-receipts/01m.../a1b2c3.pdf",
  "receipt_url": "https://.../api/v1/expenses/01m.../receipt",
  "created_by": { "id": "01m...", "name": "Rana" },
  "approved_by": null,
  "approved_at": null,
  "rejection_reason": null,
  "journal_entry_id": null,
  "created_at": "2026-10-01T09:00:00.000000Z"
}
```

`warehouse`, `supplier`, `created_by`, `approved_by` are `null` when not set.
`receipt_url` is `null` when there is no receipt. `approved_by`/`approved_at`
are also filled on rejection (who rejected it, and when).

### `GET /api/v1/expenses`

Requires `expenses.view`. Paginated. `search` matches id, payee, reference,
description, and the category/supplier/warehouse names. Export
(`?export=csv|xlsx`) needs `expenses.export` **or** `accounting.export`.

### `GET /api/v1/expenses/{id}`

Requires `expenses.view`. Returns the expense object.

### `POST /api/v1/expenses/receipts`

Requires `expenses.add`. **Step 1 of attaching a receipt.** `multipart/form-data`
with a single `receipt` file — `jpg`, `jpeg`, `png` or `pdf`, max 5 MB.

**201**
```json
{
  "data": {
    "receipt_path": "expense-receipts/01m.../a1b2c3.pdf",
    "original_name": "rent-october.pdf",
    "mime_type": "application/pdf",
    "size": 48213
  }
}
```

Send `receipt_path` on create/update to attach it. A path is only accepted
if it was uploaded by this endpoint for the same tenant, still exists, and
isn't already attached to another expense — otherwise `422`.

### `POST /api/v1/expenses`

Requires `expenses.add`. Always created as `draft`.

```json
{
  "category_id": "01m...", "warehouse_id": null, "supplier_id": null, "payee": "Landlord",
  "payment_method": "cash", "expense_date": "2026-10-01", "amount": "1250.50",
  "reference": "INV-77", "description": "Office rent — October", "receipt_path": null
}
```

Required: `category_id` (an **active** category), `payment_method`,
`expense_date`, `amount` (≥ 0.01, max 2 decimals). `payment_method` is
`cash` or `bank` — it decides which account approval credits.
`warehouse_id`/`supplier_id` must belong to the caller's tenant.

**201** — the expense object.

### `PUT /api/v1/expenses/{id}`

Requires `expenses.edit`. **Drafts only.** All fields optional, same rules
as create; `status` can't be set here. Send a new `receipt_path` to replace
the receipt (the old file is deleted) or `null` to remove it.

### `DELETE /api/v1/expenses/{id}`

Requires `expenses.delete` (Administrator only by default). **Drafts only** —
an approved expense needs a reversing journal entry, not a delete, and a
rejected one is kept as the record of the rejection.

- **204** — deleted, along with its receipt file (audit-logged as `DELETE`).
- **422** — not a draft.

### `POST /api/v1/expenses/{id}/approve`

Requires `expenses.approve` (Accountant or Administrator). **Drafts only.**
No body.

Posts a balanced journal entry dated on `expense_date`:

- Dr the category's expense account
- Cr Cash (`1110`) if `payment_method` is `cash`, Bank (`1120`) if `bank`

Sets `status: "approved"`, `approved_by`, `approved_at` and
`journal_entry_id`. The expense then shows up on the income statement.

**200** — the updated expense. **422** — already approved/rejected.

### `POST /api/v1/expenses/{id}/reject`

Requires `expenses.approve`. **Drafts only.** Posts nothing to the journal.

```json
{ "reason": "No receipt attached" }
```

`reason` is optional (max 2000 characters) and comes back as
`rejection_reason`. A rejected expense can no longer be edited, approved or
deleted.

**200** — the updated expense. **422** — not a draft.

### `GET /api/v1/expenses/{id}/receipt`

Requires `expenses.view`. Downloads the receipt file
(`receipt-{id}.pdf` / `.jpg` / `.png`) — this is what `receipt_url` points
at. Send the bearer token; it is not a public link.

**404** — the expense has no receipt.
