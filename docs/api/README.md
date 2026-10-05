# VetPharma ERP API

This file and its siblings (`auth.md`, `inventory.md`, etc.) are also served as a
browsable site at **`/docs`** on a running instance of this app (`ApiDocsController`,
`resources/views/api-docs/show.blade.php`) — same content, syntax-highlighted and
navigable, if that's easier to share with the frontend dev than raw markdown files.

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
| Analytics / Reports / Scheduled Jobs | Done | [analytics.md](./analytics.md) |
| Admin (users, audit log, notifications, discarded actions, governorates/cities) | Done — Settings has no backing schema in the spec, not built | [admin.md](./admin.md) |

This file is updated as each module ships.

## Postman collection

[docs/postman/VetPharma-ERP.postman_collection.json](../postman/VetPharma-ERP.postman_collection.json) +
[VetPharma-ERP-Local.postman_environment.json](../postman/VetPharma-ERP-Local.postman_environment.json) —
import both into Postman (or run with `newman`), select the environment, run
**Auth > Login**, and every other request authenticates automatically. Covers
all 92 endpoints below, organized to match this doc's module split, with
"create" requests auto-saving the id they create for the next request to
reuse. See the collection's own top-level description (visible in Postman)
for the couple of things worth knowing before a full top-to-bottom run —
notably a request delay to stay under the API's rate limit. Regenerate it
with `php docs/postman/generate.php` after any endpoint contract change;
don't hand-edit the JSON.

## Roles & permissions

10 roles are seeded (`Administrator`, `Owner`, `Sales Manager`, `Sales Rep`,
`Warehouse Manager`, `Warehouse Employee`, `Accountant`, `Customer Service`,
`Purchasing`, `Auditor`). Permissions are named `{module}.{capability}`, e.g.
`sales.approve`, `inventory.export`. The `permissions` array returned on login
and `GET /auth/me` is the frontend's source of truth for what to show/hide —
don't hardcode role-based UI logic, check permission strings instead.

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
