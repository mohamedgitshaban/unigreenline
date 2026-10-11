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

/**
 * Change flags. Anything added or altered after the original collection is
 * marked so it can be spotted at a glance (name prefix + description banner):
 *   🆕 NEW      — a new endpoint (or folder)
 *   🔄 CHANGED  — an existing endpoint that now accepts/returns more
 * To retire a flag once a change has been reviewed, drop the nw()/chg() call.
 */
function flagged(array $item, string $badge, string $banner): array
{
    $item['name'] = $badge.' '.$item['name'];

    if (isset($item['request'])) {
        $existing = $item['request']['description'] ?? null;
        $item['request']['description'] = "**{$badge} — {$banner}**".($existing === null ? '' : "\n\n".$existing);
    } else {
        $existing = $item['description'] ?? null;
        $item['description'] = "**{$badge} — {$banner}**".($existing === null ? '' : "\n\n".$existing);
    }

    return $item;
}

function nw(array $item): array
{
    return flagged($item, '🆕', 'NEW');
}

function chg(array $item, string $what): array
{
    return flagged($item, '🔄', 'CHANGED: '.$what);
}

/**
 * "Create a throwaway record, then delete it" — so a DELETE request never
 * destroys the id later requests in a full run still depend on. The create
 * step is the existing endpoint (unflagged); the delete is the new one.
 *
 * @param  array<string, mixed>  $createBody
 * @return list<array<string, mixed>>
 */
function deleteFlow(string $label, string $createPath, array $createBody, string $var, string $deletePath, string $deleteDescription, string $createDescription = 'A throwaway record for the Delete request below.'): array
{
    return [
        req('POST', "Create {$label} (to delete)", $createPath, [
            'description' => $createDescription,
            'body' => $createBody,
            'tests' => saveId($var),
        ]),
        nw(req('DELETE', "Delete {$label}", str_replace('{id}', '{{'.$var.'}}', $deletePath), [
            'description' => $deleteDescription,
        ])),
    ];
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
    nw(folder('Units', [
        nw(req('GET', 'List units', '/units', [
            'query' => ['page' => 1, 'per_page' => 50, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `inventory.view`, `purchasing.view` or `sales.view`. The packaging unit names (Packet, Package, Carton by default — seeded by UnitSeeder). Supports `search` on name.',
        ])),
        nw(req('POST', 'Create unit (Packet)', '/units', [
            'description' => 'Requires `inventory.add`. Adds a unit name an admin can then use in any product packaging ladder. `name` is unique per tenant. The Postman names are prefixed so they do not clash with the seeded Packet/Package/Carton.',
            'body' => ['name' => 'Postman Packet', 'active' => true],
            'tests' => saveId('unit_id_packet'),
        ])),
        nw(req('POST', 'Create unit (Package)', '/units', [
            'description' => 'Requires `inventory.add`. See Create unit (Packet).',
            'body' => ['name' => 'Postman Package'],
            'tests' => saveId('unit_id_package'),
        ])),
        nw(req('POST', 'Create unit (Carton)', '/units', [
            'description' => 'Requires `inventory.add`. See Create unit (Packet).',
            'body' => ['name' => 'Postman Carton'],
            'tests' => saveId('unit_id_carton'),
        ])),
        nw(req('GET', 'Get unit', '/units/{{unit_id_packet}}', [
            'description' => 'Requires the same permissions as List units. 404 for another tenant.',
        ])),
        nw(req('PUT', 'Update unit', '/units/{{unit_id_packet}}', [
            'description' => 'Requires `inventory.edit`. Rename it or set `active: false` to stop offering it.',
            'body' => ['active' => true],
        ])),
        nw(req('POST', 'Create unit (to delete)', '/units', [
            'description' => 'A throwaway unit for the Delete request below.',
            'body' => ['name' => 'Postman Delete Me Unit'],
            'tests' => saveId('deletable_unit_id'),
        ])),
        nw(req('DELETE', 'Delete unit', '/units/{{deletable_unit_id}}', [
            'description' => 'Requires `inventory.delete`. 204. **409** if any product is packaged in the unit — set `active: false` instead.',
        ])),
    ], 'Admin-managed packaging unit names. What each unit holds is set per product (Products > Create product with packaging units).')),
    folder('Warehouses', [
        req('GET', 'List warehouses', '/warehouses', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => "Requires `inventory.view`. Scoped to the caller's assigned warehouses (`user_warehouses`) unless they hold Owner/Auditor/Administrator.",
        ]),
        req('GET', 'Get warehouse', '/warehouses/{{warehouse_id}}', [
            'description' => 'Includes `batches_count` — the batches themselves are paged by List warehouse batches. 403 if this warehouse is not assigned to the caller.',
        ]),
        req('GET', 'List warehouse batches', '/warehouses/{{warehouse_id}}/batches', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `inventory.view` + the warehouse assigned to the caller (403 otherwise). Each batch includes its `product`. Paginated (10 per page by default) — follow `links.next`. `sort_by=exp_date&sort_dir=asc` gives FEFO order.',
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
        nw(req('POST', 'Import warehouses', '/warehouses/import', [
            'description' => 'Requires `inventory.add`. Bulk-creates warehouses from a spreadsheet. Matched by name. `manager_email` must be an existing user.'."\n\n".'**Multipart upload**: attach a .xlsx/.csv to the `file` field (headings = the template columns; get the template from the matching `import-template` request). All-or-nothing — one bad row rejects the whole file with a per-row error report (`errors[].row`, 1 = the heading row). Existing records (matched by the natural key) are skipped unless `update_existing=1`. In an unattended collection run (no file attached) this returns 422, which is expected.',
            'files' => ['file' => 'Spreadsheet (.xlsx or .csv, max 5 MB, max 5000 rows).'],
            'body' => ['update_existing' => false],
        ])),
        nw(req('GET', 'Download warehouses import template', '/warehouses/import-template', [
            'description' => 'Requires `inventory.add`. An .xlsx with the exact column headings and one example row.',
        ])),
        ...deleteFlow('warehouse', '/warehouses', ['name' => 'Postman Delete Me Warehouse', 'city' => 'Cairo', 'governorate' => 'Cairo'], 'deletable_warehouse_id', '/warehouses/{id}', 'Requires `inventory.delete` + the warehouse visible to the caller. 204. **409** while it holds batches, has transfers or purchase/sales orders. Audit-logged as DELETE.'),
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
        req('GET', 'Get category', '/categories/{{category_id}}', [
            'description' => "Requires `inventory.view`. 404 for another tenant's category.",
        ]),
        req('PUT', 'Update category', '/categories/{{category_id}}', [
            'description' => "Requires `inventory.edit`, and the category must belong to the caller's tenant (403 otherwise). Partial update — send only the fields to change. `name` stays unique per tenant, ignoring the category itself.",
            'body' => ['code' => 'PM-DEMO', 'description' => 'Updated from Postman'],
        ]),
        ...deleteFlow('category', '/categories', ['name' => 'Postman Delete Me Category', 'active' => true], 'deletable_category_id', '/categories/{id}', 'Requires `inventory.delete`. 204. **409** while any product uses the category — set `active: false` instead.'),
    ]),
    folder('Products', [
        req('GET', 'List products', '/products', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `inventory.view`. Catalog-wide, not warehouse-scoped.',
        ]),
        req('GET', 'Get product', '/products/{{product_id}}', [
            'description' => 'Includes `batches_count` — the batches themselves are paged by List product batches.',
        ]),
        req('GET', 'List product batches', '/products/{{product_id}}/batches', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'exp_date', 'sort_dir' => 'asc', 'filter[warehouse_id]' => '{{warehouse_id}}', 'filter[qty_cartons][gt]' => 0],
            'description' => "Requires `inventory.view`. The product's batches as a paginated list (default 10 per page) — use this instead of the nested `batches` on Get product when a product has many batches. Follow `links.next` for the next page. Supports the usual `search` (batch_no, warehouse name), `filter[...]` and `sort_by`; `sort_by=exp_date&sort_dir=asc` gives FEFO order. 404 for another tenant's product.",
        ]),
        chg(req('POST', 'Create product', '/products', [
            'description' => "Requires `inventory.add`. `sku` must be unique. `cost_price`/`selling_price` are computed server-side (pack price × carton_qty) and returned always in sync — don't send them.",
            // SKU is not one of the 6 seeded products' (PRD-00001..6) — a
            // real collision here is a 422 (unique per tenant).
            'body' => ['category_id' => '{{category_id}}', 'supplier_id' => null, 'name' => 'Oxytetracycline 20% Injectable (Postman Demo)', 'sku' => 'POSTMAN-DEMO-0001', 'brand' => 'EgyVet', 'pack_unit' => 'Vial 100ml', 'carton_qty' => 20, 'pack_cost_price' => 45.00, 'pack_selling_price' => 65.00, 'min_selling_price' => 1.00, 'discount_pct' => 0, 'tax_pct' => 14, 'min_stock_cartons' => 10, 'reorder_level' => 20],
            'tests' => saveId('product_id'),
        ]), 'takes `min_selling_price` (per smallest unit, never above `pack_selling_price`; sales-order lines may not be priced below it) and an optional `units` packaging ladder (see Units). Cost fields (`pack_cost_price`, `cost_price`) are only returned to Administrators.'),
        chg(req('PUT', 'Update product', '/products/{{product_id}}', [
            'description' => 'Requires `inventory.edit`. All fields optional.',
            'body' => ['pack_selling_price' => 70.00, 'min_selling_price' => 2.00],
        ]), 'accepts `min_selling_price` and `units` (replaces the whole packaging ladder; `carton_qty` is re-derived from it).'),
        nw(req('GET', 'Product availability by warehouse', '/products/{{product_id}}/availability', [
            'description' => 'Requires `inventory.view` or `sales.view`. Every active warehouse holding unexpired stock of the product — `available_cartons`, `available_base_units`, `batches_count`, `nearest_expiry` — largest first, plus totals. Use it to let a seller pick the warehouse for each sales-order line.',
        ])),
        nw(req('POST', 'Create product with packaging units', '/products', [
            'description' => 'Requires `inventory.add`. Packaging ladder smallest unit first; each larger unit says how many of the previous one it holds — here Packet, Package (10 packets), Carton (3 packages) = **30 packets per carton**, so `carton_qty` is derived (do not send it) and stock is stored by the carton. Uses the units from Units > Create unit.',
            'body' => ['category_id' => '{{category_id}}', 'name' => 'Packaged Product X (Postman Demo)', 'sku' => 'POSTMAN-UNITS-0001', 'pack_cost_price' => 5.00, 'pack_selling_price' => 10.00, 'min_selling_price' => 8.00, 'units' => [['unit_id' => '{{unit_id_packet}}'], ['unit_id' => '{{unit_id_package}}', 'contains' => 10], ['unit_id' => '{{unit_id_carton}}', 'contains' => 3]]],
            'tests' => saveId('unit_product_id'),
        ])),
        nw(req('POST', 'Import products', '/products/import', [
            'description' => 'Requires `inventory.add`. Bulk-creates products from a spreadsheet. Matched by SKU. Category (name or code) and Supplier (name) must already exist; optional `Min Selling Price` column.'."\n\n".'**Multipart upload**: attach a .xlsx/.csv to the `file` field (headings = the template columns; get the template from the matching `import-template` request). All-or-nothing — one bad row rejects the whole file with a per-row error report (`errors[].row`, 1 = the heading row). Existing records (matched by the natural key) are skipped unless `update_existing=1`. In an unattended collection run (no file attached) this returns 422, which is expected.',
            'files' => ['file' => 'Spreadsheet (.xlsx or .csv, max 5 MB, max 5000 rows).'],
            'body' => ['update_existing' => false],
        ])),
        nw(req('GET', 'Download products import template', '/products/import-template', [
            'description' => 'Requires `inventory.add`. An .xlsx with the exact column headings and one example row.',
        ])),
        ...deleteFlow('product', '/products', ['category_id' => '{{category_id}}', 'name' => 'Postman Delete Me Product', 'sku' => 'POSTMAN-DEL-0001', 'pack_unit' => 'Vial', 'carton_qty' => 10, 'pack_cost_price' => 1, 'pack_selling_price' => 2], 'deletable_product_id', '/products/{id}', 'Requires `inventory.delete`. 204. **409** if the product still has batches, order lines or transfers — set `active: false` instead.'),
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
            'description' => 'Includes `lines_count` and `invoices_count` — paged by List sales order lines / List sales order invoices. 403 if a Sales Rep requests another rep\'s order.',
        ]),
        req('GET', 'List sales order lines', '/sales-orders/{{sales_order_id}}/lines', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Same access as Get sales order. Each line includes its `product`. Paginated (10 per page by default) — follow `links.next`.',
        ]),
        req('GET', 'List sales order invoices', '/sales-orders/{{sales_order_id}}/invoices', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Same access as Get sales order. Paginated (10 per page by default) — follow `links.next`.',
        ]),
        req('POST', 'Create sales order', '/sales-orders', [
            'description' => "Requires `sales.add`. Validates FEFO stock availability and (for `pay_type: credit`) the customer's credit limit **before writing anything** — 422 with a `shortfalls` or credit-limit-exceeded body otherwise. `unit: Pack` rounds a line's stock need up to whole cartons.",
            'body' => ['customer_id' => '{{customer_id}}', 'sales_rep_id' => null, 'warehouse_id' => '{{warehouse_id}}', 'pay_type' => 'credit', 'grace_period' => 30, 'invoice_discount' => 0, 'notes' => null, 'order_date' => '2026-09-17', 'lines' => [['product_id' => '{{product_id}}', 'batch_no' => null, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65, 'discount_pct' => 5, 'free_qty' => 0]]],
            'tests' => saveId('sales_order_id'),
        ]),
        req('PUT', 'Update sales order status', '/sales-orders/{{sales_order_id}}/status', [
            'description' => "Requires `sales.edit` (+ ownership if Sales Rep). Drives the whole lifecycle: `draft → picking/invoiced/delivered/cancelled`, `picking → invoiced/delivered/cancelled`, `invoiced → delivered`. First transition to `invoiced`/`delivered` deducts stock (FEFO), auto-creates the invoice + a balanced journal entry, and (on `delivered`) the delivery record. Idempotent — re-running or advancing further never repeats these.\n\nGoes straight to `delivered` (skipping `invoiced`). The response includes `invoices_count` and the `delivery` (saved into `{{delivery_id}}`); the next request saves the invoice into `{{invoice_id}}` for the Invoices/Collections requests below — change the body to `invoiced` if you want to see that intermediate state instead.",
            'body' => ['status' => 'delivered'],
            'tests' => [
                'if (pm.response.code === 200) {',
                '    const delivery = pm.response.json().data?.delivery;',
                "    if (delivery) { pm.collectionVariables.set('delivery_id', delivery.id); }",
                '}',
            ],
        ]),
        req('GET', 'List sales order invoices (after status update)', '/sales-orders/{{sales_order_id}}/invoices', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'The invoice created by the status update above, saved into `{{invoice_id}}`.',
            'tests' => saveId('invoice_id', 'data.0.id'),
        ]),
        chg(req('POST', 'Create sales order (per-line warehouse + free gift)', '/sales-orders', [
            'description' => "Requires `sales.add`. Shows the new line options: `warehouse_id` per line (draw each line from a different warehouse — get the choices from Inventory > Product availability by warehouse), `is_gift: true` (sold at 0, no `unit_price` needed, draws only from free-gift stock received via Receive purchase order > free_gifts), and `unit_id` (order in any packaging unit of the product instead of `unit`). A non-gift line priced below the product's `min_selling_price` (per smallest unit, after discount) is a 422 on `lines.N.unit_price`. Saves the id into `{{gift_sales_order_id}}`.",
            'body' => ['customer_id' => '{{customer_id}}', 'warehouse_id' => '{{warehouse_id}}', 'pay_type' => 'cash', 'lines' => [['product_id' => '{{product_id}}', 'warehouse_id' => '{{warehouse_id}}', 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 65], ['product_id' => '{{product_id}}', 'warehouse_id' => '{{warehouse_id}}', 'qty' => 1, 'unit' => 'Carton', 'is_gift' => true]]],
            'tests' => saveId('gift_sales_order_id'),
        ]), 'lines accept `warehouse_id`, `unit_id` and `is_gift`; `unit` and `unit_price` are optional when `unit_id` / `is_gift` are used; minimum selling price is enforced.'),
        nw(req('PUT', 'Update sales order', '/sales-orders/{{gift_sales_order_id}}', [
            'description' => 'Requires `sales.edit` (+ ownership if Sales Rep). **Draft orders only** (else 422 "Only draft sales orders can be changed or deleted."). Header fields update in place; `lines`, if sent, **replace every line** and the order is re-priced and re-validated (credit limit, per-warehouse FEFO availability, minimum price). Audit-logged.',
            'body' => ['notes' => 'Updated from Postman'],
        ])),
        nw(req('DELETE', 'Delete sales order', '/sales-orders/{{gift_sales_order_id}}', [
            'description' => 'Requires `sales.delete` (Administrator by default). **Draft orders only** — a draft has no stock, invoice or journal side effects. 204; lines cascade. Anything further along: cancel it instead.',
        ])),
    ]),
    folder('Invoices', [
        req('GET', 'List invoices', '/invoices', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` or `accounting.view`. No POST — invoices only come from the sales-order status transition.',
        ]),
        req('GET', 'Get invoice', '/invoices/{{invoice_id}}', [
            'description' => 'Includes `lines_count` (lines mirrored from the originating order) and `collections_count` — paged by the two requests below.',
        ]),
        req('GET', 'List invoice lines', '/invoices/{{invoice_id}}/lines', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => "Same access as Get invoice. The originating sales order's lines. Paginated (10 per page by default) — follow `links.next`.",
        ]),
        req('GET', 'List invoice collections', '/invoices/{{invoice_id}}/collections', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Same access as Get invoice. Payments recorded against this invoice. Paginated (10 per page by default) — follow `links.next`.',
        ]),
        nw(req('POST', 'Import invoices', '/invoices/import', [
            'description' => 'Requires `sales.add`. Bulk-creates invoices from a spreadsheet. Matched by (none — invoices have no natural key, so re-importing creates duplicates). Each row posts the same journal entries a live invoice would (Dr Receivable / Cr Revenue + VAT) and raises the customer balance by what is still owed.'."\n\n".'**Multipart upload**: attach a .xlsx/.csv to the `file` field (headings = the template columns; get the template from the matching `import-template` request). All-or-nothing — one bad row rejects the whole file with a per-row error report (`errors[].row`, 1 = the heading row). Existing records (matched by the natural key) are skipped unless `update_existing=1`. In an unattended collection run (no file attached) this returns 422, which is expected.',
            'files' => ['file' => 'Spreadsheet (.xlsx or .csv, max 5 MB, max 5000 rows).'],
            'body' => ['update_existing' => false],
        ])),
        nw(req('GET', 'Download invoices import template', '/invoices/import-template', [
            'description' => 'Requires `sales.add`. An .xlsx with the exact column headings and one example row.',
        ])),
        req('POST', 'Create sales order (to void its invoice)', '/sales-orders', [
            'description' => 'A throwaway cash order whose invoice the Void request below voids (the main invoice has collections against it, so it cannot be voided).',
            'body' => ['customer_id' => '{{customer_id}}', 'warehouse_id' => '{{warehouse_id}}', 'pay_type' => 'cash', 'lines' => [['product_id' => '{{product_id}}', 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 65]]],
            'tests' => saveId('voidable_sales_order_id'),
        ]),
        req('PUT', 'Invoice that order', '/sales-orders/{{voidable_sales_order_id}}/status', [
            'description' => 'Moves it to `invoiced`, which creates the invoice.',
            'body' => ['status' => 'invoiced'],
        ]),
        req('GET', 'Find that order invoice', '/sales-orders/{{voidable_sales_order_id}}/invoices', [
            'description' => 'Saves the invoice id into `{{voidable_invoice_id}}`.',
            'tests' => saveId('voidable_invoice_id', 'data.0.id'),
        ]),
        nw(req('POST', 'Void invoice', '/invoices/{{voidable_invoice_id}}/void', [
            'description' => 'Requires `sales.delete`. Invoices are never hard-deleted — this is their "delete". Marks it `void`, posts the reversing journal entry (Dr Sales Revenue + VAT Payable / Cr Receivable) and gives the balance back to the customer. 422 if already void or if any collection was recorded against it. Returns the invoice.',
        ])),
    ]),
    folder('Deliveries', [
        req('GET', 'List deliveries', '/deliveries', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `sales.view` (+ ownership if Sales Rep).',
        ]),
        req('GET', 'Get delivery', '/deliveries/{{delivery_id}}', []),
        req('POST', 'Upload delivery invoice', '/deliveries/invoices', [
            'description' => 'Requires `sales.edit`. **Step 1 of attaching the delivered invoice** (e.g. the copy signed by the customer) — jpg/png/pdf, max 5 MB. Returns `invoice_data` (the stored path), saved into `{{delivery_invoice_data}}`, which you then send as `invoice_data` on Mark delivery delivered. Attach a file to the `invoice` field before sending — in an unattended collection run this returns 422 and the request below simply sends no file.',
            'files' => ['invoice' => 'Delivered invoice (jpg, png, pdf — max 5 MB).'],
            'tests' => saveId('delivery_invoice_data', 'data.invoice_data'),
        ]),
        req('PUT', 'Mark delivery delivered', '/deliveries/{{delivery_id}}/deliver', [
            'description' => "Requires `sales.edit` (+ ownership if Sales Rep). For marking a delivery delivered directly, separately from its order's own status transition. `invoice_data` (optional) is the path from Upload delivery invoice; a new one replaces the previous file, which is deleted. Omit it to keep the current one.",
            'body' => ['invoice_data' => '{{delivery_invoice_data}}'],
        ]),
        req('GET', 'Download delivery invoice', '/deliveries/{{delivery_id}}/invoice', [
            'description' => 'Requires `sales.view` (+ ownership if Sales Rep). File download; 404 if the delivery has no invoice file, or belongs to another tenant.',
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
            'tests' => saveId('return_id'),
        ]),
        req('GET', 'Get return', '/returns/{{return_id}}', [
            'description' => "Requires `sales.view` or `purchasing.view`. Includes nested invoice/customer/supplier/product/warehouse. 404 for another tenant's return.",
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
        req('POST', 'Upload supplier contract', '/suppliers/contracts', [
            'description' => 'Requires `purchasing.add` or `purchasing.edit`. **Step 1 of attaching a contract** — pdf/jpg/png/doc/docx, max 10 MB. Returns `contract_data` (the stored path), saved into `{{supplier_contract_data}}`, which you then send as `contract_data` on Create/Update supplier. Attach a file to the `contract` field before sending — in an unattended collection run this returns 422 and the request below simply sends no file.',
            'files' => ['contract' => 'Contract file (pdf, jpg, png, doc, docx — max 10 MB).'],
            'tests' => saveId('supplier_contract_data', 'data.contract_data'),
        ]),
        req('POST', 'Create supplier', '/suppliers', [
            'description' => 'Requires `purchasing.add`. Only `name` required. `balance` starts at 0 and moves negative as POs are received — negative means we owe them.',
            // Not one of the 3 seeded suppliers' names (spec §7) — a real
            // collision here is a 422 (unique per tenant).
            'body' => ['name' => 'Postman Demo Pharmaceutical Supplier', 'country' => 'Egypt', 'city' => 'Cairo', 'contact' => null, 'email' => null, 'phone' => null, 'pay_terms' => 'Net 30', 'currency' => 'EGP', 'rating' => null, 'status' => 'active', 'address' => null, 'contract_data' => '{{supplier_contract_data}}'],
            'tests' => saveId('supplier_id'),
        ]),
        req('GET', 'Get supplier', '/suppliers/{{supplier_id}}', [
            'description' => "Requires `purchasing.view` or `accounting.view`. 404 for another tenant's supplier.",
        ]),
        req('PUT', 'Update supplier', '/suppliers/{{supplier_id}}', [
            'description' => "Requires `purchasing.edit`, and the supplier must belong to the caller's tenant (403 otherwise). Partial update — send only the fields to change. `name` stays unique per tenant, ignoring the supplier itself. `balance` can't be set here — only receiving POs moves it. Send a new `contract_data` (from Upload supplier contract) to replace the contract — the old file is deleted — or `null` to remove it.",
            'body' => ['contact' => 'Postman Demo Contact', 'phone' => '+20 2 1234 5678', 'rating' => 4],
        ]),
        req('GET', 'Download supplier contract', '/suppliers/{{supplier_id}}/contract', [
            'description' => 'Requires `purchasing.view` or `accounting.view`. File download; 404 if the supplier has no contract, or belongs to another tenant.',
        ]),
        nw(req('POST', 'Import suppliers', '/suppliers/import', [
            'description' => 'Requires `purchasing.add`. Bulk-creates suppliers from a spreadsheet. Matched by name. Contract files cannot be imported — attach them afterwards via Upload supplier contract.'."\n\n".'**Multipart upload**: attach a .xlsx/.csv to the `file` field (headings = the template columns; get the template from the matching `import-template` request). All-or-nothing — one bad row rejects the whole file with a per-row error report (`errors[].row`, 1 = the heading row). Existing records (matched by the natural key) are skipped unless `update_existing=1`. In an unattended collection run (no file attached) this returns 422, which is expected.',
            'files' => ['file' => 'Spreadsheet (.xlsx or .csv, max 5 MB, max 5000 rows).'],
            'body' => ['update_existing' => false],
        ])),
        nw(req('GET', 'Download suppliers import template', '/suppliers/import-template', [
            'description' => 'Requires `purchasing.add`. An .xlsx with the exact column headings and one example row.',
        ])),
        ...deleteFlow('supplier', '/suppliers', ['name' => 'Postman Delete Me Supplier'], 'deletable_supplier_id', '/suppliers/{id}', 'Requires `purchasing.delete`. 204. **409** while the supplier has purchase orders or expenses; its contract file is not removed by this call. Audit-logged as DELETE.'),
    ]),
    folder('Purchase Orders', [
        req('GET', 'List purchase orders', '/purchase-orders', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
            'description' => 'Requires `purchasing.view`.',
        ]),
        req('GET', 'Get purchase order', '/purchase-orders/{{purchase_order_id}}', [
            'description' => 'Includes `lines_count` — the lines are paged by List purchase order lines.',
        ]),
        req('POST', 'Create purchase order', '/purchase-orders', [
            'description' => 'Requires `purchasing.add`. `status` starts as `draft`.',
            'body' => ['supplier_id' => '{{supplier_id}}', 'warehouse_id' => '{{warehouse_id}}', 'expected_date' => null, 'notes' => null, 'order_date' => '2026-09-19', 'lines' => [['product_id' => '{{product_id}}', 'qty_cartons' => 50, 'cost_per_carton' => 40]]],
            'tests' => saveId('purchase_order_id'),
        ]),
        req('GET', 'List purchase order lines', '/purchase-orders/{{purchase_order_id}}/lines', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => "Requires `purchasing.view`. Each line includes its `product`. Paginated (10 per page by default) — follow `links.next`. Saves the first line's id into `{{purchase_order_line_id}}` for Receive purchase order.",
            'tests' => saveId('purchase_order_line_id', 'data.0.id'),
        ]),
        req('POST', 'Upload supplier invoice', '/purchase-orders/invoices', [
            'description' => 'Requires `purchasing.approve`. **Step 1 of attaching the supplier\'s invoice** — jpg/png/pdf, max 5 MB. Returns `file_path`, saved into `{{purchase_order_invoice_path}}`, which you then send as `invoice_data.file_path` on Receive purchase order. Attach a file to the `invoice` field before sending — in an unattended collection run this returns 422 and the request below simply sends no file.',
            'files' => ['invoice' => 'Supplier invoice (jpg, png, pdf — max 5 MB).'],
            'tests' => saveId('purchase_order_invoice_path', 'data.file_path'),
        ]),
        chg(req('POST', 'Receive purchase order', '/purchase-orders/{{purchase_order_id}}/receive', [
            'description' => "Requires `purchasing.approve` (not just `add`). Every PO line needs exactly one matching `receipts` entry by `line_id` — partial receiving isn't supported. Adds stock (create-or-topup by batch_no), decreases supplier balance, posts a balanced journal entry. Rejects a second call outright — check `stock_added` on the PO first if unsure.\n\n`{{purchase_order_line_id}}` is captured automatically by List purchase order lines above — for a PO with more than one line, replace it with the specific line id you're receiving.\n\n`invoice_data` (optional) records the supplier's own invoice: `invoice_no` is required when it's sent; `invoice_date`, `amount` and `file_path` (from Upload supplier invoice) are optional. It comes back as `supplier_invoice` on the PO.",
            'body' => ['invoice_data' => ['invoice_no' => 'SUP-INV-4471', 'invoice_date' => '2026-10-05', 'amount' => '2280.00', 'file_path' => '{{purchase_order_invoice_path}}'], 'receipts' => [['line_id' => '{{purchase_order_line_id}}', 'batch_no' => 'PO-BATCH-1', 'exp_date' => '2028-01-01', 'mfg_date' => null, 'rcv_date' => null]], 'free_gifts' => [['product_id' => '{{product_id}}', 'qty' => 2, 'batch_no' => 'PO-GIFT-1', 'exp_date' => '2028-01-01']]],
        ]), 'optional `free_gifts` — bonus stock the supplier delivered on top of the order. Each entry (`product_id`, optional `unit_id`, `qty`, `batch_no`, `exp_date`, optional `mfg_date`) is added to stock at zero cost as a free-gift batch (`is_free_gift: true`, `source_po_id` = this PO); it does not change the amount owed. A gift batch number may not collide with paid stock. Only gift lines on a sales order can sell it.'),
        req('GET', 'Download supplier invoice', '/purchase-orders/{{purchase_order_id}}/invoice', [
            'description' => 'Requires `purchasing.view`. File download; 404 if the PO has no supplier invoice file, or belongs to another tenant.',
        ]),
        chg(req('POST', 'Create purchase order (ordered in packages)', '/purchase-orders', [
            'description' => 'Requires `purchasing.add`. A line can be ordered in any packaging unit of the product instead of cartons: `unit_id` + `qty` + `cost_per_unit`. Here 6 packages x 10 packets = 60 packets = **2 cartons** at 150 per carton (total 300 = 6 x 50). The quantity must come to a whole number of cartons (else 422 on `lines.N.qty`). Uses the product from Products > Create product with packaging units.',
            'body' => ['supplier_id' => '{{supplier_id}}', 'warehouse_id' => '{{warehouse_id}}', 'order_date' => '2026-09-19', 'lines' => [['product_id' => '{{unit_product_id}}', 'unit_id' => '{{unit_id_package}}', 'qty' => 6, 'cost_per_unit' => 50]]],
        ]), 'lines may use `unit_id` + `qty` + `cost_per_unit` instead of `qty_cartons` + `cost_per_carton`.'),
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
            'description' => 'Includes `orders_count`, `invoices_count` and `visits_count` — each list is paged by the three requests below.',
        ]),
        req('GET', 'List customer orders', '/customers/{{customer_id}}/orders', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `crm.view` on the customer (+ ownership if Sales Rep). Paginated (10 per page by default) — follow `links.next`.',
        ]),
        req('GET', 'List customer invoices', '/customers/{{customer_id}}/invoices', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `crm.view` on the customer (+ ownership if Sales Rep). Paginated (10 per page by default) — follow `links.next`.',
        ]),
        req('GET', 'List customer visits', '/customers/{{customer_id}}/visits', [
            'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `crm.view` on the customer (+ ownership if Sales Rep). Paginated (10 per page by default) — follow `links.next`.',
        ]),
        req('POST', 'Create customer', '/customers', [
            'description' => 'Requires `crm.add`. Only `name`/`type` required. A Sales Rep omitting `sales_rep_id` gets themselves; naming a different rep is 422.',
            'body' => ['sales_rep_id' => null, 'name' => 'Nile Valley Veterinary Clinic', 'type' => 'Clinic', 'classification' => 'B', 'phone' => null, 'email' => null, 'governorate' => null, 'province' => null, 'city' => null, 'area' => null, 'address' => null, 'credit_limit' => 50000, 'pay_terms' => null, 'status' => 'active'],
            'tests' => saveId('customer_id'),
        ]),
        nw(req('POST', 'Import customers', '/customers/import', [
            'description' => 'Requires `crm.add`. Bulk-creates customers from a spreadsheet. Matched by name. `Sales Rep Email` must be an existing user; a Sales Rep can only import under their own name.'."\n\n".'**Multipart upload**: attach a .xlsx/.csv to the `file` field (headings = the template columns; get the template from the matching `import-template` request). All-or-nothing — one bad row rejects the whole file with a per-row error report (`errors[].row`, 1 = the heading row). Existing records (matched by the natural key) are skipped unless `update_existing=1`. In an unattended collection run (no file attached) this returns 422, which is expected.',
            'files' => ['file' => 'Spreadsheet (.xlsx or .csv, max 5 MB, max 5000 rows).'],
            'body' => ['update_existing' => false],
        ])),
        nw(req('GET', 'Download customers import template', '/customers/import-template', [
            'description' => 'Requires `crm.add`. An .xlsx with the exact column headings and one example row.',
        ])),
        ...deleteFlow('customer', '/customers', ['name' => 'Postman Delete Me Customer', 'type' => 'Clinic'], 'deletable_customer_id', '/customers/{id}', 'Requires `crm.delete` (+ ownership if Sales Rep). 204. **409** while the customer has sales orders or invoices; its visits and complaints are removed with it. Audit-logged as DELETE.'),
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
            'tests' => saveId('lead_id'),
        ]),
        nw(req('PUT', 'Update lead', '/leads/{{lead_id}}', [
            'description' => 'Requires `crm.edit`. Partial update. Audit-logged with the changed fields.',
            'body' => ['status' => 'qualified', 'value' => 15000],
        ])),
        ...deleteFlow('lead', '/leads', ['name' => 'Postman Delete Me Lead', 'type' => 'Farm'], 'deletable_lead_id', '/leads/{id}', 'Requires `crm.delete`. 204.'),
        req('GET', 'Get lead', '/leads/{{lead_id}}', [
            'description' => "Requires `crm.view`. 404 for another tenant's lead.",
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
            'tests' => saveId('visit_id'),
        ]),
        nw(req('PUT', 'Update visit', '/visits/{{visit_id}}', [
            'description' => 'Requires `crm.edit`. Partial update.',
            'body' => ['outcome' => 'neutral', 'notes' => 'Updated from Postman'],
        ])),
        ...deleteFlow('visit', '/visits', ['customer_id' => '{{customer_id}}', 'visit_date' => '2026-09-21'], 'deletable_visit_id', '/visits/{id}', 'Requires `crm.delete`. 204.'),
        req('GET', 'Get visit', '/visits/{{visit_id}}', [
            'description' => "Requires `crm.view`. Includes nested customer and rep. 404 for another tenant's visit.",
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
        nw(req('PUT', 'Update complaint', '/complaints/{{complaint_id}}', [
            'description' => 'Requires `crm.edit`. Partial update of the complaint details (resolving stays a separate action — Resolve complaint).',
            'body' => ['priority' => 'high'],
        ])),
        ...deleteFlow('complaint', '/complaints', ['customer_id' => '{{customer_id}}', 'description' => 'Throwaway complaint'], 'deletable_complaint_id', '/complaints/{id}', 'Requires `crm.delete`. 204.'),
        req('GET', 'Get complaint', '/complaints/{{complaint_id}}', [
            'description' => "Requires `crm.view`. 404 for another tenant's complaint.",
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
        nw(req('PUT', 'Update campaign', '/campaigns/{{campaign_id}}', [
            'description' => 'Requires `crm.edit`. Partial update (`end_date` must not precede `start_date`). Ending a campaign stays a separate action — End campaign.',
            'body' => ['discount' => 12, 'description' => 'Updated from Postman'],
        ])),
        ...deleteFlow('campaign', '/campaigns', ['name' => 'Postman Delete Me Campaign', 'start_date' => '2026-10-01'], 'deletable_campaign_id', '/campaigns/{id}', 'Requires `crm.delete`. 204.'),
        req('GET', 'Get campaign', '/campaigns/{{campaign_id}}', [
            'description' => "Requires `crm.view`. 404 for another tenant's campaign.",
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
    req('GET', 'Get account', '/chart-of-accounts/{{expense_account_id}}', [
        'description' => "Requires `accounting.view`. Uses `{{expense_account_id}}` (account 6200), captured by Chart of accounts. 404 for another tenant's account.",
    ]),
    nw(req('POST', 'Create account', '/chart-of-accounts', [
        'description' => 'Requires `accounting.add`. `code` unique per tenant; `type` is Asset/Liability/Equity/Revenue/Expense; `parent_id` (optional, same tenant) sets `level` to parent level + 1. Saves the id into `{{new_account_id}}`.',
        'body' => ['code' => '6999', 'name' => 'Postman Demo Account', 'type' => 'Expense', 'parent_id' => null, 'active' => true],
        'tests' => saveId('new_account_id'),
    ])),
    nw(req('PUT', 'Update account', '/chart-of-accounts/{{new_account_id}}', [
        'description' => 'Requires `accounting.edit`. Rename, re-parent or deactivate. `code` and `type` are frozen (422) for system accounts (1110, 1120, 1200, 1300, 2100, 2300, 4100, 6300, 6400) and for any account that already has journal lines.',
        'body' => ['name' => 'Postman Demo Account (renamed)', 'active' => true],
    ])),
    nw(req('DELETE', 'Delete account', '/chart-of-accounts/{{new_account_id}}', [
        'description' => 'Requires `accounting.delete`. 204. **409** for system accounts, accounts with child accounts, or accounts with journal lines.',
    ])),
    req('GET', 'Journal entries', '/journal-entries', [
        'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'export' => 'csv'],
        'description' => "Requires `accounting.view`. Includes `lines_count` (lines are paged per entry by List journal entry lines). No POST — entries only come from business events auto-posting. Exported rows still summarize every line into one column.\n\nSaves the first entry's id into `{{journal_entry_id}}` for Get journal entry.",
        'tests' => saveId('journal_entry_id', 'data.0.id'),
    ]),
    req('GET', 'Get journal entry', '/journal-entries/{{journal_entry_id}}', [
        'description' => "Requires `accounting.view`. Includes `lines_count` — the lines are paged by List journal entry lines. 404 for another tenant's entry.",
    ]),
    req('GET', 'List journal entry lines', '/journal-entries/{{journal_entry_id}}/lines', [
        'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'id', 'sort_dir' => 'desc'],
        'description' => 'Requires `accounting.view`. Each line includes its `account`. Paginated (10 per page by default) — follow `links.next`.',
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
            'description' => 'Requires `expenses.edit`. Partial update. `DELETE` (needs `expenses.delete`) only works while no expense uses the category — otherwise set `active: false` to retire it; inactive categories can\'t take new expenses.',
            'body' => ['description' => 'Updated from Postman'],
        ]),
        ...deleteFlow('expense category', '/expense-categories', ['name' => 'Postman Delete Me Expense Category', 'account_id' => '{{expense_account_id}}'], 'deletable_expense_category_id', '/expense-categories/{id}', 'Requires `expenses.delete`. 204. **409** while any expense uses the category — set `active: false` instead.'),
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

$recap = nw(folder('Recap', [
    req('GET', 'Find a batch to write off', '/products/{{product_id}}/batches', [
        'query' => ['page' => 1, 'per_page' => 10, 'sort_by' => 'exp_date', 'sort_dir' => 'asc', 'filter[warehouse_id]' => '{{warehouse_id}}', 'filter[qty_cartons][gt]' => 0],
        'description' => 'Existing endpoint, used here to capture a batch id into `{{recap_batch_id}}` for the recaps below.',
        'tests' => saveId('recap_batch_id', 'data.0.id'),
    ]),
    nw(req('POST', 'Create recap', '/recaps', [
        'description' => 'Requires `recap.add` + the warehouse visible to the caller. Drafts a stock write-off (`reason`: expired, damaged, lost, recall, other). Each line names a `batch_id` (of that warehouse, no repeats) and `qty_cartons`. A draft changes no stock and posts nothing; it snapshots the batch cost so `total_value` can be reviewed. Saves the id into `{{recap_id}}`.',
        'body' => ['warehouse_id' => '{{warehouse_id}}', 'reason' => 'damaged', 'recap_date' => '2026-10-11', 'notes' => 'Damaged in transit', 'lines' => [['batch_id' => '{{recap_batch_id}}', 'qty_cartons' => 1]]],
        'tests' => saveId('recap_id'),
    ])),
    nw(req('GET', 'List recaps', '/recaps', [
        'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
        'description' => 'Requires `recap.view`. `search` matches id, notes, warehouse name. Export needs `recap.export` or `accounting.export`.',
    ])),
    nw(req('GET', 'Get recap', '/recaps/{{recap_id}}', [
        'description' => 'Requires `recap.view`. Includes the lines (batch, product, qty, cost, value). 404 for another tenant.',
    ])),
    nw(req('PUT', 'Update recap', '/recaps/{{recap_id}}', [
        'description' => 'Requires `recap.edit`. **Draft only** (else 422). `lines`, if sent, replace every line; changing `warehouse_id` requires re-sending `lines`.',
        'body' => ['notes' => 'Damaged in transit — updated'],
    ])),
    nw(req('POST', 'Approve recap', '/recaps/{{recap_id}}/approve', [
        'description' => 'Requires `recap.approve`. **This is the write-off:** locks each batch and removes the cartons (422 if a batch no longer holds enough), re-costs the lines, refreshes the warehouse stock value and posts **Dr 6300 Inventory Write-off Expense / Cr 1300 Inventory** for the total — so the loss hits the income statement and the inventory asset drops. Needs accounts 6300 and 1300 (run AccountSeeder).',
    ])),
    req('POST', 'Create recap (to reject)', '/recaps', [
        'description' => 'A throwaway draft for the Reject request below.',
        'body' => ['warehouse_id' => '{{warehouse_id}}', 'reason' => 'other', 'lines' => [['batch_id' => '{{recap_batch_id}}', 'qty_cartons' => 1]]],
        'tests' => saveId('rejectable_recap_id'),
    ]),
    nw(req('POST', 'Reject recap', '/recaps/{{rejectable_recap_id}}/reject', [
        'description' => 'Requires `recap.approve`. Draft → rejected; stock and ledger untouched. Optional `reason`.',
        'body' => ['reason' => 'Counted wrong'],
    ])),
    req('POST', 'Create recap (to delete)', '/recaps', [
        'description' => 'A throwaway draft for the Delete request below.',
        'body' => ['warehouse_id' => '{{warehouse_id}}', 'reason' => 'lost', 'lines' => [['batch_id' => '{{recap_batch_id}}', 'qty_cartons' => 1]]],
        'tests' => saveId('deletable_recap_id'),
    ]),
    nw(req('DELETE', 'Delete recap', '/recaps/{{deletable_recap_id}}', [
        'description' => 'Requires `recap.delete` (Administrator by default). **Draft only** — an approved recap already moved stock and posted a journal entry (422). 204; lines cascade.',
    ])),
    nw(req('GET', 'List expired batches', '/recaps/expired-batches', [
        'query' => ['warehouse_id' => '{{warehouse_id}}'],
        'requiredQuery' => [],
        'description' => 'Requires `recap.view`. Batches past their expiry date that still hold stock, with the value each would write off — what the generator below would pick up.',
    ])),
    nw(req('POST', 'Draft recaps from expired stock', '/recaps/from-expired', [
        'description' => 'Requires `recap.add`. Creates one **draft** recap per warehouse covering all expired batches (optionally `warehouse_id`); batches already on a draft are skipped, so it is safe to repeat. 201 with the new drafts, or 200 with an empty list when nothing new expired. The same thing runs daily from the scheduler (`php artisan recap:generate-expired`). Drafts still need approving.',
        'body' => ['warehouse_id' => null],
    ])),
], 'Stock write-offs (expired / damaged / lost / recall). Draft → approved (removes stock and posts Dr 6300 / Cr 1300) or rejected. See docs/api/recap.md.'));

$manufacturing = nw(folder('Manufacturing', [
    req('POST', 'Create finished product', '/products', [
        'description' => 'Existing endpoint — the finished good the BOM below makes. Saves the id into `{{finished_product_id}}`.',
        'body' => ['category_id' => '{{category_id}}', 'name' => 'Postman Finished Good', 'sku' => 'POSTMAN-FG-0001', 'pack_unit' => 'Bottle', 'carton_qty' => 10, 'pack_cost_price' => 10, 'pack_selling_price' => 20],
        'tests' => saveId('finished_product_id'),
    ]),
    nw(req('POST', 'Create BOM', '/boms', [
        'description' => 'Requires `manufacturing.add`. A recipe: one run consumes the listed component cartons and yields `output_qty_cartons` of the finished product, plus a per-run `conversion_cost` (labour/overhead). Components must be distinct and not the finished product. Saves the id into `{{bom_id}}`.',
        'body' => ['product_id' => '{{finished_product_id}}', 'name' => 'Postman BOM', 'output_qty_cartons' => 5, 'conversion_cost' => 10, 'notes' => null, 'active' => true, 'lines' => [['component_product_id' => '{{product_id}}', 'qty_cartons' => 1]]],
        'tests' => saveId('bom_id'),
    ])),
    nw(req('GET', 'List BOMs', '/boms', [
        'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
        'description' => 'Requires `manufacturing.view`. `search` matches name, notes, product name/SKU.',
    ])),
    nw(req('GET', 'Get BOM', '/boms/{{bom_id}}', [
        'description' => 'Requires `manufacturing.view`. Includes the finished product and component lines.',
    ])),
    nw(req('PUT', 'Update BOM', '/boms/{{bom_id}}', [
        'description' => 'Requires `manufacturing.edit`. Partial; `lines`, if sent, replace every line. Does not touch production orders already raised.',
        'body' => ['notes' => 'Updated from Postman'],
    ])),
    nw(req('POST', 'Create production order', '/production-orders', [
        'description' => 'Requires `manufacturing.add` + warehouse visible. `runs` repetitions of the BOM in a warehouse; `output_qty_cartons` and the default `conversion_cost` (BOM cost x runs) are derived. `batch_no` defaults to a generated MO-... number; `exp_date` is required. A draft touches no stock. Saves the id into `{{production_order_id}}`.',
        'body' => ['bom_id' => '{{bom_id}}', 'warehouse_id' => '{{warehouse_id}}', 'runs' => 1, 'batch_no' => 'POSTMAN-MO-1', 'production_date' => '2026-10-11', 'exp_date' => '2028-06-30', 'notes' => null],
        'tests' => saveId('production_order_id'),
    ])),
    nw(req('GET', 'List production orders', '/production-orders', [
        'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'export' => 'csv'],
        'description' => 'Requires `manufacturing.view`. Export needs `manufacturing.export` or `accounting.export`.',
    ])),
    nw(req('GET', 'Get production order', '/production-orders/{{production_order_id}}', [
        'description' => 'Requires `manufacturing.view`. After completion includes `consumptions` (which batches were used, at what cost).',
    ])),
    nw(req('PUT', 'Update production order', '/production-orders/{{production_order_id}}', [
        'description' => 'Requires `manufacturing.edit`. **Draft only** (else 422). Changing `runs` or `bom_id` re-derives the output and (unless sent) the conversion cost.',
        'body' => ['notes' => 'Updated from Postman'],
    ])),
    nw(req('POST', 'Complete production order', '/production-orders/{{production_order_id}}/complete', [
        'description' => 'Requires `manufacturing.approve`. In one transaction: consumes every component x runs from the warehouse **FEFO** (422 with `shortfalls` and nothing written if any is short), costs the output (material + conversion cost, per carton), creates the finished-goods batch, and posts Dr 1300 (finished goods) / Cr 1300 (materials used) / Cr 6400 Manufacturing Overhead Absorbed (conversion cost). 422 if the batch number already exists in the warehouse.',
    ])),
    req('POST', 'Create production order (to cancel)', '/production-orders', [
        'description' => 'A throwaway draft for the Cancel request below.',
        'body' => ['bom_id' => '{{bom_id}}', 'warehouse_id' => '{{warehouse_id}}', 'batch_no' => 'POSTMAN-MO-CANCEL', 'exp_date' => '2028-06-30'],
        'tests' => saveId('cancellable_production_order_id'),
    ]),
    nw(req('POST', 'Cancel production order', '/production-orders/{{cancellable_production_order_id}}/cancel', [
        'description' => 'Requires `manufacturing.edit`. Draft → cancelled. 422 for anything not a draft.',
    ])),
    nw(req('DELETE', 'Delete production order', '/production-orders/{{cancellable_production_order_id}}', [
        'description' => 'Requires `manufacturing.delete`. Drafts and cancelled orders only — a completed order cannot be deleted (422). 204.',
    ])),
    req('POST', 'Create BOM (to delete)', '/boms', [
        'description' => 'A throwaway BOM with no production orders, for the Delete request below.',
        'body' => ['product_id' => '{{finished_product_id}}', 'name' => 'Postman BOM Delete Me', 'lines' => [['component_product_id' => '{{product_id}}', 'qty_cartons' => 1]]],
        'tests' => saveId('deletable_bom_id'),
    ]),
    nw(req('DELETE', 'Delete BOM', '/boms/{{deletable_bom_id}}', [
        'description' => 'Requires `manufacturing.delete`. 204; lines cascade. **409** when production orders were raised from it — set `active: false` instead.',
    ])),
], 'Bills of materials and production orders. Completing an order consumes components FEFO, creates the finished batch and posts the cost. See docs/api/manufacturing.md.'));

$analytics = folder('Analytics', [
    nw(req('GET', 'Product life cycle', '/products/{{product_id}}/lifecycle', [
        'query' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
        'requiredQuery' => [],
        'description' => 'Requires `analytics.view` or `inventory.view`. One date-ordered timeline for a product: purchase order raised, received into stock, transfers between warehouses, manufacturing use/output, sales (with invoice ids), returns and write-offs, plus a `summary` (purchased / sold / transferred / written-off / produced / on-hand cartons) and the current `stock` by batch. Each event carries its source document id. `from`/`to` (optional) narrow the window. Cost amounts are returned to Administrators only.',
    ])),
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
        req('POST', 'Create user (to delete)', '/users', [
            'description' => 'A throwaway user for the Delete request below.',
            'body' => ['name' => 'Delete Me', 'email' => 'delete.me@vetpharma.com', 'password' => 'a-secure-password', 'role' => 'Warehouse Employee'],
            'tests' => saveId('deletable_user_id'),
        ]),
        nw(req('DELETE', 'Delete user', '/users/{{deletable_user_id}}', [
            'description' => 'Requires `admin.delete`. 204; the user\'s API tokens are revoked. You cannot delete your own account (422). **409** if the user owns records that must keep an owner (e.g. sales orders as the sales rep).',
        ])),
    ]),
    nw(folder('Roles & Permissions', [
        nw(req('GET', 'List permissions', '/permissions', [
            'description' => 'Requires `admin.view`. Every assignable permission grouped by module (`sales`, `inventory`, `purchasing`, `crm`, `accounting`, `expenses`, `recap`, `manufacturing`, `analytics`, `admin`) — drives the checkbox grid on a role form. Capabilities: view, add, edit, delete, approve, print, export, audit.',
        ])),
        nw(req('GET', 'List roles', '/roles', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => 'Requires `admin.view`. Each role with its `permissions` and `users_count`. Roles are global, not per tenant.',
        ])),
        nw(req('POST', 'Create role', '/roles', [
            'description' => 'Requires `admin.add`. An admin builds a role by choosing permissions — e.g. give a role `sales.edit` and `sales.delete` to let those users edit and delete sales records. `name` unique; every permission must exist (422 otherwise). Saves the id into `{{role_id}}`.',
            'body' => ['name' => 'Postman Shift Lead', 'permissions' => ['sales.view', 'sales.edit', 'sales.delete', 'crm.view']],
            'tests' => saveId('role_id'),
        ])),
        nw(req('GET', 'Get role', '/roles/{{role_id}}', [
            'description' => 'Requires `admin.view`.',
        ])),
        nw(req('PUT', 'Update role', '/roles/{{role_id}}', [
            'description' => 'Requires `admin.edit`. Rename and/or replace the full permission set (`permissions` is a sync, not a merge). The **Administrator** role is protected (403). Takes effect on the role users next request.',
            'body' => ['permissions' => ['sales.view', 'sales.edit', 'sales.delete', 'inventory.view']],
        ])),
        nw(req('PUT', 'Assign users to role', '/roles/{{role_id}}/users', [
            'description' => 'Requires `admin.edit`. Puts the listed same-tenant users on this role, **replacing** whichever role they had. (A user can also be given a role through Create/Update user.) Targets the demo user, never your own login.',
            'body' => ['user_ids' => ['{{demo_user_id}}']],
        ])),
        req('PUT', 'Put demo user back on a seeded role', '/users/{{demo_user_id}}', [
            'description' => 'Moves the demo user off the custom role so the role can be deleted below.',
            'body' => ['role' => 'Warehouse Employee'],
        ]),
        nw(req('DELETE', 'Delete role', '/roles/{{role_id}}', [
            'description' => 'Requires `admin.delete`. 204. **409** while any user still has the role; the Administrator role cannot be deleted (403).',
        ])),
    ], 'Admin-managed roles: create a role, pick its permissions (including delete/edit per module), connect users to it.')),
    folder('Audit Log', [
        req('GET', 'List audit log', '/audit-log', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc', 'module' => '', 'entity_type' => '', 'export' => 'csv'],
            'description' => "Requires `admin.audit` to view, `admin.export` to export. Most-recent-first. Includes `prev_hash`/`entry_hash` for the tamper-evident chain (spec §5.7) in both the JSON and exported shapes.\n\nSaves the first entry's id into `{{audit_log_id}}`.",
            'tests' => saveId('audit_log_id', 'data.0.id'),
        ]),
        req('GET', 'Get audit log entry', '/audit-log/{{audit_log_id}}', [
            'description' => "Requires `admin.audit`. Includes `prev_values`/`new_values`. 404 for another tenant's entry.",
        ]),
        req('POST', 'Verify audit chain integrity', '/audit-log/verify-integrity', [
            'description' => 'Requires `admin.audit`. Re-walks the whole hash chain, returns `{ intact, broken_at }`. HTTP equivalent of the `audit:verify` console command.',
        ]),
    ]),
    folder('Notifications', [
        req('GET', 'List notifications', '/notifications', [
            'query' => ['page' => 1, 'per_page' => 15, 'sort_by' => 'id', 'sort_dir' => 'desc'],
            'description' => "Any authenticated user — own inbox (personal + broadcast, `user_id: null`). Not exportable — it's a personal inbox, not business data.\n\nSaves the first notification's id into `{{notification_id}}`.",
            'tests' => saveId('notification_id', 'data.0.id'),
        ]),
        req('GET', 'Get notification', '/notifications/{{notification_id}}', [
            'description' => "Own or broadcast only — another user's notification is 404. Does not mark it read.",
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
            'tests' => saveId('discarded_action_id'),
        ]),
        req('GET', 'Get discarded action', '/discarded-actions/{{discarded_action_id}}', [
            'description' => "Requires `admin.view`. Includes the full `payload`. 404 for another tenant's action.",
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
$items = [$auth, $reference, $inventory, $crm, $purchasing, $sales, $accounting, $expenses, $recap, $manufacturing, $analytics, $admin, folder('Session end', [
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
        'description' => "Laravel rebuild of the VetPharma ERP per docs/../first.md. Base URL is `{{base_url}}` (defaults to `http://localhost:8000/api/v1` in the companion environment — the one exception is **Health check** in Auth, which uses `{{root_url}}` directly since it's unversioned and outside `/api/v1`).\n\n**Quick start:**\n1. Import the companion environment file (`VetPharma-ERP.postman_environment.json`) and select it.\n2. Run **Auth > Login** — it captures the bearer token into `{{token}}` automatically. Every other request already sends `Authorization: Bearer {{token}}`.\n3. `seed_email`/`seed_password` in the environment default to the Administrator seed account; change them (see docs/api/README.md's seed account table) to test role-specific behavior.\n4. Several \"create\" requests (warehouses, products, customers, sales orders, purchase orders, suppliers...) auto-save the created id into a collection variable (e.g. `{{warehouse_id}}`) so the next request in that folder can reference it without manual copy-paste.\n\n**Running the whole collection top to bottom** (Postman's \"Run collection\", or `newman run`): set a **~1.1s delay between requests**. The API's general rate limit is 60 requests/minute per user, and this collection has {$requestCount} requests — back-to-back with no delay, you'll get real `429`s partway through Admin. Verified end-to-end with `newman run VetPharma-ERP.postman_collection.json -e VetPharma-ERP-Local.postman_environment.json --delay-request 1100` against a freshly seeded database — 0 failures. Any single folder on its own (all well under 60 requests) is fine with no delay.\n\n**Exporting a list as CSV/Excel**: most list requests below have an `export` query param, disabled by default — enable it and set it to `csv` or `xlsx` to get a real file download instead of JSON (every matching row, not just one page). Requires `{module}.export` specifically, not just `{module}.view` — see docs/api/README.md's \"Exporting a list\" section for which roles have it.\n\n**Change flags**: anything added or altered after the original collection is marked — **🆕 NEW** = a new endpoint or folder (Recap, Manufacturing, Units, Roles & Permissions, deletes, imports, void invoice, product availability/life cycle...), **🔄 CHANGED** = an existing endpoint that now accepts or returns more (product packaging units and minimum price, purchase-order unit lines and free gifts, sales-order per-line warehouse / gift / unit). The flag is in the request name and the first line of its description. The flagged requests were added after the last full newman verification above, so run them once against a freshly migrated and seeded database (`php artisan migrate`, then `RolePermissionSeeder`, `AccountSeeder`, `UnitSeeder`, `ProductUnitSeeder`) before relying on a full unattended run; the import requests need a file attached and return 422 without one.\n\nFull request/response documentation with every error shape lives in `docs/api/*.md` — this collection is for exercising the API, not a replacement for reading those.",
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
            'return_id' => '',
            'lead_id' => '',
            'visit_id' => '',
            'journal_entry_id' => '',
            'audit_log_id' => '',
            'notification_id' => '',
            'discarded_action_id' => '',
            'purchase_order_id' => '',
            'purchase_order_line_id' => '',
            'deletable_purchase_order_id' => '',
            'complaint_id' => '',
            'campaign_id' => '',
            'expense_account_id' => '',
            'expense_category_id' => '',
            'expense_receipt_path' => '',
            'supplier_contract_data' => '',
            'purchase_order_invoice_path' => '',
            'delivery_invoice_data' => '',
            'expense_id' => '',
            'rejectable_expense_id' => '',
            'deletable_expense_id' => '',
            'unit_id_packet' => '',
            'unit_id_package' => '',
            'unit_id_carton' => '',
            'deletable_unit_id' => '',
            'unit_product_id' => '',
            'deletable_product_id' => '',
            'deletable_warehouse_id' => '',
            'deletable_category_id' => '',
            'deletable_supplier_id' => '',
            'deletable_customer_id' => '',
            'deletable_lead_id' => '',
            'deletable_visit_id' => '',
            'deletable_complaint_id' => '',
            'deletable_campaign_id' => '',
            'deletable_expense_category_id' => '',
            'new_account_id' => '',
            'gift_sales_order_id' => '',
            'voidable_sales_order_id' => '',
            'voidable_invoice_id' => '',
            'recap_batch_id' => '',
            'recap_id' => '',
            'rejectable_recap_id' => '',
            'deletable_recap_id' => '',
            'finished_product_id' => '',
            'bom_id' => '',
            'deletable_bom_id' => '',
            'production_order_id' => '',
            'cancellable_production_order_id' => '',
            'deletable_user_id' => '',
            'role_id' => '',
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
