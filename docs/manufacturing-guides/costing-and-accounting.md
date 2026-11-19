# Costing and accounting

Manufacturing uses the existing chart of accounts and `JournalPostingService`; there is no separate ledger. Every entry has
`ref` = the order's id, so everything an order has booked can be found together (`GET /production-orders/{id}/costs` lists it).

## Accounts

| Code | Name | Used for |
|---|---|---|
| `1300` | Inventory | raw materials and finished goods (existing) |
| `1350` | Work in Progress 🆕 | materials and conversion cost of orders under way, **only when WIP accounting is on** |
| `5200` | Manufacturing Variance 🆕 | cost that did not reach finished goods, written off when an order closes |
| `6300` | Inventory Write-off Expense (existing) | material scrapped in production and charged to the period |
| `6400` | Manufacturing Overhead Absorbed (existing) | labour, machine and overhead applied to production |

Run `AccountSeeder` after upgrading so `1350` and `5200` exist; a flow that needs a missing account stops with an error instead
of losing the entry.

## Cost components

| Component | Planned (estimate, fixed at confirmation) | Actual (builds up as work is recorded) |
|---|---|---|
| **Material** | Σ exact component need (scrap allowance included) × the component's *standard cost* (`pack cost × carton qty`) | value of the batches actually issued, at each batch's own cost, **plus** material scrap the product carries |
| **Operation** (labour + machine) | Σ operation minutes ÷ 60 × (work center machine rate + labour rate). Operation minutes = setup + run × cartons ÷ efficiency + cleanup | per completed work order: logged minutes ÷ 60 × the rates |
| **Overhead** | the order's conversion cost + the company's overhead rate × planned material | conversion cost × output made so far ÷ planned output + overhead rate × actual material |
| **Scrap** | – | reported separately; either expensed (6300) or carried by the product, per setting |

`conversion_cost` is the recipe's per-run amount (default `bom.conversion_cost × quantity ÷ recipe output`), editable on a draft.

## Valuing a receipt

What is "in progress" for an order is everything incurred so far less what earlier receipts already carried to stock:

```text
pool   = material + operation + overhead(after this receipt) − already capitalized
share  = 1                          if this receipt finishes the order (or leaves nothing more to make)
       = (good + rejected) ÷ (basis − good before − rejected before)   otherwise
basis  = max(output so far including this receipt, planned × material issued ÷ material required)
value  = pool × share      # all of it goes to the good cartons: they absorb the cost of rejected ones
```

* **Backflush** (the default) issues materials in step with output, so each receipt takes everything incurred since the last.
* If materials were issued **ahead** (manual consumption), `basis` is larger and the first receipt takes only its proportional share.
* Whole-carton issuing means the running shares drift by at most a carton of material and settle at the last receipt.
* Rejected cartons are never received; their cost is absorbed by the good ones. A rejection is also recorded as a (finished) scrap document for traceability.
* The unit cost of the batch created is `value ÷ good cartons`, rounded to cents.

### By-products

By-products come from the recipe revision the order was confirmed with, in proportion to good output (whole cartons). They take
a share of the receipt's value:

| `by_product_cost_method` | Share |
|---|---|
| `fixed_pct` (default) | the recipe's `cost_share_pct`, scaled to the slice of the expected by-product received now |
| `sales_value` | by selling value: by-product cartons × its selling price ÷ (all by-products + finished cartons × their selling prices) |
| `none` | the finished goods carry everything; by-products arrive at zero cost |

A by-product receipt is never duplicated: the quantity received is compared with the target for the output so far.

## Postings

Two policies, chosen by the company setting `wip_accounting` and **fixed on each order when it is confirmed** (changing the
setting does not move orders already under way).

### Cost on receipt (default)

Nothing is booked while the order is under way. When finished goods are received, one entry moves their cost:

```text
Dr 1300 Inventory                  value  (finished goods and by-products)
   Cr 1300 Inventory               material portion      } materials leave raw stock
   Cr 6400 Overhead Absorbed       value − material      } labour, machine and overhead applied
```

This is the entry the old single-step completion made, so existing behaviour and balances are unchanged.

### Work in progress

```text
Materials issued          Dr 1350 WIP                       Cr 1300 Inventory
Operation completed       Dr 1350 WIP                       Cr 6400 Overhead Absorbed   (labour + machine time)
Receipt                   Dr 1350 WIP  / Cr 6400            overhead applied by this receipt
                          Dr 1300 Inventory                 Cr 1350 WIP                 (value carried to stock)
```

The balance of `1350` is the total of what is in progress on open orders (`GET /reports/manufacturing/wip-valuation` shows it per
order beside the ledger balance to reconcile with).

### Scrap

| Policy | Cost-on-receipt orders | WIP orders |
|---|---|---|
| `expense` (default) | `Dr 6300 / Cr 1300` when recorded | same |
| `absorb` | nothing booked now; the value is part of the material in progress and reaches stock with the next receipt | `Dr 1350 / Cr 1300` when recorded |

Scrap that needs approval books nothing until it is approved. A rejected scrap request never touches stock or ledger.

### Closing the order (variance)

What the finished goods did not carry is written off when the order completes:

* with WIP accounting: everything still in progress — `Dr 5200 / Cr 1350` (or the reverse for a gain);
* otherwise: only the material that left stock without reaching finished goods — `Dr 5200 / Cr 1300`. Labour, machine and overhead
  were never booked in this policy until capitalized, so there is nothing to reverse.

Typical causes: materials issued ahead of an order that is closed short, or an order closed short with the rest not backordered.
The amount is stored on the order (`variance_cost`) and its entry linked (`variance_journal_entry_id`).

## Duplicate posting and correction

* Each receipt, issue, scrap and completion is one database transaction: stock, order totals and the ledger entry succeed or
  fail together (a failed receipt leaves no stock, no consumption, no entry).
* A request carrying an **idempotency key** (`Idempotency-Key` header or `idempotency_key` field) is processed once; a retry returns the first
  outcome (`replayed: true`) without posting again.
* Without a key, repeating a request cannot over-post because the order's quantities cap it (you cannot receive more than is
  left, issue more than is required, approve a scrap twice or complete a completed order).
* Posted entries are never edited. An order that has issued material or received output **cannot be cancelled** — close it with what was
  made and scrap the rest, so stock and ledger stay true. Mistakes in the ledger are corrected with a manual journal entry and its reversal
  (`POST /journal-entries`, `/reverse`).
* Changing a product's standard cost or a recipe never alters amounts already posted: actual costs come from the batches and records of the order, and the
  estimate is fixed when the order is confirmed.

## Reading the numbers

`GET /production-orders/{id}/costs` returns, for the order:

```json
{ "planned": {"material": 110, "operation": 0, "overhead": 100, "total": 210},
  "actual":  {"material": 110, "operation": 0, "overhead": 100, "total": 210},
  "variance": {"material": 0, "operation": 0, "overhead": 0, "total": 0},
  "scrap_cost": 0, "capitalized": {"total": 210, "material": 110}, "variance_written_off": 0,
  "good_qty_cartons": 10, "unit_cost": 21, "planned_unit_cost": 21, "wip_accounting": false,
  "journal_entries": [ ... ] }
```

Planned figures are scaled to the output made when the order is completed short, so a half-finished order is not shown as a saving.
Costs are shown only to users who hold `production-orders.costs` (or `manufacturing-cost-reports.view` for the reports).
