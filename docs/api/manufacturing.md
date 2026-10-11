# Manufacturing 🆕 NEW

> 🆕 **NEW module.** Every endpoint on this page is new.

Make a finished product from component products. Two documents:

- a **BOM** (bill of materials) — the recipe;
- a **production order** — "run this recipe N times in this warehouse".

Completing a production order consumes the components (FEFO), creates the
finished-goods batch at its real cost, and posts the cost to the chart of
accounts. Accounts `1300` (Inventory) and `6400` (Manufacturing Overhead
Absorbed) are looked up by code — run `AccountSeeder`.

| Role | Permissions |
|---|---|
| `Warehouse Manager` | `manufacturing.view/add/edit/approve/print/export` |
| `Warehouse Employee` | `manufacturing.view/add/edit` — cannot complete |
| `Owner` | `manufacturing.view/approve/print/export/audit` |
| `Auditor` | `manufacturing.view/export/audit` |
| `Administrator` | everything, including `manufacturing.delete` (grantable to any role via [Roles](./admin.md#roles--permissions)) |

Records belong to the caller's tenant (another tenant's is **404**). The
production warehouse must be visible to the caller.

---

## Bills of materials

### BOM object

```json
{
  "id": "01m...", "name": "Herbal tonic",
  "product": { "id": "01m...", "name": "Herbal Tonic 1L", "sku": "FG-001" },
  "output_qty_cartons": 5, "conversion_cost": "30.00", "notes": null, "active": true,
  "lines": [
    { "id": "01m...", "component": { "id": "01m...", "name": "Herb extract", "sku": "RM-001" },
      "component_product_id": "01m...", "qty_cartons": 2 }
  ],
  "created_at": "2026-10-11T09:00:00.000000Z"
}
```

One **run** consumes every line's `qty_cartons` and yields `output_qty_cartons` of
the finished product, with `conversion_cost` (labour/overhead) per run.

### `GET /api/v1/boms` 🆕 NEW

Requires `manufacturing.view`. Paginated; `search` matches name, notes and the
finished product's name/SKU. Export needs `manufacturing.export`.

### `GET /api/v1/boms/{id}` 🆕 NEW

Requires `manufacturing.view`.

### `POST /api/v1/boms` 🆕 NEW

Requires `manufacturing.add`.

```json
{
  "product_id": "01m...", "name": "Herbal tonic",
  "output_qty_cartons": 5, "conversion_cost": 30, "notes": null, "active": true,
  "lines": [ { "component_product_id": "01m...", "qty_cartons": 2 } ]
}
```

`product_id`, `name` (unique per tenant) and at least one line are required.
Components must be distinct and **a product cannot be a component of itself**
(422 on `lines.N.component_product_id`). **201**.

### `PUT /api/v1/boms/{id}` 🆕 NEW

Requires `manufacturing.edit`. Partial; `lines`, if sent, **replace every line**.
Existing production orders are unaffected (completed ones keep their own
consumption records).

### `DELETE /api/v1/boms/{id}` 🆕 NEW

Requires `manufacturing.delete`. **204**; lines cascade. **409** when production
orders were raised from it — retire it with `active: false` instead (inactive
BOMs cannot start new production orders).

---

## Production orders

Status flow: `draft → completed` or `draft → cancelled`. A draft touches no
stock and posts nothing. Only drafts can be edited, completed or cancelled
(**422** `Only draft production orders can be changed.`).

### Production order object

```json
{
  "id": "01m...",
  "bom": { "id": "01m...", "name": "Herbal tonic", "product_id": "01m..." },
  "warehouse": { "id": "01m...", "name": "Main Warehouse" },
  "status": "completed", "runs": 3, "output_qty_cartons": 15,
  "production_date": "2026-10-11", "batch_no": "TONIC-001",
  "mfg_date": "2026-10-11", "exp_date": "2027-10-11",
  "conversion_cost": "90.00", "material_cost": "95.00", "cost_per_carton": "12.33",
  "output_batch_id": "01m...", "journal_entry_id": "01m...", "notes": null,
  "consumptions": [
    { "product_id": "01m...", "batch_id": "01m...", "batch_no": "H-1", "qty_cartons": 4,
      "cost_per_carton": "10.00", "line_value": "40.00" }
  ],
  "created_by": { "id": "01m...", "name": "Khalid" },
  "completed_by": { "id": "01m...", "name": "Khalid" }, "completed_at": "2026-10-11T10:00:00.000000Z",
  "created_at": "2026-10-11T09:00:00.000000Z"
}
```

`material_cost`, `cost_per_carton`, `output_batch_id`, `journal_entry_id` and
`consumptions` are filled on completion.

### `GET /api/v1/production-orders` 🆕 NEW

Requires `manufacturing.view`. `search` matches id, batch number, notes, BOM and
warehouse name. Export needs `manufacturing.export` **or** `accounting.export`.

### `GET /api/v1/production-orders/{id}` 🆕 NEW

Requires `manufacturing.view`.

### `POST /api/v1/production-orders` 🆕 NEW

Requires `manufacturing.add`.

```json
{
  "bom_id": "01m...", "warehouse_id": "01m...", "runs": 3,
  "batch_no": "TONIC-001", "production_date": "2026-10-11",
  "mfg_date": null, "exp_date": "2027-10-11", "conversion_cost": null, "notes": null
}
```

`bom_id` (an **active** BOM), `warehouse_id` and `exp_date` (after the
production date — never defaulted) are required. `output_qty_cartons` is derived
(`BOM output × runs`); `conversion_cost` defaults to `BOM conversion cost × runs`;
`batch_no` defaults to a generated `MO-yymmdd-XXXX`. **201**, status `draft`.

### `PUT /api/v1/production-orders/{id}` 🆕 NEW

Requires `manufacturing.edit`. Draft only. Changing `runs` or `bom_id`
re-derives the output and (unless you send it) the conversion cost.

### `DELETE /api/v1/production-orders/{id}` 🆕 NEW

Requires `manufacturing.delete`. Drafts and cancelled orders only — a completed
order cannot be deleted (**422**). **204**.

### `POST /api/v1/production-orders/{id}/cancel` 🆕 NEW

Requires `manufacturing.edit`. Draft → cancelled; nothing else changes.

### `POST /api/v1/production-orders/{id}/complete` 🆕 NEW

Requires `manufacturing.approve`. One transaction:

1. every BOM component × `runs` is consumed from the warehouse, **earliest
   expiry first (FEFO)**. If any component is short the whole order is refused —
   **422** with the usual `shortfalls` array and nothing written;
2. output is costed: **material cost** (what the consumed batches cost) +
   `conversion_cost`, spread over the cartons produced → `cost_per_carton`;
3. the finished-goods batch is created at that cost (a `batch_no` that already
   exists in the warehouse is **422**);
4. the journal entry is posted:

```
Dr 1300  Inventory                          material + conversion   (finished goods)
Cr 1300  Inventory                          material                (components used)
Cr 6400  Manufacturing Overhead Absorbed    conversion              (income statement)
```

   so inventory carries the full cost of what was made and the overhead is
   absorbed rather than expensed twice.

Example: 3 runs of a BOM (2 herb + 5 bottle cartons per run, 5 cartons out,
30 conversion per run) → materials 95 + conversion 90 = 185 over 15 cartons =
**12.33 per carton**.
