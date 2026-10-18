# Manufacturing

> 🆕 **NEW** = a new endpoint · 🔄 **CHANGED** = an existing endpoint that now accepts or returns more.
> The module began as bills of materials plus a one-step `complete`. It is now a full manufacturing and
> planning module: recipes with revisions, an order workflow, reservations, partial production, routing and
> work centers, shop-floor work orders, quality control, scrap and by-products, costing with optional work-in-progress accounting, material
> requirements planning, and reports. **Everything that worked before still works the same way**; new fields are additive.

Deeper reading: [architecture](../manufacturing/architecture-analysis.md) ·
[workflows](../manufacturing/business-workflows.md) · [database](../manufacturing/database-erd.md) ·
[planning algorithm](../manufacturing/mrp-algorithm.md) · [costing and accounting](../manufacturing/costing-and-accounting.md) ·
[permissions](../manufacturing/permissions.md) · [operations](../manufacturing/installation-and-operations.md) ·
[tests](../manufacturing/testing.md).

Accounts used (look them up by code; run `AccountSeeder`): `1300` Inventory, `6300` Inventory Write-off Expense, `6400` Manufacturing
Overhead Absorbed, 🆕 `1350` Work in Progress, 🆕 `5200` Manufacturing Variance.

| Role | Permissions |
|---|---|
| `Warehouse Manager` | view, create, update, approve (confirm, complete, approve BoMs and scrap, convert) on every manufacturing resource; not `*.delete`, not costs, not settings |
| `Warehouse Employee` | view / create / update (plan, start, record production, work orders) — cannot approve, complete or delete |
| `Owner` | view, approve, export, audit (including costs and cost reports) and `manufacturing-settings.update` |
| `Accountant` | `production-orders.view`, `.costs`, `.history`, `manufacturing-reports.*`, `manufacturing-cost-reports.*` |
| `Purchasing` | view planning (`mrp-*`) and convert purchase suggestions |
| `Sales Manager` | `production-orders.view` and `production-orders.from-sales-order` |
| `Auditor` | view, export and costs |
| `Administrator` | everything, including all `*.delete` |

Any role can be changed endpoint by endpoint ([Roles](./admin.md#roles--permissions)); the generated list is in [permissions.md](./permissions.md#manufacturing).
Records belong to the caller's tenant (another tenant's is **404**). The production warehouse must be visible to the caller.

**Conventions**

* Quantities of stock are whole **cartons**. Fractional requirements (a recipe at 1.5 runs) are calculated exactly and rounded **up** to whole cartons when stock is issued or ordered.
* A refused business rule is **422** `{"message": "..."}` (plus details such as `shortfalls`, `materials_to_issue`, `quality_checks`, `remaining_qty_cartons`).
* **Idempotency** 🆕: `record-production`, `consume-materials`, `record-scrap` and `complete` accept an `Idempotency-Key` header (or `idempotency_key` in the body, ≤ 100 chars). The
  first request is processed; a retry with the same key returns the first outcome with `"replayed": true` and does nothing.
* Lists take the usual `search`, `sort_by`, `sort_dir`, `per_page`, plus the filters named on each endpoint; `?export=csv|xlsx` needs the resource's `.export` permission.
* Every change writes the audit log; every order change also writes the order's history.

---

## Settings 🆕 NEW

### `GET /api/v1/manufacturing/settings` · `PUT /api/v1/manufacturing/settings`

Requires `manufacturing-settings.view` / `manufacturing-settings.update` (Administrator and Owner; never granted by a role grid). The company's row is created with the
defaults on first read. `PUT` is partial.

```json
{ "data": {
  "mo_prefix": "MO", "bom_prefix": "BOM", "work_order_prefix": "WO", "scrap_prefix": "SCR", "mrp_prefix": "MRP", "number_padding": 5,
  "default_warehouse_id": "01m...", "lead_time_days": 1, "default_operation_minutes": 60,
  "reservation_policy": "on_confirm", "consumption_policy": "backflush", "backorder_policy": "ask",
  "allow_partial_production": true, "allow_over_consumption": false,
  "scrap_requires_approval": false, "scrap_cost_policy": "expense",
  "require_bom_approval": false, "auto_create_work_orders": true, "allow_reopen_cancelled": false,
  "wip_accounting": false, "overhead_rate_pct": "0.00", "by_product_cost_method": "fixed_pct", "cost_decimals": 2,
  "mrp_horizon_days": 30, "cancel_unstarted_on_demand_cancel": false,
  "allowed_transitions": null,
  "order_transitions": { "draft": ["confirmed", "cancelled", "completed"], "...": [] }
} }
```

| Field | Values |
|---|---|
| `*_prefix` | letters, digits, `-`, `_`, ≤ 10 |
| `reservation_policy` | `on_confirm` · `manual` |
| `consumption_policy` | `backflush` (issue materials as output is recorded) · `manual` (issue first with `consume-materials`) |
| `backorder_policy` | `ask` (the completing request decides) · `always` · `never` |
| `scrap_cost_policy` | `expense` (Dr 6300) · `absorb` (carried by the product) |
| `by_product_cost_method` | `none` · `fixed_pct` · `sales_value` |
| `allowed_transitions` | null, or a map status → allowed next statuses; a **subset** of `order_transitions` (422 if it adds one) |

---

## Bills of materials

A BoM is the recipe for one finished product. One **run** consumes every line's `qty_cartons` (plus its scrap %) and yields `output_qty_cartons`, with
`conversion_cost` per run. Several BoMs may exist for a product; the one used is the approved, active, in-date one with the lowest `priority` number.

### BOM object 🔄 CHANGED

```json
{
  "id": "01m...", "code": "BOM00001", "name": "Herbal tonic", "version": 2, "status": "approved", "priority": 10,
  "effective_from": null, "effective_to": null,
  "product": { "id": "01m...", "name": "Herbal Tonic 1L", "sku": "FG-001" },
  "routing": { "id": "01m...", "code": "R1", "name": "Tonic line" },
  "output_qty_cartons": 10, "conversion_cost": "100.00", "notes": null, "instructions": "Blend 20 min",
  "active": true, "approved_at": "2026-10-18T09:00:00.000000Z", "archived_at": null,
  "lines": [ { "id": "01m...", "component": { "id": "01m...", "name": "Herb", "sku": "RM-001" }, "component_product_id": "01m...",
               "qty_cartons": 5, "scrap_pct": "10.00", "optional": false, "unit_id": null, "unit_qty": null, "operation_id": null } ],
  "by_products": [ { "id": "01m...", "product": { "id": "01m...", "name": "Residue", "sku": "BP-1" }, "product_id": "01m...",
                     "qty_cartons": 2, "cost_share_pct": "10.00", "warehouse_id": null } ],
  "created_at": "2026-10-18T09:00:00.000000Z"
}
```

### `GET /api/v1/boms` · `GET /api/v1/boms/{id}` 🔄 CHANGED

Requires `boms.view`. `search` matches code, name, notes and the finished product. Export needs `boms.export`.

### `POST /api/v1/boms` 🔄 CHANGED

Requires `boms.create`.

```json
{
  "product_id": "01m...", "name": "Herbal tonic", "output_qty_cartons": 10, "conversion_cost": 100, "priority": 10,
  "effective_from": "2026-11-01", "effective_to": null, "routing_id": "01m...", "notes": null, "instructions": "Blend 20 min",
  "lines": [
    { "component_product_id": "01m...", "qty_cartons": 5, "scrap_pct": 10, "optional": false, "operation_id": null },
    { "component_product_id": "01m...", "unit_id": "01m...", "unit_qty": 6 }
  ],
  "by_products": [ { "product_id": "01m...", "qty_cartons": 2, "cost_share_pct": 10, "warehouse_id": null } ]
}
```

* A line gives either `qty_cartons` or `unit_id` + `unit_qty` (a packaging unit of the component, converted to **whole cartons** like a purchase order line; 422 if it is not a whole number of cartons).
* `scrap_pct` (0–99.99) is expected waste on top of the quantity; `optional` lines are not issued automatically.
* `operation_id` must belong to the BoM's routing; the component is then issued when that operation completes.
* **Circular recipes are refused** (422 on `lines`): a product cannot become a component of itself, directly or through other recipes (the message shows the loop).
* By-products: not the finished product, not a component of the same recipe, cost shares ≤ 100 % in total.
* A new BoM gets the next `code` and version 1. It is `approved` immediately, or `draft` when the company requires approval (`require_bom_approval`).
* **201**.

### `PUT /api/v1/boms/{id}` 🔄 CHANGED

Requires `boms.update`. Partial; `lines` and `by_products`, if sent, replace the existing ones. **Versioning:** a change to the lines, by-products, output,
conversion cost, routing or product of an *approved* BoM makes a new `version` and a new immutable revision (and, when approval is required, sends the BoM back to `draft`).
Notes, priority, dates and instructions do not. `active: false` archives it. Confirmed orders keep their own components and the revision they were confirmed with.

### `DELETE /api/v1/boms/{id}` 

Requires `boms.delete`. **204**; **409** while orders were raised from it — archive it instead.

### `POST /api/v1/boms/{id}/approve` 🆕 NEW

Requires `boms.approve`. Draft → approved (needs at least one component and no loop); snapshots the approved version. **422** if already approved.

### `POST /api/v1/boms/{id}/archive` · `POST /api/v1/boms/{id}/restore` 🆕 NEW

Requires `boms.archive` / `boms.restore`. An archived BoM is no longer chosen for new orders; existing orders are untouched.

### `GET /api/v1/boms/{id}/revisions` · `GET /api/v1/boms/{id}/revisions/{revision}` 🆕 NEW

Requires `boms.view`. The version history (newest first); one revision includes the full `snapshot` (header, lines, by-products and the routing's operations as they were).

### `POST /api/v1/boms/{id}/revisions` 🆕 NEW

Requires `boms.revise`. `{ "change_note": "Q4 review" }` — starts a new version of an approved BoM with its current content, with the reason. **201**.

### `GET /api/v1/boms/{id}/components?qty=10` 🆕 NEW

Requires `boms.view`. Everything needed to make `qty` cartons (default one run), **sub-assemblies expanded through their own recipes**, scrap included:

```json
{ "data": { "bom_id": "01m...", "output_qty_cartons": 10, "components": [
  { "level": 1, "kind": "assembly", "product": { "id": "01m...", "name": "Syrup", "sku": "SF-1" }, "parent_product_id": "01m...",
    "qty_cartons": 6.6, "required_cartons": 7, "optional": false, "bom_id": "01m..." },
  { "level": 2, "kind": "material", "product": { "id": "01m...", "name": "Herb", "sku": "RM-1" }, "parent_product_id": "01m...",
    "qty_cartons": 13.2, "required_cartons": 14, "optional": false, "bom_id": null }
] } }
```

---

## Production orders

A production order makes a quantity of a finished product from a BoM in a warehouse. See the [workflow](../manufacturing/business-workflows.md) for the diagrams.

### Production order object 🔄 CHANGED

```json
{
  "id": "01m...", "number": "MO00042",
  "bom": { "id": "01m...", "code": "BOM00001", "name": "Herbal tonic", "product_id": "01m...", "revision": 2 },
  "product": { "id": "01m...", "name": "Herbal Tonic 1L", "sku": "FG-001" },
  "warehouse": { "id": "01m...", "name": "Main Warehouse" },
  "status": "in_progress", "priority": 2, "reservation_status": "reserved",
  "runs": 2, "output_qty_cartons": 20, "produced_qty_cartons": 8, "rejected_qty_cartons": 0, "remaining_qty_cartons": 12, "progress_pct": 40.0,
  "production_date": "2026-10-18", "planned_start": "2026-10-18T08:00:00.000000Z", "planned_finish": "2026-10-19T09:10:00.000000Z",
  "actual_start": "2026-10-18T08:05:00.000000Z", "actual_finish": null,
  "assigned_to": { "id": "01m...", "name": "Rana" },
  "source_type": "sales_order", "source_id": "01m...", "parent_order_id": null, "cancellation_reason": null,
  "batch_no": "MO-261018-AB12", "mfg_date": "2026-10-18", "exp_date": "2027-10-18",
  "conversion_cost": "200.00", "material_cost": "220.00", "cost_per_carton": "21.00",
  "costs": { "planned_material": "220.00", "planned_operation": "90.00", "planned_overhead": "200.00", "actual_material": "110.00",
             "actual_operation": "45.00", "actual_overhead": "80.00", "scrap": "0.00", "capitalized": "168.00", "variance_written_off": "0.00", "wip_accounting": false },
  "output_batch_id": "01m...", "journal_entry_id": "01m...", "notes": null,
  "components": [ { "id": "01m...", "product": { "id": "01m...", "name": "Herb", "sku": "RM-1" }, "product_id": "01m...", "required_qty": "11.0000",
                   "required_cartons": 11, "reserved_cartons": 6, "consumed_cartons": 5, "scrap_pct": "10.00", "optional": false, "work_order_id": null } ],
  "work_orders": [ { "id": "01m...", "number": "WO00007", "sequence": 10, "name": "Mix", "status": "completed", "work_center_id": "01m...", "planned_minutes": 60,
                     "actual_minutes": 60, "scheduled_start": "...", "scheduled_end": "...", "qty_processed": 20, "qty_rejected": 0 } ],
  "outputs": [ { "id": "01m...", "kind": "finished", "product_id": "01m...", "batch_id": "01m...", "batch_no": "MO-261018-AB12", "qty_cartons": 8,
                 "cost_per_carton": "21.00", "line_value": "168.00", "created_at": "..." } ],
  "consumptions": [ { "product_id": "01m...", "kind": "consume", "batch_id": "01m...", "batch_no": "B-1", "qty_cartons": 5, "cost_per_carton": "10.00", "line_value": "50.00" } ],
  "created_by": { "id": "01m...", "name": "Rana" }, "completed_by": null, "completed_at": null, "created_at": "2026-10-18T09:00:00.000000Z"
}
```

`costs` and the cost columns of `outputs` appear only for users with `production-orders.costs`. The older fields (`material_cost`, `cost_per_carton`, `conversion_cost`,
`output_batch_id`, `journal_entry_id`, `consumptions`) keep their meaning.

| `status` | Meaning |
|---|---|
| `draft` | being prepared; nothing reserved or issued; freely editable |
| `confirmed` | components and estimate fixed; materials reserved (per setting) |
| `planned` | work orders created and scheduled |
| `in_progress` | production under way |
| `paused` | on hold |
| `completed` · `cancelled` | closed |

`reservation_status` (`none` / `partial` / `reserved`) is separate from `status`.

### `GET /api/v1/production-orders` 🔄 CHANGED

Requires `production-orders.view`. Filters: `status`, `priority`, `warehouse_id`, `bom_id`, `product_id`, `assigned_to`, `source_type`, `source_id`, `planned_from`, `planned_to`.
`search` matches number, id, batch, notes, BoM. Export needs `production-orders.export`.

### `GET /api/v1/production-orders/{id}` 🔄 CHANGED

Requires `production-orders.view`. Includes components, work orders, outputs and consumptions.

### `POST /api/v1/production-orders` 🔄 CHANGED

Requires `production-orders.create`.

```json
{ "bom_id": "01m...", "warehouse_id": "01m...", "qty_cartons": 25, "priority": 2, "planned_start": "2026-11-01", "planned_finish": "2026-11-03",
  "assigned_to": "01m...", "source_type": "sales_order", "source_id": "01m...", "exp_date": "2027-11-01", "conversion_cost": null, "notes": null }
```

* Give `qty_cartons` (any quantity) **or** `runs` (multiples of the recipe's yield), not both. `exp_date` is optional now — it is needed when finished goods are received (it can be sent with `record-production`).
* The BoM must be **approved and active** (422). `warehouse_id` defaults to the company's default warehouse.
* The order gets its `number`. The conversion cost defaults to the recipe's per-run amount scaled to the quantity. **201**, status `draft`.

### `PUT /api/v1/production-orders/{id}` 🔄 CHANGED

Requires `production-orders.update`. A **draft** may change anything (changing the quantity or recipe re-derives the output and the default conversion cost). Once confirmed only
`priority`, `assigned_to`, `planned_start`, `planned_finish` and `notes` may change (422 otherwise). **`status` is ignored** — status only changes through the actions below.

### `DELETE /api/v1/production-orders/{id}` 🔄 CHANGED

Requires `production-orders.delete`. Drafts and cancelled orders only (**422** otherwise: "Cancel the order before deleting it").

### `POST /api/v1/production-orders/from-sales-order` 🆕 NEW

Requires `production-orders.from-sales-order` (and `sales-orders.view`). Make-to-order: `{ "sales_order_id": "01m..." }` raises a **draft** order for each manufactured product on an open
(draft or picking) sales order that free stock does not cover, carrying `source_type: sales_order`. Never twice for the same product and order.
**201** with the created orders and `skipped: [{ product_id, reason }]` (`no_recipe`, `already_planned`, `covered_by_stock`). 422 if the sales order is not open.

### `POST /api/v1/production-orders/{id}/confirm` 🆕 NEW

Requires `production-orders.confirm`. Draft → confirmed: copies the recipe's components (with scrap) into the order, records the BoM **revision**, estimates planned cost, raises quality
checks, and reserves the materials unless `reservation_policy` is `manual`. A shortage does not block confirmation: `reservation_status` is `partial` and managers are notified.

### `POST /api/v1/production-orders/{id}/plan` 🆕 NEW

Requires `production-orders.plan`. Confirmed → planned: creates the work orders from the routing and books them on their work centers (see [scheduling](../manufacturing/business-workflows.md#4-work-order-execution)), sets planned start/finish.
A recipe without routing is planned by lead time. **422** if a work center has no free slot within a year.

### `POST /api/v1/production-orders/{id}/reserve-materials` · `release-materials` 🆕 NEW

Requires `production-orders.reserve-materials` / `.release-materials`. Reserve what is still missing (earliest-expiring, unexpired batches) / give it all back. The response adds `shortages: [{ product_id, needed, reserved, short }]`.

### `POST /api/v1/production-orders/{id}/start` · `pause` · `resume` 🆕 NEW

Requires `production-orders.start` / `.pause` / `.resume`. `start` (confirmed or planned → in progress) makes the first operations ready; `pause` takes an optional `{ "reason" }` and pauses running operations; `resume` returns a paused order to in progress.

### `POST /api/v1/production-orders/{id}/record-production` 🆕 NEW

Requires `production-orders.record-production`. Receives finished goods (and the by-products that go with them).

```json
{ "qty": 4, "rejected": 0, "batch_no": null, "exp_date": "2027-10-18", "mfg_date": null, "reject_reason": null, "notes": null, "idempotency_key": "receipt-1" }
```

* `qty` good cartons, `rejected` cartons that failed (never received; their cost is absorbed by the good ones and a scrap document is written). `qty + rejected` cannot exceed what is left (422); with `allow_partial_production` off it must equal it.
* With **backflush** the materials for this output are issued first (earliest-expiring non-expired stock, the order's own reservation first). Short of stock: **422** with `shortfalls`, nothing changes. With manual consumption: **422** with `materials_to_issue` until they are issued.
* A confirmed or planned order is started automatically. With work orders, you can only receive what the **last operation** has processed.
* The first receipt's batch is the order's `batch_no`; later ones `…-2`, `…-3`; by-products `…-BP1`. The unit cost comes from the [valuation](../manufacturing/costing-and-accounting.md#valuing-a-receipt). One ledger entry is posted.
* **200** — the order, plus `"replayed": false|true`.

### `POST /api/v1/production-orders/{id}/consume-materials` 🆕 NEW

Requires `production-orders.consume-materials`. Issues materials by hand.

```json
{ "lines": [ { "component_id": "01m...", "cartons": 5 }, { "product_id": "01m...", "cartons": 3, "work_order_id": null } ] }
```

Refused above the order's requirement unless `allow_over_consumption` (422). Expired batches are never issued. **200**, with `replayed`.

### `POST /api/v1/production-orders/{id}/record-scrap` 🆕 NEW

Requires `production-orders.record-scrap`.

```json
{ "kind": "component", "product_id": "01m...", "cartons": 2, "reason": "Spilled", "work_order_id": null, "notes": null }
```

`kind: component` (default) wastes material — it leaves stock and is expensed (`6300`) or carried by the product, per `scrap_cost_policy`. `kind: finished` writes off output that will never exist (no `product_id`). With
`scrap_requires_approval` the scrap waits (`status: pending`) and moves nothing until `production-scraps/{id}/approve`. **200** with `scrap: { id, number, status, scrap_cost }`.

### `POST /api/v1/production-orders/{id}/complete` 🔄 CHANGED

Requires `production-orders.complete`.

```json
{ "produce_remaining": true, "create_backorder": null, "batch_no": null, "exp_date": null, "mfg_date": null, "idempotency_key": null }
```

With no body this still does what it always did on a draft order — consumes the components, creates the finished batch, posts the cost — now through the same engine as the workflow. More generally it:
makes what is left (unless `produce_remaining: false`), requires every work order completed and every mandatory quality check passed (422 with `quality_checks`), writes off variance, releases reservations, and
if units were not made, follows `backorder_policy` (`ask` → send `create_backorder` true/false, else 422 with `remaining_qty_cartons`). An order that made nothing cannot be completed (cancel it).
**200** with `backorder: { id, number, output_qty_cartons } | null` and `replayed`.

### `POST /api/v1/production-orders/{id}/cancel` 🔄 CHANGED

Requires `production-orders.cancel`. `{ "reason": "Customer withdrew" }` (optional). Releases reservations and cancels open operations. **422** once stock has moved or an operation has run (complete it short instead).

### `POST /api/v1/production-orders/{id}/reopen` 🆕 NEW

Requires `production-orders.reopen`. Cancelled → draft; only when `allow_reopen_cancelled` is on (422 otherwise).

### `POST /api/v1/production-orders/{id}/create-backorder` 🆕 NEW

Requires `production-orders.create-backorder`. A new draft order for what a *completed* order did not make (once per order; 422 if nothing is left or one exists). **201**.

### `GET /api/v1/production-orders/{id}/costs` 🆕 NEW

Requires `production-orders.costs`. Planned against actual by material / operation / overhead, variance, scrap, unit cost, and the ledger entries (with lines) the order made. See [costing](../manufacturing/costing-and-accounting.md#reading-the-numbers).

### `GET /api/v1/production-orders/{id}/traceability` 🆕 NEW

Requires `production-orders.traceability`. `consumed` (every material batch issued, with `kind` consume/scrap), `produced` (batches made, with `qty_on_hand` now) and `source` (sales order / parent order).

### `GET /api/v1/production-orders/{id}/history` 🆕 NEW

Requires `production-orders.history`. The append-only history: `{ action, from_status, to_status, user, payload, at }`, oldest first, paginated.

---

## Production scrap 🆕 NEW

### `GET /api/v1/production-scraps` · `GET /api/v1/production-scraps/{id}`

Requires `production-scraps.view` (export: `.export`). Filters: `status`, `kind`, `product_id`, `production_order_id`, `from`, `to`. `scrap_cost` needs `production-orders.costs`.

### `POST /api/v1/production-scraps/{id}/approve` · `reject`

Requires `production-scraps.approve` / `.reject`. Only a `pending` request (422 otherwise). Approving carries out the scrap (stock, ledger); rejecting changes nothing.

---

## Work centers 🆕 NEW

```json
{ "id": "01m...", "code": "MIX", "name": "Mixer", "capacity": 1, "efficiency_pct": "100.00", "cost_per_hour": "60.00", "labor_cost_per_hour": "30.00",
  "notes": null, "active": true, "calendar": [ { "id": "01m...", "weekday": 0, "start_time": "08:00", "end_time": "16:00" } ], "downtimes": [] }
```

`capacity` = operations it can run at once; `efficiency_pct` below 100 lengthens run time. `weekday`: 0 = Sunday … 6 = Saturday. With no shifts the center works Sunday–Thursday 08:00–16:00 (`config/manufacturing.php`); company holidays (HR) are non-working days.

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /work-centers` · `GET /work-centers/{id}` | `work-centers.view` | filters `active`; export `.export` |
| `POST /work-centers` | `work-centers.create` | `calendar` (shifts) optional; `code` unique. **201** |
| `PUT /work-centers/{id}` | `work-centers.update` | |
| `DELETE /work-centers/{id}` | `work-centers.delete` | **409** while routings or work orders use it |
| `PUT /work-centers/{id}/calendar` | `work-centers.update-calendar` | `{ "shifts": [{ "weekday", "start_time", "end_time" }] }` replaces the week |
| `GET /work-centers/{id}/downtimes` | `work-center-downtimes.view` | |
| `POST /work-centers/{id}/downtimes` | `work-center-downtimes.create` | `{ starts_at, ends_at, reason }`; the response lists `work_orders_to_reschedule` booked inside it. **201** |
| `DELETE /work-centers/{id}/downtimes/{downtime}` | `work-center-downtimes.delete` | **204** |
| `GET /work-centers/{id}/schedule?from=&to=` | `work-centers.schedule` | `available_minutes`, `booked_minutes`, and the operations booked in the period |

---

## Routings 🆕 NEW

A routing is the ordered list of operations needed to make a product; a BoM points at one.

```json
{ "id": "01m...", "code": "R1", "name": "Tonic line", "notes": null, "active": true,
  "operations": [ { "id": "01m...", "sequence": 10, "name": "Mix", "work_center": { "id": "01m...", "code": "MIX", "name": "Mixer" }, "work_center_id": "01m...",
                    "setup_minutes": 30, "run_minutes": "2.00", "cleanup_minutes": 10, "depends_on_id": null, "instructions": null } ] }
```

Operation time for an order = setup + `run_minutes` × cartons (÷ the center's efficiency) + cleanup.

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /routings` · `GET /routings/{id}` | `routings.view` | filters `active`; export `.export` |
| `POST /routings` | `routings.create` | optional `operations`; in this array `depends_on_id` is the **sequence** of an earlier operation. **201** |
| `PUT /routings/{id}` | `routings.update` | name, code, notes, active |
| `DELETE /routings/{id}` | `routings.delete` | **409** while recipes use it |
| `POST /routings/{id}/operations` | `routing-operations.create` | `sequence` (unique in the routing), `name`, `work_center_id`, minutes, `depends_on_id` (an **operation id** that comes earlier — 422 otherwise). **201** |
| `PUT /routings/{id}/operations/{operation}` | `routing-operations.update` | |
| `DELETE /routings/{id}/operations/{operation}` | `routing-operations.delete` | **204** |

Changing a routing's operations makes a **new version of every approved recipe that uses it**, so orders confirmed afterwards are planned on the new operations and orders already confirmed keep theirs.

---

## Work orders 🆕 NEW

The operations of a production order. Created by `plan` (or `start`), one per routing operation.

```json
{ "id": "01m...", "number": "WO00007", "production_order": { "id": "01m...", "number": "MO00042", "status": "in_progress" },
  "sequence": 10, "name": "Mix", "status": "ready", "work_center": { "id": "01m...", "code": "MIX", "name": "Mixer" }, "work_center_id": "01m...",
  "depends_on_id": null, "assigned_to": { "id": "01m...", "name": "Omar" },
  "qty_planned": 10, "qty_processed": 0, "qty_rejected": 0, "planned_minutes": 60, "actual_minutes": 0, "duration_variance_minutes": null,
  "scheduled_start": "2026-11-01T08:00:00.000000Z", "scheduled_end": "2026-11-01T09:00:00.000000Z", "actual_start": null, "actual_end": null,
  "instructions": null, "notes": null, "failure_reason": null, "labor_cost": "0.00", "machine_cost": "0.00",
  "time_logs": [ { "started_at": "...", "ended_at": null, "minutes": 0, "user_id": "01m..." } ], "quality_checks": [] }
```

`status`: `pending` (waiting for the operation before it) → `ready` → `in_progress` ⇄ `paused` → `completed`; `cancelled` with its order. Costs need `production-orders.costs`.

| Endpoint | Permission | Rules |
|---|---|---|
| `GET /work-orders` · `GET /work-orders/{id}` | `work-orders.view` | filters `status`, `work_center_id`, `production_order_id`, `assigned_to`, `from`, `to`; export `.export` |
| `POST /work-orders/{id}/start` | `work-orders.start` | must be `ready` (**422** "the operation before it is not completed"); starts the order if it was only confirmed/planned; opens a time log |
| `POST /work-orders/{id}/pause` · `resume` | `work-orders.pause` / `.resume` | closes / opens a time log |
| `POST /work-orders/{id}/complete` | `work-orders.complete` | `{ qty_processed, qty_rejected, notes, failure_reason, reject_reason }` — defaults to everything the previous operation passed on; never more than that. Mandatory quality checks of the operation must have passed. Costs the time, issues the materials tied to the operation, records rejects on the order, readies the next operation. **Does not complete the order** |
| `POST /work-orders/{id}/reschedule` | `work-orders.reschedule` | `{ scheduled_start, auto_shift }` — a busy or non-working time is **422** with `next_free_start` and `conflicts` unless `auto_shift`; never before the operation it depends on ends |
| `POST /work-orders/{id}/assign` | `work-orders.assign` | `{ user_id }` — the operator |

---

## Quality 🆕 NEW

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /quality-check-definitions` · `GET …/{id}` | `quality-check-definitions.view` | filters `point`, `active` |
| `POST /quality-check-definitions` | `quality-check-definitions.create` | `{ name, point: incoming\|in_process\|final, type: pass_fail\|measure, product_id, routing_operation_id, min_value, max_value, unit_label, mandatory, instructions }` |
| `PUT` / `DELETE /quality-check-definitions/{id}` | `….update` / `….delete` | |
| `GET /quality-checks` · `GET …/{id}` | `quality-checks.view` | filters `status`, `point`, `production_order_id`, `work_order_id`, `product_id`, `mandatory` |
| `POST /quality-checks` | `quality-checks.create` | an inspection by hand, e.g. incoming material: `{ definition_id \| name, point, product_id, reference_type, reference_id, production_order_id, work_order_id, mandatory }` |
| `POST /quality-checks/{id}/record` | `quality-checks.record` | `{ result: passed\|failed }` or `{ measured_value }` (checked against the limits), `defect_class`, `rejected_cartons`, `notes`, `severity`, `corrective_action`. A failure opens an alert. **422** if it already has a result |
| `GET /quality-alerts` | `quality-alerts.view` | filters `status`, `severity`, `production_order_id`, `product_id` |
| `POST /quality-alerts/{id}/resolve` | `quality-alerts.resolve` | `{ corrective_action }` |

Checks are raised from the definitions when an order is confirmed (those without an operation) and when its work orders are created (those tied to the operation). A **mandatory** check blocks completion of its
order or operation until its latest inspection has passed; a failed check is superseded by a later passing check of the same kind.

---

## Planning (MRP) 🆕 NEW

How it works: [mrp-algorithm.md](../manufacturing/mrp-algorithm.md). Planning only *proposes*.

### Planning policy per product

`GET/POST /product-planning`, `GET/PUT/DELETE /product-planning/{id}` — `product-planning.view/create/update/delete` (export `.export`). `POST` for a product that already has a policy updates it.

```json
{ "product_id": "01m...", "procurement": "make", "lead_time_days": 3, "lot_rule": "min_max", "lot_qty": null, "min_qty": 20, "max_qty": 50, "multiple_qty": null, "make_to_order": false }
```

`procurement`: `make`, `buy`, or null (automatic: made when it has an approved recipe). `lot_rule`: `exact` · `fixed` (needs `lot_qty`) · `multiple` (needs `multiple_qty`) · `min_max` (needs `min_qty`). Safety stock and reorder point are the
product's existing `min_stock_cartons` and `reorder_level`.

### Manual demand and forecasts

`GET/POST /mrp-demands`, `GET/PUT/DELETE /mrp-demands/{id}` — `mrp-demands.*`. `{ product_id, warehouse_id, qty_cartons, due_date, kind: manual|forecast, status: open|fulfilled|cancelled, notes }`. Filters `status`, `kind`, `product_id`, `warehouse_id`, `from`, `to`.

### `POST /api/v1/mrp/plan`

Requires `mrp-runs.run`. `{ "warehouse_id": null, "horizon_days": null, "async": false }` — records a run (defaults: the company's default warehouse and horizon) and runs it. **201** with the finished run, or with `async: true` **202** with the queued run (poll it).
**422** if a run for the warehouse is already waiting or running, or no warehouse can be determined. A planning failure is reported **in the run** (`status: failed`, a safe `error`), not as a server error.

```json
{ "data": { "id": "01m...", "number": "MRP00001", "status": "completed", "inputs": { "warehouse_id": "01m...", "horizon_days": 30 },
            "stats": { "products": 3, "lines": 3, "manufacture": 1, "purchase": 2, "shortages": 3, "superseded": 0, "needs_supplier": 0, "cancelled_orders": 0, "orphaned_orders": 0 },
            "error": null, "started_at": "...", "finished_at": "...", "requested_by": { "id": "01m...", "name": "Rana" } } }
```

### `GET /api/v1/mrp/runs` · `GET /api/v1/mrp/runs/{id}`

Requires `mrp-runs.view`. Filter `status`.

### `GET /api/v1/mrp/requirements`

Requires `mrp-requirements.view`. The report of the latest completed run (or `?run_id=`), by product, shallowest first: `gross_requirement`, `on_hand`, `reserved`, `available`, `incoming`, `safety_stock`, `net_requirement`, `planned_qty`, `due_date`, `action`
(`none` / `manufacture` / `purchase`), `level`, `sources`. Filters `action`, `product_id`, `level`, `short_only`.

### `GET /api/v1/mrp/shortages`

Requires `mrp-shortages.view`. Only the products that need something ordered, each with the `suggestions` that cover it and `needs_supplier` for a bought product with no supplier.

### Suggestions

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /mrp/suggestions` | `mrp-suggestions.view` | filters `status`, `type`, `product_id`, `warehouse_id`, `mrp_run_id`; default shows proposed, dismissed and converted; export `.export` |
| `GET /mrp/suggestions/{id}` | `mrp-suggestions.view` | |
| `POST /mrp/suggestions/{id}/convert` | `mrp-suggestions.convert` + `production-orders.create` | a *manufacture* suggestion becomes a **draft** order (`source_type: mrp_suggestion`). **201** |
| `POST /mrp/suggestions/convert-purchases` | `mrp-suggestions.convert` + `purchase-orders.create` | `{ "suggestion_ids": [...] }` → one draft purchase order per supplier and warehouse **without costs**, so each waits for an administrator to price it. **422** with `products` when some have no supplier. **201** |
| `POST /mrp/suggestions/{id}/dismiss` | `mrp-suggestions.dismiss` | stays dismissed while the same need remains |

A suggestion: `{ id, type, status: proposed|dismissed|converted|superseded, product, warehouse, supplier, qty_cartons, start_date, due_date, sources, mrp_run_id, converted_type, converted_id }`.
Converted documents count as supply in later runs, so they are not proposed again.

---

## Reports 🆕 NEW

All under `/api/v1/reports/manufacturing/…`. Aggregated in SQL; `from` / `to` (dates), filters `product_id`, `warehouse_id`, `status` where they apply; `per_page`; `?export=csv|xlsx` with the report's export permission. How each number is worked out:
[api-reference](../manufacturing/api-reference.md#report-calculation-rules).

| Report | Permission | Shows |
|---|---|---|
| `production` | `manufacturing-reports.view` | planned vs produced vs rejected, completion % and yield %, `group_by=product\|month\|warehouse` |
| `material-consumption` | `manufacturing-reports.view` | used and scrapped cartons per component (value for users with `production-orders.costs`) |
| `scrap` | `manufacturing-reports.view` | scrap and rejections by kind / reason / product, and the period's rejection rate |
| `work-center-utilization` | `manufacturing-reports.view` | per work center: available, scheduled and worked minutes, duration variance, load % and utilization % |
| `lead-times` | `manufacturing-reports.view` | completed orders: lead time, delay against plan, on-time % |
| `traceability?batch_no=` | `manufacturing-reports.view` | what made a batch and which material batches went in; or which orders used a batch and what they made |
| `cost-variance` | `manufacturing-cost-reports.view` | completed orders: planned vs actual, variance, scrap, unit cost |
| `wip-valuation` | `manufacturing-cost-reports.view` | work in progress per open WIP order and the balance of account 1350 to reconcile with |

---

## Attachments 🆕 NEW

Drawings, work instructions, certificates and photos can be attached to a BoM, production order, work order, routing or quality check.

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /manufacturing/attachments?attachable_type=bom&attachable_id=…` | `manufacturing-attachments.view` | `attachable_type`: `bom`, `production_order`, `work_order`, `routing`, `quality_check`. **404** if the record is not this company's |
| `POST /manufacturing/attachments` | `manufacturing-attachments.create` | multipart: `attachable_type`, `attachable_id`, `file` (pdf, jpg, png, doc, docx, xls, xlsx, csv, txt; ≤ 10 MB), optional `description`. **201** |
| `GET /manufacturing/attachments/{id}/download` | `manufacturing-attachments.view` | the file; **404** for another company's |
| `DELETE /manufacturing/attachments/{id}` | `manufacturing-attachments.delete` | removes the record and the stored file. **204** |

Files are stored on the default disk under `manufacturing-attachments/{company}/` and are only ever served through the download endpoint.

---

## Events and notifications

After the transaction that caused them commits, orders raise events (confirmed, materials reserved, **material shortage**, started, materials consumed, goods received, completed, cancelled, work order completed,
**quality check failed**, planning completed). In-app notifications go to the order's creator and operator, and — for shortages, failed checks and finished planning — to the people who can confirm production orders.
