<?php

namespace Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\AccountingRuleException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AssetCategory;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\FixedAssetDepreciation;
use Modules\Accounting\Support\DepreciationScheduleCalculator;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Throwable;

/**
 * The life of a fixed asset: registering it, planning its depreciation, booking each period, changing the plan, pausing,
 * and selling or scrapping it. Every posting goes through {@see JournalPostingService}; every change leaves an audit entry.
 */
class FixedAssetService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly AuditLogService $auditLog,
        private readonly AccountingNumbers $numbers,
    ) {}

    /**
     * Registers an asset as a draft and plans its depreciation. Anything not given comes from its category.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($data, $actor) {
            $category = AssetCategory::query()->where('tenant_id', $actor->tenant_id)->findOrFail($data['category_id']);
            $original = round((float) $data['original_value'], 2);

            $asset = new FixedAsset([
                'tenant_id' => $actor->tenant_id,
                'number' => $this->numbers->next($actor->tenant_id, 'fixed_asset', 'FA'),
                'name' => $data['name'],
                'category_id' => $category->id,
                'status' => 'draft',
                'acquisition_date' => $data['acquisition_date'],
                'depreciation_start_date' => $data['depreciation_start_date'] ?? $data['acquisition_date'],
                'original_value' => $original,
                'salvage_value' => round((float) ($data['salvage_value'] ?? $original * (float) $category->salvage_pct / 100), 2),
                'method' => $data['method'] ?? $category->method,
                'duration_periods' => $data['duration_periods'] ?? $category->duration_periods,
                'period_months' => $data['period_months'] ?? $category->period_months,
                'declining_factor' => $data['declining_factor'] ?? $category->declining_factor,
                'prorata' => $data['prorata'] ?? $category->prorata,
                'auto_post' => $data['auto_post'] ?? $category->auto_post,
                'asset_account_code' => $data['asset_account_code'] ?? $category->asset_account_code,
                'accumulated_account_code' => $data['accumulated_account_code'] ?? $category->accumulated_account_code,
                'expense_account_code' => $data['expense_account_code'] ?? $category->expense_account_code,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->assertSalvageBelowCost($asset);
            $asset->save();
            $this->planFromScratch($asset);

            $this->audit($actor, $asset, 'INSERT', null, ['number' => $asset->number, 'name' => $asset->name, 'original_value' => (string) $asset->original_value]);

            return $asset->refresh();
        });
    }

    /**
     * A draft can change in every respect and gets its plan redrawn; a running asset only its descriptive details.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FixedAsset $asset, array $data, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $descriptive = ['name', 'reference', 'serial_number', 'location', 'assigned_user_id', 'notes', 'supplier_id', 'auto_post'];
            $financial = ['category_id', 'acquisition_date', 'depreciation_start_date', 'original_value', 'salvage_value', 'method', 'duration_periods', 'period_months', 'declining_factor', 'prorata', 'asset_account_code', 'accumulated_account_code', 'expense_account_code'];

            if ($locked->status !== 'draft' && array_intersect_key($data, array_flip($financial)) !== []) {
                throw new AccountingRuleException('Only a draft asset can have its value, dates or depreciation settings changed. Use "modify" to change the plan of a running asset.');
            }

            if (in_array($locked->status, ['sold', 'disposed', 'cancelled'], true) && array_intersect_key($data, array_flip(['auto_post'])) !== []) {
                throw new AccountingRuleException('This asset is no longer depreciated.');
            }

            $previous = $locked->only(array_keys($data));
            $locked->fill(array_intersect_key($data, array_flip([...$descriptive, ...$financial])));
            $dirty = $locked->getDirty();

            if ($locked->status === 'draft') {
                $this->assertSalvageBelowCost($locked);
            }

            $locked->save();

            if ($locked->status === 'draft' && array_intersect_key($dirty, array_flip($financial)) !== []) {
                $this->planFromScratch($locked);
            }

            if ($dirty !== []) {
                $this->audit($actor, $locked, 'UPDATE', array_intersect_key($previous, $dirty), $dirty);
            }

            return $locked->refresh();
        });
    }

    /**
     * What the depreciation board would be for these figures, without saving anything.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function preview(array $data, User $actor): array
    {
        $category = isset($data['category_id']) ? AssetCategory::query()->where('tenant_id', $actor->tenant_id)->find($data['category_id']) : null;
        $original = round((float) $data['original_value'], 2);

        return DepreciationScheduleCalculator::lines([
            'original_value' => $original,
            'salvage_value' => $data['salvage_value'] ?? ($category ? $original * (float) $category->salvage_pct / 100 : 0),
            'method' => $data['method'] ?? $category?->method ?? 'straight_line',
            'duration_periods' => $data['duration_periods'] ?? $category?->duration_periods ?? 60,
            'period_months' => $data['period_months'] ?? $category?->period_months ?? 1,
            'declining_factor' => $data['declining_factor'] ?? $category?->declining_factor ?? 0.3,
            'prorata' => $data['prorata'] ?? $category?->prorata ?? false,
            'start_date' => $data['depreciation_start_date'] ?? $data['acquisition_date'],
        ]);
    }

    /**
     * Puts a draft asset on the books. If `$acquisitionAccountCode` is given the purchase is booked too
     * (Dr the asset account, Cr that account — cash, bank or payables); leave it out when the cost was booked already.
     */
    public function activate(FixedAsset $asset, ?string $acquisitionAccountCode, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $acquisitionAccountCode, $actor) {
            $locked = $this->lock($asset);

            if ($locked->status !== 'draft') {
                throw new AccountingRuleException('Only a draft asset can be put on the books.');
            }

            $this->assertAccountsExist($locked->tenant_id, [$locked->asset_account_code, $locked->accumulated_account_code, $locked->expense_account_code, ...($acquisitionAccountCode ? [$acquisitionAccountCode] : [])]);

            if (! $locked->depreciations()->exists()) {
                $this->planFromScratch($locked);
            }

            $entry = null;

            if ($acquisitionAccountCode !== null && (float) $locked->original_value > 0) {
                $entry = $this->journal->post(
                    $locked->tenant_id, $locked->id, "Acquisition of asset {$locked->number} {$locked->name}",
                    [
                        ['account_code' => $locked->asset_account_code, 'debit' => (float) $locked->original_value],
                        ['account_code' => $acquisitionAccountCode, 'credit' => (float) $locked->original_value],
                    ],
                    $actor->id, $locked->acquisition_date,
                );
            }

            $locked->forceFill([
                'status' => $this->plannedLeft($locked) ? 'running' : 'fully_depreciated',
                'activated_at' => now(),
                'acquisition_journal_entry_id' => $entry?->id,
            ])->save();

            $this->audit($actor, $locked, 'APPROVE', ['status' => 'draft'], ['status' => $locked->status, 'journal_entry_id' => $entry?->id]);

            return $locked->refresh();
        });
    }

    /**
     * Books one period's depreciation (Dr expense, Cr accumulated depreciation). Lines go in order and only once due.
     */
    public function postLine(FixedAsset $asset, FixedAssetDepreciation $line, User $actor, ?DateTimeInterface $today = null): FixedAssetDepreciation
    {
        return DB::transaction(function () use ($asset, $line, $actor, $today) {
            $locked = $this->lock($asset);
            $fresh = FixedAssetDepreciation::query()->whereKey($line->id)->where('fixed_asset_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paused') {
                throw new AccountingRuleException('This asset is paused. Resume it to book depreciation.');
            }

            if (! in_array($locked->status, ['running', 'fully_depreciated'], true)) {
                throw new AccountingRuleException('Depreciation can only be booked for an asset that is running.');
            }

            if ($fresh->status === 'posted') {
                throw new AccountingRuleException('This depreciation has already been booked.');
            }

            if ($fresh->depreciation_date->gt(CarbonImmutable::parse($today ?? now())->startOfDay())) {
                throw new AccountingRuleException("This depreciation is not due until {$fresh->depreciation_date->toDateString()}.");
            }

            if ($locked->depreciations()->where('status', 'planned')->where('sequence', '<', $fresh->sequence)->exists()) {
                throw new AccountingRuleException('Earlier depreciation lines have to be booked first.');
            }

            $this->book($locked, $fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Books every depreciation that is due on or before `$upTo` for running assets of the company. Assets that fail are
     * skipped and reported; the others are not held up. With `$onlyAutomatic` assets set to be posted by hand are left alone.
     *
     * @return array{lines: int, assets: int, total: float, failed: array<int, array{asset: string, message: string}>}
     */
    public function depreciateDue(string $tenantId, DateTimeInterface $upTo, ?User $actor = null, bool $onlyAutomatic = false): array
    {
        $upTo = CarbonImmutable::parse($upTo)->startOfDay();
        $result = ['lines' => 0, 'assets' => 0, 'total' => 0.0, 'failed' => []];

        $assets = FixedAsset::query()->where('tenant_id', $tenantId)->where('status', 'running')
            ->when($onlyAutomatic, fn ($query) => $query->where('auto_post', true))
            ->whereHas('depreciations', fn ($query) => $query->where('status', 'planned')->whereDate('depreciation_date', '<=', $upTo->toDateString()))
            ->orderBy('number')->get();

        foreach ($assets as $asset) {
            try {
                [$posted, $amount] = DB::transaction(function () use ($asset, $upTo, $actor) {
                    $locked = $this->lock($asset);
                    $posted = 0;
                    $amount = 0.0;

                    foreach ($locked->depreciations()->where('status', 'planned')->whereDate('depreciation_date', '<=', $upTo->toDateString())->lockForUpdate()->get() as $line) {
                        $this->book($locked, $line, $actor);
                        $posted++;
                        $amount = round($amount + (float) $line->amount, 2);
                    }

                    return [$posted, $amount];
                });

                $result['lines'] += $posted;
                $result['assets']++;
                $result['total'] = round($result['total'] + $amount, 2);
            } catch (Throwable $e) {
                report($e);
                $result['failed'][] = ['asset' => $asset->number, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Redraws the rest of the plan of a running asset: what is still to depreciate (its book value less the new salvage value)
     * is spread over `remaining_periods` periods starting after the last booked one.
     *
     * @param  array{remaining_periods: int, salvage_value?: float|string, method?: string, declining_factor?: float|string}  $changes
     */
    public function modify(FixedAsset $asset, array $changes, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $changes, $actor) {
            $locked = $this->lock($asset);

            if (! in_array($locked->status, ['running', 'paused', 'fully_depreciated'], true)) {
                throw new AccountingRuleException('Only an asset that is on the books can have its depreciation plan modified.');
            }

            $previous = $locked->only(['salvage_value', 'method', 'declining_factor', 'duration_periods']);
            $salvage = round((float) ($changes['salvage_value'] ?? $locked->salvage_value), 2);

            if ($salvage > $locked->bookValue()) {
                throw new AccountingRuleException('The salvage value cannot be more than what the asset is worth now.');
            }

            $locked->forceFill([
                'salvage_value' => $salvage,
                'method' => $changes['method'] ?? $locked->method,
                'declining_factor' => $changes['declining_factor'] ?? $locked->declining_factor,
            ]);

            $this->replan($locked, (int) $changes['remaining_periods'], null);

            if ($locked->status === 'fully_depreciated' && $this->plannedLeft($locked)) {
                $locked->status = 'running';
            }

            $locked->save();
            $this->audit($actor, $locked, 'UPDATE', $previous, $locked->only(array_keys($previous)));

            return $locked->refresh();
        });
    }

    public function pause(FixedAsset $asset, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor) {
            $locked = $this->lock($asset);

            if ($locked->status !== 'running') {
                throw new AccountingRuleException('Only a running asset can be paused.');
            }

            $locked->forceFill(['status' => 'paused', 'paused_at' => now()])->save();
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'running'], ['status' => 'paused']);

            return $locked->refresh();
        });
    }

    /**
     * Takes a paused asset back: the periods not yet booked are planned again from the month of `$date`.
     */
    public function resume(FixedAsset $asset, DateTimeInterface $date, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $date, $actor) {
            $locked = $this->lock($asset);

            if ($locked->status !== 'paused') {
                throw new AccountingRuleException('Only a paused asset can be resumed.');
            }

            $left = $locked->depreciations()->where('status', 'planned')->count();
            $locked->forceFill(['status' => $left > 0 ? 'running' : 'fully_depreciated', 'paused_at' => null]);

            if ($left > 0) {
                $this->replan($locked, $left, CarbonImmutable::parse($date)->startOfMonth());
            }

            $locked->save();
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'paused'], ['status' => $locked->status]);

            return $locked->refresh();
        });
    }

    /**
     * Sells (with `sale_value` > 0) or scraps the asset. Depreciation due up to the date is booked first and the periods
     * after it are dropped; then the asset leaves the books: its cost and accumulated depreciation are cleared, the
     * proceeds come in, and the difference with the book value is a gain or a loss.
     *
     * @param  array{date: string, sale_value?: float|string, proceeds_account_code?: ?string, reason?: ?string}  $data
     */
    public function dispose(FixedAsset $asset, array $data, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $locked = $this->lock($asset);
            $date = CarbonImmutable::parse($data['date'])->startOfDay();
            $sale = round((float) ($data['sale_value'] ?? 0), 2);

            if (! $locked->isLive()) {
                throw new AccountingRuleException('Only an asset that is on the books can be sold or scrapped.');
            }

            if ($date->lt($locked->acquisition_date)) {
                throw new AccountingRuleException('An asset cannot be disposed of before it was acquired.');
            }

            if ($sale > 0 && empty($data['proceeds_account_code'])) {
                throw new AccountingRuleException('Say which account the proceeds go to (cash, bank or receivables).');
            }

            $this->assertAccountsExist($locked->tenant_id, array_filter([$locked->asset_account_code, $locked->accumulated_account_code, '4400', '6700', $sale > 0 ? $data['proceeds_account_code'] : null]));

            foreach ($locked->depreciations()->where('status', 'planned')->whereDate('depreciation_date', '<=', $date->toDateString())->lockForUpdate()->get() as $line) {
                $this->book($locked, $line, $actor);
            }

            $locked->depreciations()->where('status', 'planned')->delete();
            $locked->refresh();

            $original = (float) $locked->original_value;
            $accumulated = (float) $locked->accumulated_depreciation;
            $book = round($original - $accumulated, 2);
            $loss = round(max($book - $sale, 0), 2);
            $gain = round(max($sale - $book, 0), 2);

            $lines = array_values(array_filter([
                ['account_code' => $locked->accumulated_account_code, 'debit' => $accumulated],
                ['account_code' => $data['proceeds_account_code'] ?? '', 'debit' => $sale],
                ['account_code' => '6700', 'debit' => $loss],
                ['account_code' => $locked->asset_account_code, 'credit' => $original],
                ['account_code' => '4400', 'credit' => $gain],
            ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0));

            $entry = $this->journal->post(
                $locked->tenant_id, $locked->id, ($sale > 0 ? 'Sale' : 'Disposal')." of asset {$locked->number} {$locked->name}",
                $lines, $actor->id, $date,
            );

            $locked->forceFill([
                'status' => $sale > 0 ? 'sold' : 'disposed',
                'disposal_date' => $date,
                'disposal_value' => $sale,
                'disposal_reason' => $data['reason'] ?? null,
                'disposal_journal_entry_id' => $entry->id,
            ])->save();

            $this->audit($actor, $locked, 'UPDATE', ['status' => 'running'], ['status' => $locked->status, 'disposal_value' => (string) $sale, 'gain' => (string) $gain, 'loss' => (string) $loss]);

            return $locked->refresh();
        });
    }

    public function cancel(FixedAsset $asset, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor) {
            $locked = $this->lock($asset);

            if ($locked->status !== 'draft') {
                throw new AccountingRuleException('Only a draft asset can be cancelled. An asset on the books is sold or scrapped instead.');
            }

            $locked->depreciations()->delete();
            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'draft'], ['status' => 'cancelled']);

            return $locked->refresh();
        });
    }

    /**
     * Deletes a draft or cancelled asset.
     */
    public function delete(FixedAsset $asset, User $actor): void
    {
        DB::transaction(function () use ($asset, $actor) {
            $locked = $this->lock($asset);

            if (! in_array($locked->status, ['draft', 'cancelled'], true)) {
                throw new AccountingRuleException('An asset that has been on the books cannot be deleted.');
            }

            $snapshot = ['number' => $locked->number, 'name' => $locked->name];
            $locked->delete();
            $this->audit($actor, $locked, 'DELETE', $snapshot, null);
        });
    }

    private function lock(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::query()->where('tenant_id', $asset->tenant_id)->whereKey($asset->id)->lockForUpdate()->firstOrFail();
    }

    private function plannedLeft(FixedAsset $asset): bool
    {
        return $asset->depreciations()->where('status', 'planned')->exists();
    }

    /**
     * Books a planned line: the journal entry, the line and the asset's running total.
     */
    private function book(FixedAsset $asset, FixedAssetDepreciation $line, ?User $actor): void
    {
        $entry = (float) $line->amount > 0
            ? $this->journal->post(
                $asset->tenant_id, $asset->id, "Depreciation of {$asset->number} {$asset->name} to {$line->depreciation_date->toDateString()}",
                [
                    ['account_code' => $asset->expense_account_code, 'debit' => (float) $line->amount],
                    ['account_code' => $asset->accumulated_account_code, 'credit' => (float) $line->amount],
                ],
                $actor?->id, $line->depreciation_date,
            )
            : null;

        $line->forceFill(['status' => 'posted', 'journal_entry_id' => $entry?->id, 'posted_at' => now(), 'posted_by' => $actor?->id])->save();

        $asset->forceFill(['accumulated_depreciation' => round((float) $asset->accumulated_depreciation + (float) $line->amount, 2)]);

        if (! $asset->depreciations()->where('status', 'planned')->where('id', '!=', $line->id)->exists() && $asset->status === 'running') {
            $asset->status = 'fully_depreciated';
        }

        $asset->save();

        $this->audit($actor, $asset, 'UPDATE', null, ['depreciation' => (string) $line->amount, 'date' => $line->depreciation_date->toDateString(), 'accumulated_depreciation' => (string) $asset->accumulated_depreciation]);
    }

    /**
     * Writes the whole plan of a draft asset.
     */
    private function planFromScratch(FixedAsset $asset): void
    {
        $asset->depreciations()->delete();

        $lines = DepreciationScheduleCalculator::lines([
            'original_value' => $asset->original_value, 'salvage_value' => $asset->salvage_value, 'method' => $asset->method,
            'duration_periods' => $asset->duration_periods, 'period_months' => $asset->period_months,
            'declining_factor' => $asset->declining_factor, 'prorata' => $asset->prorata, 'start_date' => $asset->depreciation_start_date,
        ]);

        foreach ($lines as $line) {
            $asset->depreciations()->create($line + ['tenant_id' => $asset->tenant_id, 'status' => 'planned']);
        }
    }

    /**
     * Replaces the unbooked lines with a fresh plan spreading what is left over `$periods` periods, starting the day
     * after the last booked line (or at `$from` / the asset's start when nothing is booked).
     */
    private function replan(FixedAsset $asset, int $periods, ?CarbonImmutable $from): void
    {
        $asset->depreciations()->where('status', 'planned')->delete();

        $last = $asset->depreciations()->where('status', 'posted')->reorder('sequence', 'desc')->first();
        $next = $last ? CarbonImmutable::parse($last->depreciation_date)->addDay() : null;
        $start = match (true) {
            $from !== null && $next !== null => $from->max($next),
            $from !== null => $from,
            $next !== null => $next,
            default => CarbonImmutable::parse($asset->depreciation_start_date),
        };
        $accumulated = (float) $asset->accumulated_depreciation;

        $lines = DepreciationScheduleCalculator::lines([
            'original_value' => $asset->bookValue(), 'salvage_value' => $asset->salvage_value, 'method' => $asset->method,
            'duration_periods' => $periods, 'period_months' => $asset->period_months, 'declining_factor' => $asset->declining_factor,
            'prorata' => $last === null && $from === null ? $asset->prorata : false, 'start_date' => $start,
        ]);

        $sequence = (int) ($last?->sequence ?? 0);

        foreach ($lines as $line) {
            $sequence++;
            $total = round($accumulated + $line['accumulated_after'], 2);

            $asset->depreciations()->create([
                'tenant_id' => $asset->tenant_id, 'sequence' => $sequence, 'period_start' => $line['period_start'], 'depreciation_date' => $line['depreciation_date'],
                'amount' => $line['amount'], 'accumulated_after' => $total, 'book_value_after' => round((float) $asset->original_value - $total, 2), 'status' => 'planned',
            ]);
        }

        $asset->duration_periods = (int) $asset->depreciations()->where('status', 'posted')->count() + count($lines);
    }

    private function assertSalvageBelowCost(FixedAsset $asset): void
    {
        if ((float) $asset->salvage_value > (float) $asset->original_value) {
            throw new AccountingRuleException('The salvage value cannot be more than the original value.');
        }
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function assertAccountsExist(string $tenantId, array $codes): void
    {
        $codes = array_values(array_unique($codes));
        $found = Account::query()->where('tenant_id', $tenantId)->whereIn('code', $codes)->pluck('code')->all();
        $missing = array_diff($codes, $found);

        if ($missing !== []) {
            throw new AccountingRuleException('These accounts do not exist in the chart of accounts: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function audit(?User $actor, FixedAsset $asset, string $operation, ?array $previous, ?array $new): void
    {
        $this->auditLog->record([
            'module' => 'accounting',
            'entity_type' => 'FixedAsset',
            'entity_id' => $asset->id,
            'operation' => $operation,
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'tenant_id' => $asset->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
        ]);
    }
}
