# Admin

Covers user management, the audit log viewer, notifications, and discarded
actions. All endpoints require authentication; most additionally require an
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

---

## Audit Log

### `GET /api/v1/audit-log`

Requires `admin.audit`. Paginated, most-recent-first, scoped to tenant.

`?module=&entity_type=&per_page=` — both filters optional.

Each row includes `prev_hash`/`entry_hash` (spec §5.7) so the frontend can
display or spot-check the tamper-evident chain, not just the business
fields.

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

---

## Settings

**Not built.** Spec §8 lists "Settings" as an Admin screen and §10's build
order mentions it, but no `settings` table exists anywhere in §4 (schema)
and no `/settings` endpoint is listed in §6 (API surface) — there's nothing
in the spec to build against. If the frontend needs persisted app-level
settings (company info, tax rate, invoice numbering, etc.), that needs a
schema decision first; this isn't an oversight in this build, it's a gap
in the source spec.
