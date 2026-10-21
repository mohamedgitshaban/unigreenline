# Admin

Covers user management, roles & permissions, the audit log viewer, notifications, discarded
actions, and the governorate/city reference lists. All endpoints require authentication; most additionally need their
own permission (`users.view|create|update|delete|export`, `roles.view|create|update|delete|assign-users`, `permissions.view`,
`audit-log.view|verify|export`, `discarded-actions.view|export`) — **only `Administrator`, `Owner`, and `Auditor` hold
the viewing ones by default**, and only `Administrator` holds create/update/delete
(Owner/Auditor are oversight roles — view/export/audit only, no data entry).

---

## Users

### `GET /api/v1/users`

Requires `users.view`. Paginated, scoped to the caller's tenant.

`?role=&status=&per_page=` — `role` filters by role name (e.g. `Sales Rep`).

### `GET /api/v1/users/{id}`

Requires `users.view`. Includes `warehouse_ids` (see below).

### `POST /api/v1/users`

Requires `users.create`.

```json
{
  "name": "Karim Adel",
  "email": "karim2@vetpharma.com",
  "password": "a-secure-password",
  "role": "Warehouse Employee",
  "status": "active",
  "warehouse_ids": ["01m..."]
}
```

- `role` must be one of the 10 seeded role names (`RolePermissionSeeder`).
- `warehouse_ids` is optional — an array of warehouse ids to assign via the
  `user_warehouses` pivot (spec §2 layer 1 scoping). **A user with no
  warehouses assigned sees zero warehouses/inventory data** unless their
  role is `Owner` or `Auditor` (global scope) or `Administrator` (bypasses
  every permission check). If the frontend creates a Warehouse Manager or
  Warehouse Employee without setting this, that account will look broken —
  always send `warehouse_ids` for those two roles.
- `status` defaults to `active`.
- 🆕 `manager_id` — who this person reports to (a user of the same company) and 🆕 `data_scope` — `all`, `team` or `null` (follow the role). See [Reporting line](./README.md#reporting-line-who-sees-whose-data--new).
- Response is `201` with the created user (password never included). 🔄 It now also returns `manager_id`, `manager` (`{id, name}`), `data_scope` and `effective_data_scope` (`all` or `team`, after the role's default is applied).

### `PUT /api/v1/users/{id}`

Requires `users.update`. Same body shape as create, all fields optional
(`sometimes`), **except `password` — not accepted here**. There is no
admin-initiated password reset in this build; a user changes their own
password via `POST /auth/change-password` (requires their current
password). Sending `warehouse_ids` replaces the full set, not a merge.

```json
{ "role": "Sales Manager", "status": "suspended" }
```

🔄 `PUT` also takes `manager_id` (send `null` to remove the manager) and `data_scope`. A person cannot be made to report to themselves or to anyone on their own team — **422** `A person cannot report to themselves or to someone on their own team.` `GET /users?manager_id=` lists one manager's direct reports.

### Reporting line 🆕 NEW

**`GET /api/v1/team`** — any signed-in user: their own team.

```json
{ "data": {
  "user": { "id": "01k…", "name": "Mona Adel" },
  "manager": { "id": "01k…", "name": "Hany Samir" },
  "data_scope": "team",
  "direct_reports": 2,
  "members": [ { "id": "01k…", "name": "Karim", "email": "karim@…", "role": "Sales Rep", "status": "active", "manager_id": "01k…", "depth": 1 },
               { "id": "01k…", "name": "Dina",  "email": "dina@…",  "role": "Sales Rep", "status": "active", "manager_id": "01k…", "depth": 2 } ] } }
```

`depth` is 1 for direct reports, 2 for theirs, and so on. **`GET /api/v1/users/{id}/team`** — the same for any user; requires `users.team` (Administrator, Owner-style roles by explicit grant) and, for a team-scoped caller, that the user is on their team (else **403**). Another company's user is **404**.

### `DELETE /api/v1/users/{id}` 🆕 NEW

Requires `users.delete`. **204**; the user's API tokens are revoked and the
deletion is audit-logged. Another tenant's user is **404**.

- You cannot delete your own account — **422**.
- **409** `{"message": "This User is referenced by other records and cannot be deleted."}`
  if the user owns records that must keep an owner (e.g. sales orders where they
  are the sales rep). Set `status: inactive`/`suspended` instead.

---

## Roles & Permissions 🆕 NEW

> 🆕 **NEW.** An admin can create roles, choose each role's permissions —
> endpoint by endpoint, including every edit and delete — and connect users to
> them. The seeded roles are editable the same way (except `Administrator`).

Roles are **global** (not per tenant). 🔄 **Permissions are per endpoint**: every
endpoint is guarded by exactly one permission named `{resource}.{action}`, so a
role can be given `expenses.view` without `expense-categories.view`, or
`sales-orders.update` without `sales-orders.delete`.

- `{resource}` is the API resource (`expenses`, `expense-categories`, `sales-orders`, `invoices`, `employees`, ...);
- `{action}` is `view` (every read of it), `create`, `update`, `delete`, `export` (the `?export=` download) or an
  action of its own (`approve`, `reject`, `receive`, `void`, `pay`, `check-in`, ...).

The complete list — **207 permissions**, the endpoints each one guards, and which
seeded role holds it — is [permissions.md](./permissions.md), generated from the
code; `GET /permissions` returns the same catalogue live (below).

Access to the roles API itself: `roles.view` to read, `roles.create`,
`roles.update` (also `roles.assign-users`) and `roles.delete` to change — by
default only `Administrator`. Changes apply on the affected users' next request.

Enforcement is the same everywhere: the route checks its permission before the
controller runs (**403** if missing), then record-level rules apply on top —
tenant isolation (404), a Sales Rep's own orders, a self-service employee's own
records, only draft documents being editable, invoices being voided rather than
deleted.

Two seeded roles were added with the HR module: **HR Manager** (everything in
HR, including approving and paying payroll) and **Employee** (self-service:
check in/out, own leave and permission requests, own payslips). The other ten
seeded roles keep exactly the access they had — upgrading converts them to the
per-endpoint permissions automatically.

### Role object

```json
{
  "id": 11, "name": "Shift Lead",
  "permissions": ["customers.view", "sales-orders.delete", "sales-orders.update", "sales-orders.view"],
  "users_count": 3,
  "created_at": "2026-10-11T09:00:00.000000Z", "updated_at": "2026-10-11T09:00:00.000000Z"
}
```

### `GET /api/v1/permissions` 🆕 NEW

Requires `permissions.view`. Every assignable permission, three ways — drives
the permission grid on a role form:

```json
{
  "data": { "expenses": ["expense-categories.create", "expense-categories.update", "expense-categories.view", "expenses.approve", "..."], "sales": ["..."] },
  "resources": { "expenses": { "expense-categories": ["expense-categories.create", "..."], "expenses": ["expenses.approve", "..."] } },
  "endpoints": { "expenses.view": ["GET /api/v1/expenses", "GET /api/v1/expenses/{expense}", "GET /api/v1/expenses/{expense}/receipt"] }
}
```

- `data`: module → permission names;
- `resources`: module → resource → permission names (group a checkbox grid by resource);
- `endpoints`: permission → the endpoints it guards (show a tooltip so the admin knows what ticking a box allows).

### `GET /api/v1/roles` 🆕 NEW

Requires `roles.view`. Paginated; `search` matches the name. Each role has its
`permissions` and `users_count`.

### `GET /api/v1/roles/{id}` 🆕 NEW

Requires `roles.view`.

### `POST /api/v1/roles` 🆕 NEW

Requires `roles.create`.

```json
{ "name": "Shift Lead", "permissions": ["sales-orders.view", "sales-orders.update", "customers.view", "expenses.view"] }
```

`name` is required and unique; every entry in `permissions` must exist (**422**
on `permissions.N` otherwise). **201** — the role.

### `PUT /api/v1/roles/{id}` 🆕 NEW

Requires `roles.update`. Rename and/or replace the permission set — `permissions`
is a **sync**, not a merge (send the full list). The `Administrator` role is
protected: **403** `The Administrator role cannot be modified.`

### `DELETE /api/v1/roles/{id}` 🆕 NEW

Requires `roles.delete`. **204**. **409** while any user still has the role
(reassign them first); the `Administrator` role cannot be deleted (**403**).

### `PUT /api/v1/roles/{id}/users` 🆕 NEW

Requires `roles.assign-users`. Connects existing users to the role:

```json
{ "user_ids": ["01m...", "01m..."] }
```

Users must be in the caller's tenant. Each listed user is moved **onto this role,
replacing whichever role they had** (a user has one role). Returns the role with
its new `users_count`. (A user can also be given a role through
`POST/PUT /users` with `role`.)

---

## Audit Log

### `GET /api/v1/audit-log`

Requires `audit-log.view`. Paginated, most-recent-first, scoped to tenant.

`?module=&entity_type=&per_page=` — both filters optional.

Each row includes `prev_hash`/`entry_hash` (spec §5.7) so the frontend can
display or spot-check the tamper-evident chain, not just the business
fields.

### `GET /api/v1/audit-log/{id}`

Requires `audit-log.view`. A single entry, including `prev_values`/`new_values`.
**404** — the entry belongs to another account.

### `POST /api/v1/audit-log/verify-integrity`

Requires `audit-log.verify`. Re-walks the entire chain and reports the first row
where it broke, if any:

```json
{ "intact": true, "broken_at": null }
```
```json
{ "intact": false, "broken_at": 42 }
```

This is the HTTP/admin-UI equivalent of the `audit:verify` console command
(built in Step 1 for ops/cron use) — same `AuditLogService::verifyChain()`
underneath.

---

## Notifications

Any authenticated user — no `admin.*` permission required, this is a
user's own inbox.

### `GET /api/v1/notifications`

Paginated, newest first (sortable — see README's "Sorting a list"). Returns notifications where `user_id` is the
caller's own id, or `user_id` is `null` (broadcast — spec §4.14). 🔄 Filters: `?unread=1`, `?module=crm` (the module the record belongs to), `?type=customer.update`.

🔄 Notifications about a changed record now carry a way to open it:

```json
{ "id": "01k…", "type": "customer.update", "title": "Customer updated",
  "body": "Hany Samir updated customer \"Nile Farm\". Changed: phone, city.", "icon": "edit",
  "link_module": "crm", "link_entity": "Customer", "link_id": "01k…",
  "link": { "module": "crm", "entity": "Customer", "id": "01k…", "url": "/api/v1/customers/01k…" },
  "actor_id": "01k…", "unread": true, "created_at": "…" }
```

`link` is `null` for notifications that point nowhere, and for a record that was deleted. Who is told, and about what, is in [Change notifications](./README.md#change-notifications--new).

### `GET /api/v1/notifications/unread-count` 🆕 NEW

`{ "data": { "total": 5, "by_module": { "crm": 3, "inventory": 2 } } }` — for a badge.

### `PUT /api/v1/notifications/{id}/read` 🆕 NEW

Marks one of the caller's own notifications read and returns it. A broadcast (no owner) is **404** here — use `read-all`.

### `GET /api/v1/notifications/{id}`

A single notification. Same visibility as the list — the caller's own or a
broadcast; anything else (another user's, another account's) is **404**.
Does not mark it read.

### `PUT /api/v1/notifications/read-all`

Marks every notification currently visible to the caller (own + broadcast)
as read.

**Known limitation, inherited from the spec's own schema (§4.14):**
`unread` is a single flag per row, not a per-viewer read state — there is
no `notification_reads` pivot table in the spec. Marking a *broadcast*
notification read via this endpoint sets `unread = false` for every user,
not just the caller — the next user to call this endpoint (or list
notifications) will see it as already read even though they never opened
it. If the frontend needs true per-user read tracking on broadcasts, that
requires a schema change beyond what §4.14 defines.

---

## Discarded Actions

Spec §4.15: abandoned/partially-filled forms the frontend can save so the
user doesn't lose their draft, plus an admin screen to review them (spec
§8 lists "Discarded Actions" under Admin). Not in the §6 endpoint table,
but §4.15 explicitly says to "wire it up rather than leaving it dead like
the prototype did" — built here since the table and its purpose are both
fully specified, just missing from that one table.

### `POST /api/v1/discarded-actions`

Any authenticated user — saves their own abandoned form.

```json
{ "type": "sales_order", "label": "Draft order for Al-Salam Vet Clinic", "payload": { "customer_id": "01m...", "lines": [] } }
```

`payload` is free-form JSON (whatever partial state the frontend had).

### `GET /api/v1/discarded-actions`

Requires `discarded-actions.view`. Tenant-wide list (not scoped to the caller) — the
admin screen for reviewing everyone's discarded drafts.

### `GET /api/v1/discarded-actions/{id}`

Requires `discarded-actions.view`. A single discarded action, including its full
`payload`. **404** — the action belongs to another account.

---

## Reference data (governorates / cities)

Any authenticated user — no permission needed. Static lists for address
dropdowns (the `governorate` / `city` fields on customers and warehouses),
backed by `Modules/Core/config/cities.php`, not a database table.

### `GET /api/v1/governorates`

All 27 Egyptian governorates, sorted by name. Show `name`; use `slug` for
the cities request.

```json
{ "data": [ { "slug": "alexandria", "name": "Alexandria" }, { "slug": "cairo", "name": "Cairo" } ] }
```

### `GET /api/v1/governorates/{slug}/cities`

The cities of one governorate, sorted by name.

```json
{ "data": ["15th of May", "Ain Shams", "Al-Rehab"] }
```

**404** — unknown slug.

---

## Settings

**Not built.** Spec §8 lists "Settings" as an Admin screen and §10's build
order mentions it, but no `settings` table exists anywhere in §4 (schema)
and no `/settings` endpoint is listed in §6 (API surface) — there's nothing
in the spec to build against. If the frontend needs persisted app-level
settings (company info, tax rate, invoice numbering, etc.), that needs a
schema decision first; this isn't an oversight in this build, it's a gap
in the source spec.
