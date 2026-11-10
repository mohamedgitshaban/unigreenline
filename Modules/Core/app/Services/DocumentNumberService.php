<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap-free, readable document numbers per company: {prefix}{counter padded}, for example INV-000123.
 * The counter row is locked while the caller's transaction is open, so two requests can never receive
 * the same number, and a rolled-back document gives its number back.
 */
class DocumentNumberService
{
    public function next(string $tenantId, string $key, string $prefix, int $padding = 6): string
    {
        return DB::transaction(function () use ($tenantId, $key, $prefix, $padding) {
            $this->ensureRow($tenantId, $key);

            $row = DB::table('document_sequences')
                ->where('tenant_id', $tenantId)->where('key', $key)
                ->lockForUpdate()->first();

            DB::table('document_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return $prefix.str_pad((string) $row->next_value, $padding, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The number the next document will get, without using it. A form can show this while it is open;
     * the real number is assigned when the document is saved.
     */
    public function peek(string $tenantId, string $key, string $prefix, int $padding = 6): string
    {
        $next = DB::table('document_sequences')->where('tenant_id', $tenantId)->where('key', $key)->value('next_value') ?? 1;

        return $prefix.str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
    }

    /**
     * Makes sure the next number is at least `$nextValue` (used when existing rows are numbered after the fact).
     */
    public function startAt(string $tenantId, string $key, int $nextValue): void
    {
        $this->ensureRow($tenantId, $key);

        DB::table('document_sequences')->where('tenant_id', $tenantId)->where('key', $key)
            ->where('next_value', '<', $nextValue)->update(['next_value' => $nextValue, 'updated_at' => now()]);
    }

    private function ensureRow(string $tenantId, string $key): void
    {
        DB::table('document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'key' => $key,
            'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
