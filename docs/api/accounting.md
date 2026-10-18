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
Inventory, 🆕 `1400` VAT Receivable (input VAT), `2100` Accounts Payable, 🆕 `2200`
Salaries Payable, 🆕 `2210` Payroll Taxes & Insurance Payable, `2300` VAT
Payable, 🆕 `3300` Opening Balance Equity, `4100` Sales Revenue, 🆕 `5100` Cost
of Goods Sold, 🆕 `6100` Salaries Expense, `6300` Inventory Write-off Expense
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
  { "code": "1510", "name": "Equipment", "kind": "manual", "used_by": ["Manual journal entries (no fixed-asset register yet)"], "posting_lines": 0, "attention": null },
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

Still manual by design (no flow feeds them): `1510`/`1520` fixed assets, `3100`
capital, `3200` retained earnings (year-end closing), `4200` other income — book
them with a manual journal entry. There is no fixed-asset register, depreciation
run, period close or bank reconciliation yet.

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
