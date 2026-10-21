<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Models\Loan;
use Modules\Core\Models\Notification;
use Modules\Core\Models\User;

/**
 * Tells the people who pay loans which instalments are due soon or late: once a day per loan, to whoever agreed the loan
 * and everyone who may pay instalments.
 */
class RemindLoanInstallments extends Command
{
    protected $signature = 'loans:remind {--days=7 : How many days ahead to warn}';

    protected $description = 'Notify about loan instalments that are due soon or overdue';

    public function handle(): int
    {
        $until = today()->addDays((int) $this->option('days'));
        $told = 0;

        Loan::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', today()))
            ->whereHas('installments', fn ($q) => $q->where('status', 'planned')->whereDate('due_date', '<=', $until->toDateString()))
            ->each(function (Loan $loan) use ($until, &$told) {
                $due = $loan->installments()->where('status', 'planned')->whereDate('due_date', '<=', $until->toDateString())->orderBy('sequence')->get();
                $late = $due->filter(fn ($i) => $i->due_date->lt(today()));
                $amount = number_format((float) $due->sum('payment_amount'), 2);
                $verb = $loan->kind === 'borrowed' ? 'to pay' : 'to collect';

                $recipients = User::query()->where('tenant_id', $loan->tenant_id)->where('status', 'active')->get()
                    ->filter(fn (User $user) => $user->id === $loan->created_by || $user->can('loans.pay-installment'));

                foreach ($recipients as $user) {
                    Notification::create([
                        'tenant_id' => $loan->tenant_id,
                        'user_id' => $user->id,
                        'type' => $late->isNotEmpty() ? 'loan.overdue' : 'loan.due',
                        'title' => $late->isNotEmpty() ? "Loan {$loan->number} has overdue instalments" : "Loan {$loan->number} has instalments due soon",
                        'body' => "{$due->count()} instalment(s), {$amount} {$verb}, ".($late->isNotEmpty() ? "{$late->count()} already late." : "the next on {$due->first()->due_date->toDateString()}."),
                        'icon' => $late->isNotEmpty() ? 'alert' : 'calendar',
                        'link_module' => 'accounting',
                        'link_entity' => 'Loan',
                        'link_id' => $loan->id,
                        'link_url' => "/api/v1/loans/{$loan->id}",
                        'unread' => true,
                    ]);
                    $told++;
                }

                $loan->forceFill(['last_reminded_on' => today()])->save();
            });

        $this->info("{$told} reminder(s) created.");

        return self::SUCCESS;
    }
}
