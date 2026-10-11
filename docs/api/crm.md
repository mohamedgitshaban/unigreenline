# CRM

> 🆕 **Delete** (`crm.delete`), **edit** for leads/visits/complaints/campaigns and **customer import** were added later and are flagged below. By default only `Administrator` holds `crm.delete`; an admin can grant it to any role (see [Admin > Roles](./admin.md#roles--permissions)).

`Customer Service` has `crm.view/add/edit` (sees/manages every customer, no
ownership scoping). `Sales Rep` also has `crm.view/add/edit/print`, per
spec §2 grouping "orders/customers" as one scope — **but** scoped to their
own `sales_rep_id`, same as sales orders. No other role gets `crm.*` unless
noted otherwise.

---

## Customers

### `GET /api/v1/customers`

Requires `crm.view`. Sales Rep sees only their own customers.

### `GET /api/v1/customers/{id}`

Includes `orders_count`, `invoices_count` and `visits_count`. `403` for a
Sales Rep requesting another rep's customer.

### `GET /api/v1/customers/{id}/orders`, `/invoices`, `/visits`

The customer's sales orders, invoices and visits, each as its own list.
Same access as the customer show. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

### `POST /api/v1/customers`

Requires `crm.add`. Only `name` and `type` are required.

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

Requires `crm.edit` (+ ownership if Sales Rep). All fields optional.

### `DELETE /api/v1/customers/{id}` 🆕 NEW

Requires `crm.delete` (+ ownership if Sales Rep). **204**, audit-logged as
`DELETE`; the customer's visits and complaints are removed with it. **409**
`This Customer is referenced by other records and cannot be deleted.` while the
customer has sales orders, invoices or collections — set `status: inactive`
instead. Another tenant's customer is **404**.

### `POST /api/v1/customers/import` 🆕 NEW

Requires `crm.add`. Bulk-creates customers from a spreadsheet — see
[Importing from a spreadsheet](./README.md#importing-from-a-spreadsheet--new).
Multipart: `file` (.xlsx/.csv) and optional `update_existing` (`1` = update
customers matched by **name**, otherwise they are skipped).

Columns (headings are case-insensitive): `Name`*, `Type`*, `Classification`,
`Phone`, `Email`, `Governorate`, `Province`, `City`, `Area`, `Address`,
`Credit Limit`, `Pay Terms`, `Status`, `Sales Rep Email` (must be an existing
user; a Sales Rep can only import customers under their own name — a blank
value assigns them to the importer).

### `GET /api/v1/customers/import-template` 🆕 NEW

Requires `crm.add`. Downloads `customers-import-template.xlsx` — the headings
above plus one example row.

---

## `GET/POST /api/v1/leads`

Requires `crm.view`/`crm.add`. Same `type` enum as customers; `status`: `new` (default),
`qualified`, `proposal`, `won`, `lost`.

### `GET /api/v1/leads/{id}`

Requires `crm.view`. Includes `assigned_to`. **404** — the lead belongs to another account.

### `PUT /api/v1/leads/{id}` 🆕 NEW

Requires `crm.edit`. Partial update with the create rules. Audit-logged with the
changed fields.

### `DELETE /api/v1/leads/{id}` 🆕 NEW

Requires `crm.delete`. **204**.

---

## `GET/POST /api/v1/visits`

Requires `crm.view`/`crm.add`. A rep logging a visit gets `rep_id`
defaulted to themselves if omitted. `outcome`: `positive`, `neutral`,
`negative`.

### `GET /api/v1/visits/{id}`

Requires `crm.view`. Includes the nested `customer` and `rep`. **404** — the visit belongs to another account.

### `PUT /api/v1/visits/{id}` 🆕 NEW

Requires `crm.edit`. Partial update with the create rules (`next_visit` must not
precede `visit_date`).

### `DELETE /api/v1/visits/{id}` 🆕 NEW

Requires `crm.delete`. **204**.

---

## Complaints

### `GET/POST /api/v1/complaints`

Requires `crm.view`/`crm.add`. `priority` defaults to `medium`, `status`
defaults to `investigating`.

### `GET /api/v1/complaints/{id}`

Requires `crm.view`. Includes the nested `customer`, `product` and
`assigned_to`. **404** — the complaint belongs to another account.

### `PUT /api/v1/complaints/{id}` 🆕 NEW

Requires `crm.edit`. Partial update of the complaint's details (type, priority,
description, product, batch, assignee...). Resolving remains its own action,
`PUT /complaints/{id}/resolve`, below.

### `DELETE /api/v1/complaints/{id}` 🆕 NEW

Requires `crm.delete`. **204**.

### `PUT /api/v1/complaints/{id}/resolve`

Requires `crm.edit`.

```json
{ "resolution": "Replacement shipped." }
```
Sets `status: "resolved"` and `resolved_date` to today. `resolution` is
required — `422` without it.

---

## Campaigns

### `GET/POST /api/v1/campaigns`

Requires `crm.view`/`crm.add`. `status` defaults to `active`.

### `GET /api/v1/campaigns/{id}`

Requires `crm.view`. **404** — the campaign belongs to another account.

### `PUT /api/v1/campaigns/{id}` 🆕 NEW

Requires `crm.edit`. Partial update (`end_date` must not precede `start_date`).
Ending a campaign remains its own action, `PUT /campaigns/{id}/end`, below.

### `DELETE /api/v1/campaigns/{id}` 🆕 NEW

Requires `crm.delete`. **204**.

### `PUT /api/v1/campaigns/{id}/end`

Requires `crm.edit`. Sets `status: "completed"` and fills `end_date` with
today if it wasn't already set.

---

## Not built in this step

- Customers, leads, visits, complaints and campaigns all take `PUT /{id}`
  (needs `crm.edit`) and `DELETE /{id}` (needs `crm.delete`, which an admin can
  grant to any role). A customer with sales orders or invoices can't be deleted
  (409). `resolve`/`end` remain separate actions.
