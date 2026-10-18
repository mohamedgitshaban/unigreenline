# Manufacturing — permissions

Every manufacturing endpoint is guarded by its own permission, named `{resource}.{action}` and declared on the route, so a role can be given
exactly the actions it should have (for instance `production-orders.view` without `production-orders.complete`). Administrator holds all of
them. Access is enforced on the server for every request; record-level rules (the company's own records only — another company's is a
404; warehouse visibility; draft-only edits) are enforced in the services on top of the permission.

The complete generated list for the whole system is [`docs/api/permissions.md`](../api/permissions.md) (regenerate with
`php artisan permissions:docs`; a test fails if it is stale). This page explains the manufacturing part.

## The user-visible capabilities and the permissions behind them

| Capability | Permissions |
|---|---|
| View manufacturing | `boms.view`, `production-orders.view`, `production-orders.traceability`, `production-orders.history`, `work-centers.view`, `work-centers.schedule`, `routings.view`, `work-orders.view`, `production-scraps.view`, `quality-*.view`, `product-planning.view`, `mrp-*.view`, `manufacturing-settings.view`, `manufacturing-reports.view` |
| Create and edit BoMs | `boms.create`, `boms.update`, `boms.revise`, `boms.restore` |
| Approve or activate BoMs | `boms.approve` |
| Archive or delete BoMs | `boms.archive`, `boms.delete` |
| View, create and confirm orders | `production-orders.view`, `.create`, `.update`, `.confirm`, `.from-sales-order` |
| Reserve materials | `production-orders.reserve-materials`, `.release-materials` |
| Plan and start production | `production-orders.plan`, `.start`, `.pause`, `.resume`, `work-orders.start`, `.pause`, `.resume`, `.reschedule`, `.assign` |
| Record consumption and production | `production-orders.consume-materials`, `.record-production`, `.record-scrap`, `work-orders.complete`, `quality-checks.record` |
| Complete or cancel orders | `production-orders.complete`, `.cancel`, `.reopen`, `.create-backorder`, `production-scraps.approve`, `.reject` |
| Manage work centers and routings | `work-centers.create/update/update-calendar/delete`, `work-center-downtimes.*`, `routings.*`, `routing-operations.*` |
| Manage quality control | `quality-check-definitions.*`, `quality-checks.create`, `quality-alerts.resolve` |
| Run planning | `mrp-runs.run`, `product-planning.*`, `mrp-demands.*`, `mrp-suggestions.dismiss` |
| Turn suggestions into orders | `mrp-suggestions.convert` **plus** `production-orders.create` (manufacture) or `purchase-orders.create` (purchase) |
| View production costs | `production-orders.costs` |
| View cost reports | `manufacturing-cost-reports.view` |
| Financial integration details | `production-orders.costs` (the order's ledger entries are part of its cost breakdown) |
| Export reports | `manufacturing-reports.export`, `manufacturing-cost-reports.export` and each list's `.export` |
| Manage configuration | `manufacturing-settings.update` |

## How the seeded roles get them

Roles are described on the older module × capability grid and expanded per endpoint, so each role keeps one readable line:

| Action family | Needs the grid capability | Notes |
|---|---|---|
| `view`, `traceability`, `history`, `schedule` | `view` | |
| `create`, `from-sales-order`, `run`, `create-backorder` | `add` | |
| `update`, `plan`, `start`, `pause`, `resume`, `reserve-materials`, `release-materials`, `consume-materials`, `record-production`, `record-scrap`, `reschedule`, `assign`, `record`, `revise`, `restore`, `dismiss`, `cancel` | `edit` | |
| `confirm`, `complete`, `approve`, `reject`, `reopen`, `convert` | `approve` | the decisions |
| `delete`, `archive` | `delete` | Administrator only by default |
| `costs`, and the cost reports | `audit` | cost is not shown to warehouse staff |
| `manufacturing-settings.update` | – | **never given by the grid**; only Administrator and Owner (named grants) |
| `export` | `export` | |

| Role | Manufacturing |
|---|---|
| Administrator | everything |
| Owner | view, approve (confirm, complete, approve BoMs, convert), export, audit (costs, cost reports) and `manufacturing-settings.update` |
| Warehouse Manager | view, add, edit, approve, print, export — runs production day to day; no deletes, no costs, no settings |
| Warehouse Employee | view, add, edit — plans, starts, records production and work orders; cannot confirm, complete, approve or delete |
| Accountant | `production-orders.view`, `.costs`, `.history`, `manufacturing-reports.*`, `manufacturing-cost-reports.*` |
| Purchasing | `mrp-runs.view`, `mrp-requirements.view`, `mrp-shortages.view`, `mrp-suggestions.view/convert/dismiss/export` (and so can raise purchase orders from suggestions) |
| Sales Manager | `production-orders.view`, `production-orders.from-sales-order` |
| Auditor | view, export, audit (costs and cost reports) |
| Sales Rep, Customer Service, HR Manager, Employee | none |

Cost figures are returned only to users holding `production-orders.costs`: the `costs` block and output values of an order, work-order labour/machine cost, scrap cost,
material values in the consumption report. The original fields `material_cost`, `cost_per_carton` and `conversion_cost` of an order keep being shown to everyone who can view orders, as before.

## Scope

* **Company:** every record carries `tenant_id`; another company's record is a 404 on every endpoint (tested for each new endpoint) and never appears in a list.
* **Warehouse:** creating or updating an order checks that the warehouse is visible to the user (assigned warehouses, unless Owner / Auditor / Administrator).
* **Branch / sub-company:** the ERP has no such dimension; the company is the only scope.

## Per-permission table

<!-- permissions-table -->

Columns: Adm Administrator · Own Owner · SM Sales Manager · SR Sales Rep · WM Warehouse Manager · WE Warehouse Employee · Acc Accountant · CS Customer Service · Pur Purchasing · HR HR Manager · Emp Employee · Aud Auditor. Generated from the routes and the role seeder.

| Permission | Guards | Adm | Own | SM | SR | WM | WE | Acc | CS | Pur | HR | Emp | Aud |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `boms.approve` | `POST /api/v1/boms/{bom}/approve` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `boms.archive` | `POST /api/v1/boms/{bom}/archive` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `boms.create` | `POST /api/v1/boms` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `boms.delete` | `DELETE /api/v1/boms/{bom}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `boms.export` | `GET /api/v1/boms` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `boms.restore` | `POST /api/v1/boms/{bom}/restore` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `boms.revise` | `POST /api/v1/boms/{bom}/revisions` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `boms.update` | `PUT /api/v1/boms/{bom}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `boms.view` | `GET /api/v1/boms`<br>`GET /api/v1/boms/{bom}`<br>`GET /api/v1/boms/{bom}/components`<br>`GET /api/v1/boms/{bom}/revisions`<br>`GET /api/v1/boms/{bom}/revisions/{revision}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `manufacturing-attachments.create` | `POST /api/v1/manufacturing/attachments` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `manufacturing-attachments.delete` | `DELETE /api/v1/manufacturing/attachments/{attachment}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `manufacturing-attachments.view` | `GET /api/v1/manufacturing/attachments`<br>`GET /api/v1/manufacturing/attachments/{attachment}/download` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `manufacturing-cost-reports.export` | `GET /api/v1/reports/manufacturing/cost-variance`<br>`GET /api/v1/reports/manufacturing/wip-valuation` | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |  | ✓ |
| `manufacturing-cost-reports.view` | `GET /api/v1/reports/manufacturing/cost-variance`<br>`GET /api/v1/reports/manufacturing/wip-valuation` | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |  | ✓ |
| `manufacturing-reports.export` | `GET /api/v1/reports/manufacturing/production`<br>`GET /api/v1/reports/manufacturing/material-consumption`<br>`GET /api/v1/reports/manufacturing/scrap`<br>`GET /api/v1/reports/manufacturing/work-center-utilization`<br>`GET /api/v1/reports/manufacturing/lead-times` | ✓ | ✓ |  |  | ✓ |  | ✓ |  |  |  |  | ✓ |
| `manufacturing-reports.view` | `GET /api/v1/reports/manufacturing/production`<br>`GET /api/v1/reports/manufacturing/material-consumption`<br>`GET /api/v1/reports/manufacturing/scrap`<br>`GET /api/v1/reports/manufacturing/work-center-utilization`<br>`GET /api/v1/reports/manufacturing/lead-times`<br>`GET /api/v1/reports/manufacturing/traceability` | ✓ | ✓ |  |  | ✓ | ✓ | ✓ |  |  |  |  | ✓ |
| `manufacturing-settings.update` | `PUT /api/v1/manufacturing/settings` | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |
| `manufacturing-settings.view` | `GET /api/v1/manufacturing/settings` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `mrp-demands.create` | `POST /api/v1/mrp-demands` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `mrp-demands.delete` | `DELETE /api/v1/mrp-demands/{demand}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `mrp-demands.export` | `GET /api/v1/mrp-demands` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `mrp-demands.update` | `PUT /api/v1/mrp-demands/{demand}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `mrp-demands.view` | `GET /api/v1/mrp-demands`<br>`GET /api/v1/mrp-demands/{demand}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `mrp-requirements.view` | `GET /api/v1/mrp/requirements` | ✓ | ✓ |  |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `mrp-runs.run` | `POST /api/v1/mrp/plan` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `mrp-runs.view` | `GET /api/v1/mrp/runs`<br>`GET /api/v1/mrp/runs/{run}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `mrp-shortages.view` | `GET /api/v1/mrp/shortages` | ✓ | ✓ |  |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `mrp-suggestions.convert` | `POST /api/v1/mrp/suggestions/convert-purchases`<br>`POST /api/v1/mrp/suggestions/{suggestion}/convert` | ✓ | ✓ |  |  | ✓ |  |  |  | ✓ |  |  |  |
| `mrp-suggestions.dismiss` | `POST /api/v1/mrp/suggestions/{suggestion}/dismiss` | ✓ |  |  |  | ✓ | ✓ |  |  | ✓ |  |  |  |
| `mrp-suggestions.export` | `GET /api/v1/mrp/suggestions` | ✓ | ✓ |  |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `mrp-suggestions.view` | `GET /api/v1/mrp/suggestions`<br>`GET /api/v1/mrp/suggestions/{suggestion}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `product-planning.create` | `POST /api/v1/product-planning` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `product-planning.delete` | `DELETE /api/v1/product-planning/{planning}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `product-planning.export` | `GET /api/v1/product-planning` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `product-planning.update` | `PUT /api/v1/product-planning/{planning}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `product-planning.view` | `GET /api/v1/product-planning`<br>`GET /api/v1/product-planning/{planning}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `production-orders.cancel` | `POST /api/v1/production-orders/{productionOrder}/cancel` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.complete` | `POST /api/v1/production-orders/{productionOrder}/complete` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `production-orders.confirm` | `POST /api/v1/production-orders/{productionOrder}/confirm` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `production-orders.consume-materials` | `POST /api/v1/production-orders/{productionOrder}/consume-materials` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.costs` | `GET /api/v1/production-orders/{productionOrder}/costs` | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |  | ✓ |
| `production-orders.create` | `POST /api/v1/production-orders` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.create-backorder` | `POST /api/v1/production-orders/{productionOrder}/create-backorder` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.delete` | `DELETE /api/v1/production-orders/{productionOrder}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `production-orders.export` | `GET /api/v1/production-orders` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `production-orders.from-sales-order` | `POST /api/v1/production-orders/from-sales-order` | ✓ |  | ✓ |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.history` | `GET /api/v1/production-orders/{productionOrder}/history` | ✓ | ✓ |  |  | ✓ | ✓ | ✓ |  |  |  |  | ✓ |
| `production-orders.pause` | `POST /api/v1/production-orders/{productionOrder}/pause` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.plan` | `POST /api/v1/production-orders/{productionOrder}/plan` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.record-production` | `POST /api/v1/production-orders/{productionOrder}/record-production` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.record-scrap` | `POST /api/v1/production-orders/{productionOrder}/record-scrap` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.release-materials` | `POST /api/v1/production-orders/{productionOrder}/release-materials` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.reopen` | `POST /api/v1/production-orders/{productionOrder}/reopen` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `production-orders.reserve-materials` | `POST /api/v1/production-orders/{productionOrder}/reserve-materials` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.resume` | `POST /api/v1/production-orders/{productionOrder}/resume` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.start` | `POST /api/v1/production-orders/{productionOrder}/start` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.traceability` | `GET /api/v1/production-orders/{productionOrder}/traceability` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `production-orders.update` | `PUT /api/v1/production-orders/{productionOrder}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `production-orders.view` | `GET /api/v1/production-orders`<br>`GET /api/v1/production-orders/{productionOrder}` | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ |  |  |  |  | ✓ |
| `production-scraps.approve` | `POST /api/v1/production-scraps/{productionScrap}/approve` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `production-scraps.export` | `GET /api/v1/production-scraps` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `production-scraps.reject` | `POST /api/v1/production-scraps/{productionScrap}/reject` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `production-scraps.view` | `GET /api/v1/production-scraps`<br>`GET /api/v1/production-scraps/{productionScrap}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `quality-alerts.resolve` | `POST /api/v1/quality-alerts/{qualityAlert}/resolve` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `quality-alerts.view` | `GET /api/v1/quality-alerts` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `quality-check-definitions.create` | `POST /api/v1/quality-check-definitions` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `quality-check-definitions.delete` | `DELETE /api/v1/quality-check-definitions/{definition}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `quality-check-definitions.update` | `PUT /api/v1/quality-check-definitions/{definition}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `quality-check-definitions.view` | `GET /api/v1/quality-check-definitions`<br>`GET /api/v1/quality-check-definitions/{definition}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `quality-checks.create` | `POST /api/v1/quality-checks` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `quality-checks.record` | `POST /api/v1/quality-checks/{qualityCheck}/record` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `quality-checks.view` | `GET /api/v1/quality-checks`<br>`GET /api/v1/quality-checks/{qualityCheck}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `routing-operations.create` | `POST /api/v1/routings/{routing}/operations` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `routing-operations.delete` | `DELETE /api/v1/routings/{routing}/operations/{operation}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `routing-operations.update` | `PUT /api/v1/routings/{routing}/operations/{operation}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `routings.create` | `POST /api/v1/routings` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `routings.delete` | `DELETE /api/v1/routings/{routing}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `routings.export` | `GET /api/v1/routings` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `routings.update` | `PUT /api/v1/routings/{routing}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `routings.view` | `GET /api/v1/routings`<br>`GET /api/v1/routings/{routing}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `work-center-downtimes.create` | `POST /api/v1/work-centers/{workCenter}/downtimes` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-center-downtimes.delete` | `DELETE /api/v1/work-centers/{workCenter}/downtimes/{downtime}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `work-center-downtimes.view` | `GET /api/v1/work-centers/{workCenter}/downtimes` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `work-centers.create` | `POST /api/v1/work-centers` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-centers.delete` | `DELETE /api/v1/work-centers/{workCenter}` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `work-centers.export` | `GET /api/v1/work-centers` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `work-centers.schedule` | `GET /api/v1/work-centers/{workCenter}/schedule` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `work-centers.update` | `PUT /api/v1/work-centers/{workCenter}` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-centers.update-calendar` | `PUT /api/v1/work-centers/{workCenter}/calendar` | ✓ |  |  |  |  |  |  |  |  |  |  |  |
| `work-centers.view` | `GET /api/v1/work-centers`<br>`GET /api/v1/work-centers/{workCenter}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
| `work-orders.assign` | `POST /api/v1/work-orders/{workOrder}/assign` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-orders.complete` | `POST /api/v1/work-orders/{workOrder}/complete` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  |  |
| `work-orders.export` | `GET /api/v1/work-orders` | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |
| `work-orders.pause` | `POST /api/v1/work-orders/{workOrder}/pause` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-orders.reschedule` | `POST /api/v1/work-orders/{workOrder}/reschedule` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-orders.resume` | `POST /api/v1/work-orders/{workOrder}/resume` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-orders.start` | `POST /api/v1/work-orders/{workOrder}/start` | ✓ |  |  |  | ✓ | ✓ |  |  |  |  |  |  |
| `work-orders.view` | `GET /api/v1/work-orders`<br>`GET /api/v1/work-orders/{workOrder}` | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |  | ✓ |
