# CRM

> 🆕 **Delete** (`customers.delete`, `leads.delete`, `visits.delete`, ...), **edit** for leads/visits/complaints/campaigns and **customer import** were added later and are flagged below. By default only `Administrator` holds the CRM delete permissions; an admin can grant any of them to any role (see [Admin > Roles](./admin.md#roles--permissions)).

`Customer Service` has `crm.view/add/edit` (sees/manages every customer, no
ownership scoping). `Sales Rep` also has `crm.view/add/edit/print`, per
spec §2 grouping "orders/customers" as one scope — **but** scoped to their
own `sales_rep_id`, same as sales orders. 🔄 `Sales Manager` now holds the CRM permissions too (so a manager can see and manage their reps' customers, visits and calls). No other role gets `crm.*` unless
noted otherwise.

> 🔄 **Ownership follows the reporting line.** "Their own" now means *themselves and everyone who reports to them* for any user whose
> data scope is `team` (a Sales Rep by default; set `data_scope: "team"` on a manager to limit them the same way). A rep with nobody under
> them still sees only their own. See [Reporting line](./README.md#reporting-line-who-sees-whose-data--new). The rule applies to
> customers, visits, calls, and to sales orders, invoices, collections and deliveries in [sales.md](./sales.md).

---

## Customers

### `GET /api/v1/customers`

Requires `customers.view`. 🔄 A team-scoped user (a Sales Rep by default) sees their own customers and those of everyone under them.

### `GET /api/v1/customers/{id}`

Includes `orders_count`, `invoices_count` and `visits_count`. `403` for a
Sales Rep requesting another rep's customer.

### `GET /api/v1/customers/{id}/orders`, `/invoices`, `/visits`

The customer's sales orders, invoices and visits, each as its own list.
Same access as the customer show. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

### `GET /api/v1/customers/check-duplicate` 🆕 NEW

Requires `customers.create`. Call it while the user types: `?name=&phone=&email=&city=`. Customers of the company that match are
listed with whose they are:

```json
{ "data": { "has_duplicates": true, "can_override": false,
  "duplicates": [ { "id": null, "name": "Green Valley Vets", "city": "Cairo", "phone": "+20 100-123-4567",
                    "matched_on": ["phone"], "sales_rep": { "id": "01k…", "name": "Karim" },
                    "owned_by_me": false, "visible_to_me": false } ] } }
```

A match is the same phone number (compared on its last 9 digits, so `+20 100 123 4567` equals `0100 123 4567`), the same e-mail, or the same name **and** city (all ignoring case). `id` is `null` for a customer the caller may not open. `can_override` says whether the caller may create the new customer anyway (only when every match is theirs, their team's, or they see the whole company).

### `POST /api/v1/customers/{id}/assign` 🆕 NEW

Requires `customers.assign` and access to the customer. Hands the customer to someone else — or takes it:

```json
{ "sales_rep_id": "01k…" }
```

`sales_rep_id` must be present; `null` removes the owner. A user who sees the whole company may give a customer to anyone in the company or to no one; a team-scoped user (a manager, a rep) only to **themselves or someone on their team** — otherwise **422**. Returns the customer. Audit-logged as `UPDATE`; the previous and the new owner (and their managers) are notified.

### `POST /api/v1/customers`

Requires `customers.create`. Only `name` and `type` are required.

```json
{
  "sales_rep_id": null, "name": "Nile Valley Veterinary Clinic", "type": "Clinic",
  "classification": "B", "phone": null, "email": null,
  "governorate": null, "province": null, "city": null, "area": null, "address": null,
  "credit_limit": 50000, "pay_terms": null, "status": "active"
}
```
`type`: `Clinic`, `Farm`, `Poultry`, `Distributor`, `Retailer`, `Other`.
`classification`: `A+`, `A`, `B`, `C` (defaults to `B`). `credit_limit`
defaults to `50000`. 🔄 A team-scoped user (a Sales Rep by default) omitting `sales_rep_id` gets themselves;
naming someone outside their team is `422`.

🆕 **Duplicates.** If a customer like this already exists (same phone, e-mail, or name in the same city), the request is refused with **422** and says whose it is:

```json
{ "message": "A customer like this already exists and belongs to Karim.",
  "errors": { "customer": ["A customer like this already exists and belongs to Karim."] },
  "duplicates": [ { "id": null, "name": "Green Valley Vets", "matched_on": ["phone"], "sales_rep": { "id": "01k…", "name": "Karim" }, "owned_by_me": false, "visible_to_me": false } ],
  "can_override": false }
```

Send `"allow_duplicate": true` to create it anyway — accepted only when `can_override` is true (every match is the caller's, their team's, or the caller sees the whole company). A customer already owned by someone outside the caller's reach can never be copied.

### `PUT /api/v1/customers/{id}`

Requires `customers.update` (+ ownership if Sales Rep). All fields optional.

### `DELETE /api/v1/customers/{id}` 🆕 NEW

Requires `customers.delete` (+ ownership if Sales Rep). **204**, audit-logged as
`DELETE`; the customer's visits and complaints are removed with it. **409**
`This Customer is referenced by other records and cannot be deleted.` while the
customer has sales orders, invoices or collections — set `status: inactive`
instead. Another tenant's customer is **404**.

### `POST /api/v1/customers/import` 🆕 NEW

Requires `customers.import`. Bulk-creates customers from a spreadsheet — see
[Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
Multipart: `file` (.xlsx/.csv) and optional `update_existing` (`1` = update
customers matched by **name**, otherwise they are skipped).

Columns (headings are case-insensitive): `Name`*, `Type`*, `Classification`,
`Phone`, `Email`, `Governorate`, `Province`, `City`, `Area`, `Address`,
`Credit Limit`, `Pay Terms`, `Status`, `Sales Rep Email` (must be an existing
user; a Sales Rep can only import customers under their own name — a blank
value assigns them to the importer).

### `GET /api/v1/customers/import-template` 🆕 NEW

Requires `customers.import`. Downloads `customers-import-template.xlsx` — the headings
above plus one example row.

---

## `GET/POST /api/v1/leads`

Requires `leads.view` / `leads.create`. Same `type` enum as customers; `status`: `new` (default),
`qualified`, `proposal`, `won`, `lost`.

### `GET /api/v1/leads/{id}`

Requires `leads.view`. Includes `assigned_to`. **404** — the lead belongs to another account.

### `PUT /api/v1/leads/{id}` 🆕 NEW

Requires `leads.update`. Partial update with the create rules. Audit-logged with the
changed fields.

### `DELETE /api/v1/leads/{id}` 🆕 NEW

Requires `leads.delete`. **204**.

---

## `GET/POST /api/v1/visits`

Requires `visits.view` / `visits.create`. A rep logging a visit gets `rep_id`
defaulted to themselves if omitted. `outcome`: `positive`, `neutral`,
`negative`.

### `GET /api/v1/visits/{id}`

Requires `visits.view`. Includes the nested `customer` and `rep`. **404** — the visit belongs to another account.

### `PUT /api/v1/visits/{id}` 🆕 NEW

Requires `visits.update`. Partial update with the create rules (`next_visit` must not
precede `visit_date`).

### `DELETE /api/v1/visits/{id}` 🆕 NEW

Requires `visits.delete`. **204**.

---

## Complaints

### `GET/POST /api/v1/complaints`

Requires `complaints.view` / `complaints.create`. `priority` defaults to `medium`, `status`
defaults to `investigating`.

### `GET /api/v1/complaints/{id}`

Requires `complaints.view`. Includes the nested `customer`, `product` and
`assigned_to`. **404** — the complaint belongs to another account.

### `PUT /api/v1/complaints/{id}` 🆕 NEW

Requires `complaints.update`. Partial update of the complaint's details (type, priority,
description, product, batch, assignee...). Resolving remains its own action,
`PUT /complaints/{id}/resolve`, below.

### `DELETE /api/v1/complaints/{id}` 🆕 NEW

Requires `complaints.delete`. **204**.

### `PUT /api/v1/complaints/{id}/resolve`

Requires `complaints.resolve`.

```json
{ "resolution": "Replacement shipped." }
```
Sets `status: "resolved"` and `resolved_date` to today. `resolution` is
required — `422` without it.

---

## Campaigns

### `GET/POST /api/v1/campaigns`

Requires `campaigns.view` / `campaigns.create`. `status` defaults to `active`.

### `GET /api/v1/campaigns/{id}`

Requires `campaigns.view`. **404** — the campaign belongs to another account.

### `PUT /api/v1/campaigns/{id}` 🆕 NEW

Requires `campaigns.update`. Partial update (`end_date` must not precede `start_date`).
Ending a campaign remains its own action, `PUT /campaigns/{id}/end`, below.

### `DELETE /api/v1/campaigns/{id}` 🆕 NEW

Requires `campaigns.delete`. **204**.

### `PUT /api/v1/campaigns/{id}/end`

Requires `campaigns.end`. Sets `status: "completed"` and fills `end_date` with
today if it wasn't already set.

---

## Not built in this step

- Customers, leads, visits, complaints and campaigns all take `PUT /{id}`
  (needs `{resource}.update`) and `DELETE /{id}` (needs `{resource}.delete`, which an admin can
  grant to any role). A customer with sales orders or invoices can't be deleted
  (409). `resolve`/`end` remain separate actions.

## Calls 🆕 NEW

Every call a salesperson makes (or takes) is recorded against the customer, with what was said. Same visibility as visits: a user
sees the calls they logged, those logged by everyone who reports to them, and those on customers they or their team own — so a customer's
call history stays with it when it is handed to someone else. Permissions `calls.view`, `calls.create`, `calls.update`, `calls.delete`, `calls.export`.

### `POST /api/v1/calls`

```json
{
  "customer_id": "01k…",
  "message": "Asked for a quote on 20 cartons of Ivermectin; will confirm Thursday.",
  "subject": "Quote request", "direction": "inbound", "outcome": "answered",
  "duration_seconds": 240, "called_at": "2026-10-21T10:15:00Z", "next_follow_up": "2026-10-24", "rep_id": null
}
```

Only `customer_id` and `message` (up to 5 000 characters) are required. `rep_id` defaults to the caller; a manager may log a call for someone on their team (anyone else is **422**).
`direction`: `outbound` (default) or `inbound`. `outcome`: `answered`, `no_answer`, `busy`, `voicemail`, `wrong_number`. `called_at` defaults to now; `next_follow_up` cannot be in the past. **201**; audit-logged; the customer's owner and managers are notified.

### `GET /api/v1/calls`, `GET /api/v1/calls/{id}`

Paginated, searchable (`search` matches subject, message, customer and rep name), sortable. Filters: `customer_id`, `rep_id`, `direction`, `outcome`, `from`, `to` (dates of `called_at`). `?export=csv|xlsx` needs `calls.export`. **403** for a call outside the caller's reach, **404** for another company's.

### `GET /api/v1/customers/{id}/calls`

The customer's calls as their own list (requires `customers.view` and access to the customer).

### `PUT /api/v1/calls/{id}`, `DELETE /api/v1/calls/{id}`

Same fields, all optional; allowed on any call the caller can see. Edits and deletions are audit-logged.

### Visits follow the same rules 🔄 CHANGED

`GET /visits`, `/visits/{id}` and `/customers/{id}/visits` are now limited to the visits the caller logged, their team logged, or that are on customers they or their team own (before, every user saw every visit). New filters on the list: `customer_id`, `rep_id`. Creating or editing a visit with a `rep_id` outside the caller's team is **422**. Creating a visit is now audit-logged.

