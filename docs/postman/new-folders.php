<?php

/**
 * Requests for the reporting line, notifications, calls, warehouse tools, printing, fixed assets, loans, recruitment,
 * fleet and dashboards. Required by generate.php (after its helpers exist); it returns the folders to merge into the
 * existing top-level folders by name, and the collection variables they use.
 *
 * @return array{folders: array<string, list<array<string, mixed>>>, variables: array<string, string>}
 */
$ym = date('Y-m');

$folders = [];

// --- Admin ---------------------------------------------------------------------------------------------------------
$folders['Admin'] = [
    nw(folder('Reporting line', [
        nw(req('GET', 'My team', '/team', ['description' => 'The signed-in user\'s own team: their manager and everyone below them with their `depth` (1 = direct report). Open to every signed-in user.'])),
        nw(req('GET', 'A user\'s team', '/users/{{user_id}}/team', ['description' => 'The same for any user. A team-scoped caller may only look at someone on their own team (403 otherwise).'])),
        nw(req('PUT', 'Set a user\'s manager and data scope', '/users/{{demo_user_id}}', [
            'description' => 'Makes the demo user report to the signed-in user and limits them to their own team\'s records (`data_scope: team`; `all` sees the whole company; `null` follows the role). A person cannot report to themselves or to someone on their own team (422).',
            'body' => ['manager_id' => '{{user_id}}', 'data_scope' => 'team'],
        ])),
        nw(req('GET', 'List a manager\'s direct reports', '/users', ['description' => 'Filter by `manager_id`.', 'query' => ['manager_id' => '{{user_id}}']])),
    ])),
    nw(folder('Notifications (changes)', [
        nw(req('GET', 'Unread notifications of one module', '/notifications', ['description' => 'Notifications about changed records carry `link` (`module`, `entity`, `id`, `url`) and `actor_id`.', 'query' => ['unread' => '1', 'module' => 'crm']])),
        nw(req('GET', 'Unread count', '/notifications/unread-count', ['description' => '`{ total, by_module }` for a badge.'])),
        nw(req('PUT', 'Mark one notification read', '/notifications/{{notification_id}}/read', ['description' => 'One of the caller\'s own notifications.'])),
    ])),
];

// --- CRM -----------------------------------------------------------------------------------------------------------
$folders['CRM'] = [
    nw(folder('Calls', [
        nw(req('POST', 'Log a call', '/calls', [
            'description' => 'Record a call with the customer and what was said. `rep_id` defaults to the caller; a manager may log it for someone on their team. The customer\'s owner and managers are notified.',
            'body' => ['customer_id' => '{{customer_id}}', 'message' => 'Asked for a quote on 20 cartons; will confirm Thursday.', 'subject' => 'Quote request', 'direction' => 'inbound', 'outcome' => 'answered', 'duration_seconds' => 240, 'next_follow_up' => date('Y-m-d', strtotime('+3 days'))],
            'tests' => saveId('call_id'),
        ])),
        nw(req('GET', 'List calls', '/calls', ['description' => 'A user sees the calls they logged, their team\'s, and those on customers they or their team own.', 'query' => ['search' => '', 'customer_id' => '', 'rep_id' => '', 'direction' => '', 'outcome' => '', 'from' => '', 'to' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get call', '/calls/{{call_id}}')),
        nw(req('GET', 'Customer\'s calls', '/customers/{{customer_id}}/calls')),
        nw(req('PUT', 'Update call', '/calls/{{call_id}}', ['body' => ['message' => 'Quote sent by e-mail.', 'outcome' => 'answered']])),
        ...deleteFlow('call', '/calls', ['customer_id' => '{{customer_id}}', 'message' => 'A throwaway call'], 'deletable_call_id', '/calls/{id}', 'Removes the call (audit-logged).'),
    ])),
    nw(folder('Customer ownership', [
        nw(req('GET', 'Check for a duplicate customer', '/customers/check-duplicate', ['description' => 'Call while typing: matches on phone (last 9 digits), e-mail, or name + city, and says whose customer it is.', 'query' => ['name' => 'Green Valley Vets', 'phone' => '0100 123 4567', 'email' => '', 'city' => 'Giza']])),
        nw(req('POST', 'Assign a customer to a user', '/customers/{{customer_id}}/assign', ['description' => 'A team-scoped caller can assign only to themselves or someone on their team; a user who sees the whole company to anyone, or `null` for no one.', 'body' => ['sales_rep_id' => '{{user_id}}']])),
    ])),
];

// --- Inventory -----------------------------------------------------------------------------------------------------
$folders['Inventory'] = [
    nw(folder('Warehouse tools', [
        nw(req('GET', 'Warehouse users', '/warehouses/{{warehouse_id}}/users', ['description' => 'Who works in the warehouse and who manages it.'])),
        nw(req('PUT', 'Assign people and a manager to a warehouse', '/warehouses/{{warehouse_id}}/users', ['description' => 'Replaces the people of the warehouse. `manager_id` names the manager (kept in the list); `null` removes them; leave it out to keep the current one. The manager sees the warehouse from now on.', 'body' => ['user_ids' => ['{{user_id}}'], 'manager_id' => '{{user_id}}']])),
        nw(req('GET', 'Warehouse overview', '/warehouses/{{warehouse_id}}/overview', ['description' => 'Stock, alerts, what is on its way in and out, the last 7 days and the latest movements.'])),
        nw(req('GET', 'Incoming (purchase orders on their way)', '/warehouses/{{warehouse_id}}/incoming', ['description' => 'Open purchase orders for this warehouse, soonest first, without prices.', 'query' => ['status' => '', 'purchase_order_id' => '', 'search' => '']])),
        nw(req('GET', 'Outgoing (sales orders due out)', '/warehouses/{{warehouse_id}}/outgoing', ['description' => 'Sales orders that will take stock out of this warehouse (`stage`: `to_prepare` / `to_deliver`), only this warehouse\'s lines, without prices.', 'query' => ['status' => '', 'sales_order_id' => '', 'search' => '']])),
        nw(req('GET', 'Warehouse stock movements', '/warehouses/{{warehouse_id}}/movements', ['description' => 'The ledger of everything that came in and went out, with a `summary` of the filtered set.', 'query' => ['product_id' => '', 'type' => '', 'direction' => '', 'batch_no' => '', 'from' => '', 'to' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Product stock movements', '/products/{{product_id}}/movements', ['description' => 'A product\'s ledger across the warehouses the caller may see.', 'query' => ['warehouse_id' => '', 'type' => '', 'from' => '', 'to' => '']])),
        nw(req('GET', 'Print transfer slip', '/transfers/{{transfer_id}}/print', ['description' => 'HTML page for A4 (or `?format=json`); no prices.', 'query' => ['format' => 'json']])),
    ])),
];

// --- Sales / Purchasing / Recap printing ---------------------------------------------------------------------------------
$folders['Sales'] = [
    nw(folder('Print documents', [
        nw(req('GET', 'Print invoice', '/invoices/{{invoice_id}}/print', ['description' => 'The invoice with its payments received, as a page for A4. `?format=json` returns the same content as data, `?dir=rtl` lays it out right to left.', 'query' => ['format' => 'json']])),
        nw(req('GET', 'Print sales order', '/sales-orders/{{sales_order_id}}/print', ['query' => ['format' => 'json']])),
        nw(req('GET', 'Print delivery note', '/deliveries/{{delivery_id}}/print', ['description' => 'Quantities and batches, no prices.', 'query' => ['format' => 'json']])),
        nw(req('GET', 'Print payment receipt', '/collections/{{collection_id}}/print', ['query' => ['format' => 'json']])),
    ])),
];
$folders['Purchasing'] = [
    nw(req('GET', 'Print purchase order', '/purchase-orders/{{purchase_order_id}}/print', ['description' => 'The purchase order as sent to the supplier (prices shown once priced).', 'query' => ['format' => 'json']])),
];
$folders['Recap'] = [
    nw(req('GET', 'Print write-off slip', '/recaps/{{recap_id}}/print', ['description' => 'Values are shown to the Administrator only.', 'query' => ['format' => 'json']])),
];

// --- Accounting ----------------------------------------------------------------------------------------------------
$folders['Accounting'] = [
    nw(folder('Asset models', [
        nw(req('POST', 'Create asset model', '/asset-categories', [
            'description' => 'How a kind of asset is booked and depreciated by default. Account codes must exist in the chart.',
            'body' => ['name' => 'Lab equipment '.date('His'), 'asset_account_code' => '1510', 'accumulated_account_code' => '1590', 'expense_account_code' => '6500', 'method' => 'straight_line', 'duration_periods' => 12, 'period_months' => 1, 'prorata' => false, 'salvage_pct' => 0],
            'tests' => saveId('asset_category_id'),
        ])),
        nw(req('GET', 'List asset models', '/asset-categories')),
        nw(req('GET', 'Get asset model', '/asset-categories/{{asset_category_id}}')),
        nw(req('PUT', 'Update asset model', '/asset-categories/{{asset_category_id}}', ['body' => ['duration_periods' => 24, 'declining_factor' => 0.3]])),
    ])),
    nw(folder('Fixed assets', [
        nw(req('POST', 'Preview a depreciation board', '/fixed-assets/preview', ['description' => 'The board for figures typed in, without saving.', 'body' => ['category_id' => '{{asset_category_id}}', 'acquisition_date' => '2026-01-01', 'original_value' => 12000, 'duration_periods' => 12, 'method' => 'straight_line', 'prorata' => false]])),
        nw(req('POST', 'Register an asset', '/fixed-assets', [
            'description' => 'Created as a draft with its depreciation board.',
            'body' => ['name' => 'Centrifuge', 'category_id' => '{{asset_category_id}}', 'acquisition_date' => '2026-01-01', 'original_value' => 12000, 'duration_periods' => 12, 'serial_number' => 'SN-9', 'location' => 'Lab 1'],
            'tests' => saveId('fixed_asset_id'),
        ])),
        nw(req('GET', 'List assets', '/fixed-assets', ['query' => ['status' => '', 'category_id' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get asset', '/fixed-assets/{{fixed_asset_id}}')),
        nw(req('GET', 'Depreciation board', '/fixed-assets/{{fixed_asset_id}}/schedule', ['query' => ['status' => ''], 'tests' => saveId('asset_line_id', 'data.0.id')])),
        nw(req('PUT', 'Update asset (draft)', '/fixed-assets/{{fixed_asset_id}}', ['body' => ['location' => 'Lab 2']])),
        nw(req('POST', 'Put the asset on the books', '/fixed-assets/{{fixed_asset_id}}/activate', ['description' => 'Draft to running. With `acquisition_account_code` the purchase is booked too (Dr asset account / Cr that account).', 'body' => ['acquisition_account_code' => '1120']])),
        nw(req('POST', 'Book one depreciation line', '/fixed-assets/{{fixed_asset_id}}/depreciations/{{asset_line_id}}/post', ['description' => 'Dr 6500 / Cr 1590. Lines go in order and only when due (422 otherwise).'])),
        nw(req('POST', 'Book all depreciation due', '/fixed-assets/depreciate', ['description' => 'Everything due on or before the date for every running asset of the company. Safe to repeat.', 'body' => ['date' => date('Y-m-d')]])),
        nw(req('POST', 'Modify the depreciation plan', '/fixed-assets/{{fixed_asset_id}}/modify', ['description' => 'Spreads the current book value (less the new salvage value) over the remaining periods.', 'body' => ['remaining_periods' => 6, 'salvage_value' => 0]])),
        nw(req('POST', 'Pause an asset', '/fixed-assets/{{fixed_asset_id}}/pause')),
        nw(req('POST', 'Resume an asset', '/fixed-assets/{{fixed_asset_id}}/resume', ['body' => ['date' => date('Y-m-d')]])),
        nw(req('GET', 'Assets summary', '/fixed-assets/summary')),
        nw(req('GET', 'Depreciation forecast', '/fixed-assets/forecast', ['query' => ['months' => '12']])),
        nw(req('POST', 'Sell or scrap an asset', '/fixed-assets/{{fixed_asset_id}}/dispose', ['description' => 'Approve-level permission. Books depreciation due to the date, drops later periods, clears cost and accumulated depreciation and books the gain (4400) or loss (6700).', 'body' => ['date' => date('Y-m-d'), 'sale_value' => 5000, 'proceeds_account_code' => '1110', 'reason' => 'Upgraded']])),
        ...deleteFlow('asset', '/fixed-assets', ['name' => 'Throwaway asset', 'category_id' => '{{asset_category_id}}', 'acquisition_date' => '2026-01-01', 'original_value' => 1000], 'deletable_asset_id', '/fixed-assets/{id}', 'Only a draft or cancelled asset can be deleted.'),
    ])),
    nw(folder('Loans', [
        nw(req('POST', 'Preview a repayment schedule', '/loans/preview', ['description' => 'The amortization table for terms typed in.', 'body' => ['principal' => 120000, 'annual_rate_pct' => 12, 'method' => 'annuity', 'periods' => 24, 'period_months' => 1, 'start_date' => '2026-01-01']])),
        nw(req('POST', 'Agree a loan', '/loans', [
            'description' => 'Created as a draft with its schedule. `kind`: `borrowed` (we owe) or `granted` (we lent).',
            'body' => ['name' => 'Bank loan', 'kind' => 'borrowed', 'counterparty_name' => 'National Bank', 'principal' => 120000, 'annual_rate_pct' => 12, 'method' => 'annuity', 'periods' => 24, 'period_months' => 1, 'start_date' => '2026-01-01'],
            'tests' => saveId('loan_id'),
        ])),
        nw(req('GET', 'List loans', '/loans', ['query' => ['status' => '', 'kind' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get loan', '/loans/{{loan_id}}')),
        nw(req('GET', 'Repayment schedule', '/loans/{{loan_id}}/schedule')),
        nw(req('PUT', 'Update loan', '/loans/{{loan_id}}', ['body' => ['notes' => 'Renegotiated rate pending']])),
        nw(req('POST', 'Activate a loan', '/loans/{{loan_id}}/activate', ['description' => 'The money changes hands: borrowed Dr bank / Cr 2400; granted Dr 1600 / Cr bank.', 'body' => ['account_code' => '1120']])),
        nw(req('POST', 'Pay an instalment', '/loans/{{loan_id}}/installments/{{installment_no}}/pay', ['description' => 'Instalments go in order. Borrowed: Dr 2400 + Dr 6600 / Cr bank.', 'body' => ['payment_date' => date('Y-m-d')]])),
        nw(req('POST', 'Repay early (part)', '/loans/{{loan_id}}/early-repayment', ['description' => 'Approve-level permission. `reduce_term` keeps the instalment, `reduce_installment` keeps the end date, `payoff` clears everything with the interest run up.', 'body' => ['mode' => 'reduce_term', 'amount' => 10000, 'payment_account_code' => '1120']])),
        nw(req('GET', 'Loans summary', '/loans/summary')),
        nw(req('GET', 'Instalments due', '/loans/due', ['query' => ['days' => '30', 'kind' => '']])),
        ...deleteFlow('loan', '/loans', ['name' => 'Throwaway loan', 'principal' => 1000, 'periods' => 4, 'start_date' => '2026-01-01'], 'deletable_loan_id', '/loans/{id}', 'Only a draft or cancelled loan can be deleted.'),
    ])),
];

// --- HR -----------------------------------------------------------------------------------------------------------------
$folders['HR'] = [
    nw(folder('Recruitment', [
        nw(req('POST', 'Create job position', '/job-positions', [
            'body' => ['name' => 'Lab Technician', 'vacancies' => 2, 'employment_type' => 'full_time', 'location' => 'Cairo', 'salary_min' => 7000, 'salary_max' => 10000, 'deadline' => date('Y-12-31')],
            'tests' => saveId('job_position_id'),
        ])),
        nw(req('GET', 'List job positions', '/job-positions', ['query' => ['status' => '', 'department_id' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get job position', '/job-positions/{{job_position_id}}')),
        nw(req('PUT', 'Update job position', '/job-positions/{{job_position_id}}', ['body' => ['status' => 'open', 'vacancies' => 3]])),
        nw(req('GET', 'Pipeline stages', '/recruitment-stages', ['description' => 'The standard six are created the first time.', 'tests' => [
            'if (pm.response.code === 200) {',
            '    const stages = pm.response.json().data;',
            '    const byName = (n) => stages.find(s => s.name === n);',
            "    if (byName('First Interview')) { pm.collectionVariables.set('stage_id_interview', byName('First Interview').id); }",
            '}',
        ]])),
        nw(req('POST', 'Create pipeline stage', '/recruitment-stages', ['body' => ['name' => 'Trial day '.date('His'), 'sequence' => 45], 'tests' => saveId('stage_id')])),
        nw(req('GET', 'Refusal reasons', '/refuse-reasons', ['tests' => saveId('refuse_reason_id', 'data.0.id')])),
        nw(req('POST', 'Add an applicant', '/applicants', [
            'description' => 'Enters the first stage; the recruiter defaults to the job\'s recruiter.',
            'body' => ['name' => 'Salma Hassan', 'email' => 'salma.'.date('His').'@example.com', 'phone' => '0100 555 1234', 'job_position_id' => '{{job_position_id}}', 'source' => 'LinkedIn', 'expected_salary' => 9000, 'priority' => 2],
            'tests' => saveId('applicant_id'),
        ])),
        nw(req('GET', 'List applicants', '/applicants', ['query' => ['job_position_id' => '', 'stage_id' => '', 'status' => '', 'mine' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get applicant', '/applicants/{{applicant_id}}')),
        nw(req('PUT', 'Update applicant', '/applicants/{{applicant_id}}', ['body' => ['priority' => 3, 'notes' => 'Strong CV']])),
        nw(req('POST', 'Move applicant to a stage', '/applicants/{{applicant_id}}/move', ['body' => ['stage_id' => '{{stage_id_interview}}']])),
        nw(req('POST', 'Add a note', '/applicants/{{applicant_id}}/notes', ['body' => ['text' => 'Called, will send portfolio']])),
        nw(req('GET', 'Applicant history', '/applicants/{{applicant_id}}/history')),
        nw(req('POST', 'Upload CV', '/applicants/{{applicant_id}}/resume', ['description' => 'multipart `file`: pdf, doc or docx up to 5 MB.', 'files' => ['file' => 'The applicant\'s CV']])),
        nw(req('GET', 'Download CV', '/applicants/{{applicant_id}}/resume')),
        nw(req('POST', 'Schedule an interview', '/interviews', [
            'body' => ['applicant_id' => '{{applicant_id}}', 'interviewer_id' => '{{user_id}}', 'scheduled_at' => date('Y-m-d\TH:i:s', strtotime('+2 days')), 'duration_minutes' => 45, 'mode' => 'video'],
            'tests' => saveId('interview_id'),
        ])),
        nw(req('GET', 'List interviews', '/interviews', ['query' => ['applicant_id' => '', 'status' => '', 'mine' => '', 'from' => '', 'to' => '']])),
        nw(req('POST', 'Record an interview result', '/interviews/{{interview_id}}/record-result', ['body' => ['result' => 'pass', 'score' => 8, 'feedback' => 'Strong technical answers']])),
        nw(req('POST', 'Make an offer', '/applicants/{{applicant_id}}/offer', ['body' => ['offer_status' => 'sent', 'proposed_salary' => 9500, 'offer_valid_until' => date('Y-m-d', strtotime('+14 days'))]])),
        nw(req('POST', 'Hire the applicant', '/applicants/{{applicant_id}}/hire', ['description' => 'Approve-level permission. Creates the employee (code generated unless given), marks the applicant hired, counts the hire on the job and closes it when the vacancies are filled.', 'body' => ['hire_date' => date('Y-m-d', strtotime('+30 days'))]])),
        nw(req('POST', 'Refuse an applicant', '/applicants/{{refusable_applicant_id}}/refuse', ['body' => ['refuse_reason_id' => '{{refuse_reason_id}}', 'note' => 'Wants more']])),
        nw(req('POST', 'Restore an applicant', '/applicants/{{refusable_applicant_id}}/restore')),
        nw(req('GET', 'Recruitment pipeline (board)', '/recruitment/pipeline', ['query' => ['job_position_id' => '', 'limit' => '20']])),
        nw(req('GET', 'Recruitment analysis', '/recruitment/analysis', ['query' => ['period' => 'month']])),
    ])),
    nw(folder('Fleet', [
        nw(req('POST', 'Register a vehicle', '/vehicles', [
            'body' => ['license_plate' => 'PM-'.date('His'), 'brand' => 'Toyota', 'model' => 'Hilux', 'model_year' => 2024, 'vehicle_type' => 'truck', 'fuel_type' => 'diesel', 'odometer' => 10000, 'acquisition_date' => '2026-01-10'],
            'tests' => saveId('vehicle_id'),
        ])),
        nw(req('GET', 'List vehicles', '/vehicles', ['query' => ['status' => '', 'fuel_type' => '', 'driver_employee_id' => '', 'search' => '', 'export' => 'csv']])),
        nw(req('GET', 'Get vehicle', '/vehicles/{{vehicle_id}}')),
        nw(req('PUT', 'Update vehicle', '/vehicles/{{vehicle_id}}', ['body' => ['color' => 'White', 'location' => 'Main Depot']])),
        nw(req('POST', 'Assign a driver', '/vehicles/{{vehicle_id}}/assign-driver', ['description' => '`employee_id: null` takes the vehicle away. The previous driver\'s turn ends the day before.', 'body' => ['employee_id' => '{{employee_id}}', 'date' => date('Y-m-d')]])),
        nw(req('GET', 'Driver history', '/vehicles/{{vehicle_id}}/assignments')),
        nw(req('POST', 'Log an odometer reading', '/vehicle-odometer-logs', ['description' => 'Must fit between the readings before and after it.', 'body' => ['vehicle_id' => '{{vehicle_id}}', 'value' => 10500, 'logged_on' => date('Y-m-d')]])),
        nw(req('GET', 'List odometer readings', '/vehicle-odometer-logs', ['query' => ['vehicle_id' => '{{vehicle_id}}', 'from' => '', 'to' => '']])),
        nw(req('POST', 'Log a refuelling', '/vehicle-fuel-logs', ['description' => 'Give the litres and either the price per litre or the amount; the other is worked out.', 'body' => ['vehicle_id' => '{{vehicle_id}}', 'fueled_on' => date('Y-m-d'), 'liters' => 40, 'price_per_liter' => 12.5, 'odometer' => 10600], 'tests' => saveId('fuel_log_id')])),
        nw(req('GET', 'List fuel logs', '/vehicle-fuel-logs', ['query' => ['vehicle_id' => '', 'from' => '', 'to' => '', 'export' => 'csv']])),
        nw(req('PUT', 'Update fuel log', '/vehicle-fuel-logs/{{fuel_log_id}}', ['body' => ['liters' => 45]])),
        nw(req('GET', 'Service types', '/fleet-service-types', ['description' => 'Oil change, Tyres, Brakes, Battery, General inspection and Repair are created the first time.', 'tests' => saveId('service_type_id', 'data.3.id')])),
        nw(req('POST', 'Plan a service', '/vehicle-services', ['body' => ['vehicle_id' => '{{vehicle_id}}', 'service_type_id' => '{{service_type_id}}', 'service_date' => date('Y-m-d', strtotime('+5 days')), 'description' => '10k service'], 'tests' => saveId('vehicle_service_id')])),
        nw(req('POST', 'Mark a service done', '/vehicle-services/{{vehicle_service_id}}/mark-done', ['body' => ['cost' => 850, 'odometer' => 10700, 'next_due_date' => date('Y-m-d', strtotime('+6 months')), 'next_due_odometer' => 15700]])),
        nw(req('GET', 'List services', '/vehicle-services', ['query' => ['vehicle_id' => '', 'status' => '', 'due' => '', 'from' => '', 'to' => '', 'export' => 'csv']])),
        nw(req('POST', 'Add a contract', '/vehicle-contracts', ['body' => ['vehicle_id' => '{{vehicle_id}}', 'type' => 'insurance', 'vendor_name' => 'Misr Insurance', 'starts_on' => date('Y-m-d'), 'expires_on' => date('Y-m-d', strtotime('+1 year')), 'cost' => 3650, 'frequency' => 'yearly', 'reminder_days' => 30], 'tests' => saveId('vehicle_contract_id')])),
        nw(req('GET', 'List contracts', '/vehicle-contracts', ['query' => ['vehicle_id' => '', 'type' => '', 'expiring_within' => '30', 'export' => 'csv']])),
        nw(req('POST', 'Close a contract', '/vehicle-contracts/{{vehicle_contract_id}}/close')),
        nw(req('GET', 'Vehicle costs', '/vehicles/{{vehicle_id}}/costs', ['description' => 'Fuel, services and the share of each contract that falls in the period; distance, cost per kilometre and kilometres per litre; per month.', 'query' => ['period' => 'year']])),
        nw(req('GET', 'Fleet analysis', '/fleet/analysis', ['query' => ['period' => 'month']])),
    ])),
];

// --- Dashboards ----------------------------------------------------------------------------------------------------
$dashboardRequests = [nw(req('GET', 'List my dashboards', '/dashboards', ['description' => 'The dashboards the caller may open, with the features each offers.']))];

foreach (['sales' => 'Sales', 'crm' => 'CRM', 'inventory' => 'Inventory', 'purchasing' => 'Purchasing', 'accounting' => 'Accounting', 'expenses' => 'Expenses', 'hr' => 'HR', 'manufacturing' => 'Manufacturing', 'recap' => 'Recap'] as $module => $title) {
    $dashboardRequests[] = nw(req('GET', "{$title} dashboard", "/dashboards/{$module}", ['description' => 'Figures for a period, limited to what the caller may see. `?features=` builds only some features.', 'query' => ['period' => 'month', 'from' => '', 'to' => '', 'features' => '']]));
}

$folders['Dashboards'] = $dashboardRequests;

return [
    'folders' => $folders,
    'variables' => ['installment_no' => '1'] + array_fill_keys([
        'call_id', 'deletable_call_id', 'asset_category_id', 'fixed_asset_id', 'asset_line_id', 'deletable_asset_id', 'loan_id', 'deletable_loan_id',
        'job_position_id', 'stage_id', 'stage_id_interview', 'refuse_reason_id', 'applicant_id', 'refusable_applicant_id', 'interview_id',
        'vehicle_id', 'fuel_log_id', 'service_type_id', 'vehicle_service_id', 'vehicle_contract_id',
    ], ''),
];
