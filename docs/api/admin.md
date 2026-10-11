# Admin

Covers user management, roles & permissions, the audit log viewer, notifications, discarded
actions, and the governorate/city reference lists. All endpoints require authentication; most additionally require an
`admin.*` permission — **only `Administrator`, `Owner`, and `Auditor` hold
any `admin.*` permission** (spec §2's own role table never grants it to an
operational role), and only `Administrator` holds `admin.add`/`admin.edit`
(Owner/Auditor are oversight roles — view/export/audit only, no data entry).

---

## Users

### `GET /api/v1/users`

Requires `admin.audit`. Paginated, scoped to the caller's tenant.

`?role=&status=&per_page=` — `role` filters by role name (e.g. `Sales Rep`).

### `GET /api/v1/users/{id}`

Requires `admin.audit`. Includes `warehouse_ids` (see below).

### `POST /api/v1/users`

Requires `admin.add`.

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
- Response is `201` with the created user (password never included).

### `PUT /api/v1/users/{id}`

Requires `admin.edit`. Same body shape as create, all fields optional
(`sometimes`), **except `password` — not accepted here**. There is no
admin-initiated password reset in this build; a user changes their own
password via `POST /auth/change-password` (requires their current
password). Sending `warehouse_ids` replaces the full set, not a merge.

```json
{ "role": "Sales Manager", "status": "suspended" }
```

### `DELETE /api/v1/users/{id}` 🆕 NEW

Requires `admin.delete`. **204**; the user's API tokens are revoked and the
deletion is audit-logged. Another tenant's user is **404**.

- You cannot delete your own account — **422**.
- **409** `{"message": "This User is referenced by other records and cannot be deleted."}`
  if the user owns records that must keep an owner (e.g. sales orders where they
  are the sales rep). Set `status: inactive`/`suspended` instead.

---

## Roles & Permissions 🆕 NEW

> 🆕 **NEW.** An admin can create roles, choose each role's permissions —
> including `*.edit` and `*.delete` for every module — and connect users to
> them. The 10 seeded roles are editable the same way (except `Administrator`).

Roles are **global** (not per tenant). Permissions are named
`{module}.{capability}`:

- modules: `sales`, `inventory`, `purchasing`, `crm`, `accounting`, `expenses`,
  `recap`, `manufacturing`, `analytics`, `admin`
- capabilities: `view`, `add`, `edit`, `delete`, `approve`, `print`, `export`, `audit`

Access: `admin.view` to read, `admin.add` to create, `admin.edit` to update or
assign users, `admin.delete` to delete — by default only `Administrator` holds
the write ones. Changes apply on the affected users' next request.

How "edit" and "delete" are enforced everywhere: every update endpoint checks
`{module}.edit` and every delete endpoint `{module}.delete`, so giving a role
those permissions is all it takes to let its users edit or delete that module's
records. Business rules still apply on top (e.g. only draft sales orders can be
deleted, invoices are voided rather than deleted).

### Role object

```json
{
  "id": 11, "name": "Shift Lead",
  "permissions": ["crm.view", "sales.delete", "sales.edit", "sales.view"],
  "users_count": 3,
  "created_at": "2026-10-11T09:00:00.000000Z", "updated_at": "2026-10-11T09:00:00.000000Z"
}
```

### `GET /api/v1/permissions` 🆕 NEW

Requires `admin.view`. Every assignable permission grouped by module — drives
the checkbox grid on a role form:

```json
{ "data": { "sales": ["sales.add", "sales.approve", "sales.audit", "sales.delete", "..."], "inventory": ["..."] } }
```

### `GET /api/v1/roles` 🆕 NEW

Requires `admin.view`. Paginated; `search` matches the name. Each role has its
`permissions` and `users_count`.

### `GET /api/v1/roles/{id}` 🆕 NEW

Requires `admin.view`.

### `POST /api/v1/roles` 🆕 NEW

Requires `admin.add`.

```json
{ "name": "Shift Lead", "permissions": ["sales.view", "sales.edit", "sales.delete", "crm.view"] }
```

`name` is required and unique; every entry in `permissions` must exist (**422**
on `permissions.N` otherwise). **201** — the role.

### `PUT /api/v1/roles/{id}` 🆕 NEW

Requires `admin.edit`. Rename and/or replace the permission set — `permissions`
is a **sync**, not a merge (send the full list). The `Administrator` role is
protected: **403** `The Administrator role cannot be modified.`

### `DELETE /api/v1/roles/{id}` 🆕 NEW

Requires `admin.delete`. **204**. **409** while any user still has the role
(reassign them first); the `Administrator` role cannot be deleted (**403**).

### `PUT /api/v1/roles/{id}/users` 🆕 NEW

Requires `admin.edit`. Connects existing users to the role:

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

Requires `admin.audit`. Paginated, most-recent-first, scoped to tenant.

`?module=&entity_type=&per_page=` — both filters optional.

Each row includes `prev_hash`/`entry_hash` (spec §5.7) so the frontend can
display or spot-check the tamper-evident chain, not just the business
fields.

### `GET /api/v1/audit-log/{id}`

Requires `admin.audit`. A single entry, including `prev_values`/`new_values`.
**404** — the entry belongs to another account.

### `POST /api/v1/audit-log/verify-integrity`

Requires `admin.audit`. Re-walks the entire chain and reports the first row
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
caller's own id, or `user_id` is `null` (broadcast — spec §4.14).

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

Requires `admin.view`. Tenant-wide list (not scoped to the caller) — the
admin screen for reviewing everyone's discarded drafts.

### `GET /api/v1/discarded-actions/{id}`

Requires `admin.view`. A single discarded action, including its full
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
