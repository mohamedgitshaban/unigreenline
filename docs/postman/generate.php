<?php

/**
 * Generates docs/postman/VetPharma-ERP.postman_collection.json from the
 * documented request/response shapes in docs/api/*.md. Not part of the
 * application — run manually (`php docs/postman/generate.php`) whenever an
 * endpoint's contract changes, then delete this script's output is the
 * deliverable, this file is just how it's built.
 */
function url(string $path, array $query = [], array $requiredQuery = []): array
{
    $segments = array_values(array_filter(explode('/', $path)));
    $raw = '{{base_url}}'.$path;

    $urlObj = [
        'raw' => $raw.($query === [] ? '' : ('?'.http_build_query($query))),
        'host' => ['{{base_url}}'],
        'path' => $segments,
    ];

    if ($query !== []) {
        $urlObj['query'] = array_map(
            fn ($k, $v) => ['key' => $k, 'value' => (string) $v, 'disabled' => ! in_array($k, $requiredQuery, true)],
            array_keys($query),
            $query
        );
    }

    return $urlObj;
}

function req(string $method, string $name, string $path, array $opts = []): array
{
    $request = [
        'method' => $method,
        'header' => [],
        'url' => url($path, $opts['query'] ?? [], $opts['requiredQuery'] ?? []),
    ];

    if (isset($opts['description'])) {
        $request['description'] = $opts['description'];
    }

    if (isset($opts['files'])) {
        // Any request carrying a file goes out as multipart/form-data; no
        // explicit Content-Type header, since Postman must add the boundary
        // itself. PHP only parses multipart bodies on POST, so PUT/PATCH are
        // sent as POST with Laravel's `_method` spoofing field.
        $fields = $opts['body'] ?? [];
        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $fields = ['_method' => $method] + $fields;
            $request['method'] = 'POST';
        }

        $request['body'] = ['mode' => 'formdata', 'formdata' => formFields($fields, $opts['files'])];
    } elseif (isset($opts['body'])) {
        $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($opts['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if (! empty($opts['noauth'])) {
        $request['auth'] = ['type' => 'noauth'];
    }

    $item = ['name' => $name, 'request' => $request];

    if (isset($opts['tests'])) {
        $item['event'] = [[
            'listen' => 'test',
            'script' => ['type' => 'text/javascript', 'exec' => $opts['tests']],
        ]];
    }

    return $item;
}

/**
 * Postman form-data entries: text fields flattened to bracket notation
 * (`lines[0][qty]`, which Laravel parses back into arrays), booleans as
 * 1/0, then one file entry per `$files` key (field => description).
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, string>  $files
 * @return list<array{key: string, type: string, value?: string, src?: array<never>, description?: string}>
 */
function formFields(array $fields, array $files, string $prefix = ''): array
{
    $entries = [];

    foreach ($fields as $key => $value) {
        $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

        if (is_array($value)) {
            array_push($entries, ...formFields($value, [], $name));
        } else {
            $entries[] = ['key' => $name, 'value' => is_bool($value) ? (string) (int) $value : (string) $value, 'type' => 'text'];
        }
    }

    foreach ($files as $key => $description) {
        $entries[] = ['key' => $key, 'type' => 'file', 'src' => [], 'description' => $description];
    }

    return $entries;
}

function folder(string $name, array $items, ?string $description = null): array
{
    $folder = ['name' => $name, 'item' => $items];
    if ($description !== null) {
        $folder['description'] = $description;
    }

    return $folder;
}

/** Standard "save this id for later requests" test script. */
function saveId(string $varName, string $jsonPath = 'data.id'): array
{
    return [
        'if (pm.response.code >= 200 && pm.response.code < 300) {',
        '    const json = pm.response.json();',
        '    const value = '.jsonPathJs($jsonPath).';',
        "    if (value) { pm.collectionVariables.set('{$varName}', value); }",
        '}',
    ];
}

function jsonPathJs(string $path): string
{
    $parts = explode('.', $path);
    $expr = 'json';
    foreach ($parts as $part) {
        $expr .= is_numeric($part) ? "[{$part}]" : "?.{$part}";
    }

    return $expr;
}

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------

$auth = folder('Auth', [
    [
        'name' => 'Health check',
        'request' => [
            'method' => 'GET',
            'header' => [],
            'url' => [
                'raw' => '{{root_url}}/api/health',
                'host' => ['{{root_url}}'],
                'path' => ['api', 'health'],
            ],
            'description' => 'No auth required. Note: this one endpoint is unversioned (`/api/health`, not `/api/v1/health`) — it uses `{{root_url}}` directly rather than `{{base_url}}`.',
            'auth' => ['type' => 'noauth'],
        ],
    ],
    req('POST', 'Login', '/auth/login', [
        'description' => "No auth. Rate-limited: 5 attempts/minute per email+IP, then 429.\n\nSaves the returned token to the `token` collection variable automatically — every other request in this collection uses `{{token}}` via Bearer auth, so just run this first.",
        'noauth' => true,
        'body' => ['email' => '{{seed_email}}', 'password' => '{{seed_password}}'],
        'tests' => [
            'if (pm.response.code === 200) {',
            '    const json = pm.response.json();',
            "    pm.collectionVariables.set('token', json.token);",
            "    pm.collectionVariables.set('user_id', json.user.id);",
            '}',
        ],
    ]),
    req('GET', 'Me', '/auth/me', [
        'description' => 'Current authenticated user, same shape as the `user` object from login.',
    ]),
    req('POST', 'Change password', '/auth/change-password', [
        'description' => "Requires the caller's own current password. The old token keeps working afterward — not auto-revoked.\n\nSets the new password to the same value as the current one (`{{seed_password}}` both ways — that's allowed, there's no distinctness rule) so this request stays safely re-runnable without invalidating `seed_email`'s real login for the next run. Change the body to a genuinely different password if you want to test that case specifically.",
        'body' => ['current_password' => '{{seed_password}}', 'password' => '{{seed_password}}', 'password_confirmation' => '{{seed_password}}'],
    ]),
], 'Spec §6. Bearer token via Sanctum. Run **Login** first — it captures `{{token}}` automatically for every other folder. **Logout lives at the very end of this collection, not here** — running it this early would revoke the token every other folder needs.');

// ---------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------

$reference = folder('Reference', [
    req('GET', 'List governorates', '/governorates', [
        'description' => 'Any authenticated user. All 27 Egyptian governorates as `{ slug, name }`, sorted by name — use `slug` for the cities request, show `name`.',
    ]),
    req('GET', 'List cities of a governorate', '/governorates/cairo/cities', [
        'description' => 'Any authenticated user. Replace `cairo` with a `slug` from List governorates (e.g. `kafr-el-sheikh`). Returns a sorted array of city names; an unknown slug is 404.',
    ]),
], 'Static lookup lists for address dropdowns (governorate → city). Backed by Modules/Core/config/cities.php, not a database table.');

// ---------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------

$inventory = folder('Inventory', [
    folder('Warehouses', [
        req('GET', 'List warehouses', '/warehouses', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => "Requires `inventory.view`. Scoped to the caller's assigned warehouses (`user_warehouses`) unless they hold Owner/Auditor/Administrator.",
        ]),
        req('GET', 'Get warehouse', '/warehouses/{{warehouse_id}}', [
            'description' => 'Includes nested `batches`. 403 if this warehouse is not assigned to the caller.',
        ]),
        req('POST', 'Create warehouse', '/warehouses', [
            'description' => "Requires `inventory.add`. Only name/city/governorate are required — `manager_name` is derived server-side from `manager_id`, don't send it.",
            'body' => ['name' => 'Main Warehouse', 'city' => 'Cairo', 'governorate' => 'Cairo', 'address' => null, 'manager_id' => null, 'temperature' => 'ambient', 'capacity' => 10000, 'phone' => null, 'status' => 'active'],
            'tests' => saveId('warehouse_id'),
        ]),
        req('POST', 'Create warehouse (secondary, for transfers)', '/warehouses', [
            'description' => "Same as above — kept separate so `{{warehouse_id_2}}` is a distinct real warehouse for the Transfers request below (a transfer's from/to warehouse must differ).",
            'body' => ['name' => 'Alexandria Branch', 'city' => 'Alexandria', 'governorate' => 'Alexandria', 'temperature' => 'ambient', 'capacity' => 5000, 'status' => 'active'],
            'tests' => saveId('warehouse_id_2'),
        ]),
        req('PUT', 'Update warehouse', '/warehouses/{{warehouse_id}}', [
            'description' => 'Requires `inventory.edit` + visibility. All fields optional.',
            'body' => ['status' => 'active'],
        ]),
    ]),
    folder('Categories', [
        req('GET', 'List categories', '/categories', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `inventory.view`.',
        ]),
        req('POST', 'Create category', '/categories', [
            'description' => 'Requires `inventory.add`. Only `name` required, unique per tenant.',
            // Not one of the 6 seeded category names (spec §7) — a real
            // name collision here is a 422 (unique per tenant).
            'body' => ['name' => 'Postman Demo Category', 'code' => null, 'description' => null, 'active' => true],
            'tests' => saveId('category_id'),
        ]),
        req('PUT', 'Update category', '/categories/{{category_id}}', [
            'description' => "Requires `inventory.edit`, and the category must belong to the caller's tenant (403 otherwise). Partial update — send only the fields to change. `name` stays unique per tenant, ignoring the category itself.",
            'body' => ['code' => 'PM-DEMO', 'description' => 'Updated from Postman'],
        ]),
    ]),
    folder('Products', [
        req('GET', 'List products', '/products', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `inventory.view`. Catalog-wide, not warehouse-scoped.',
        ]),
        req('GET', 'Get product', '/products/{{product_id}}', [
            'description' => 'Includes nested `batches`.',
        ]),
        req('POST', 'Create product', '/products', [
            'description' => "Requires `inventory.add`. `sku` must be unique. `cost_price`/`selling_price` are computed server-side (pack price × carton_qty) and returned always in sync — don't send them.",
            // SKU is not one of the 6 seeded products' (PRD-00001..6) — a
            // real collision here is a 422 (unique per tenant).
            'body' => ['category_id' => '{{category_id}}', 'supplier_id' => null, 'name' => 'Oxytetracycline 20% Injectable (Postman Demo)', 'sku' => 'POSTMAN-DEMO-0001', 'brand' => 'EgyVet', 'pack_unit' => 'Vial 100ml', 'carton_qty' => 20, 'pack_cost_price' => 45.00, 'pack_selling_price' => 65.00, 'discount_pct' => 0, 'tax_pct' => 14, 'min_stock_cartons' => 10, 'reorder_level' => 20],
            'tests' => saveId('product_id'),
        ]),
        req('PUT', 'Update product', '/products/{{product_id}}', [
            'description' => 'Requires `inventory.edit`. All fields optional.',
            'body' => ['pack_selling_price' => 70.00],
        ]),
    ]),
    req('POST', 'Goods receipt (manual GRN)', '/inventory/grn', [
        'description' => 'Requires `inventory.add` + destination warehouse visibility. `exp_date` is required — never fabricated. Re-receiving the same `batch_no` for the same product+warehouse tops up quantity instead of duplicating the row.',
        'body' => ['product_id' => '{{product_id}}', 'warehouse_id' => '{{warehouse_id}}', 'batch_no' => 'BATCH-001', 'qty_cartons' => 50, 'cost_per_carton' => 120.00, 'exp_date' => '2027-12-31', 'mfg_date' => null, 'rcv_date' => null],
    ]),
    folder('Transfers', [
        req('GET', 'List transfers', '/transfers', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `inventory.view`.',
        ]),
        req('POST', 'Create transfer', '/transfers', [
            'description' => "Requires `inventory.add` + **both** warehouses visible to the caller. Executes immediately and atomically — `status` is always `completed` in the response, there's no separate confirm step. Expiry date carries over to the destination batch.",
            'body' => ['product_id' => '{{product_id}}', 'from_warehouse_id' => '{{warehouse_id}}', 'to_warehouse_id' => '{{warehouse_id_2}}', 'batch_no' => 'BATCH-001', 'qty_cartons' => 20, 'transfer_date' => '2026-09-20', 'notes' => null],
            'tests' => saveId('transfer_id'),
        ]),
        req('GET', 'Get transfer', '/transfers/{{transfer_id}}', [
            'description' => 'Requires `inventory.view`. 404 for another tenant\'s transfer.',
        ]),
    ]),
], 'Spec §6, §5.1/§5.8. Warehouse-scoped for every non-Owner/Auditor/Administrator role — see docs/api/inventory.md. Any endpoint using FEFO deduction can return 422 with a `shortfalls` array if stock is insufficient.');

// ---------------------------------------------------------------------
// Sales
// ---------------------------------------------------------------------

$sales = folder('Sales', [
    folder('Sales Orders', [
        req('GET', 'List sales orders', '/sales-orders', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view`. A Sales Rep only sees their own orders. Newest `order_date` first.',
        ]),
        req('GET', 'Get sales order', '/sales-orders/{{sales_order_id}}', [
            'description' => 'Includes nested `lines`. 403 if a Sales Rep requests another rep\'s order.',
        ]),
        req('POST', 'Create sales order', '/sales-orders', [
            'description' => "Requires `sales.add`. Validates FEFO stock availability and (for `pay_type: credit`) the customer's credit limit **before writing anything** — 422 with a `shortfalls` or credit-limit-exceeded body otherwise. `unit: Pack` rounds a line's stock need up to whole cartons.",
            'body' => ['customer_id' => '{{customer_id}}', 'sales_rep_id' => null, 'warehouse_id' => '{{warehouse_id}}', 'pay_type' => 'credit', 'grace_period' => 30, 'invoice_discount' => 0, 'notes' => null, 'order_date' => '2026-09-17', 'lines' => [['product_id' => '{{product_id}}', 'batch_no' => null, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65, 'discount_pct' => 5, 'free_qty' => 0]]],
            'tests' => saveId('sales_order_id'),
        ]),
        req('PUT', 'Update sales order status', '/sales-orders/{{sales_order_id}}/status', [
            'description' => "Requires `sales.edit` (+ ownership if Sales Rep). Drives the whole lifecycle: `draft → picking/invoiced/delivered/cancelled`, `picking → invoiced/delivered/cancelled`, `invoiced → delivered`. First transition to `invoiced`/`delivered` deducts stock (FEFO), auto-creates the invoice + a balanced journal entry, and (on `delivered`) the delivery record. Idempotent — re-running or advancing further never repeats these.\n\nGoes straight to `delivered` (skipping `invoiced`) so this one call populates both `{{invoice_id}}` and `{{delivery_id}}` for the Invoices/Deliveries/Collections requests below — change the body to `invoiced` if you want to see that intermediate state instead.",
            'body' => ['status' => 'delivered'],
            'tests' => [
                'if (pm.response.code === 200) {',
                '    const json = pm.response.json();',
                '    const invoice = json.data?.invoices?.[0];',
                "    if (invoice) { pm.collectionVariables.set('invoice_id', invoice.id); }",
                '    const delivery = json.data?.delivery;',
                "    if (delivery) { pm.collectionVariables.set('delivery_id', delivery.id); }",
                '}',
            ],
        ]),
    ]),
    folder('Invoices', [
        req('GET', 'List invoices', '/invoices', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` or `accounting.view`. No POST — invoices only come from the sales-order status transition.',
        ]),
        req('GET', 'Get invoice', '/invoices/{{invoice_id}}', [
            'description' => 'Includes `lines` (mirrored from the originating order) and `collections`.',
        ]),
    ]),
    folder('Deliveries', [
        req('GET', 'List deliveries', '/deliveries', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` (+ ownership if Sales Rep).',
        ]),
        req('GET', 'Get delivery', '/deliveries/{{delivery_id}}', []),
        req('PUT', 'Mark delivery delivered', '/deliveries/{{delivery_id}}/deliver', [
            'description' => "Requires `sales.edit` (+ ownership if Sales Rep). For marking a delivery delivered directly, separately from its order's own status transition.",
        ]),
    ]),
    folder('Collections', [
        req('GET', 'List collections', '/collections', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` or `accounting.view` (+ ownership if Sales Rep).',
        ]),
        req('POST', 'Record collection', '/collections', [
            'description' => "Requires `sales.add` or `accounting.add`. Records a payment against an invoice: updates the invoice's paid/balance/status, decreases the customer's AR balance, posts a balanced journal entry. 422 if amount ≤ 0, exceeds the remaining balance, or the invoice is already paid.",
            'body' => ['invoice_id' => '{{invoice_id}}', 'amount' => 300, 'method' => 'Bank Transfer', 'reference' => null, 'payment_date' => '2026-09-18', 'notes' => null],
            'tests' => saveId('collection_id'),
        ]),
        req('GET', 'Get collection', '/collections/{{collection_id}}', [
            'description' => 'Requires `sales.view` or `accounting.view` (+ ownership if Sales Rep).',
        ]),
    ]),
    folder('Returns', [
        req('GET', 'List returns', '/returns', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` or `purchasing.view`.',
        ]),
        req('POST', 'Create return', '/returns', [
            'description' => "Requires `sales.add` or `purchasing.add`. No financial side effect — only ever moves stock (or doesn't). `type: \"Purchase Return\"` always deducts; the other 4 types only move stock if `restocked: true` (and then add back). The batch is never fabricated — a non-existent batch_no fails rather than guessing an expiry.",
            'body' => ['invoice_id' => null, 'customer_id' => '{{customer_id}}', 'supplier_id' => null, 'product_id' => '{{product_id}}', 'warehouse_id' => '{{warehouse_id}}', 'batch_no' => 'BATCH-001', 'type' => 'Sales Return', 'qty' => 3, 'unit' => 'Carton', 'amount' => 60, 'restocked' => true, 'reason' => null, 'return_date' => '2026-09-20'],
        ]),
    ]),
    req('GET', 'AR aging report', '/reports/ar-aging', [
        'description' => 'Requires `sales.view` or `accounting.view`. Every customer with an outstanding/partial/overdue balance, bucketed by days overdue.',
    ]),
], 'Spec §6, §5.1/§5.3/§5.4/§5.6/§5.9. See docs/api/sales.md for full lifecycle rules, ownership scoping, and every error-response shape.');

// ---------------------------------------------------------------------
// Purchasing
// ---------------------------------------------------------------------

$purchasing = folder('Purchasing', [
    folder('Suppliers', [
        req('GET', 'List suppliers', '/suppliers', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `purchasing.view` (also reachable with `accounting.view`).',
        ]),
        req('POST', 'Create supplier', '/suppliers', [
            'description' => 'Requires `purchasing.add`. Only `name` required. `balance` starts at 0 and moves negative as POs are received — negative means we owe them.',
            // Not one of the 3 seeded suppliers' names (spec §7) — a real
            // collision here is a 422 (unique per tenant).
            'body' => ['name' => 'Postman Demo Pharmaceutical Supplier', 'country' => 'Egypt', 'city' => 'Cairo', 'contact' => null, 'email' => null, 'phone' => null, 'pay_terms' => 'Net 30', 'currency' => 'EGP', 'rating' => null, 'status' => 'active'],
            'tests' => saveId('supplier_id'),
        ]),
        req('PUT', 'Update supplier', '/suppliers/{{supplier_id}}', [
            'description' => "Requires `purchasing.edit`, and the supplier must belong to the caller's tenant (403 otherwise). Partial update — send only the fields to change. `name` stays unique per tenant, ignoring the supplier itself. `balance` can't be set here — only receiving POs moves it.",
            'body' => ['contact' => 'Postman Demo Contact', 'phone' => '+20 2 1234 5678', 'rating' => 4],
        ]),
    ]),
    folder('Purchase Orders', [
        req('GET', 'List purchase orders', '/purchase-orders', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `purchasing.view`.',
        ]),
        req('GET', 'Get purchase order', '/purchase-orders/{{purchase_order_id}}', [
            'description' => 'Includes nested `lines`.',
        ]),
        req('POST', 'Create purchase order', '/purchase-orders', [
            'description' => 'Requires `purchasing.add`. `status` starts as `draft`.',
            'body' => ['supplier_id' => '{{supplier_id}}', 'warehouse_id' => '{{warehouse_id}}', 'expected_date' => null, 'notes' => null, 'order_date' => '2026-09-19', 'lines' => [['product_id' => '{{product_id}}', 'qty_cartons' => 50, 'cost_per_carton' => 40]]],
            'tests' => array_merge(saveId('purchase_order_id'), [
                'if (pm.response.code === 201) {',
                '    const line = pm.response.json().data?.lines?.[0];',
                "    if (line) { pm.collectionVariables.set('purchase_order_line_id', line.id); }",
                '}',
            ]),
        ]),
        req('POST', 'Receive purchase order', '/purchase-orders/{{purchase_order_id}}/receive', [
            'description' => "Requires `purchasing.approve` (not just `add`). Every PO line needs exactly one matching `receipts` entry by `line_id` — partial receiving isn't supported. Adds stock (create-or-topup by batch_no), decreases supplier balance, posts a balanced journal entry. Rejects a second call outright — check `stock_added` on the PO first if unsure.\n\n`{{purchase_order_line_id}}` is captured automatically by the Create Purchase Order request above — for a PO with more than one line, replace it with the specific line id you're receiving.",
            'body' => ['receipts' => [['line_id' => '{{purchase_order_line_id}}', 'batch_no' => 'PO-BATCH-1', 'exp_date' => '2028-01-01', 'mfg_date' => null, 'rcv_date' => null]]],
        ]),
        req('POST', 'Create purchase order (to edit and delete)', '/purchase-orders', [
            'description' => 'A throwaway draft PO for the Update and Delete requests below — the main PO above has already been received by this point in the run, so it can no longer be edited or deleted.',
            'body' => ['supplier_id' => '{{supplier_id}}', 'warehouse_id' => '{{warehouse_id}}', 'expected_date' => null, 'notes' => 'Postman demo — deleted by the next request', 'order_date' => '2026-09-19', 'lines' => [['product_id' => '{{product_id}}', 'qty_cartons' => 1, 'cost_per_carton' => 40]]],
            'tests' => saveId('deletable_purchase_order_id'),
        ]),
        req('PUT', 'Update purchase order', '/purchase-orders/{{deletable_purchase_order_id}}', [
            'description' => 'Requires `purchasing.edit`. **Only if not received** — a received PO returns 422. Partial update: send only the fields to change. `lines`, if sent, **replaces every line** and subtotal/tax/total are recalculated (omit it to keep the current lines). `status` accepts draft/sent/pending/cancelled — `received` only comes from the receive endpoint. A PO from another tenant is 404.',
            'body' => ['status' => 'sent', 'expected_date' => '2026-10-15', 'notes' => 'Updated from Postman', 'lines' => [['product_id' => '{{product_id}}', 'qty_cartons' => 2, 'cost_per_carton' => 40]]],
        ]),
        req('DELETE', 'Delete purchase order', '/purchase-orders/{{deletable_purchase_order_id}}', [
            'description' => "Requires `purchasing.delete` (only Administrator has it by default). **Only if not received** — a received PO (`stock_added: true`) returns 422, since its stock, supplier balance and journal entry can't be undone by a delete. Returns 204 and removes the PO with its lines; audit-logged as DELETE. A PO from another tenant is 404.",
        ]),
    ]),
    req('GET', 'AP aging report', '/reports/ap-aging', [
        'description' => "Requires `purchasing.view` or `accounting.view`. **Not date-bucketed** (unlike AR) — `purchase_orders` has no due-date/payment tracking, so this is every supplier's current outstanding balance sorted by amount owed.",
    ]),
], 'Spec §6, §5.2. See docs/api/purchasing.md — note /suppliers isn\'t in the spec\'s own endpoint table but is required to make purchase_orders.supplier_id usable at all.');

// ---------------------------------------------------------------------
// CRM
// ---------------------------------------------------------------------

$crm = folder('CRM', [
    folder('Customers', [
        req('GET', 'List customers', '/customers', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `crm.view`. A Sales Rep sees only their own customers.',
        ]),
        req('GET', 'Get customer', '/customers/{{customer_id}}', [
            'description' => 'Includes nested `orders`, `invoices`, and `visits`.',
        ]),
        req('POST', 'Create customer', '/customers', [
            'description' => 'Requires `crm.add`. Only `name`/`type` required. A Sales Rep omitting `sales_rep_id` gets themselves; naming a different rep is 422.',
            'body' => ['sales_rep_id' => null, 'name' => 'Nile Valley Veterinary Clinic', 'type' => 'Clinic', 'classification' => 'B', 'phone' => null, 'email' => null, 'governorate' => null, 'province' => null, 'city' => null, 'area' => null, 'address' => null, 'credit_limit' => 50000, 'pay_terms' => null, 'status' => 'active'],
            'tests' => saveId('customer_id'),
        ]),
        req('PUT', 'Update customer', '/customers/{{customer_id}}', [
            'description' => 'Requires `crm.edit` (+ ownership if Sales Rep). All fields optional.',
            'body' => ['classification' => 'A'],
        ]),
    ]),
    folder('Leads', [
        req('GET', 'List leads', '/leads', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `crm.view`. No update/delete endpoint.',
        ]),
        req('POST', 'Create lead', '/leads', [
            'description' => 'Requires `crm.add`.',
            'body' => ['assigned_to' => null, 'name' => 'Delta Farms', 'type' => 'Farm', 'contact' => null, 'phone' => null, 'email' => null, 'source' => 'Referral', 'status' => 'new', 'value' => null, 'notes' => null],
        ]),
    ]),
    folder('Visits', [
        req('GET', 'List visits', '/visits', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `crm.view`.',
        ]),
        req('POST', 'Create visit', '/visits', [
            'description' => 'Requires `crm.add`. `rep_id` defaults to the caller if omitted.',
            'body' => ['customer_id' => '{{customer_id}}', 'rep_id' => null, 'visit_date' => '2026-09-20', 'type' => 'Follow-up', 'outcome' => 'positive', 'notes' => null, 'next_visit' => null],
        ]),
    ]),
    folder('Complaints', [
        req('GET', 'List complaints', '/complaints', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `crm.view`.',
        ]),
        req('POST', 'Create complaint', '/complaints', [
            'description' => 'Requires `crm.add`. `priority` defaults to `medium`, `status` defaults to `investigating`.',
            'body' => ['customer_id' => '{{customer_id}}', 'product_id' => null, 'assigned_to' => null, 'batch_no' => null, 'type' => 'Quality', 'description' => 'Vial arrived damaged.', 'priority' => 'medium', 'complaint_date' => '2026-09-20'],
            'tests' => saveId('complaint_id'),
        ]),
        req('PUT', 'Resolve complaint', '/complaints/{{complaint_id}}/resolve', [
            'description' => 'Requires `crm.edit`. `resolution` required — sets `status: resolved` and `resolved_date` to today.',
            'body' => ['resolution' => 'Replacement shipped.'],
        ]),
    ]),
    folder('Campaigns', [
        req('GET', 'List campaigns', '/campaigns', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `crm.view`.',
        ]),
        req('POST', 'Create campaign', '/campaigns', [
            'description' => 'Requires `crm.add`. `status` defaults to `active`.',
            'body' => ['name' => 'Autumn Vaccine Drive', 'type' => 'Discount', 'target' => 'Clinics', 'discount' => 10, 'start_date' => '2026-10-01', 'end_date' => null, 'description' => null],
            'tests' => saveId('campaign_id'),
        ]),
        req('PUT', 'End campaign', '/campaigns/{{campaign_id}}/end', [
            'description' => "Requires `crm.edit`. Sets `status: completed` and fills `end_date` with today if it wasn't already set.",
        ]),
    ]),
], 'Spec §6. A Sales Rep gets crm.* too (not just sales.*) per spec §2\'s "own orders/customers" grouping, scoped to their own sales_rep_id.');

// ---------------------------------------------------------------------
// Accounting
// ---------------------------------------------------------------------

$accounting = folder('Accounting', [
    req('GET', 'Chart of accounts', '/chart-of-accounts', [
        'query' => ['page' => 1, 'per_page' => 100, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
        'description' => "Requires `accounting.view`. Flat list ordered by code — `parent_id`/`level` let the frontend build a tree.\n\nSaves the id of account `6200` (General & Administrative Expense) into `{{expense_account_id}}` for Expenses > Create expense category.",
        'tests' => [
            'if (pm.response.code === 200) {',
            "    const account = (pm.response.json().data || []).find(a => a.code === '6200');",
            "    if (account) { pm.collectionVariables.set('expense_account_id', account.id); }",
            '}',
        ],
    ]),
    req('GET', 'Journal entries', '/journal-entries', [
        'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'export' => 'csv'],
        'description' => 'Requires `accounting.view`. Includes nested `lines`. No POST — entries only come from business events auto-posting. Exported rows summarize lines into one column rather than one row per line.',
    ]),
    req('GET', 'Balance sheet', '/reports/balance-sheet', [
        'description' => 'Requires `accounting.view`. Current snapshot only, no `as_of` param — Account.balance is a running total. `balanced: false` is expected in this build (no period-close process sweeps net income into Retained Earnings).',
    ]),
    req('GET', 'Income statement', '/reports/income-statement', [
        'query' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
        'requiredQuery' => ['start_date', 'end_date'],
        'description' => 'Requires `accounting.view`. `start_date`/`end_date` are both required (422 without) — unlike every other `?filter=` param in this collection, these two are enabled by default rather than disabled, since omitting them isn\'t just "no filter," it\'s a 422.',
    ]),
], "Spec §6 only lists chart-of-accounts and journal-entries; the two reports above and the aging reports (see Sales/Purchasing folders) fill a gap between the spec's endpoint table and its build-order narrative (§10.7) — shapes here are this build's own design, not spec-mandated. See docs/api/accounting.md.");

// ---------------------------------------------------------------------
// Expenses
// ---------------------------------------------------------------------

$expenses = folder('Expenses', [
    folder('Categories', [
        req('GET', 'List expense categories', '/expense-categories', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `expenses.view`. Each category carries the Expense-type chart-of-accounts `account` it posts to.',
        ]),
        req('POST', 'Create expense category', '/expense-categories', [
            'description' => "Requires `expenses.add`. `account_id` must be an **Expense-type** account in the caller's tenant — approval debits it. `name` is unique per tenant. Uses `{{expense_account_id}}`, captured by Accounting > Chart of accounts.",
            'body' => ['name' => 'Postman Demo Category', 'account_id' => '{{expense_account_id}}', 'description' => null, 'active' => true],
            'tests' => saveId('expense_category_id'),
        ]),
        req('PUT', 'Update expense category', '/expense-categories/{{expense_category_id}}', [
            'description' => 'Requires `expenses.edit`. Partial update. There is no delete — set `active: false` to retire a category; inactive categories can\'t take new expenses.',
            'body' => ['description' => 'Updated from Postman'],
        ]),
    ]),
    folder('Expenses', [
        req('POST', 'Upload receipt', '/expenses/receipts', [
            'description' => 'Requires `expenses.add`. **Step 1 of attaching a receipt** — jpg/png/pdf, max 5 MB. Returns `receipt_path`, saved into `{{expense_receipt_path}}`, which you then send on Create/Update expense. Attach a file to the `receipt` field before sending — in an unattended collection run this returns 422 and the expense below is simply created without a receipt.',
            'files' => ['receipt' => 'Receipt image or PDF (jpg, png, pdf — max 5 MB).'],
            'tests' => saveId('expense_receipt_path', 'data.receipt_path'),
        ]),
        req('POST', 'Create expense', '/expenses', [
            'description' => 'Requires `expenses.add` (Accountant, Purchasing). Always starts as `draft` — nothing is posted to the journal until approval. `payment_method` is `cash` or `bank` (decides whether approval credits 1110 Cash or 1120 Bank). `warehouse_id`, `supplier_id`, `payee`, `reference`, `description` and `receipt_path` are optional; `receipt_path` must come from Upload receipt (same tenant, not already attached to another expense).',
            'body' => ['category_id' => '{{expense_category_id}}', 'warehouse_id' => '{{warehouse_id}}', 'supplier_id' => '{{supplier_id}}', 'payee' => null, 'payment_method' => 'cash', 'expense_date' => '2026-10-01', 'amount' => '1250.50', 'reference' => 'INV-77', 'description' => 'Office rent — October', 'receipt_path' => '{{expense_receipt_path}}'],
            'tests' => saveId('expense_id'),
        ]),
        req('GET', 'List expenses', '/expenses', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'search' => '', 'filter[status]' => 'draft', 'export' => 'csv'],
            'description' => 'Requires `expenses.view`. `search` matches id/payee/reference/description/category/supplier/warehouse names. Export needs `expenses.export` or `accounting.export`.',
        ]),
        req('GET', 'Get expense', '/expenses/{{expense_id}}', [
            'description' => 'Requires `expenses.view`. `receipt_url` (when a receipt is attached) points at Download receipt.',
        ]),
        req('PUT', 'Update expense', '/expenses/{{expense_id}}', [
            'description' => 'Requires `expenses.edit`. **Drafts only** — approved/rejected expenses return 422. Partial update. Send a new `receipt_path` (from Upload receipt) to replace the receipt — the old file is deleted — or `null` to remove it. `status` can\'t be set here.',
            'body' => ['amount' => '1300.00', 'payee' => 'Building management'],
        ]),
        req('GET', 'Download receipt', '/expenses/{{expense_id}}/receipt', [
            'description' => 'Requires `expenses.view`. File download; 404 if the expense has no receipt.',
        ]),
        req('POST', 'Approve expense', '/expenses/{{expense_id}}/approve', [
            'description' => 'Requires `expenses.approve` — **Accountant or Administrator only** (Owner and Purchasing are 403). Drafts only. Posts a journal entry dated on `expense_date`: Dr the category\'s expense account / Cr 1110 Cash or 1120 Bank, and sets `journal_entry_id`, `approved_by`, `approved_at`. A second approve is 422.',
        ]),
        req('POST', 'Create expense (to reject)', '/expenses', [
            'description' => 'A second draft for Reject expense below — the one above is approved by now.',
            'body' => ['category_id' => '{{expense_category_id}}', 'payment_method' => 'bank', 'expense_date' => '2026-10-02', 'amount' => '80.00', 'payee' => 'Taxi', 'description' => 'Postman demo — rejected by the next request'],
            'tests' => saveId('rejectable_expense_id'),
        ]),
        req('POST', 'Reject expense', '/expenses/{{rejectable_expense_id}}/reject', [
            'description' => 'Requires `expenses.approve` (Accountant or Administrator). Drafts only. `reason` is optional. Posts nothing to the journal; a rejected expense can no longer be edited, approved or deleted.',
            'body' => ['reason' => 'No receipt attached'],
        ]),
        req('POST', 'Create expense (to delete)', '/expenses', [
            'description' => 'A throwaway draft for Delete expense below.',
            'body' => ['category_id' => '{{expense_category_id}}', 'payment_method' => 'cash', 'expense_date' => '2026-10-03', 'amount' => '15.00', 'description' => 'Postman demo — deleted by the next request'],
            'tests' => saveId('deletable_expense_id'),
        ]),
        req('DELETE', 'Delete expense', '/expenses/{{deletable_expense_id}}', [
            'description' => 'Requires `expenses.delete` (Administrator only by default). **Drafts only** — approved ones need a reversing entry, not a delete (422). Returns 204, removes the receipt file too; audit-logged as DELETE.',
        ]),
    ]),
], 'Draft → approved (posts to the journal) or rejected. Accountant and Purchasing record expenses; only Accountant or Administrator approve/reject. Run after Accounting — Create expense category needs `{{expense_account_id}}` from Chart of accounts.');

// ---------------------------------------------------------------------
// Analytics
// ---------------------------------------------------------------------

$analytics = folder('Analytics', [
    req('GET', 'Dashboard', '/analytics/dashboard', [
        'description' => 'Requires `analytics.view`. The 8 KPI cards from spec §8 (monthly_sales, active_customers, collected_amount, outstanding_ar, overdue_amount, inventory_value, critical_expiry_count, pending_deliveries).',
    ]),
    req('GET', 'Expiry tracking', '/analytics/expiry', [
        'query' => ['page' => 1, 'per_page' => 15, 'export' => 'csv'],
        'description' => 'Requires `analytics.view`. Paginated, soonest-expiring first. `days_left` is signed (negative = already expired). Excludes exhausted batches.',
    ]),
    req('GET', 'Stock rollup', '/analytics/stock', [
        'query' => ['page' => 1, 'per_page' => 15, 'export' => 'csv'],
        'description' => 'Requires `analytics.view`. Per-product stock across every warehouse — paginated by product, not by row.',
    ]),
    req('GET', 'Sales report (export)', '/reports/sales', [
        'query' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'warehouse_id' => '{{warehouse_id}}', 'per_page' => 15],
        'description' => 'Requires `analytics.export` specifically (not just `view`). Same row shape as GET /sales-orders.',
    ]),
    req('GET', 'Inventory report (export)', '/reports/inventory', [
        'query' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'warehouse_id' => '{{warehouse_id}}', 'per_page' => 15],
        'description' => 'Requires `analytics.export`. Batch-level detail, date filters apply to `rcv_date` not `exp_date`.',
    ]),
], "Spec §6/§8. **Only Administrator, Owner, and Auditor hold any analytics.* permission in this build** — the spec's own role table never grants it to an operational role. See docs/api/analytics.md.");

// ---------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------

$admin = folder('Admin', [
    folder('Users', [
        req('GET', 'List users', '/users', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'role' => '', 'status' => '', 'export' => 'csv'],
            'description' => 'Requires `admin.audit` to view, `admin.export` to export (see the collection description\'s export note — same distinction as everywhere else).',
        ]),
        req('GET', 'Get user', '/users/{{demo_user_id}}', [
            'description' => "Requires `admin.audit`. Includes `warehouse_ids`. Targets `{{demo_user_id}}` (captured by Create user below), **not** `{{user_id}}` — `user_id` is whoever Login authenticated as, and this folder deliberately never reads or writes that id, so running it can't lock you out of your own session.",
        ]),
        req('POST', 'Create user', '/users', [
            'description' => 'Requires `admin.add` (Administrator only — Owner/Auditor are oversight roles without add/edit). `role` must be one of the 10 seeded role names. `warehouse_ids` is required in practice for Warehouse Manager/Employee — without it that account sees zero warehouses.',
            'body' => ['name' => 'New Employee', 'email' => 'new.employee@vetpharma.com', 'password' => 'a-secure-password', 'role' => 'Warehouse Employee', 'status' => 'active', 'warehouse_ids' => ['{{warehouse_id}}']],
            'tests' => saveId('demo_user_id'),
        ]),
        req('PUT', 'Update user', '/users/{{demo_user_id}}', [
            'description' => "Requires `admin.edit`. Same shape as create, all fields optional, **except password is not accepted here** — a user changes their own via Auth > Change password. `warehouse_ids` replaces the full set, not a merge.\n\n**Deliberately targets the demo user created above, not `{{user_id}}`** — this body suspends its target account, and `{{user_id}}` is whoever you're currently authenticated as. Pointing this at your own id would lock you out of the rest of the collection.",
            'body' => ['status' => 'suspended'],
        ]),
    ]),
    folder('Audit Log', [
        req('GET', 'List audit log', '/audit-log', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'module' => '', 'entity_type' => '', 'export' => 'csv'],
            'description' => 'Requires `admin.audit` to view, `admin.export` to export. Most-recent-first. Includes `prev_hash`/`entry_hash` for the tamper-evident chain (spec §5.7) in both the JSON and exported shapes.',
        ]),
        req('POST', 'Verify audit chain integrity', '/audit-log/verify-integrity', [
            'description' => 'Requires `admin.audit`. Re-walks the whole hash chain, returns `{ intact, broken_at }`. HTTP equivalent of the `audit:verify` console command.',
        ]),
    ]),
    folder('Notifications', [
        req('GET', 'List notifications', '/notifications', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => "Any authenticated user — own inbox (personal + broadcast, `user_id: null`). Not exportable — it's a personal inbox, not business data.",
        ]),
        req('PUT', 'Mark all notifications read', '/notifications/read-all', [
            'description' => '`unread` is a single flag per row (spec §4.14 has no per-viewer read state) — marking a broadcast notification read here affects every user, not just the caller. See docs/api/admin.md for the full trade-off.',
        ]),
    ]),
    folder('Discarded Actions', [
        req('GET', 'List discarded actions', '/discarded-actions', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `admin.view`. Tenant-wide — the admin review screen, not scoped to the caller.',
        ]),
        req('POST', 'Save discarded action', '/discarded-actions', [
            'description' => "Any authenticated user — saves their own abandoned form draft. `payload` is free-form JSON. Not in the spec's own §6 table, but §4.15 explicitly asks for it to be wired up.",
            'body' => ['type' => 'sales_order', 'label' => 'Draft order for Al-Salam Vet Clinic', 'payload' => ['customer_id' => '{{customer_id}}', 'lines' => []]],
        ]),
    ]),
], "Spec §6/§9.4. See docs/api/admin.md — also documents why there's no Settings endpoint (no backing schema anywhere in the spec).");

// Order matters if you "Run collection" end to end: Inventory creates the
// warehouse/product a sales order needs, CRM creates the customer a
// sales order needs, Purchasing creates the supplier a PO needs — all
// before Sales, which is the first folder that consumes them. Expenses
// follows Accounting, whose Chart of accounts captures the expense
// account a category needs (and reuses the warehouse/supplier). Logout
// runs dead last for the same reason — it revokes {{token}}, so nothing
// after it in a full collection run could authenticate.
$items = [$auth, $reference, $inventory, $crm, $purchasing, $sales, $accounting, $expenses, $analytics, $admin, folder('Session end', [
    req('POST', 'Logout', '/auth/logout', [
        'description' => 'Revokes the token used to make this request — real Sanctum token deletion, not just an audit entry (401 on any further use of it, immediately). Deliberately the last request in the whole collection — everything above needs `{{token}}` to still be valid.',
    ]),
])];

$requestCount = 0;
array_walk_recursive($items, function ($v, $k) use (&$requestCount) {
    if ($k === 'method') {
        $requestCount++;
    }
});

$collection = [
    'info' => [
        'name' => 'VetPharma ERP API',
        'description' => "Laravel rebuild of the VetPharma ERP per docs/../first.md. Base URL is `{{base_url}}` (defaults to `http://localhost:8000/api/v1` in the companion environment — the one exception is **Health check** in Auth, which uses `{{root_url}}` directly since it's unversioned and outside `/api/v1`).\n\n**Quick start:**\n1. Import the companion environment file (`VetPharma-ERP.postman_environment.json`) and select it.\n2. Run **Auth > Login** — it captures the bearer token into `{{token}}` automatically. Every other request already sends `Authorization: Bearer {{token}}`.\n3. `seed_email`/`seed_password` in the environment default to the Administrator seed account; change them (see docs/api/README.md's seed account table) to test role-specific behavior.\n4. Several \"create\" requests (warehouses, products, customers, sales orders, purchase orders, suppliers...) auto-save the created id into a collection variable (e.g. `{{warehouse_id}}`) so the next request in that folder can reference it without manual copy-paste.\n\n**Running the whole collection top to bottom** (Postman's \"Run collection\", or `newman run`): set a **~1.1s delay between requests**. The API's general rate limit is 60 requests/minute per user, and this collection has {$requestCount} requests — back-to-back with no delay, you'll get real `429`s partway through Admin. Verified end-to-end with `newman run VetPharma-ERP.postman_collection.json -e VetPharma-ERP-Local.postman_environment.json --delay-request 1100` against a freshly seeded database — 0 failures. Any single folder on its own (all well under 60 requests) is fine with no delay.\n\n**Exporting a list as CSV/Excel**: most list requests below have an `export` query param, disabled by default — enable it and set it to `csv` or `xlsx` to get a real file download instead of JSON (every matching row, not just one page). Requires `{module}.export` specifically, not just `{module}.view` — see docs/api/README.md's \"Exporting a list\" section for which roles have it.\n\nFull request/response documentation with every error shape lives in `docs/api/*.md` — this collection is for exercising the API, not a replacement for reading those.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'variable' => array_map(
        fn ($k, $v) => ['key' => $k, 'value' => $v, 'type' => 'string'],
        array_keys($vars = [
            'root_url' => 'http://localhost:8000',
            'base_url' => '{{root_url}}/api/v1',
            'token' => '',
            'seed_email' => 'admin@vetpharma.com',
            'seed_password' => 'password',
            'user_id' => '', // the currently logged-in user's own id (set by Login) — never targeted by Admin > Users, on purpose
            'demo_user_id' => '', // a separate user created by Admin > Create user — safe to mutate/suspend
            'warehouse_id' => '',
            'warehouse_id_2' => '',
            'product_id' => '',
            'category_id' => '',
            'customer_id' => '',
            'supplier_id' => '',
            'sales_order_id' => '',
            'invoice_id' => '',
            'delivery_id' => '',
            'collection_id' => '',
            'transfer_id' => '',
            'purchase_order_id' => '',
            'purchase_order_line_id' => '',
            'deletable_purchase_order_id' => '',
            'complaint_id' => '',
            'campaign_id' => '',
            'expense_account_id' => '',
            'expense_category_id' => '',
            'expense_receipt_path' => '',
            'expense_id' => '',
            'rejectable_expense_id' => '',
            'deletable_expense_id' => '',
        ]),
        $vars
    ),
    'item' => $items,
];

$environment = [
    'id' => '7c4b0b3a-3b1a-4b6a-9b3a-2f4e0f6c9a11',
    'name' => 'VetPharma ERP — Local',
    'values' => [
        ['key' => 'root_url', 'value' => 'http://localhost:8000', 'type' => 'default', 'enabled' => true],
        ['key' => 'base_url', 'value' => '{{root_url}}/api/v1', 'type' => 'default', 'enabled' => true],
        ['key' => 'seed_email', 'value' => 'admin@vetpharma.com', 'type' => 'default', 'enabled' => true],
        ['key' => 'seed_password', 'value' => 'password', 'type' => 'default', 'enabled' => true],
        // `token` deliberately isn't defined here: it's a collection variable
        // (see $collection above) that the Login request's test script
        // writes at runtime. An environment variable of the same name would
        // take precedence over that collection variable and silently shadow
        // it with an empty string on every request after login.
    ],
    '_postman_variable_scope' => 'environment',
];

file_put_contents(
    __DIR__.'/VetPharma-ERP.postman_collection.json',
    json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
);

file_put_contents(
    __DIR__.'/VetPharma-ERP-Local.postman_environment.json',
    json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
);

echo "Generated collection with {$requestCount} requests.\n";
