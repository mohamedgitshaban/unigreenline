<?php

namespace Modules\Core\Support;

/**
 * Translates the original coarse permissions (`{module}.{capability}`, e.g.
 * `expenses.view` meaning "everything view-like in Expenses") into the
 * per-endpoint permissions (`{resource}.{action}`, e.g. `expense-categories.view`).
 *
 * Used twice: by RolePermissionSeeder, whose seeded roles are still described
 * on the coarse grid, and by the one-off data migration that converts the
 * roles of an existing database. The translation also reproduces what the old
 * code allowed implicitly — for instance `accounting.view` used to be
 * accepted on the supplier and invoice lists — via IMPLIED, so nobody loses
 * (or gains) access in the switch.
 */
class LegacyPermissionMap
{
    public const MODULES = ['sales', 'inventory', 'purchasing', 'crm', 'accounting', 'expenses', 'recap', 'manufacturing', 'analytics', 'hr', 'admin'];

    public const CAPABILITIES = ['view', 'add', 'edit', 'delete', 'approve', 'print', 'export', 'audit'];

    /**
     * What an action needs from the old capability grid. `a|b` = either,
     * `a&b` = both, absent = never granted by the grid (explicit grant only).
     *
     * @var array<string, string>
     */
    private const ACTION_CAPABILITY = [
        'view' => 'view', 'availability' => 'view', 'lifecycle' => 'view',
        'create' => 'add', 'import' => 'add', 'generate' => 'add',
        'update' => 'edit', 'cancel' => 'edit', 'change-status' => 'edit', 'deliver' => 'edit', 'resolve' => 'edit',
        'end' => 'edit', 'assign-users' => 'edit', 'reverse' => 'edit', 'recalculate' => 'edit', 'check-in' => 'add', 'check-out' => 'add',
        'delete' => 'delete', 'void' => 'delete',
        'export' => 'export',
        'approve' => 'approve', 'reject' => 'approve', 'receive' => 'approve', 'complete' => 'approve', 'pay' => 'approve',
        'upload-contract' => 'add|edit', 'upload-receipt' => 'add',
        'upload-invoice' => 'edit', 'quick-deliver' => 'add&edit',
        'verify' => 'audit',
        // Manufacturing: moving an order through its life, shop-floor execution, planning.
        'confirm' => 'approve', 'reopen' => 'approve', 'convert' => 'approve', 'archive' => 'delete',
        'plan' => 'edit', 'reserve-materials' => 'edit', 'release-materials' => 'edit', 'start' => 'edit', 'pause' => 'edit',
        'resume' => 'edit', 'record-production' => 'edit', 'consume-materials' => 'edit', 'record-scrap' => 'edit',
        'reschedule' => 'edit', 'assign' => 'edit', 'restore' => 'edit', 'revise' => 'edit', 'dismiss' => 'edit', 'record' => 'edit',
        'create-backorder' => 'add', 'from-sales-order' => 'add', 'run' => 'add',
        'traceability' => 'view', 'history' => 'view', 'schedule' => 'view',
        'costs' => 'audit',
    ];

    /**
     * Single permissions whose capability differs from their action's usual one.
     *
     * @var array<string, string>
     */
    private const PERMISSION_CAPABILITY = [
        'users.view' => 'audit',
        'audit-log.view' => 'audit',
        'purchase-orders.upload-invoice' => 'approve',
        // What things cost in production is for the people who audit or sign off, like other cost figures.
        'manufacturing-cost-reports.view' => 'audit',
        'manufacturing-cost-reports.export' => 'audit',
        // Changes how production is costed and which moves are allowed: never given by the grid, only granted by name.
        'manufacturing-settings.update' => 'manage',
    ];

    /**
     * Old permission => new permissions it also used to unlock through an OR
     * in a policy.
     *
     * @var array<string, array<int, string>>
     */
    private const IMPLIED = [
        'accounting.view' => ['suppliers.view', 'invoices.view', 'collections.view', 'ar-aging.view', 'ap-aging.view', 'supplier-payments.view'],
        'accounting.add' => ['collections.create', 'supplier-payments.create'],
        'accounting.export' => ['suppliers.export', 'invoices.export', 'collections.export', 'expenses.export', 'recaps.export', 'supplier-payments.export'],
        'sales.view' => ['units.view', 'products.availability'],
        'purchasing.view' => ['units.view', 'returns.view'],
        'purchasing.add' => ['returns.create'],
        'purchasing.pricing' => ['purchase-orders.pricing'],
    ];

    /**
     * @param  array<int, string>  $legacy  Old-style names such as `sales.view`.
     * @return array<int, string> New-style names, sorted, only ones that exist.
     */
    public static function expand(array $legacy): array
    {
        $held = array_flip($legacy);
        $granted = [];

        foreach (PermissionCatalogue::all() as $entry) {
            $spec = self::PERMISSION_CAPABILITY[$entry['permission']] ?? self::ACTION_CAPABILITY[$entry['action']] ?? null;

            if ($spec !== null && self::satisfied($spec, $entry['module'], $held)) {
                $granted[$entry['permission']] = true;
            }
        }

        foreach ($legacy as $name) {
            foreach (self::IMPLIED[$name] ?? [] as $implied) {
                $granted[$implied] = true;
            }
        }

        $known = array_flip(PermissionCatalogue::names());
        $granted = array_intersect_key($granted, $known);

        ksort($granted);

        return array_keys($granted);
    }

    /**
     * Whether a stored permission name is an old coarse one.
     */
    public static function isLegacy(string $name): bool
    {
        if ($name === 'purchasing.pricing') {
            return true;
        }

        [$module, $capability] = array_pad(explode('.', $name, 2), 2, '');

        return in_array($module, self::MODULES, true) && in_array($capability, self::CAPABILITIES, true);
    }

    /**
     * @param  array<string, int>  $held  Old names held, as keys.
     */
    private static function satisfied(string $spec, string $module, array $held): bool
    {
        $has = fn (string $capability) => isset($held["{$module}.{$capability}"]);

        if (str_contains($spec, '&')) {
            return collect(explode('&', $spec))->every($has);
        }

        return collect(explode('|', $spec))->contains($has);
    }
}
