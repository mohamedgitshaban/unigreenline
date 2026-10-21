<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap-free document numbers per company for assets and loans: {prefix}{counter padded}. The counter row is
 * locked while the caller's transaction is open, so two requests never get the same number and a rolled-back
 * document gives its number back.
 */
class AccountingNumbers
{
    public function next(string $tenantId, string $key, string $prefix, int $padding = 5): string
    {
        return DB::transaction(function () use ($tenantId, $key, $prefix, $padding) {
            DB::table('accounting_sequences')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'key' => $key,
                'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('accounting_sequences')->where('tenant_id', $tenantId)->where('key', $key)->lockForUpdate()->first();

            DB::table('accounting_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return $prefix.str_pad((string) $row->next_value, $padding, '0', STR_PAD_LEFT);
        });
    }
}
