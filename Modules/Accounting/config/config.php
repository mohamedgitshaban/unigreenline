<?php

/**
 * How each account in the seeded chart is fed. Auto-posting looks accounts up
 * by code, so this is the map of "what writes to what" — served by
 * GET /chart-of-accounts/connections so an accountant can see at a glance
 * which accounts are wired to a business flow, which are group headings, and
 * which only move through manual journal entries. A test fails if a seeded
 * account is missing from this list or a posting refers to an unseeded code.
 *
 * kind: header = a grouping line nothing posts to; posted = fed by the flows
 * listed; manual = no automatic flow, use POST /journal-entries.
 */
return [
    'name' => 'Accounting',

    'connections' => [
        '1000' => ['kind' => 'header', 'used_by' => []],
        '1100' => ['kind' => 'header', 'used_by' => []],
        '1110' => ['kind' => 'posted', 'used_by' => ['Collections paid in cash', 'Expense payments (cash)', 'Supplier payments (cash)', 'Payroll payments (cash)', 'Invoice import (amount already paid)']],
        '1120' => ['kind' => 'posted', 'used_by' => ['Collections by bank / other methods', 'Expense payments (bank)', 'Supplier payments (bank)', 'Payroll payments (bank)']],
        '1200' => ['kind' => 'posted', 'used_by' => ['Sales invoices', 'Invoice import', 'Collections', 'Invoice void', 'Sales returns (credit to the customer)']],
        '1300' => ['kind' => 'posted', 'used_by' => ['Purchase receipts', 'Manual goods receipts', 'Cost of goods sold on delivery', 'Stock write-offs (Recap)', 'Manufacturing', 'Purchase returns', 'Sales returns put back in stock']],
        '1350' => ['kind' => 'posted', 'used_by' => ['Manufacturing (materials and conversion cost in progress, when WIP accounting is on)']],
        '1400' => ['kind' => 'posted', 'used_by' => ['Purchase receipts (input VAT)']],
        '1500' => ['kind' => 'header', 'used_by' => []],
        '1510' => ['kind' => 'manual', 'used_by' => ['Manual journal entries (no fixed-asset register yet)']],
        '1520' => ['kind' => 'manual', 'used_by' => ['Manual journal entries (no fixed-asset register yet)']],
        '2000' => ['kind' => 'header', 'used_by' => []],
        '2100' => ['kind' => 'posted', 'used_by' => ['Purchase receipts', 'Supplier payments', 'Purchase returns']],
        '2200' => ['kind' => 'posted', 'used_by' => ['Payroll approval (net pay owed)', 'Payroll payment']],
        '2210' => ['kind' => 'posted', 'used_by' => ['Payroll approval (insurance and tax withheld)']],
        '2300' => ['kind' => 'posted', 'used_by' => ['Sales invoices (output VAT)', 'Invoice import', 'Invoice void']],
        '3000' => ['kind' => 'header', 'used_by' => []],
        '3100' => ['kind' => 'manual', 'used_by' => ['Manual journal entries (capital contributions)']],
        '3200' => ['kind' => 'manual', 'used_by' => ['Manual journal entries (year-end closing)']],
        '3300' => ['kind' => 'posted', 'used_by' => ['Manual goods receipts (opening stock)']],
        '4000' => ['kind' => 'header', 'used_by' => []],
        '4100' => ['kind' => 'posted', 'used_by' => ['Sales invoices', 'Invoice import', 'Invoice void', 'Sales returns']],
        '4200' => ['kind' => 'manual', 'used_by' => ['Manual journal entries (other income)']],
        '5000' => ['kind' => 'header', 'used_by' => []],
        '5100' => ['kind' => 'posted', 'used_by' => ['Cost of goods sold on delivery', 'Sales returns put back in stock']],
        '5200' => ['kind' => 'posted', 'used_by' => ['Manufacturing (cost not carried into finished goods, written off at completion)']],
        '6000' => ['kind' => 'header', 'used_by' => []],
        '6100' => ['kind' => 'posted', 'used_by' => ['Payroll approval (salaries expense)']],
        '6200' => ['kind' => 'posted', 'used_by' => ['Expenses, through their categories']],
        '6300' => ['kind' => 'posted', 'used_by' => ['Stock write-offs (Recap)', 'Manufacturing component scrap']],
        '6400' => ['kind' => 'posted', 'used_by' => ['Manufacturing (labour, machine and overhead absorbed)']],
    ],
];
