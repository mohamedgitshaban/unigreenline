# Dashboards 🆕 NEW

Every department (module) has its own dashboard, and everything inside a department is a **feature** (a *section*) of that
dashboard. A dashboard is just its sections side by side, so a department added later — or a feature added to an existing
department — shows up without changing how clients read them.

All of them answer in the same shape, so one client component can draw any department:

```json
{
  "data": {
    "module": "sales",
    "title": "Sales",
    "generated_at": "2026-10-21T10:02:11+00:00",
    "period": { "from": "2026-10-01", "to": "2026-10-31", "label": "month", "days": 31,
                "previous": { "from": "2026-08-31", "to": "2026-09-30" } },
    "scope": { "data_scope": "team", "people": 4 },
    "features": [ { "key": "orders", "label": "Sales orders", "available": true },
                  { "key": "invoices", "label": "Invoices and collections", "available": false } ],
    "kpis":   [ { "feature": "orders", "key": "sales", "label": "Sales value", "value": 18250.5, "previous": 15100,
                  "change_pct": 20.9, "trend": "up", "unit": "currency", "hint": null } ],
    "charts": [ { "feature": "orders", "key": "sales_by_day", "title": "Sales per day", "type": "line", "unit": "currency",
                  "series": [ { "label": "2026-10-01", "value": 1200 } ] } ],
    "tables": [ { "feature": "orders", "key": "top_customers", "title": "Top customers",
                  "columns": [ { "key": "name", "label": "Customer" } ], "rows": [ { "id": "01k…", "name": "Nile Farm", "orders": 4, "value": 5200 } ] } ]
  }
}
```

- `kpis[].unit` is `count`, `currency`, `percent`, `cartons`, `days` or `hours`. `previous` / `change_pct` / `trend` (`up`, `down`, `flat`) compare with the period of the same length right before; they are `null` for figures with nothing to compare (a balance today).
- `charts[].type` is `line`, `bar`, `area`, `pie` or `donut`; every chart is one series of `{label, value}` points.
- `features[].available` is `false` for a feature the caller may not open (its figures are left out). Every widget carries its `feature` key so a client can group them.
- `scope` says whose figures these are: `all` for a user who sees the whole company, `team` for someone limited to themselves and the people who report to them (`people` = how many).

## `GET /api/v1/dashboards`

Any signed-in user. Lists the dashboards the caller may open, with the features each offers:

```json
{ "data": [ { "module": "sales", "title": "Sales", "description": "Orders, invoices, collections and deliveries.",
              "url": "/api/v1/dashboards/sales", "features": [ { "key": "orders", "label": "Sales orders" } ] } ] }
```

## `GET /api/v1/dashboards/{module}`

Requires `{module}-dashboard.view` (`sales-dashboard.view`, `crm-dashboard.view`, `inventory-dashboard.view`, `purchasing-dashboard.view`, `accounting-dashboard.view`, `expenses-dashboard.view`, `hr-dashboard.view`, `manufacturing-dashboard.view`, `recap-dashboard.view`). Each feature additionally needs the permission of its own list (given below). **404** for a module that has no dashboard.

| Query | Meaning |
|---|---|
| `period` | `today`, `yesterday`, `7d`, `30d`, `month` (default, this month), `last_month`, `quarter`, `year`, `custom` |
| `from`, `to` | the dates of a `custom` period (`to` not before `from`) |
| `features` | comma-separated feature keys to build only some (`?features=orders,invoices`) |

Figures are limited to what the caller may see: a team-scoped manager sees their team's orders, calls and customers; a warehouse manager sees their warehouses; stock **values** are shown to the Administrator only (the same rule as batch costs).

## The dashboards

| Module | Feature (needs) | Headline figures (`kpis[].key`) | Charts / tables |
|---|---|---|---|
| **sales** | `orders` (`sales-orders.view`) | `orders`, `sales`, `average_order`, `open_orders`, `delivered_orders` | `sales_by_day`, `orders_by_status`; `top_customers`, `by_sales_rep` |
| | `invoices` (`invoices.view`) | `billed`, `collected`, `outstanding`, `overdue`, `collection_rate` | `collections_by_day`, `receivables_aging`; `overdue_invoices` |
| | `deliveries` (`deliveries.view`) | `pending_deliveries`, `delivered`, `failed_deliveries` | `deliveries_by_status` |
| **crm** | `customers` (`customers.view`) | `customers`, `active_customers`, `new_customers`, `unassigned_customers` | `customers_by_classification`, `customers_by_type`; `customers_by_rep` |
| | `calls` (`calls.view`) | `calls`, `answered_rate`, `talk_time` | `calls_by_day`, `calls_by_outcome`; `call_follow_ups`, `calls_by_rep` |
| | `visits` (`visits.view`) | `visits`, `positive_visits` | `visits_by_day`, `visits_by_outcome`; `planned_visits`, `visits_by_rep` |
| | `leads` (`leads.view`) | `new_leads`, `open_leads`, `pipeline_value`, `win_rate` | `leads_by_status`, `pipeline_by_status` |
| | `complaints` (`complaints.view`) | `complaints`, `open_complaints`, `high_priority`, `resolution_days` | `open_by_priority` |
| **inventory** | `stock` (`warehouses.view`) | `cartons_on_hand`, `available_cartons`, `products_in_stock`, `stock_value`\*, `low_stock_products`, `expired_batches`, `expiring_batches` | `stock_by_warehouse`, `stock_by_category`; `low_stock`, `expiring_soon` |
| | `movements` (`warehouses.movements`) | `received_cartons`, `issued_cartons`, `net_cartons` | `received_by_day`, `issued_by_day`, `movements_by_type`; `top_issued_products` |
| | `flow` (`warehouses.incoming`) | `open_purchase_orders`, `cartons_expected`, `late_purchase_orders`, `orders_to_prepare`, `orders_to_deliver` | `next_arrivals`, `orders_due_out` |
| **purchasing** | `purchase-orders` (`purchase-orders.view`) | `purchase_orders`, `purchase_spend`, `pending_pricing`, `awaiting_goods`, `late_purchase_orders` | `spend_by_day`, `purchase_orders_by_status`; `top_suppliers` |
| | `payables` (`suppliers.view`) | `payables`, `supplier_payments`, `suppliers_owed` | `payments_by_day`; `largest_payables` |
| **accounting** | `ledger` (`income-statement.view`) | `revenue`, `expenses`, `profit`, `margin` | `revenue_by_day`, `expenses_by_day`; `top_expense_accounts` |
| | `balances` (`balance-sheet.view`) | `total_assets`, `total_liabilities`, `total_equity`, `receivables`, `payables`, `inventory_value` | `assets_breakdown` |
| | `assets` (`fixed-assets.view`) | `assets_on_books`, `assets_cost`, `assets_book_value`, `depreciation_period`, `depreciation_due` | `book_value_by_category`, `depreciation_forecast` |
| | `loans` (`loans.view`) | `loans_borrowed`, `loans_granted`, `loan_interest`, `loan_due_30_days`, `loan_overdue` | `loans_by_balance`; `upcoming_installments` |
| **expenses** | `expenses` (`expenses.view`) | `expenses_total`, `expenses_count`, `expenses_pending`, `expenses_pending_amount` | `expenses_by_day`, `expenses_by_category` |
| **hr** | `workforce` (`employees.view`) | `headcount`, `hired`, `left` | `headcount_by_department` |
| | `attendance` (`attendances.view`) | `present_today`, `not_in_yet`, `late_arrivals`, `hours_worked`, `overtime_hours` | `attendance_by_day`, `late_by_day` |
| | `leave` (`leave-requests.view`) | `leave_pending`, `on_leave_today`, `leave_days` | `leave_by_type` |
| | `payroll` (`payroll-runs.view`) | `latest_payroll`, `payroll_employees`, `payroll_to_approve`, `payroll_to_pay` | `payroll_history` |
| | `recruitment` (`applicants.view`) | `open_positions`, `open_vacancies`, `applications`, `hires`, `active_applicants`, `interviews_this_week` | `recruitment_funnel`, `applicants_by_source`; `next_interviews`, `busiest_positions` |
| | `fleet` (`vehicles.view`) | `vehicles`, `fleet_cost`, `fleet_fuel_cost`, `fleet_service_cost`, `contracts_expiring`, `services_due` | `vehicles_by_status`, `cost_by_vehicle`; `expiring_contracts` |
| **manufacturing** | `production` (`production-orders.view`) | `orders_finished`, `cartons_produced`, `yield`, `orders_in_progress`, `orders_late` | `production_by_day`, `orders_by_status`; `late_orders` |
| | `planning` (`mrp-runs.view`) | `mrp_suggestions_open`, `mrp_to_make`, `mrp_to_buy`, `quality_alerts_open` | — |
| **recap** | `write-offs` (`recaps.view`) | `writeoffs`, `writeoff_value`, `writeoffs_pending` | `writeoffs_by_reason` |

\* Administrator only.

The older company-wide `GET /api/v1/analytics/dashboard` (eight headline cards) is unchanged — see [analytics.md](./analytics.md).

## Adding a department or a feature (for developers)

A module registers its dashboard in its service provider's `boot()`; nothing else in the application changes:

```php
$dashboards = app(DashboardRegistry::class);
$dashboards->register(new Dashboard('fleet', 'Fleet', 'fleet-dashboard.view', 'Vehicles and what they cost.'));
$dashboards->section('fleet', app(VehiclesSection::class));   // a class implementing DashboardSection
```

and adds one route, whose controller lives in the module (so the permission belongs to the module's permission group):

```php
Route::get('dashboards/fleet', [FleetDashboardController::class, 'show'])->defaults('module', 'fleet')->can('fleet-dashboard.view');
```

A feature of an existing department — even one contributed by another module — is one more `$dashboards->section('hr', …)`. A section returns `kpis`, `charts` and `tables` built with the `Widgets` helpers (`kpi`, `chart`, `table`, `daily`); it receives the signed-in user and the `DashboardPeriod`, and is expected to limit its queries to what that user may see (`DataScope`, `WarehouseAccess`).
