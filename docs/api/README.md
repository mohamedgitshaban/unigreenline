# VetPharma ERP API

This file and its siblings (`auth.md`, `inventory.md`, etc.) are also served as a
browsable site at **`/docs`** on a running instance of this app (`ApiDocsController`,
`resources/views/api-docs/show.blade.php`) — same content, syntax-highlighted and
navigable, if that's easier to share with the frontend dev than raw markdown files.

> ## 🏷️ Change flags
>
> Anything added or altered in the latest update is marked so you can find it:
>
> | Flag | Meaning |
> |---|---|
> | 🆕 **NEW** | a brand-new endpoint, section or module |
> | 🔄 **CHANGED** | an existing endpoint that now accepts or returns more (existing requests keep working) |
>
> The same flags are on the matching requests in the Postman collection
> (`🆕` / `🔄` in the request name). Summary of this update:
>
> - 🆕 **Roles & permissions API** — create roles, pick permissions (incl. edit/delete per module), assign users → [admin.md](./admin.md#roles--permissions)
> - 🆕 **Edit & delete endpoints** across the API, gated by `{module}.edit` / `{module}.delete` → [Editing and deleting](#editing-and-deleting-records--new)
> - 🆕 **Spreadsheet import** for customers, products, suppliers, warehouses and invoices → [Importing](#importing-from-a-spreadsheet--new)
> - 🆕 **Recap** (stock write-off posting to the chart of accounts) → [recap.md](./recap.md)
> - 🔄 **Manufacturing, now a full module** — recipes with revisions and approval, an order workflow (confirm → plan → start → produce → complete) with partial production and backorders, batch **reservations**, routing and work centers with capacity scheduling, shop-floor work orders, quality control, scrap and by-products, costing with optional work-in-progress accounting, **material requirements planning**, and reports → [manufacturing.md](./manufacturing.md); background in [docs/manufacturing](../manufacturing/architecture-analysis.md)
> - 🆕 **Units / packaging ladder** (packet → package → carton) → [inventory.md](./inventory.md#units--product-packaging--new)
> - 🆕 **Free gifts** on purchase receipts and gift lines on sales orders
> - 🆕 **Minimum selling price** per product; 🔄 **product cost is Administrator-only**
> - 🔄 **Sales orders from several warehouses** (per-line warehouse) + 🆕 `GET /products/{id}/availability`
> - 🆕 **Product life cycle** timeline → [analytics.md](./analytics.md#get-apiv1productsidlifecycle--new)
> - 🆕 Chart of accounts create/edit/delete; 🆕 invoice **void**
> - 🆕 **Purchase orders pending pricing** — buyers no longer enter costs; an admin prices the order → [purchasing.md](./purchasing.md#pending-pricing-who-sets-the-cost--new)
> - 🔄 **Permissions are now per endpoint** (`{resource}.{action}`) so a role can read `expenses` without reading `expense-categories` → [permissions.md](./permissions.md)
> - 🆕 **HR module** — employees, attendance (check-in/out), work schedules, global holidays, time permissions (late / early leave / partial), leave, and monthly payroll posted to the chart of accounts → [hr.md](./hr.md)
> - 🔄 **Finance review** — cost of goods sold on delivery, input VAT on purchases, returns and manual goods receipts now post to the ledger; supplier payments, manual journal entries (with reversal), an account-connections map, and a balance sheet that balances → [accounting.md](./accounting.md#finance-review--what-was-missing-and-what-changed)
> - 🆕 **Create + deliver in one call** — `POST /sales-orders/deliver` → [sales.md](./sales.md#post-apiv1sales-ordersdeliver--new)
> - 🆕 **Reporting line** — every user can have a manager and a data scope; managers see (and hand out) the customers, orders, visits and calls of everyone below them → [Reporting line](#reporting-line-who-sees-whose-data--new)
> - 🆕 **Call log** — record every customer call with what was said → [crm.md](./crm.md#calls--new); 🆕 **customer assignment** and a **duplicate check** when creating a customer
> - 🆕 **Warehouse managers** — assign people and a manager to a warehouse, see what is coming in (purchase orders) and going out (sales orders), and a **stock movement ledger** of every in and out → [inventory.md](./inventory.md#warehouses-for-the-people-who-run-them--new)
> - 🆕 **Fixed assets** (depreciation board, disposal) and **loans** (amortization schedule, early repayment) → [accounting.md](./accounting.md#fixed-assets--new)
> - 🆕 **Printable documents** — invoices, orders, delivery notes, receipts, purchase orders, transfers and write-offs → [Printing](#printing-documents--new)
> - 🆕 **Recruitment and Fleet** inside HR → [hr.md](./hr.md#recruitment--new)
> - 🆕 **Notifications for changes** to records, with a link to the record → [Change notifications](#change-notifications--new)
> - 🆕 **Dashboards for every department** and a way to add one for the next → [dashboards.md](./dashboards.md)

Base URL: `/api/v1` (plus `GET /api/health`, unversioned).

Auth: Bearer token (Laravel Sanctum). Send `Authorization: Bearer <token>` on every
request except `POST /auth/login` and `GET /health`.

**CORS:** locked down outside local/testing (`config/cors.php`) — in any deployed
environment, the frontend's origin(s) must be added to the `FRONTEND_URLS` env var
(comma-separated) or the browser will block responses. Not needed for local dev
against this repo's default `.env` (defaults to allowing any origin there). No
cookies are involved (`supports_credentials` is always `false`) — only the
`Authorization` header, so no CSRF token or `withCredentials` setup is needed.

All responses are JSON. Validation errors return `422` with:

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Unauthenticated requests return `401`:

```json
{ "message": "Unauthenticated." }
```

## Reporting line: who sees whose data 🆕 NEW

Every user can have a **manager** (`manager_id`), which makes a tree. Together with a **data scope** it decides whose records a
user sees:

| `data_scope` | Sees |
|---|---|
| `all` | every record their permissions allow in the company |
| `team` | only records owned by themselves or by anyone who reports to them, directly or through others |
| *(not set)* | follows the role: a **Sales Rep** is `team`, everyone else `all` (what the API did before) |

So a rep sees their own customers, orders, invoices, collections, deliveries, visits and calls; a manager set to `team` also sees
those of every rep under them (and theirs, and so on down the tree); a manager left on `all` sees everything. Records with no owner
are visible only to users whose scope is `all`. The same tree shapes warehouse access (a manager sees the warehouses of their
people) and who is told about a change.

- Set it when creating or editing a user: `manager_id` (a user of the same company; a person cannot report to themselves or to someone on their own team — `422`) and `data_scope` (`all` / `team` / `null`). The user object returns `manager`, `data_scope` and `effective_data_scope`. [admin.md](./admin.md#reporting-line--new)
- `GET /api/v1/team` — the signed-in user's own team; `GET /api/v1/users/{id}/team` — anyone's (`users.team`).
- A manager can **hand a customer to someone on their team** (`POST /customers/{id}/assign`) and sees their team's visits and calls. Creating a customer that already exists under another user is refused and says whose it is. [crm.md](./crm.md)

## Change notifications 🆕 NEW

When a record changes, the people it concerns are notified (in the in-app inbox, `GET /notifications`) with a **link to the record**:
its owner, **and everyone the owner reports to**, plus extra people where it makes sense (the warehouse manager for a purchase order
heading to their warehouse, the driver of a vehicle). Nobody is told about their own change, only users who hold the permission to open the
record are told, and nothing is sent for a request that failed.

| Record | Told | Permission needed |
|---|---|---|
| Customer, visit, call, lead, complaint (CRM) | the owner / rep / assignee (and the customer's owner), and their managers | `customers.view`, `visits.view`, `calls.view`, `leads.view`, `complaints.view` |
| Sales order, invoice, collection, delivery | the order's sales rep and their managers; the **warehouse managers** of the warehouses the order draws from | `sales-orders.view` … (warehouse managers: `warehouses.outgoing`) |
| Purchase order, supplier payment | whoever created it and their managers; the **manager of the receiving warehouse** | `purchase-orders.view` (warehouse: `warehouses.incoming`) |
| Warehouse, transfer | the old and new warehouse manager; both warehouses' managers for a transfer | `warehouses.view`, `transfers.view` |
| Expense, write-off | who created it, their managers; the warehouse manager for a write-off | `expenses.view`, `recaps.view` |
| Leave request, time permission | the employee and their managers | `leave-requests.view`, `time-permissions.view` |
| Payroll run | who prepared and approved it | `payroll-runs.view` |
| Fixed asset, loan | who created it (and the asset's user); loans also send daily reminders for instalments that are due or late | `fixed-assets.view`, `loans.view` |
| Job, applicant, interview, vehicle | the recruiter / interviewer / driver | `job-positions.view`, `applicants.view`, `interviews.view`, `vehicles.view` |

A notification carries `link_module`, `link_entity`, `link_id`, a ready `link.url` (the API path of the record; `null` once it is deleted)
and `actor_id`. A new department makes its records notify by registering them (`ChangeNotificationRegistry`) — no other change.
Endpoints: [admin.md](./admin.md#notifications).

## Printing documents 🆕 NEW

Business documents print from the API as a page laid out for A4 (the browser's *Print* / *Save as PDF* turns it into a file —
no PDF library is needed on the server):

| Document | Endpoint | Permission |
|---|---|---|
| Sales invoice (with payments received) | `GET /invoices/{id}/print` | `invoices.print` |
| Sales order | `GET /sales-orders/{id}/print` | `sales-orders.print` |
| Delivery note (quantities and batches, no prices) | `GET /deliveries/{id}/print` | `deliveries.print` |
| Payment receipt | `GET /collections/{id}/print` | `collections.print` |
| Purchase order | `GET /purchase-orders/{id}/print` | `purchase-orders.print` |
| Stock transfer slip (no prices) | `GET /transfers/{id}/print` | `transfers.print` |
| Stock write-off slip | `GET /recaps/{id}/print` | `recaps.print` |

The response is `text/html` (open it in a tab or an iframe; it has its own *Print* button). `?format=json` returns the same content as
data (`type`, `number`, `company`, `meta`, `parties`, `tables`, `totals`, `notes`, `signatures`) to draw it your own way; `?dir=rtl` lays the page
out right to left. The same record rules as the record's own endpoint apply (a rep cannot print another team's invoice; another company's
document is `404`).

## Editing and deleting records 🆕 NEW

Every master-data resource now has `PUT /{resource}/{id}` (permission
`{resource}.update`) and `DELETE /{resource}/{id}` (permission `{resource}.delete`).
An admin decides who may edit or delete by giving a role those permissions
([Roles & permissions](./admin.md#roles--permissions)) — by default only
`Administrator` holds any `*.delete`.

| Resource | Edit | Delete |
|---|---|---|
| Users | `PUT /users/{id}` | 🆕 `DELETE /users/{id}` (`users.delete`) |
| Roles | 🆕 `PUT /roles/{id}` | 🆕 `DELETE /roles/{id}` |
| Customers, leads, visits, complaints, campaigns | 🆕 `PUT` (customers existed) | 🆕 `DELETE` (`customers.delete`, `leads.delete`, ...) |
| Warehouses, products, categories | existed | 🆕 `DELETE` (`warehouses.delete`, `products.delete`, `categories.delete`) |
| Units | 🆕 `PUT /units/{id}` | 🆕 `DELETE` |
| Suppliers | existed | 🆕 `DELETE` (`suppliers.delete`) |
| Purchase orders | existed | existed (unreceived only) |
| Sales orders | 🆕 `PUT /sales-orders/{id}` (draft only) | 🆕 `DELETE` (draft only, `sales-orders.delete`) |
| Invoices | — | 🆕 `POST /invoices/{id}/void` (invoices are never hard-deleted) |
| Expense categories | existed | 🆕 `DELETE` (`expense-categories.delete`) |
| Chart of accounts | 🆕 `PUT` | 🆕 `DELETE` (`chart-of-accounts.delete`) |
| Recaps, BOMs, production orders | 🆕 (draft only) | 🆕 (draft only / unused only) |
| HR: departments, schedules, holidays, employees, attendance | 🆕 | 🆕 |
| HR: time permissions, leave requests | 🆕 (pending only) | 🆕 (pending only) |
| HR: payroll runs | 🆕 recalculate (draft only) | 🆕 (draft only) |

Common behaviour:

- **204 No Content** on delete; the deletion (and every edit's before/after) is
  written to the audit log.
- **404** for another tenant's record — checked *before* permissions, so even an
  Administrator cannot touch another tenant's data.
- **409 Conflict** when other records still reference the row:
  `{"message": "This Customer is referenced by other records and cannot be deleted."}`
  (or a specific message, e.g. `This role is assigned to users...`). Retire the
  record (`active: false` / `status: inactive`) instead.
- **422** when a business rule forbids the change (e.g. editing a non-draft
  document).
- **Deliberately not editable / deletable:** posted financial and stock
  documents — journal entries, collections, deliveries, returns, transfers,
  goods receipts and invoices. They are corrected by posting a reversal (e.g.
  `POST /invoices/{id}/void`), never by rewriting history.

## Importing from a spreadsheet 🆕 NEW

Bulk-load data from an Excel/CSV file:

| Resource | Import | Template | Needs | Matched by |
|---|---|---|---|---|
| Customers | `POST /customers/import` | `GET /customers/import-template` | `customers.import` | name |
| Products | `POST /products/import` | `GET /products/import-template` | `products.import` | SKU |
| Suppliers | `POST /suppliers/import` | `GET /suppliers/import-template` | `suppliers.import` | name |
| Warehouses | `POST /warehouses/import` | `GET /warehouses/import-template` | `warehouses.import` | name |
| Invoices | `POST /invoices/import` | `GET /invoices/import-template` | `invoices.import` | — (no natural key) |

- **Request:** `multipart/form-data` with `file` (`.xlsx`, `.xls` or `.csv`, max
  5 MB, max 5000 rows) and optional `update_existing` (boolean). The first row
  must be the headings from the template (case-insensitive, spaces or
  underscores); blank rows are ignored; unknown columns are ignored.
- **Template:** `GET .../import-template` downloads an `.xlsx` with the exact
  headings and one example row.
- **Existing records** (matched by the key above) are **skipped** by default;
  with `update_existing=1` they are updated with the non-blank cells (a blank
  cell never wipes an existing value).
- **All-or-nothing.** Everything is validated and written in one transaction. If
  any row is invalid, **nothing is imported** and the response lists every bad
  row:

```json
{
  "message": "The file has 2 invalid row(s). Nothing was imported.",
  "created": 0, "updated": 0, "skipped": 0,
  "errors": [
    { "row": 3, "errors": { "type": ["The selected type is invalid."], "email": ["The email field must be a valid email address."] } },
    { "row": 5, "errors": { "category": ["No product category named or coded \"Mystery\"."] } }
  ]
}
```

  `row` is the spreadsheet row number (row 1 = headings). Status **422**; a
  missing required column or an unreadable / empty file is also 422 (with
  `errors: []`).
- **Success** — **200**:
  `{ "message": "...", "created": 12, "updated": 0, "skipped": 3, "errors": [] }`.
  One `IMPORT` entry is written to the audit log.
- Dates may be real Excel dates or `YYYY-MM-DD` text.

## Exporting a list as CSV or Excel

Every list (index) endpoint documented below accepts `?export=csv` or
`?export=xlsx` in addition to its normal filters — same filtered result set as
the JSON response, but **every matching row, not just one page** (pagination
params are ignored once `export` is set). The response is a real file
download (`Content-Disposition: attachment`), not JSON — a real `.xlsx`
(PhpSpreadsheet via `maatwebsite/excel`), not CSV with a renamed extension.

```
GET /api/v1/customers?export=csv
GET /api/v1/sales-orders?export=xlsx&pay_type=credit   (any other filters the endpoint supports still apply)
```

**Requires `{module}.export` specifically — not just `{module}.view`.**
Spec §2 treats Export as one of the eight capability types, distinct from
View, and several roles have one without the other (e.g. `Sales Rep` and
`Purchasing` can view their module's data but not export it; check the
`permissions` array from login rather than assuming view implies export).
An unrecognized `export` value (anything other than `csv`/`xlsx`) is ignored
and falls back to the normal paginated JSON — it's not a validation error.

Not exportable: `GET /notifications` (a personal inbox, not business data),
`GET /analytics/dashboard` (a single object, not a list), and the two
existing `/reports/*` endpoints (already dedicated JSON exports — see
[analytics.md](./analytics.md)). `GET /analytics/stock`'s file export is
flattened to one row per product+warehouse, unlike its nested JSON response.

## Sorting a list

Every list (index) endpoint accepts `?sort_by=` and `?sort_dir=`. The default
is **`sort_by=id&sort_dir=desc`** — ids are ULIDs (time-ordered), so that's
newest first. Exports (`?export=`) use the same order.

```
GET /api/v1/products?sort_by=name&sort_dir=asc
GET /api/v1/sales-orders?sort_by=order_date          (sort_dir defaults to desc)
GET /api/v1/chart-of-accounts?sort_by=code&sort_dir=asc
```

- `sort_by` is any column of that resource's table (the field names in its
  JSON response, for fields stored as columns). Hidden columns such as a
  user's password are not sortable.
- `sort_dir` is `asc` or `desc` (lowercase).
- Anything else is a **422** validation error on `sort_by`/`sort_dir` —
  unlike `export`, an unrecognized value is not silently ignored.
- When sorting by a column other than `id`, `id desc` is applied as a
  tie-breaker so rows with equal values keep a stable order across pages.

Not sortable: `GET /analytics/*` and `/reports/*` (fixed report orderings).

## Searching and filtering a list

Every list (index) endpoint accepts `?search=` and `?filter[...]=`. Both
combine with sorting, pagination and `?export=` (exports contain every
matching row). Pagination `links` keep the query string.

```
GET /api/v1/products?search=amox
GET /api/v1/sales-orders?filter[status]=invoiced&filter[total][gte]=1000
GET /api/v1/invoices?filter[status][in]=outstanding,overdue&filter[due_date][lt]=2026-10-01
GET /api/v1/customers?filter[name][like]=pharma&filter[city]=Cairo
GET /api/v1/complaints?filter[resolved_date][null]=true
```

**`search`** is a case-insensitive "contains" match across a fixed set of
text columns per resource (names, codes, phones, references, and related
names such as the customer, product or supplier). `%` and `_` match
literally. Max 255 characters.

**`filter[column]=value`** is an exact match. `filter[column][op]=value`
applies an operator:

| op | Meaning |
|---|---|
| `eq` / `ne` | equal / not equal |
| `gt` / `gte` / `lt` / `lte` | comparison (numbers, dates) |
| `like` | contains (case-insensitive) |
| `in` / `not_in` | comma-separated list |
| `null` | `true` = is empty, `false` = has a value |

- `column` is any column of that resource's table, the same set as `sort_by`.
  Hidden columns such as a user's password can't be used. An unknown column
  or operator returns a **422**.
- Several filters are combined with AND, including two operators on the same
  column (a range).
- Booleans accept `true`/`false` (or `1`/`0`).
- A date-only value (`YYYY-MM-DD`) against a timestamp column such as
  `created_at` compares by calendar day, so `lte=2026-10-01` includes that
  whole day.
- An empty value (`filter[status]=`) is ignored.

Endpoint-specific filters that already existed still work: `role`/`status`
on users, `module`/`entity_type` on the audit log, `start_date`/`end_date` on
journal entries.

## Nested lists (`/{parent}/{id}/{children}`)

A show response never embeds a list of child records. It returns a
**`{children}_count`** instead, and the children are paged by their own
endpoint:

```
GET /api/v1/products/{id}            → { ..., "batches_count": 120 }
GET /api/v1/products/{id}/batches    → page 1 (10 per page by default)
GET /api/v1/products/{id}/batches?page=2&per_page=25
```

Each nested list is a normal list: same `data` / `links` / `meta` envelope,
**10 per page by default**, and `search`, `filter[...]`, `sort_by` work as
described above. It needs the same permission as showing the parent (a
Sales Rep gets `403` for another rep's order or customer), and a parent
from another account is `404`.

| Parent | Count field(s) | Nested list(s) |
|---|---|---|
| Product | `batches_count` | `GET /products/{id}/batches` |
| Warehouse | `batches_count` | `GET /warehouses/{id}/batches` |
| Customer | `orders_count`, `invoices_count`, `visits_count` | `GET /customers/{id}/orders`, `/invoices`, `/visits` |
| Sales order | `lines_count`, `invoices_count` | `GET /sales-orders/{id}/lines`, `/invoices` |
| Invoice | `lines_count`, `collections_count` | `GET /invoices/{id}/lines`, `/collections` |
| Purchase order | `lines_count` | `GET /purchase-orders/{id}/lines` |
| Journal entry | `lines_count` | `GET /journal-entries/{id}/lines` |

The counts are on both the list and the show response of the parent.

## Endpoint groups (by build step)

| Group | Status | Doc |
|---|---|---|
| Auth | Done | [auth.md](./auth.md) |
| Warehouses / Products / Categories / Goods Receipt / Transfers | Done | [inventory.md](./inventory.md) |
| Sales Orders / Invoices / Deliveries / Collections / Returns | Done | [sales.md](./sales.md) |
| Purchasing (Suppliers / Purchase Orders / Receiving) | Done | [purchasing.md](./purchasing.md) |
| CRM (Customers / Leads / Visits / Complaints / Campaigns) | Done | [crm.md](./crm.md) |
| Accounting (Chart of Accounts / Journal Entries / Balance Sheet / Income Statement) | Done | [accounting.md](./accounting.md) |
| Expenses (Expense Categories / Expenses / Receipts / Approval) | Done | [expenses.md](./expenses.md) |
| 🆕 Recap (stock write-offs posting to the chart of accounts) | New | [recap.md](./recap.md) |
| 🔄 Manufacturing (BOMs / Production Orders / Work centers / Routings / Work orders / Quality / Planning / Reports) | Extended | [manufacturing.md](./manufacturing.md) |
| 🆕 HR (Employees / Attendance / Holidays / Time permissions / Leave / Payroll) | New | [hr.md](./hr.md) |
| 🔄 Permission reference (every permission, the endpoints it guards, the roles holding it) | Generated | [permissions.md](./permissions.md) |
| Analytics / Reports / Scheduled Jobs | Done | [analytics.md](./analytics.md) |
| Admin (users, 🆕 roles & permissions, audit log, notifications, discarded actions, governorates/cities) | Done — Settings has no backing schema in the spec, not built | [admin.md](./admin.md) |

This file is updated as each module ships.

## Postman collection

[docs/postman/VetPharma-ERP.postman_collection.json](../postman/VetPharma-ERP.postman_collection.json) +
[VetPharma-ERP-Local.postman_environment.json](../postman/VetPharma-ERP-Local.postman_environment.json) —
import both into Postman (or run with `newman`), select the environment, run
**Auth > Login**, and every other request authenticates automatically. Covers
all the endpoints below (387 requests; 🆕/🔄 flagged where added or changed), organized to match this doc's module split, with
"create" requests auto-saving the id they create for the next request to
reuse. See the collection's own top-level description (visible in Postman)
for the couple of things worth knowing before a full top-to-bottom run —
notably a request delay to stay under the API's rate limit. Regenerate it
with `php docs/postman/generate.php` after any endpoint contract change;
don't hand-edit the JSON.

## Roles & permissions

> 🔄 Roles are not fixed: an admin can create roles, choose their permissions
> and connect users to them — see [admin.md](./admin.md#roles--permissions).
> 🔄 **Permissions are per endpoint.** Each endpoint needs exactly one permission,
> named `{resource}.{action}` — e.g. `expenses.view`, `expense-categories.view`,
> `sales-orders.change-status`, `payroll-runs.approve`. Reading `expenses` does not
> imply reading `expense-categories`. The full list, with the endpoints each guards
> and the seeded roles that hold it, is [permissions.md](./permissions.md).

12 roles are seeded (`Administrator`, `Owner`, `Sales Manager`, `Sales Rep`,
`Warehouse Manager`, `Warehouse Employee`, `Accountant`, `Customer Service`,
`Purchasing`, `HR Manager`, `Employee`, `Auditor`). The `permissions` array
returned on login and `GET /auth/me` is the frontend's source of truth for what
to show/hide — don't hardcode role-based UI logic, check permission strings
instead (`GET /permissions` lists every one with the endpoints it guards, handy
for hiding a button whose endpoint the user cannot call).

## Upgrading an existing database 🆕 NEW

After pulling this update, an existing install needs (in order):

```bash
php artisan migrate
php artisan db:seed --class="Modules\\Accounting\\Database\\Seeders\\AccountSeeder"     # accounts 1350, 1400, 2200, 2210, 3300, 5000, 5100, 5200, 6300, 6400
php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\RolePermissionSeeder"   # per-endpoint permissions + the HR Manager and Employee roles
php artisan db:seed --class="Modules\\Inventory\\Database\\Seeders\\UnitSeeder"        # Packet / Package / Carton
php artisan db:seed --class="Modules\\Inventory\\Database\\Seeders\\ProductUnitSeeder" # every product gets a Packet -> Carton ladder
php artisan db:seed --class="Modules\\HR\\Database\\Seeders\\HRDatabaseSeeder"          # a default work schedule per company
```

- **`migrate` converts the permissions for you.** The old coarse permissions
  (`expenses.view` meaning "everything view-like in Expenses") are replaced by the
  per-endpoint ones: every role, including roles an admin created, is given the
  per-endpoint permissions equivalent to what it held, so **nobody gains or loses
  access** in the switch; then the old permission rows are deleted. Narrow a role
  afterwards with `PUT /roles/{id}`. The conversion is one-way.
- All seeders are idempotent. `AccountSeeder` is needed because flows now post to
  the new accounts — a missing one stops the flow with a clear error rather than
  losing the entry.
- Journal entries gained `type` (`system` / `manual` / `reversal`), `reversal_of`
  and `reversed_at`; existing entries become `system`.
- **Manufacturing:** the migrations number existing BoMs (`BOM00001` …) and orders (`MO00001` …) and keep them working;
  `inventory_batches` gains `reserved_cartons`. Stock that a confirmed manufacturing order has **reserved** can no longer be taken by
  sales, transfers, write-offs or purchase returns. Set the default warehouse in `PUT /manufacturing/settings` before the nightly
  `mrp:plan` run, and run `php artisan queue:work` if you plan with `async: true` → [operations guide](../manufacturing/installation-and-operations.md).
- **Reporting line, notifications, calls, assets, loans, recruitment, fleet, stock ledger:** the migrations add `users.manager_id` / `data_scope`,
  `notifications.link_entity` / `link_url` / `actor_id`, `customer_calls`, `stock_movements`, `accounting_sequences`, the fixed-asset and loan tables
  and the recruitment and fleet tables. **Existing stock becomes one `opening_balance` ledger line per batch** so the ledger adds up from day one.
  Run the `AccountSeeder` for the new accounts (`1530`, `1540`, `1590`, `1600`, `2400`, `4300`, `4400`, `6500`, `6600`, `6700`) and the `RolePermissionSeeder` for the new
  permissions (`Sales Manager` now also holds the CRM permissions, so a manager can see and manage their reps' customers). Users keep working exactly as before until you
  give them a `manager_id`; only a Sales Rep is limited to `team` by default, which for a rep with nobody under them is what it always was.
- **Scheduler** (`php artisan schedule:run` every minute): `assets:depreciate` (02:30), `loans:remind` (08:00), `fleet:remind` (07:30), in addition to the existing jobs.
- Re-run `php docs/postman/generate.php` and `php artisan permissions:docs`
  after changing endpoints (a test fails if the permission reference is stale).

## Seed accounts (local/dev only)

All seeded users share the password `password`.

| Email | Role |
|---|---|
| admin@vetpharma.com | Administrator |
| ahmed@vetpharma.com | Sales Manager |
| msalem@vetpharma.com | Sales Rep |
| heba@vetpharma.com | Sales Rep |
| omar@vetpharma.com | Sales Rep |
| khalid@vetpharma.com | Warehouse Manager |
| rana@vetpharma.com | Accountant |
| dina@vetpharma.com | Customer Service |
| youssef@vetpharma.com | Owner |
| karim@vetpharma.com | Warehouse Employee |
| mona@vetpharma.com | Purchasing |
| sara@vetpharma.com | Auditor |
