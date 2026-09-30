# Accounting

Requires `accounting.view` for everything below. `Accountant` has this;
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

## `GET /api/v1/journal-entries`

Paginated, includes nested `lines`. Optional `?start_date=&end_date=` filter
on `entry_date`.

```json
{
  "data": [
    {
      "id": "01m...", "created_by": "01m...", "ref": "<invoice id>",
      "description": "Invoice for sales order ...", "entry_date": "2026-09-20", "posted": true,
      "lines": [
        { "id": "01m...", "account_id": "01m...", "account_code": "1200", "account_name": "Accounts Receivable", "debit": "741.00", "credit": "0.00" },
        { "id": "01m...", "account_id": "01m...", "account_code": "4100", "account_name": "Sales Revenue", "debit": "0.00", "credit": "650.00" },
        { "id": "01m...", "account_id": "01m...", "account_code": "2300", "account_name": "VAT Payable", "debit": "0.00", "credit": "91.00" }
      ]
    }
  ]
}
```
There's no `POST` here — entries only come from business events auto-posting
(invoice creation, collections, PO receiving).

---

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
    "equity": { "accounts": [ "..." ], "total": "0.00" },
    "total_liabilities_and_equity": "91.00",
    "balanced": false
  }
}
```
`balanced` checks `assets == liabilities + equity`. It's normal for this to
read `false` in this build: there's no period-close process that sweeps net
income (Revenue − Expenses) into Retained Earnings, so undistributed profit
sits in Revenue/Expense accounts rather than Equity, and the equation won't
balance until a close-out entry exists (not built).

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
ledger) but is reachable by `accounting.view` too — see
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
