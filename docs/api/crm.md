# CRM

> 🆕 **Delete** (`customers.delete`, `leads.delete`, `visits.delete`, ...), **edit** for leads/visits/complaints/campaigns and **customer import** were added later and are flagged below. By default only `Administrator` holds the CRM delete permissions; an admin can grant any of them to any role (see [Admin > Roles](./admin.md#roles--permissions)).

`Customer Service` has `crm.view/add/edit` (sees/manages every customer, no
ownership scoping). `Sales Rep` also has `crm.view/add/edit/print`, per
spec §2 grouping "orders/customers" as one scope — **but** scoped to their
own `sales_rep_id`, same as sales orders. No other role gets `crm.*` unless
noted otherwise.

---

## Customers

### `GET /api/v1/customers`

Requires `customers.view`. Sales Rep sees only their own customers.

### `GET /api/v1/customers/{id}`

Includes `orders_count`, `invoices_count` and `visits_count`. `403` for a
Sales Rep requesting another rep's customer.

### `GET /api/v1/customers/{id}/orders`, `/invoices`, `/visits`

The customer's sales orders, invoices and visits, each as its own list.
Same access as the customer show. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

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
defaults to `50000`. A Sales Rep omitting `sales_rep_id` gets themselves;
naming a different rep is `422`.

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
