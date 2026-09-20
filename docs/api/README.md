# VetPharma ERP API

Base URL: `/api/v1` (plus `GET /api/health`, unversioned).

Auth: Bearer token (Laravel Sanctum). Send `Authorization: Bearer <token>` on every
request except `POST /auth/login` and `GET /health`.

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
| Admin (users, audit log, notifications) | Partial — audit log hash-chain exists, no HTTP endpoint yet | — |

This file is updated as each module ships.

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
