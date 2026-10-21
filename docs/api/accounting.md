# Accounting

Every endpoint below has its own permission (`chart-of-accounts.view|create|update|delete|export`,
`journal-entries.view|create|reverse|export`, `balance-sheet.view`, `income-statement.view`) — see
[permissions.md](./permissions.md). `Accountant` holds the accounting ones;
`Sales Manager`/`Warehouse Manager`/etc. do not, and get `403`.

**Note on scope**: `GET /chart-of-accounts` and `GET /journal-entries` are
the only two Accounting endpoints in the spec's own §6 table. The financial
statements and aging reports below fill a gap between that table and the
build-order narrative (§10.7), which explicitly asks for them without
defining their shape — the request/response formats here are this build's
own design, not spec-mandated.

---

## `GET /api/v1/chart-of-accounts`

Flat list, not nested — newest first by default like every list; pass
`?sort_by=code&sort_dir=asc` for chart order. `parent_id` and `level` let the
frontend build a tree if it wants one. Defaults to 100/page (paginated, but
there are usually few enough accounts that one page covers it).

```json
{
  "data": [
    { "id": "01m...", "code": "1110", "name": "Cash", "type": "Asset", "level": 3, "parent_id": "01m...", "balance": "0.00", "active": true }
  ]
}
```

## `GET /api/v1/chart-of-accounts/{id}`

A single account, same shape as a list row. **404** — the account belongs to another account.

## `POST /api/v1/chart-of-accounts` 🆕 NEW

Requires `chart-of-accounts.create`. Adds an account to the chart.

```json
{ "code": "6500", "name": "Fuel", "type": "Expense", "parent_id": "01m...", "active": true }
```

`code` (unique per tenant, max 20 chars), `name` and `type`
(`Asset`/`Liability`/`Equity`/`Revenue`/`Expense`) are required. `parent_id`
(optional, same tenant) sets `level` = parent level + 1; without it the account
is level 1. **201** — the account. **422** on a duplicate code.

## `PUT /api/v1/chart-of-accounts/{id}` 🆕 NEW

Requires `chart-of-accounts.update`. Partial update of `name`, `active`, `parent_id`
(cannot be itself), `code`, `type`. **`code` and `type` are frozen (422) for the
system accounts below and for any account that already has journal lines** —
postings and statements key on them. `balance` is never editable (only postings
move it). Audit-logged.

## `DELETE /api/v1/chart-of-accounts/{id}` 🆕 NEW

Requires `chart-of-accounts.delete`. **204**. **409** for a system account, an account
with child accounts, or an account with journal lines. Another tenant's account
is **404**.

**System accounts** (auto-posting looks these up by code; never deleted or
re-coded/re-typed): `1110` Cash, `1120` Bank, `1200` Accounts Receivable, `1300`
Inventory, 🆕 `1350` Work in Progress (manufacturing, only when WIP accounting is on),
🆕 `1400` VAT Receivable (input VAT), `2100` Accounts Payable, 🆕 `2200`
Salaries Payable, 🆕 `2210` Payroll Taxes & Insurance Payable, `2300` VAT
Payable, 🆕 `3300` Opening Balance Equity, `4100` Sales Revenue, 🆕 `5100` Cost
of Goods Sold, 🆕 `5200` Manufacturing Variance, 🆕 `6100` Salaries Expense, `6300` Inventory Write-off Expense
([Recap](./recap.md)) and `6400` Manufacturing Overhead Absorbed
([Manufacturing](./manufacturing.md)). Run `AccountSeeder` to create the new ones
on an existing database.

### `GET /api/v1/chart-of-accounts/connections` 🆕 NEW

Requires `chart-of-accounts.view`. Which business flow feeds each account, and
how many journal lines each has actually received — the answer to "is this
account connected to anything?":

```json
{ "data": [
  { "id": "01m...", "code": "1200", "name": "Accounts Receivable", "type": "Asset",
    "kind": "posted",
    "used_by": ["Sales invoices", "Invoice import", "Collections", "Invoice void", "Sales returns (credit to the customer)"],
    "posting_lines": 41, "balance": "12500.00", "attention": null },
  { "code": "4200", "name": "Other Income", "kind": "manual", "used_by": ["Manual journal entries (other income)"], "posting_lines": 0, "attention": null },
  { "code": "7000", "name": "Custom", "kind": "custom", "used_by": [], "posting_lines": 0, "attention": "custom account with no postings" }
] }
```

`kind`: `header` (a grouping line, nothing posts to it), `posted` (fed
automatically by the flows in `used_by`), `manual` (no automatic flow — use a
manual journal entry) or `custom` (an account you added). `attention` flags a
`posted` account that has never received a line yet, or a `custom` one nobody
has posted to. The map lives in `Modules/Accounting/config/config.php`; tests
fail if a seeded account is missing from it or any flow posts to an unseeded code.

> Posted entries are never edited or deleted. A manual entry is cancelled by
> reversing it (below); an entry a business flow made is undone by undoing its
> document (void the invoice, reject the write-off...).

## Finance review — what was missing and what changed

🔄 A review of the chart of accounts against the code found accounts that nothing
posted to and flows that never reached the ledger. What was done:

| Gap found | Fix |
|---|---|
| **No cost of goods sold.** Delivering stock deducted it from the warehouse but the Inventory asset never fell and the income statement showed revenue with no cost. | 🔄 Delivering/invoicing an order posts **Dr 5100 Cost of Goods Sold / Cr 1300 Inventory** for the cost of the batches that left (FEFO). Free-gift stock costs nothing, so adds nothing. Once per order. |
| **Purchase VAT buried in inventory.** The receipt posted Dr Inventory for the VAT-inclusive total although batches carry the cost excluding tax. | 🔄 Receipt now posts **Dr 1300 (net) + Dr 1400 VAT Receivable (tax) / Cr 2100 (total)**. Settle input against output VAT with a manual entry. |
| **Manual goods receipts never reached the ledger** (stock appeared, no value). | 🔄 `POST /inventory/grn` posts **Dr 1300 Inventory / Cr 3300 Opening Balance Equity** for qty × cost. |
| **Returns had no accounting** (stock moved, money didn't). | 🔄 Customer returns post **Dr 4100 / Cr 1200** and lower the customer's balance; returned stock put back posts **Dr 1300 / Cr 5100**. Purchase returns post **Dr 2100 / Cr 1300** and reduce what is owed the supplier. |
| **No way to pay a supplier** — Accounts Payable only ever grew. | 🆕 `POST /supplier-payments`: **Dr 2100 / Cr 1110 or 1120**, supplier balance back toward zero. See [purchasing.md](./purchasing.md#supplier-payments--new). |
| **Journal entries were read-only** — no way to book capital, opening balances, fixed assets, other income, depreciation or a correction (accounts 1510, 1520, 3100, 3200, 4200 were unreachable). | 🆕 `POST /journal-entries` (manual entry) and `POST /journal-entries/{id}/reverse`. |
| **The balance sheet never balanced** — profit sits in revenue/expense accounts, so assets exceeded liabilities + equity by exactly the profit. | 🔄 Equity now includes `current_period_earnings` (revenue − expenses to date) and `balanced` is true when the books are sound. |
| **Payroll had no accounts** (6100 existed but nothing posted to it). | 🆕 HR payroll posts **Dr 6100 / Cr 2210 / Cr 2200**, then **Dr 2200 / Cr cash or bank**. See [hr.md](./hr.md#payroll). |
| **Invoice void did not give the balance back** (a mass-assignment slip silently dropped the customer balance update). | 🔄 Fixed: voiding restores the customer's balance, with a test that would have caught it. |

Still manual by design (no flow feeds them): `3100` capital, `3200` retained
earnings (year-end closing), `4200` other income — book them with a manual
journal entry. 🔄 Fixed assets (`1510`–`1540`, `1590`, `6500`, `4400`, `6700`) and loans
(`2400`, `1600`, `6600`, `4300`) now have their own flows — see [Fixed assets](#fixed-assets--new) and [Loans](#loans--new).
There is no period close or bank reconciliation yet.

## `POST /api/v1/journal-entries` 🆕 NEW

Requires `journal-entries.create`. A **manual** entry — the way to book anything
no business flow posts.

```json
{
  "entry_date": "2026-10-01",
  "description": "Capital introduced",
  "ref": "CAP-1",
  "lines": [
    { "account_id": "01m...(1110 Cash)",   "debit": 5000 },
    { "account_id": "01m...(3100 Capital)", "credit": 5000 }
  ]
}
```

- at least two lines; each has **either** a `debit` **or** a `credit` (not both, not neither);
- the entry must **balance** exactly (**422** on `lines` otherwise);
- accounts must be active and **leaf accounts** — a group heading (an account with
  sub-accounts) is refused (**422** on `lines.N.account_id`);
- **201** — the entry, `type: "manual"`, already posted (account balances move at once); audit-logged.

## `POST /api/v1/journal-entries/{id}/reverse` 🆕 NEW

Requires `journal-entries.reverse`. Cancels a **manual** entry by posting its
mirror image (debits and credits swapped) dated `entry_date` (optional, default
today) and marking the original `reversed_at`. **201** — the reversal
(`type: "reversal"`, `reversal_of` = the original). **422** if the entry was made
by a business flow ("Only manual journal entries can be reversed...") or has
already been reversed. Another tenant's entry is **404**.

## `GET /api/v1/journal-entries`

Paginated, includes `lines_count`. 🔄 Each entry now has `type` (`system` / `manual` / `reversal`), `reversal_of` and `reversed_at`. Optional `?start_date=&end_date=` filter
on `entry_date`.

```json
{
  "data": [
    {
      "id": "01m...", "created_by": "01m...", "ref": "<invoice id>",
      "description": "Invoice for sales order ...", "entry_date": "2026-09-20", "posted": true,
      "lines_count": 3
    }
  ]
}
```
There's no `POST` here — entries only come from business events auto-posting
(invoice creation, collections, PO receiving).

---

## `GET /api/v1/journal-entries/{id}`

A single entry with `lines_count`. **404** — the entry belongs to another account.

## `GET /api/v1/journal-entries/{id}/lines`

Requires `journal-entries.view`. The entry's debit/credit lines as their own
list. Paginated like every list (**10 per page by default**, `?per_page=`, `?page=`, follow `links.next`); `search`, `filter[...]` and `sort_by` work as described in the README.

```json
{
  "data": [
    { "id": "01m...", "account": { "id": "01m...", "code": "1200", "...": "" }, "account_code": "1200", "account_name": "Accounts Receivable", "debit": "741.00", "credit": "0.00" },
    { "id": "01m...", "account": { "...": "" }, "account_code": "4100", "account_name": "Sales Revenue", "debit": "0.00", "credit": "650.00" }
  ],
  "links": { "...": "" }, "meta": { "...": "" }
}
```

The CSV/XLSX export of `GET /journal-entries` still flattens every line
into one column per entry.

## `GET /api/v1/reports/balance-sheet`

No parameters — **a current snapshot only**, not historical. `Account.balance`
is a running total updated in real time, not a point-in-time figure, so
there's no `as_of` date to request.

```json
{
  "data": {
    "as_of": "2026-09-20",
    "assets": { "accounts": [ { "account_id": "01m...", "code": "1200", "name": "Accounts Receivable", "balance": "741.00" } ], "total": "741.00" },
    "liabilities": { "accounts": [ "..." ], "total": "91.00" },
    "equity": { "accounts": [ "..." ], "current_period_earnings": "650.00", "total": "650.00" },
    "total_liabilities_and_equity": "741.00",
    "balanced": true
  }
}
```
🔄 `balanced` checks `assets == liabilities + equity`, where equity includes
**`current_period_earnings`** — revenue less expenses posted so far. Revenue and
expense accounts are not closed into Retained Earnings by any process (there is
no period close yet), so without that line the sheet could never balance; with
it, `balanced: false` now means a real problem in the books.

## `GET /api/v1/reports/income-statement`

**Requires** `?start_date=&end_date=` — this is a period report, not a
snapshot (`422` without both).

```json
{
  "data": {
    "start_date": "2026-09-01", "end_date": "2026-09-30",
    "revenue": { "accounts": [ { "account_id": "01m...", "code": "4100", "name": "Sales Revenue", "amount": "650.00" } ], "total": "650.00" },
    "expenses": { "accounts": [], "total": "0.00" },
    "net_income": "650.00"
  }
}
```
Sums `journal_lines` for Revenue/Expense accounts within the date range —
not the accounts' running balances, which would ignore the period entirely.

---

## `GET /api/v1/reports/ar-aging`

Lives in the **Sales** module (reads `Invoice`/`Customer` directly, not the
ledger) but the `Accountant` role holds its `ar-aging.view` permission too — see
[sales.md](./sales.md#get-apiv1reportsar-aging).

## `GET /api/v1/reports/ap-aging`

Lives in the **Purchasing** module for the same reason. **Not a true
date-bucketed aging report** — `purchase_orders` has no due date or payment
tracking, so unlike AR there's no per-transaction age to bucket by. This is
every supplier's current outstanding balance, sorted by amount owed. See
[purchasing.md](./purchasing.md#get-apiv1reportsap-aging).

```json
{
  "data": {
    "as_of": "2026-09-20",
    "suppliers": [ { "supplier_id": "01m...", "supplier_name": "ArabiVet Industries", "balance": "-2280.00" } ],
    "grand_total_owed": "2280.00"
  }
}
```

## Fixed assets 🆕 NEW

A register of what the company owns and writes off over time, in the style of Odoo's *Assets*: **asset models** (categories) that carry the accounts and depreciation settings, **assets** with a computed **depreciation board**, monthly posting, changes to the plan, pausing, and sale or scrapping with the gain or loss booked.

New accounts in the chart (run the `AccountSeeder` on an existing database — it is idempotent): `1530` Furniture & Office Equipment, `1540` Buildings & Improvements, **`1590` Accumulated Depreciation** (a credit balance against the asset), **`6500` Depreciation Expense**, **`4400` Gain on Disposal of Assets**, **`6700` Loss on Disposal of Assets**. `1510 Equipment` and `1520 Vehicles` are now fed by the register. They are system accounts (cannot be deleted or re-coded).

Permissions (all `fixed-assets.*` / `asset-categories.*`): the **Accountant** can create, activate, depreciate, modify, pause and cancel; **selling or scrapping an asset (`fixed-assets.dispose`) needs the approve level** (Owner, Administrator) because it moves the books; deleting needs the delete level (Administrator).

### Asset models — `/api/v1/asset-categories`

`GET` / `POST` / `GET {id}` / `PUT {id}` / `DELETE {id}` (`asset-categories.view|create|update|delete`). **409** while assets use the model.

```json
{ "name": "Lab equipment", "asset_account_code": "1510", "accumulated_account_code": "1590", "expense_account_code": "6500",
  "method": "straight_line", "duration_periods": 60, "period_months": 1, "declining_factor": 0.3,
  "prorata": false, "salvage_pct": 0, "auto_post": true, "active": true, "description": null }
```

- `method`: `straight_line` (the same each period), `declining_balance` (each period takes `declining_factor` of what is still to be depreciated; the last takes the rest), `declining_then_straight` (declining, but never less than spreading the remainder evenly over the periods left).
- `duration_periods` periods of `period_months` (1, 2, 3, 4, 6 or 12) months each — a 5-year asset booked monthly is 60 × 1. Lines are dated the last day of each period.
- `prorata`: the asset's life runs exactly `duration_periods` periods from the day it starts, so the first and last part-periods count only for the days they cover (a period with 16 of its 31 days weighs 16/31 of a full one) and the board has one more line.
- `salvage_pct`: the share of the cost that is not depreciated. `auto_post`: whether the daily command books this model's assets by itself.
- The three account codes must exist in the chart (**422**).

### `POST /api/v1/fixed-assets`

Requires `fixed-assets.create`.

```json
{ "name": "Centrifuge", "category_id": "01k…", "acquisition_date": "2026-01-01", "original_value": 12000,
  "salvage_value": 0, "duration_periods": 12, "method": "straight_line", "serial_number": "SN-9", "location": "Lab 1",
  "supplier_id": null, "assigned_user_id": null, "reference": "INV-4471" }
```

`name`, `category_id`, `acquisition_date` and `original_value` are required; everything else defaults from the model (`salvage_value` = `salvage_pct` of the cost; `depreciation_start_date` = the acquisition date). The asset is created as a **draft** with number `FA00001`… and its board computed. Returns the asset with `book_value`, `depreciable_value`, `depreciated_pct`, `next_depreciation` and `depreciations_count`.

### `POST /api/v1/fixed-assets/preview`

`fixed-assets.preview`. The board for figures typed in — same body as create (`category_id` and `name` optional) — without saving: `{ "data": [ {sequence, period_start, depreciation_date, amount, accumulated_after, book_value_after} … ], "summary": {periods, total} }`.

### Lifecycle

| Endpoint | Permission | What it does |
|---|---|---|
| `PUT /fixed-assets/{id}` | `fixed-assets.update` | a **draft** can change in every respect (the board is redrawn); a running asset only name, reference, serial number, location, assigned user, notes, supplier and `auto_post` (**422** otherwise) |
| `POST /fixed-assets/{id}/activate` | `fixed-assets.activate` | draft → `running`. Body `{ "acquisition_account_code": "1120" }` (optional) also **books the purchase**: Dr the asset account / Cr that account (bank, cash or payables). Leave it out when the cost was booked already |
| `POST /fixed-assets/{id}/depreciations/{line}/post` | `fixed-assets.post-depreciation` | books one period: **Dr 6500 Depreciation Expense / Cr 1590 Accumulated Depreciation**. Lines go in order and only when due (**422** otherwise); a paused asset books nothing |
| `POST /fixed-assets/depreciate` | `fixed-assets.depreciate` | `{ "date": "2026-03-31" }` (default today): books everything due for every running asset → `{ lines, assets, total, failed: [] }`. Safe to repeat — a line is only ever booked once |
| `POST /fixed-assets/{id}/modify` | `fixed-assets.modify` | redraws what is left: `{ "remaining_periods": 6, "salvage_value": 900, "method": "straight_line" }` spreads the current book value (less the new salvage value) over 6 periods after the last booked one. What was booked stays |
| `POST /fixed-assets/{id}/pause` · `/resume` | `fixed-assets.pause` · `.resume` | stops booking; `resume` (`{ "date": … }`) re-plans the unbooked periods from that month |
| `POST /fixed-assets/{id}/dispose` | `fixed-assets.dispose` | **sell or scrap**: `{ "date": "2026-04-30", "sale_value": 9500, "proceeds_account_code": "1110", "reason": "Upgraded" }`. Depreciation due up to the date is booked, later periods are dropped, then the asset leaves the books: Dr accumulated depreciation, Dr proceeds, Cr the asset's cost, and the difference with the book value is a **gain (Cr 4400)** or a **loss (Dr 6700)**. Status `sold` (with proceeds) or `disposed` |
| `POST /fixed-assets/{id}/cancel` | `fixed-assets.cancel` | a draft only |
| `DELETE /fixed-assets/{id}` | `fixed-assets.delete` | a draft or cancelled asset only |

Statuses: `draft`, `running`, `paused`, `fully_depreciated`, `sold`, `disposed`, `cancelled`.

### Reading

`GET /fixed-assets` (`?status=a,b`, `?category_id=`, `?assigned_user_id=`, `search` on number, name, serial, reference, location; `?export=csv|xlsx` needs `fixed-assets.export`), `GET /fixed-assets/{id}` (with the board), `GET /fixed-assets/{id}/schedule` (`fixed-assets.schedule`; `?status=planned|posted`; `summary` totals), `GET /fixed-assets/summary` (`fixed-assets.summary`: counts by status, cost, accumulated depreciation, book value, depreciation this year, lines due now, per category) and `GET /fixed-assets/forecast?months=12` (`fixed-assets.forecast`: planned depreciation per month).

### Scheduler 🆕

`php artisan assets:depreciate [--date=] [--tenant=] [--all]` runs daily at 02:30 and books what is due for assets whose model has `auto_post` (add `--all` to include the rest). The Accounting dashboard shows book value by category and the depreciation still to come.

## Loans 🆕 NEW

Money the company **borrowed** (`kind: borrowed`, a liability) or **lent** (`kind: granted`, an asset — a staff advance, a loan to a customer), with its repayment schedule — the amortization table — instalments paid one by one, early repayment, and reminders. Modelled on Odoo's loan management.

New accounts: **`2400` Loans Payable**, **`1600` Loans Receivable**, **`6600` Interest Expense**, **`4300` Interest Income** (system accounts).

### Methods

| `method` | Instalment |
|---|---|
| `annuity` | the same payment every period (interest falls, principal grows) — the usual bank loan; payment = P·r / (1 − (1+r)^−n) with r = annual rate × months in the period ÷ 12 |
| `fixed_principal` | the same principal every period, so the payment falls with the interest |
| `interest_only` | interest every period and the whole principal with the last instalment |

`grace_periods` at the start pay interest only (annuity and fixed principal). Interest for a period is the opening balance × the period rate. The last instalment always clears the balance to the cent. Example — 10 000 at 12 % over 12 months, annuity: payment **888.49**; first instalment 100.00 interest + 788.49 principal, balance 9 211.51.

### `POST /api/v1/loans`

Requires `loans.create`.

```json
{ "name": "Bank loan", "kind": "borrowed", "counterparty_name": "National Bank", "principal": 120000, "annual_rate_pct": 12,
  "method": "annuity", "periods": 24, "period_months": 1, "grace_periods": 0,
  "start_date": "2026-01-01", "first_due_date": "2026-02-01", "notes": null }
```

`name`, `principal`, `periods` and `start_date` are required; `first_due_date` defaults to one period after the start; `counterparty_type` (`supplier`, `customer`, `employee`, `other`) / `counterparty_id` / `counterparty_name` say who; `principal_account_code` and `interest_account_code` default by kind (`2400`/`6600` borrowed, `1600`/`4300` granted) and must exist. The loan starts as a **draft** `LN00001`… with its schedule.

### `POST /api/v1/loans/preview` (`loans.preview`)

The schedule for terms typed in, without saving: `{ "data": [ {sequence, due_date, opening_balance, principal_amount, interest_amount, payment_amount, closing_balance} … ], "summary": {instalments, total_interest, total_to_pay, first_payment} }`.

### Lifecycle

| Endpoint | Permission | What it does |
|---|---|---|
| `PUT /loans/{id}` | `loans.update` | a draft can change its terms (the schedule is redrawn); an active loan only name, counterparty and notes |
| `POST /loans/{id}/activate` | `loans.activate` | `{ "account_code": "1120", "date": … }`: the money changes hands. Borrowed: **Dr bank / Cr 2400**; granted: **Dr 1600 / Cr bank**. Status `active` |
| `POST /loans/{id}/installments/{n}/pay` | `loans.pay-installment` | pays instalment *n* (in order only): borrowed **Dr 2400 principal + Dr 6600 interest / Cr bank**; granted **Dr bank / Cr 1600 + Cr 4300**. Optional `payment_date`, `payment_account_code` (default the account the loan was activated with). The last one closes the loan |
| `POST /loans/{id}/early-repayment` | `loans.early-repay` (approve level) | repay ahead of schedule — below |
| `POST /loans/{id}/cancel` | `loans.cancel` | a draft only |
| `DELETE /loans/{id}` | `loans.delete` | a draft or cancelled loan only |

**Early repayment** — `{ "mode": "reduce_term" | "reduce_installment" | "payoff", "amount": 3000, "date": …, "payment_account_code": "1120" }`:
`reduce_term` keeps the instalment and the loan ends sooner; `reduce_installment` keeps the end date and lowers the instalments; `payoff` (no `amount`) repays the whole outstanding principal **plus the interest run up since the last paid instalment (or the start)** and closes the loan. A partial repayment books principal only (Dr 2400 / Cr bank) and redraws the unpaid instalments; the next instalment's interest is on the reduced balance.

### Reading and reminders

`GET /loans` (`?status=`, `?kind=`, `search`; export), `GET /loans/{id}` (counts, `next_installment` with `overdue`), `GET /loans/{id}/schedule` (`loans.schedule`; summary of total interest and what is left to pay), `GET /loans/summary` (`loans.summary`: per kind active loans, outstanding principal, interest paid; overdue and next-30-days instalments) and `GET /loans/due?days=30&kind=` (`loans.due`: instalments due soon or late).

`php artisan loans:remind` runs daily at 08:00: for an active loan with instalments due within 7 days or overdue it notifies whoever agreed the loan and everyone who may pay instalments, once a day, with a link to the loan.

## Printing

`GET /api/v1/invoices/{id}/print` and `GET /api/v1/collections/{id}/print` are available to the Accountant — see [Printing documents](./README.md#printing-documents--new).

