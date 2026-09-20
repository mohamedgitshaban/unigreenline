# VetPharma ERP — Laravel Rebuild Specification

## 0. How to use this document

This is a complete functional and technical specification for **VetPharma ERP**, a
veterinary-pharmaceutical distribution ERP system. It is written so that it can be handed
to a fresh coding session with **no other context** and used to build the system from
scratch in **Laravel** (backend) with a database of your choice (MySQL/PostgreSQL
recommended). A prior prototype (Python stdlib + SQLite) already exists and works
end-to-end — this document captures everything that prototype does correctly, plus a
list of things it got wrong or left unfinished that the Laravel rebuild should fix
properly instead of repeating.

Treat this as the source of truth. Where the prototype's behavior is ambiguous or
incomplete, this document states the *intended* correct behavior.

---

## 1. Business Context

VetPharma is a veterinary pharmaceutical **distributor** (not a retailer) operating in
Egypt/MENA. It buys medicines (antibiotics, vaccines, antiparasitics, vitamins,
disinfectants, hormones) from suppliers, stores them across multiple warehouses (some
requiring cold storage), and sells them on credit or cash to:

- Veterinary clinics
- Farms (cattle, camel, horse)
- Poultry operations
- Distributors / retailers

The product is a full **ERP**, modeled closely on Odoo, covering Sales, Inventory,
Purchasing, CRM, Accounting, Analytics, and Admin. The UI must be **Arabic (RTL)** as the
primary language, ideally with English as a secondary/switchable language (the existing
frontend already has both language dictionaries — preserve that if reusing UI copy).

### Why FEFO matters
Pharmaceutical stock has expiry dates. Selling older (sooner-to-expire) stock before
newer stock — **First-Expired, First-Out (FEFO)** — is a regulatory/quality expectation,
not just good practice. Every stock deduction in the system must respect FEFO ordering.

### Why the audit log matters
Pharma distribution is regulated (Good Distribution Practice / Egyptian NTRA-style
expectations). Every meaningful data change must be attributable to a user, timestamped,
and **tamper-evident** — i.e., if someone edits history directly in the database, that
tampering must be detectable.

---

## 2. Roles & Permissions

Ten roles, each with a permission set drawn from eight capabilities:
**View, Add, Edit, Delete, Approve, Print, Export, Audit**.

| Role | Intended scope |
|---|---|
| Administrator | Full system access (all 8 permissions) |
| Owner | Read + Approve + Print + Export + Audit (oversight, not data entry) |
| Sales Manager | Full sales module: View, Add, Edit, Approve, Print, Export |
| Sales Rep | Own orders/customers only: View, Add, Edit, Print (**must be scoped to their own `sales_rep_id`** — the prototype defines this role but never actually enforces the "own orders only" scoping in queries; the Laravel build must enforce it) |
| Warehouse Manager | Full inventory: View, Add, Edit, Approve, Print, Export |
| Warehouse Employee | Picking & receiving only: View, Add, Edit (no approve/export) |
| Accountant | Full accounting: View, Add, Edit, Print, Export |
| Customer Service | CRM only: View, Add, Edit |
| Purchasing | Purchasing module: View, Add, Edit, Approve, Print |
| Auditor | Read-only + Audit: View, Export, Audit (cannot Add/Edit/Delete anything) |

**Implementation guidance:** Use `spatie/laravel-permission` for roles/permissions
instead of a raw bitmask — it maps cleanly onto "8 permission types × N modules" via
named permissions (e.g. `sales.add`, `inventory.approve`) and is easier to extend later
than a bitmask. Preserve the *concept* of the 8 capability types, but you don't need to
literally replicate the bitmask integer from the prototype.

**Multi-layer enforcement (do this properly — the prototype only did layer 2):**
1. **Warehouse scoping** — a `user_warehouses` pivot table restricts which warehouses a
   user can see/act on (schema already exists in the prototype but was never enforced —
   fix this in the rebuild: every inventory/warehouse query must filter by the
   authenticated user's assigned warehouses unless they hold a role with global scope).
2. **Route/policy authorization** — Laravel Policies or Gates per resource, checking the
   permission set above.
3. **Ownership scoping** — Sales Reps should only see/edit their own sales orders and
   customers unless they're a Sales Manager or Admin.
4. **Row-level tenant isolation** — see §3, multi-tenancy.

---

## 3. Multi-tenancy

The schema includes a `tenants` table and every business table carries a `tenant_id`.
**The prototype declared this but never actually used it as real multi-tenancy** — it
hardcoded a single tenant ID globally. Decide up front whether you actually need
multi-tenant support:
- If this is single-company software (most likely, given the context): keep `tenant_id`
  columns for future-proofing but don't over-engineer tenant isolation middleware.
- If multi-tenant SaaS is actually a goal: implement proper tenant resolution
  (subdomain or header-based) and scope every Eloquent query via a global scope on
  `tenant_id`, not just a config constant.

---

## 4. Database Schema

Below is the full schema (26 tables), described independently of any specific SQL
dialect so it translates cleanly to Laravel migrations. Use UUIDs or ULIDs for primary
keys (`id` as string) — the prototype uses human-readable prefixed IDs like `SO-XXXXXX`,
`INV-XXXXXX`, `PRD-XXXXXX` which is good for support/debugging; keep that convention if
you like it, or use auto-increment + a separate display-code column — your call, but
**document whichever way you go** because the frontend keys on these IDs.

### 4.1 System / Auth
- **tenants**: id, name, slug (unique), plan, active, timestamps
- **roles**: id, tenant_id, name, description, permissions (int bitmask OR use Spatie
  instead), timestamps — unique (tenant_id, name)
- **users**: id, tenant_id, role_id (FK), name, email (unique), password_hash, salt
  (drop if using Laravel's built-in `password` + bcrypt/argon2 hashing — **do this**,
  don't hand-roll SHA-256+salt like the prototype did), avatar_initials, status
  (active/inactive/suspended), mfa_enabled, failed_logins, last_login, timestamps
- **user_warehouses**: user_id, warehouse_id (pivot, enforce this in queries — see §2)
- **sessions**: id, user_id, token_hash, created_at, expires_at, ip_address, user_agent,
  revoked — **use this table for real token revocation** (the prototype defined it but
  never used it, so logout/revocation was fake — a "logged out" JWT stayed valid until
  natural expiry). Prefer Laravel Sanctum personal access tokens, which give you real
  revocation for free, over hand-rolled JWT.

### 4.2 Audit Log (append-only)
- **audit_log**: id, occurred_at, user_id, user_name (denormalized), tenant_id,
  warehouse_id (nullable), module, entity_type, entity_id, operation
  (INSERT/UPDATE/DELETE/LOGIN/LOGOUT/EXPORT/IMPORT/APPROVE), prev_values (JSON),
  new_values (JSON), ip_address, request_id, prev_hash, entry_hash
  (`HMAC-SHA256(prev_hash || canonical_json(payload))`, chained — each row's hash covers
  the previous row's hash, so any retroactive edit breaks the chain from that point
  forward). Indexes on (entity_type, entity_id), user_id, occurred_at, module.
  **At the DB level, revoke UPDATE and DELETE on this table for the application's DB
  role** (a real Postgres/MySQL grant, not just "we promise not to") — this is the one
  place a real DB-level guarantee is worth the setup cost.

### 4.3 Reference
- **product_categories**: id, tenant_id, name, code, description, active — unique
  (tenant_id, name)

### 4.4 Inventory
- **warehouses**: id, tenant_id, name, city, governorate, address, manager_id (FK
  users), manager_name (denormalized), temperature, capacity, phone, status
  (active/inactive/maintenance), stock_value (derived, recalculated on every stock
  change), timestamps
- **products**: id, tenant_id, category_id (FK), supplier_id (FK), name, sku (unique),
  brand, pack_unit (e.g. "Vial 100ml"), carton_qty (packs per carton),
  pack_cost_price, pack_selling_price, cost_price (= pack_cost_price × carton_qty),
  selling_price (= pack_selling_price × carton_qty), discount_pct, tax_pct (default 14,
  Egyptian VAT), min_stock_cartons, reorder_level, active, timestamps
- **inventory_batches**: id, tenant_id, product_id (FK), warehouse_id (FK), batch_no,
  mfg_date, exp_date (**not nullable** — every batch must have an expiry), rcv_date,
  qty_cartons (>= 0), qty_packs (>= 0), cost_per_carton — unique (tenant_id, batch_no,
  warehouse_id). **Critical index**: composite (product_id, warehouse_id, exp_date,
  rcv_date) — this is the FEFO query path and must be fast.

### 4.5 Purchasing
- **suppliers**: id, tenant_id, name, country, city, contact, email, phone,
  pay_terms (default "Net 30"), currency, balance (AP balance, negative = we owe them),
  rating, status, timestamps
- **purchase_orders**: id, tenant_id, supplier_id (FK), warehouse_id (FK), created_by
  (FK users), status (draft/sent/pending/received/cancelled), stock_added (bool,
  idempotency flag), order_date, expected_date, received_date, subtotal, tax_amount,
  total, notes, timestamps
- **purchase_order_lines**: id, po_id (FK, cascade delete), product_id (FK), qty_cartons,
  cost_per_carton, total

### 4.6 CRM
- **customers**: id, tenant_id, sales_rep_id (FK users), name, type
  (Clinic/Farm/Poultry/Distributor/Retailer/Other), classification (A+/A/B/C), phone,
  email, governorate, province, city, area, address, credit_limit (default 50000),
  pay_terms, balance (AR balance), status, timestamps
- **leads**: id, tenant_id, assigned_to (FK users), name, type, contact, phone, email,
  source, status (new/qualified/proposal/won/lost), value, notes, timestamps
- **customer_visits**: id, tenant_id, customer_id (FK), rep_id (FK users), visit_date,
  type, outcome (positive/neutral/negative), notes, next_visit, created_at
- **complaints**: id, tenant_id, customer_id (FK), product_id (FK, nullable),
  assigned_to (FK users), batch_no, type, description, resolution, priority
  (high/medium/low), status (investigating/resolved/closed), complaint_date,
  resolved_date, timestamps
- **campaigns**: id, tenant_id, created_by (FK users), name, type, target, discount,
  start_date, end_date, description, status (active/completed/paused/cancelled), reach,
  revenue, timestamps

### 4.7 Sales
- **sales_orders**: id, tenant_id, customer_id (FK), sales_rep_id (FK users),
  warehouse_id (FK), status (draft/picking/invoiced/delivered/cancelled), pay_type
  (cash/credit), grace_period (days), due_date, invoice_discount, subtotal, tax_amount,
  total, stock_deducted (bool, idempotency flag), notes, order_date, timestamps.
  Indexes: customer_id, sales_rep_id, status, order_date.
- **sales_order_lines**: id, so_id (FK, cascade delete), product_id (FK), batch_no
  (optional pre-selection, actual deduction always follows FEFO regardless), qty, unit
  (Carton/Pack), unit_price, discount_pct, free_qty, subtotal

### 4.8 Invoices
- **invoices**: id, tenant_id, so_id (FK, nullable — some invoices aren't tied to an
  SO), customer_id (FK), issued_date, due_date, subtotal, tax_amount, total, paid,
  balance, status (outstanding/partial/paid/overdue/void), timestamps.
  **Invoices must never be hard-deleted after creation** — enforce this at the DB level
  (a trigger, or in Laravel, override the model's `delete()` to always throw, and also
  add the DB-level guard as a second line of defense) — void instead of delete.

### 4.9 Deliveries
- **deliveries**: id, tenant_id, so_id (FK), invoice_id (FK, nullable), customer_id
  (FK), driver, delivery_date, status
  (pending/preparing/out_for_delivery/delivered/failed), delivered_at, notes,
  timestamps

### 4.10 Collections (payments received)
- **collections**: id, tenant_id, invoice_id (FK), customer_id (FK), collected_by (FK
  users), amount (> 0), method (Cash/Bank Transfer/Cheque/Credit Card/Other),
  reference, payment_date, notes, created_at

### 4.11 Returns
- **returns**: id, tenant_id, invoice_id (FK, nullable), customer_id (FK, nullable),
  supplier_id (FK, nullable), product_id (FK, nullable), warehouse_id (FK, nullable),
  batch_no, type (Sales Return/Damaged/Expired Return/Wrong Item/Purchase Return), qty,
  unit, amount, restocked (bool — if true, adds qty back into the matching batch and
  triggers a warehouse value recalculation), reason, status
  (pending/completed/rejected), return_date, created_at

### 4.12 Transfers (inter-warehouse)
- **transfers**: id, tenant_id, product_id (FK), from_warehouse (FK), to_warehouse (FK,
  must differ from from_warehouse), batch_no, qty_cartons (> 0), transfer_date, notes,
  status (pending/completed/cancelled), created_by (FK users), created_at

### 4.13 Accounting
- **chart_of_accounts**: id, tenant_id, code, name, type
  (Asset/Liability/Equity/Revenue/Expense), level, parent_id (self-FK, for
  hierarchical rollup), balance, active — unique (tenant_id, code)
- **journal_entries**: id, tenant_id, created_by (FK users), ref (free-text reference
  to the source document, e.g. an invoice or PO id), description, entry_date, posted
  (bool), created_at
- **journal_lines**: id, journal_entry_id (FK, cascade delete), account_id (FK),
  account_code, account_name (denormalized for reporting), debit, credit — **a line is
  either debit or credit, never both, and debit/credit must be >= 0**; enforce with a
  DB check constraint (MySQL 8.0.16+/Postgres both support this) or model-level
  validation.

### 4.14 Notifications
- **notifications**: id, tenant_id, user_id (FK, nullable — null = broadcast to
  everyone), type, title, body, icon, link_module, link_id, unread (bool), created_at.
  Indexes: (user_id, unread), (tenant_id, created_at).

### 4.15 Misc
- **discarded_actions**: id, tenant_id, user_id (FK), user_name, type, label, payload
  (JSON — partially-filled form data the user abandoned; the frontend has an "Import /
  Export" and a "Discarded Actions" admin screen for this, so wire it up rather than
  leaving it dead like the prototype did)
- **schema_migrations**: not needed — Laravel's own migrations table replaces this.

---

## 5. Core Business Logic

These are the algorithms that make this an ERP and not a CRUD app. Get these right;
everything else is straightforward REST resource controllers.

### 5.1 FEFO stock deduction (the single most important algorithm)
When a sales order's stock is deducted (on transition to `invoiced` or `delivered`):
1. For each order line, resolve the product's `carton_qty` and compute cartons needed.
2. Fetch all batches for that product **in that specific warehouse** with
   `qty_cartons > 0`, ordered by `exp_date ASC, rcv_date ASC` (earliest-expiring first;
   tie-break by earliest-received).
3. Walk the batches, taking `min(remaining_needed, batch.qty_cartons)` from each until
   the line's need is fully satisfied.
4. **This must be validated as fully satisfiable BEFORE any row is written.** Pre-flight
   check: sum of available cartons per product/warehouse must be >= needed cartons for
   *every* line in the order, or the whole operation is rejected (HTTP 422) with no
   partial deduction. Never let one line succeed while another fails.
5. The whole deduction for the whole order must be **one atomic DB transaction**. If
   anything fails partway, roll back everything (this is the #1 place the Python
   prototype was structurally correct — preserve that atomicity, and wrap it in an
   actual Laravel DB transaction rather than several independent statements).
6. Use an idempotency flag (`stock_deducted` on the sales order) so re-processing the
   same order never double-deducts.
7. After deduction, recalculate the affected warehouse's `stock_value`
   (`SUM(batch.qty_cartons * product.cost_price)` across that warehouse).

### 5.2 Purchase order receiving (adds stock)
1. For each PO line, find the most-recently-received existing batch for that
   product+warehouse; if found, add the received quantity to it, otherwise create a new
   batch row (with a real expiry date supplied at receiving time — **the prototype
   defaulted to "received date + 365 days" as a placeholder, which is wrong for real
   pharma data; the Laravel build should require the actual expiry date as input on
   receiving, not fabricate one**).
2. Idempotent via a `stock_added` flag on the PO.
3. One atomic transaction; recalculate warehouse stock value afterward.
4. Auto-post a journal entry: Debit Inventory (1300), Credit Accounts Payable (2100),
   for the PO total.

### 5.3 Double-entry accounting auto-posting
Whenever one of these business events happens, auto-generate a balanced journal entry
(sum of debits must equal sum of credits, or reject the entry):
- **Invoice created**: Dr Accounts Receivable (1200) for total; Cr Sales Revenue (4100)
  for subtotal; Cr VAT Payable (2300) for tax.
- **Collection (payment) recorded**: Dr Bank/Cash (1120 or 1110 depending on method); Cr
  Accounts Receivable (1200).
- **PO received**: Dr Inventory (1300); Cr Accounts Payable (2100).

Chart of account codes above are the seed defaults (§6.7) — keep them stable since the
auto-posting logic looks accounts up by code.

### 5.4 Invoice generation & lifecycle
- An invoice is auto-created when a sales order moves to `invoiced` status (or
  `delivered` directly from `draft`/`picking`, whichever path — the transition should
  auto-invoice if not already invoiced).
- Invoice `status` is derived from `paid` vs `total`: `outstanding` (paid = 0) →
  `partial` (0 < paid < total) → `paid` (paid >= total). A separate scheduled job
  should flip `outstanding`/`partial` invoices to `overdue` once `due_date` passes (the
  prototype never implemented this — it's seed-data-only in the prototype; build it as
  a real scheduled command).
- Invoices are **never deleted**, only voided.

### 5.5 Collections (payments)
- Reject if amount <= 0, if amount exceeds the invoice's remaining balance, or if the
  invoice is already fully paid.
- Update invoice `paid`/`balance`/`status`, decrement the customer's AR `balance`
  (floor at 0), and auto-post the journal entry from §5.3.

### 5.6 Credit limit enforcement
The architecture intent (see the seed data's credit limits and the "Credit Warning"
notification type) is: **block a new sales order if it would push the customer's
balance over their credit_limit**, and proactively notify at an 85% threshold. The
prototype's actual code never implemented this check — it's a real gap. The Laravel
rebuild should implement it as a validation step in sales order creation.

### 5.7 Immutable, hash-chained audit log
On every meaningful write (INSERT/UPDATE/DELETE on business tables, plus
LOGIN/LOGOUT/EXPORT/IMPORT/APPROVE actions):
1. Fetch the most recent audit row's `entry_hash` (or the literal string `"GENESIS"` if
   none exists yet) as `prev_hash`.
2. Build a canonical JSON payload of `{id, module, entity_type, entity_id, operation,
   new_values, occurred_at}` with **sorted keys** (so the hash is deterministic).
3. `entry_hash = HMAC-SHA256(secret_key, prev_hash + payload_json)`.
4. Insert the audit row **in the same DB transaction** as the business change it's
   recording — either both commit or neither does. (The prototype wrote audit entries
   as a separate call after the business write with its own lock, which is *not* truly
   atomic with the business transaction — fix this by writing the audit row inside the
   same transaction/unit of work.)
5. Verifying the chain later means re-walking every row in insertion order and
   recomputing each hash — if any row's stored data was altered after the fact, the
   recomputed hash won't match what the *next* row recorded as its `prev_hash`, and the
   chain breaks from that point forward. Build an admin "verify audit integrity" action
   that does exactly this walk.
6. At the DB layer, revoke UPDATE/DELETE grants on the audit table for the app's
   runtime DB user (see §4.2) as defense in depth — HMAC chaining detects tampering,
   but a DB grant *prevents* the easy version of it.

### 5.8 Warehouse transfers
- Validate source batch has enough quantity.
- Move quantity out of the source batch (delete the batch row if it hits zero),
  merge into or create the destination batch (same `batch_no`, `exp_date` carried over
  — don't lose the expiry date on transfer).
- One atomic transaction; recalculate **both** warehouses' stock values.

### 5.9 Returns with restocking
- If `restocked = true`, add the returned quantity back to the matching batch (creating
  it if it doesn't exist) and recalculate that warehouse's stock value.
- Support both sales returns (from customers) and purchase returns (back to suppliers).

---

## 6. API Surface

Build these as versioned REST resources (`/api/v1/...`). Auth via Laravel Sanctum
bearer tokens. All endpoints require authentication except `/health` and
`/auth/login`. Apply the permission checks from §2 per route via Policies.

| Method | Path | Notes |
|---|---|---|
| POST | /auth/login | Returns token + user profile (id, name, email, role, permissions, avatar) |
| POST | /auth/logout | **Must actually revoke the token** (delete the Sanctum token row) |
| GET | /auth/me | Current user profile |
| POST | /auth/change-password | **Missing from the prototype entirely — must exist.** Require current password. |
| GET/POST/PUT | /warehouses, /warehouses/{id} | Include nested batches on GET {id} |
| GET/POST/PUT | /products, /products/{id} | Include nested batches |
| GET/POST | /categories | |
| GET/POST/PUT | /customers, /customers/{id} | Include nested orders/invoices/visits on GET {id}; scope to sales_rep_id for Sales Rep role |
| GET/POST | /leads | |
| GET/POST | /visits | |
| GET/POST | /complaints; PUT /complaints/{id}/resolve | |
| GET/POST | /campaigns; PUT /campaigns/{id}/end | |
| GET/POST | /sales-orders; GET /sales-orders/{id} | POST validates stock (§5.1) before writing, returns 422 on insufficient stock |
| PUT | /sales-orders/{id}/status | Drives invoice auto-creation, stock deduction, delivery/visit auto-creation per §5.4 |
| GET | /invoices; GET /invoices/{id} | Include line items + collections |
| GET/POST | /collections | Per §5.5 |
| GET/POST | /purchase-orders | |
| POST | /purchase-orders/{id}/receive | Per §5.2, requires Approve permission |
| POST | /inventory/grn | Manual goods receipt (batch doesn't come from a PO) |
| GET/POST | /transfers | Per §5.8 |
| GET/POST | /returns | Per §5.9 |
| GET | /deliveries; GET /deliveries/{id} | |
| PUT | /deliveries/{id}/deliver | Marks delivered, updates SO status, auto-creates a follow-up CRM visit |
| GET | /journal-entries | With nested lines |
| GET | /chart-of-accounts | |
| GET | /users | Requires Audit permission; **add POST/PUT for actual user management — missing from the prototype** |
| GET | /audit-log | Requires Audit permission; paginate (the prototype hard-capped at 500 rows with no pagination) |
| GET | /notifications; PUT /notifications/read-all | |
| GET | /analytics/dashboard | KPI aggregates |
| GET | /analytics/expiry | Batches with computed days_left, ordered by exp_date |
| GET | /analytics/stock | Per-product stock rollup across warehouses |
| GET | /reports/sales, /reports/inventory | Date/warehouse-filtered exports, requires Export permission |

**Pagination:** every list endpoint should support `?page=&per_page=` (Laravel's
built-in paginator). The prototype returned unbounded result sets for almost every
list — fine for demo data, not fine for real volume.

---

## 7. Seed Data (for parity with the existing demo/UAT dataset)

Recreate this as a Laravel seeder so the new build can be demoed with the same data
the client has already seen.

- **Tenant**: `VetPharma Distribution Co.` (slug `vetpharma`, plan `enterprise`)
- **Roles**: Administrator, Owner, Sales Manager, Sales Rep, WH Manager, WH Employee,
  Accountant, Customer Svc, Purchasing, Auditor (permission sets per §2)
- **Users** (all same password in seed, force change on first login in production):
  Ahmed Hassan (Sales Manager, ahmed@vetpharma.com), Mohamed Salem (Sales Rep,
  msalem@vetpharma.com), Heba Mahmoud (Sales Rep, heba@vetpharma.com), Omar Farouk
  (Sales Rep, omar@vetpharma.com), Khalid Omar (WH Manager, khalid@vetpharma.com), Rana
  Sami (Accountant, rana@vetpharma.com), Dina Hassan (Customer Svc,
  dina@vetpharma.com)
- **Warehouses**: Main Warehouse (Cairo, ambient, 10,000 capacity), Cold Storage
  (Cairo, refrigerated 2-8°C, 2,000 capacity), Alexandria Branch (ambient, 5,000
  capacity)
- **Categories**: Antibiotics, Antiparasitics, Vaccines, Vitamins & Supplements,
  Hormones, Disinfectants
- **Suppliers**: EgyVet Pharmaceutical (Egypt), ImmunoVet Biologics (Germany), ArabiVet
  Industries (Egypt)
- **Products** (6): Oxytetracycline 20% Injectable, Ivermectin 1% Injection, FMD
  Vaccine 20 Dose, Multivitamin AD3E Injection, Amoxicillin 15% LA Injection,
  Glutaraldehyde Disinfectant 5L — each with realistic pack/carton pricing and multiple
  expiry batches across warehouses (see `002_seed.sql` in the prototype for exact
  figures to copy).
- **Customers** (5): mix of A+/A/B classifications, clinic/farm/poultry types, credit
  limits from 100k to 500k EGP
- **Chart of accounts**: standard Asset/Liability/Equity/Revenue/Expense hierarchy with
  codes 1000–6200 (copy exactly — the auto-posting logic in §5.3 depends on these
  codes existing)
- Sample sales orders, invoices, deliveries, collections, purchase orders, journal
  entries, complaints, campaigns, visits, and notifications in various states (draft,
  picking, invoiced, delivered; outstanding, partial, paid, overdue) so every UI state
  has something to render.

The exact seed values are in the prototype's `migrations/002_seed.sql` — port them
directly into a Laravel `DatabaseSeeder`.

---

## 8. Frontend Requirements

A working reference frontend already exists (`static/index.html` in the prototype) —
a single-page Arabic-RTL app with an Odoo-style collapsible sidebar. Whether you rebuild
it in Blade + Livewire/Alpine, keep it as a Vue/React SPA consuming the Laravel API, or
port the existing HTML/JS as-is against new endpoints is an open decision — **flag this
choice to whoever picks up this spec rather than assuming**, since it materially
changes the project shape. Whatever you choose, the sidebar/module structure to match:

**Overview:** Dashboard (KPI cards: monthly sales, active customers, collected amount,
outstanding AR, overdue amount, inventory value, critical expiry count, pending
deliveries)

**Sales:** Sales Orders, Invoices, Deliveries, Returns, Collections

**Warehouse:** Warehouses, Products, Goods Receipt (GRN), Transfers, Stock Count,
Expiry Tracking

**Purchasing:** Purchase Orders, Suppliers

**CRM:** Customers, Leads, Customer Visits, Complaints, Marketing Campaigns

**Accounting:** Journal Entries, Chart of Accounts, Accounts Receivable, Accounts
Payable, Financial Reports

**Analytics:** Analytics, Reports Center

**Admin:** Users & Permissions, Audit Log, Settings, Product Categories, Discarded
Actions, Import/Export

**A real login screen is required.** The prototype's frontend had none — it hardcoded
the logged-in user client-side and never actually called its own backend's login
endpoint. That is not acceptable for the Laravel rebuild: authentication must gate
access to the app, full stop.

---

## 9. Known Gaps in the Prior Prototype — Do Not Repeat These

This list exists so the Laravel build doesn't silently reproduce the prototype's
shortcuts. Treat every line below as a requirement, not a suggestion:

1. **No login screen.** The demo frontend never called its own login API — it hardcoded
   the current user. Build a real, enforced login flow.
2. **Only one write path was ever wired end-to-end** (creating a sales order). Every
   other create/edit action in the old demo only mutated client-side state and was
   never persisted or visible to other users. Every module in §8 needs its CRUD screens
   actually wired to the API, tested by actually creating/editing/viewing each entity
   type end to end, not just sales orders.
3. **Passwords hashed with unsalted-work-factor SHA-256(salt+password).** Use bcrypt or
   argon2 (Laravel's default `Hash::make` is fine) — SHA-256 is fast to brute-force at
   scale even with a salt, because it has no configurable work factor.
4. **No password-change endpoint existed at all**, despite documentation telling users
   to change their password after first login. Build one.
5. **No real token revocation** — logout just wrote an audit entry; the token itself
   stayed valid until natural expiry. Use Sanctum so logout actually invalidates the
   token.
6. **Warehouse-level and rep-level data scoping were declared in the schema
   (`user_warehouses`, `sales_rep_id` "own orders only") but never enforced in
   queries.** Any authenticated user could see all warehouses' and all reps' data
   regardless of role description. Enforce it for real (§2).
7. **No credit-limit enforcement** on sales order creation, despite customers having a
   `credit_limit` field and the notification system having a "Credit Warning" type.
   Implement it (§5.6).
8. **No pagination anywhere.** Fine for a 6-product demo, not fine for real data volume.
9. **CORS reflected any Origin header with credentials allowed** — overly permissive;
   lock this down to known frontend origins in production config.
10. **The default JWT/app signing secret shipped as a plaintext default in the code**
    (`change-this-in-production-vetpharma-2025`). Never ship a real default secret in
    version control — require it from environment/`.env` with no functional fallback,
    and fail startup loudly if it's missing in production.
11. **No overdue-invoice job** — `overdue` status only existed in hand-written seed
    data, nothing actually transitioned invoices into that state over time. Build a
    scheduled command.
12. **A companion "cloud architecture" document describes a much larger, unbuilt
    system** (Kubernetes, Postgres RLS, Entra ID, event streaming). Don't treat that
    document as a description of what exists — it's a future-scaling proposal, not a
    spec of delivered functionality. This document (the one you're reading) is the
    actual spec.

---

## 10. Suggested Build Order

1. **Foundation**: Laravel project, migrations for all 26 tables, Sanctum auth, roles
   via `spatie/laravel-permission`, seeders from §7.
2. **Inventory core**: Warehouses, Products, Batches, FEFO deduction service (§5.1),
   GRN endpoint, unit tests for FEFO edge cases (partial batch, multiple batches,
   exact-exhaustion, insufficient-stock rejection) — write these tests first.
3. **Audit log**: hash-chain service (§5.7), wired into the FEFO/GRN work from step 2
   as the first real usage, plus a chain-verification command.
4. **Sales cycle**: Sales Orders → stock validation → Invoices → Deliveries →
   Collections, with journal auto-posting (§5.3) and credit-limit enforcement (§5.6).
5. **Purchasing**: Purchase Orders → receiving (§5.2) → supplier balances.
6. **CRM**: Customers, Leads, Visits, Complaints, Campaigns.
7. **Accounting reporting**: Chart of Accounts views, AR/AP aging, financial
   statements.
8. **Analytics & scheduled jobs**: dashboard aggregates, expiry alerts, overdue-invoice
   job, notification generation.
9. **Admin**: user management (with real create/edit, not just list), audit log
   viewer + integrity-check action, settings.
10. **Frontend integration**: whichever approach was chosen in §8, wired to every
    endpoint above, with the login gate from item 1 in §9 enforced from day one.
11. **Hardening**: pagination everywhere, rate limiting on auth endpoints, CORS
    lockdown, secrets via `.env` only, load-test the FEFO query path with realistic
    batch volumes.
