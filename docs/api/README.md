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

## Endpoint groups (by build step)

| Group | Status | Doc |
|---|---|---|
| Auth | Done | [auth.md](./auth.md) |
| Warehouses / Products / Categories / Goods Receipt / Transfers | Done | [inventory.md](./inventory.md) |
| Sales Orders / Invoices / Deliveries / Collections / Returns | Done | [sales.md](./sales.md) |
| Purchasing (Suppliers / Purchase Orders / Receiving) | Done | [purchasing.md](./purchasing.md) |
| CRM (Customers / Leads / Visits / Complaints / Campaigns) | Done | [crm.md](./crm.md) |
| Accounting (Chart of Accounts / Journal Entries / Balance Sheet / Income Statement) | Done | [accounting.md](./accounting.md) |
| Analytics / Reports / Scheduled Jobs | Done | [analytics.md](./analytics.md) |
| Admin (users, audit log, notifications, discarded actions) | Done — Settings has no backing schema in the spec, not built | [admin.md](./admin.md) |

This file is updated as each module ships.

## Postman collection

[docs/postman/VetPharma-ERP.postman_collection.json](../postman/VetPharma-ERP.postman_collection.json) +
[VetPharma-ERP-Local.postman_environment.json](../postman/VetPharma-ERP-Local.postman_environment.json) —
import both into Postman (or run with `newman`), select the environment, run
**Auth > Login**, and every other request authenticates automatically. Covers
all 72 endpoints below, organized to match this doc's module split, with
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
