# CRM

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

Includes nested `orders`, `invoices` (from the Sales module — sales orders
and invoices for this customer), and `visits` (below). `403` for a Sales
Rep requesting another rep's customer.

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

---

## `GET/POST /api/v1/leads`

Requires `crm.view`/`crm.add`. No update/delete endpoint (not in the spec's
table). Same `type` enum as customers; `status`: `new` (default),
`qualified`, `proposal`, `won`, `lost`.

---

## `GET/POST /api/v1/visits`

Requires `crm.view`/`crm.add`. A rep logging a visit gets `rep_id`
defaulted to themselves if omitted. `outcome`: `positive`, `neutral`,
`negative`.

---

## Complaints

### `GET/POST /api/v1/complaints`

Requires `crm.view`/`crm.add`. `priority` defaults to `medium`, `status`
defaults to `investigating`.

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

### `PUT /api/v1/campaigns/{id}/end`

Requires `crm.edit`. Sets `status: "completed"` and fills `end_date` with
today if it wasn't already set.

---

## Not built in this step

- Leads/visits/complaints/campaigns have no `PUT` (edit) beyond the
  specific `resolve`/`end` actions — matches the spec's endpoint table.
- No delete anywhere in CRM.
