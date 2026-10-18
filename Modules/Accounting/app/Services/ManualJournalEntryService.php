<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\JournalEntryNotReversibleException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\AuditLogService;

/**
 * Entries typed by an accountant — opening balances, capital, fixed assets,
 * depreciation, other income, corrections — and their reversal. A posted
 * entry is never edited or deleted: a mistake is cancelled by reversing it,
 * which posts the mirror image and marks the original reversed. Only manual
 * entries can be reversed here; an entry a business flow created (an invoice,
 * a receipt...) is undone by undoing that document (void the invoice, etc.),
 * otherwise the document and the ledger would disagree.
 */
class ManualJournalEntryService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{entry_date: string, description: string, ref?: ?string, lines: array<int, array{account_id: string, debit?: float|string|null, credit?: float|string|null}>}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(string $tenantId, array $data, array $actor = []): JournalEntry
    {
        $codes = Account::query()->where('tenant_id', $tenantId)->whereIn('id', array_column($data['lines'], 'account_id'))->pluck('code', 'id');

        $lines = array_map(fn (array $line) => array_filter([
            'account_code' => $codes[$line['account_id']],
            'debit' => round((float) ($line['debit'] ?? 0), 2) ?: null,
            'credit' => round((float) ($line['credit'] ?? 0), 2) ?: null,
        ], fn ($value) => $value !== null), $data['lines']);

        return DB::transaction(function () use ($tenantId, $data, $lines, $actor) {
            $entry = $this->journalPosting->post(
                $tenantId,
                $data['ref'] ?? 'MANUAL',
                $data['description'],
                $lines,
                $actor['actor_id'] ?? null,
                Carbon::parse($data['entry_date']),
                'manual',
            );

            $this->record($entry, 'INSERT', null, ['description' => $entry->description, 'lines' => count($lines)], $actor);

            return $entry;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reverse(JournalEntry $entry, ?string $entryDate = null, array $actor = []): JournalEntry
    {
        return DB::transaction(function () use ($entry, $entryDate, $actor) {
            $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== 'manual') {
                throw new JournalEntryNotReversibleException('Only manual journal entries can be reversed. An entry created by a business flow is undone by undoing its document (for example, void the invoice).');
            }

            if ($locked->reversed_at !== null) {
                throw new JournalEntryNotReversibleException('This journal entry has already been reversed.');
            }

            $lines = $locked->lines()->get()->map(fn ($line) => array_filter([
                'account_code' => $line->account_code,
                // Mirror image: what was debited is credited and vice versa.
                'debit' => (float) $line->credit ?: null,
                'credit' => (float) $line->debit ?: null,
            ], fn ($value) => $value !== null))->all();

            $reversal = $this->journalPosting->post(
                $locked->tenant_id,
                $locked->ref ?? 'MANUAL',
                "Reversal of: {$locked->description}",
                $lines,
                $actor['actor_id'] ?? null,
                Carbon::parse($entryDate ?? now()->toDateString()),
                'reversal',
                $locked->id,
            );

            $locked->update(['reversed_at' => now()]);

            $this->record($reversal, 'INSERT', null, ['reversal_of' => $locked->id], $actor);

            return $reversal;
        });
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(JournalEntry $entry, string $operation, ?array $previous, ?array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'accounting',
            'entity_type' => 'JournalEntry',
            'entity_id' => $entry->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $entry->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
