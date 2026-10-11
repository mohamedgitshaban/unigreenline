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
> - 🆕 **Manufacturing** (BOMs, production orders) → [manufacturing.md](./manufacturing.md)
> - 🆕 **Units / packaging ladder** (packet → package → carton) → [inventory.md](./inventory.md#units--product-packaging--new)
> - 🆕 **Free gifts** on purchase receipts and gift lines on sales orders
> - 🆕 **Minimum selling price** per product; 🔄 **product cost is Administrator-only**
> - 🔄 **Sales orders from several warehouses** (per-line warehouse) + 🆕 `GET /products/{id}/availability`
> - 🆕 **Product life cycle** timeline → [analytics.md](./analytics.md#get-apiv1productsidlifecycle--new)
> - 🆕 Chart of accounts create/edit/delete; 🆕 invoice **void**
> - 🆕 **Purchase orders pending pricing** — buyers no longer enter costs; an admin prices the order → [purchasing.md](./purchasing.md#pending-pricing-who-sets-the-cost--new)
> - 🆕 **Create + deliver in one call** — `POST /sales-orders/deliver` → [sales.md](./sales.md#post-apiv1sales-ordersdeliver--new)

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

## Editing and deleting records 🆕 NEW

Every master-data resource now has `PUT /{resource}/{id}` (permission
`{module}.edit`) and `DELETE /{resource}/{id}` (permission `{module}.delete`).
An admin decides who may edit or delete by giving a role those permissions
([Roles & permissions](./admin.md#roles--permissions)) — by default only
`Administrator` holds any `*.delete`.

| Resource | Edit | Delete |
|---|---|---|
| Users | `PUT /users/{id}` | 🆕 `DELETE /users/{id}` (`admin.delete`) |
| Roles | 🆕 `PUT /roles/{id}` | 🆕 `DELETE /roles/{id}` |
| Customers, leads, visits, complaints, campaigns | 🆕 `PUT` (customers existed) | 🆕 `DELETE` (`crm.delete`) |
| Warehouses, products, categories | existed | 🆕 `DELETE` (`inventory.delete`) |
| Units | 🆕 `PUT /units/{id}` | 🆕 `DELETE` |
| Suppliers | existed | 🆕 `DELETE` (`purchasing.delete`) |
| Purchase orders | existed | existed (unreceived only) |
| Sales orders | 🆕 `PUT /sales-orders/{id}` (draft only) | 🆕 `DELETE` (draft only, `sales.delete`) |
| Invoices | — | 🆕 `POST /invoices/{id}/void` (invoices are never hard-deleted) |
| Expense categories | existed | 🆕 `DELETE` (`expenses.delete`) |
| Chart of accounts | 🆕 `PUT` | 🆕 `DELETE` (`accounting.delete`) |
| Recaps, BOMs, production orders | 🆕 (draft only) | 🆕 (draft only / unused only) |

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
| Customers | `POST /customers/import` | `GET /customers/import-template` | `crm.add` | name |
| Products | `POST /products/import` | `GET /products/import-template` | `inventory.add` | SKU |
| Suppliers | `POST /suppliers/import` | `GET /suppliers/import-template` | `purchasing.add` | name |
| Warehouses | `POST /warehouses/import` | `GET /warehouses/import-template` | `inventory.add` | name |
| Invoices | `POST /invoices/import` | `GET /invoices/import-template` | `sales.add` | — (no natural key) |

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
| 🆕 Manufacturing (BOMs / Production Orders) | New | [manufacturing.md](./manufacturing.md) |
| Analytics / Reports / Scheduled Jobs | Done | [analytics.md](./analytics.md) |
| Admin (users, 🆕 roles & permissions, audit log, notifications, discarded actions, governorates/cities) | Done — Settings has no backing schema in the spec, not built | [admin.md](./admin.md) |

This file is updated as each module ships.

## Postman collection

[docs/postman/VetPharma-ERP.postman_collection.json](../postman/VetPharma-ERP.postman_collection.json) +
[VetPharma-ERP-Local.postman_environment.json](../postman/VetPharma-ERP-Local.postman_environment.json) —
import both into Postman (or run with `newman`), select the environment, run
**Auth > Login**, and every other request authenticates automatically. Covers
all the endpoints below (224 requests; 🆕/🔄 flagged where added or changed), organized to match this doc's module split, with
"create" requests auto-saving the id they create for the next request to
reuse. See the collection's own top-level description (visible in Postman)
for the couple of things worth knowing before a full top-to-bottom run —
notably a request delay to stay under the API's rate limit. Regenerate it
with `php docs/postman/generate.php` after any endpoint contract change;
don't hand-edit the JSON.

## Roles & permissions

> 🆕 Roles are no longer fixed: an admin can create roles, choose their
> permissions and connect users to them — see [admin.md](./admin.md#roles--permissions).
> 🆕 Two new permission modules exist: `recap.*` and `manufacturing.*` (each
> with the same eight capabilities). Re-run `RolePermissionSeeder` to create them.

10 roles are seeded (`Administrator`, `Owner`, `Sales Manager`, `Sales Rep`,
`Warehouse Manager`, `Warehouse Employee`, `Accountant`, `Customer Service`,
`Purchasing`, `Auditor`). Permissions are named `{module}.{capability}`, e.g.
`sales.approve`, `inventory.export`. The `permissions` array returned on login
and `GET /auth/me` is the frontend's source of truth for what to show/hide —
don't hardcode role-based UI logic, check permission strings instead.

## Upgrading an existing database 🆕 NEW

After pulling this update, an existing install needs (in order):

```bash
php artisan migrate                                    # units, product_units, min price, gift batches, PO/SO line columns, recap + manufacturing tables
php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\RolePermissionSeeder"   # recap.* and manufacturing.* permissions
php artisan db:seed --class="Modules\\Accounting\\Database\\Seeders\\AccountSeeder"     # accounts 6300 and 6400
php artisan db:seed --class="Modules\\Inventory\\Database\\Seeders\\UnitSeeder"        # Packet / Package / Carton
php artisan db:seed --class="Modules\\Inventory\\Database\\Seeders\\ProductUnitSeeder" # every product gets a Packet -> Carton ladder
```

All five seeders are idempotent. Which roles got the new permissions:
`Warehouse Manager` → `recap.*` and `manufacturing.*` (view/add/edit/approve/print/export),
`Warehouse Employee` → `manufacturing.view/add/edit`, `Accountant` → `recap.view`;
`Owner`/`Auditor` get the new modules through their module-wide grants;
`Administrator` gets everything (including the new `purchasing.pricing`, the right to price purchase orders). Nobody but `Administrator` gets any `*.delete`
until an admin grants it.

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
