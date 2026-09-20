<?php

namespace Modules\Accounting\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use Modules\Accounting\Exceptions\UnknownAccountCodeException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;

/**
 * Auto-posts balanced double-entry journal entries (spec §5.3). Business
 * events call post() with account codes, not account ids — the codes in
 * config('accounting.seed_codes') are the contract auto-posting logic
 * depends on staying stable.
 */
class JournalPostingService
{
    /**
     * @param  array<int, array{account_code: string, debit?: float, credit?: float}>  $lines
     */
    public function post(
        string $tenantId,
        string $ref,
        string $description,
        array $lines,
        ?string $createdBy = null,
        ?DateTimeInterface $entryDate = null,
    ): JournalEntry {
        $totalDebits = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredits = round(array_sum(array_column($lines, 'credit')), 2);

        if ($totalDebits !== $totalCredits) {
            throw new UnbalancedJournalEntryException($totalDebits, $totalCredits);
        }

        return DB::transaction(function () use ($tenantId, $ref, $description, $lines, $createdBy, $entryDate) {
            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'created_by' => $createdBy,
                'ref' => $ref,
                'description' => $description,
                'entry_date' => $entryDate ?? now(),
                'posted' => true,
            ]);

            foreach ($lines as $line) {
                $account = Account::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $line['account_code'])
                    ->first();

                if (! $account) {
                    throw new UnknownAccountCodeException($line['account_code']);
                }

                $debit = $line['debit'] ?? 0;
                $credit = $line['credit'] ?? 0;

                $entry->lines()->create([
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'debit' => $debit,
                    'credit' => $credit,
                ]);

                $account->applyPosting($debit, $credit);
            }

            return $entry->load('lines');
        });
    }
}
