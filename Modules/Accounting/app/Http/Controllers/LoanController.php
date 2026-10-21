<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Requests\StoreLoanRequest;
use Modules\Accounting\Http\Resources\LoanInstallmentResource;
use Modules\Accounting\Http\Resources\LoanResource;
use Modules\Accounting\Models\Loan;
use Modules\Accounting\Models\LoanInstallment;
use Modules\Accounting\Services\LoanService;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;

class LoanController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function __construct(private readonly LoanService $loans) {}

    public function index(Request $request)
    {
        $query = $this->withCounts(Loan::query()->where('tenant_id', $request->user()->tenant_id)->with('installments'))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', array_filter(explode(',', (string) $request->query('status')))))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->query('kind')));

        $this->applyFilters($request, $query, ['number', 'name', 'counterparty_name']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Number', 'Name', 'Kind', 'Counterparty', 'Status', 'Principal', 'Rate %', 'Method', 'Periods', 'Outstanding', 'Interest Paid'],
            fn (Loan $l) => [$l->number, $l->name, $l->kind, $l->counterparty_name, $l->status, $l->principal, $l->annual_rate_pct, $l->method, $l->periods, $l->outstanding_principal, $l->interest_paid],
            'loans',
        )) {
            return $export;
        }

        return LoanResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new LoanResource($this->withCounts(Loan::query()->where('tenant_id', $request->user()->tenant_id)->with('installments'))->findOrFail($id));
    }

    public function schedule(Request $request, string $id)
    {
        $loan = $this->find($request, $id);
        $query = $loan->installments()->getQuery();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return LoanInstallmentResource::collection($query->paginate($request->integer('per_page', 100)))->additional([
            'summary' => [
                'principal' => $loan->principal, 'outstanding_principal' => $loan->outstanding_principal, 'interest_paid' => $loan->interest_paid,
                'total_interest' => number_format((float) $loan->installments()->sum('interest_amount') + (float) $loan->interest_paid - (float) $loan->installments()->where('status', 'paid')->sum('interest_amount'), 2, '.', ''),
                'total_to_pay' => number_format((float) $loan->installments()->where('status', 'planned')->sum('payment_amount'), 2, '.', ''),
            ],
        ]);
    }

    public function store(StoreLoanRequest $request)
    {
        $loan = $this->loans->create($request->validated(), $request->user());

        return (new LoanResource($this->withCounts(Loan::query()->with('installments'))->findOrFail($loan->id)))->response()->setStatusCode(201);
    }

    public function update(StoreLoanRequest $request, Loan $loan)
    {
        abort_unless($loan->tenant_id === $request->user()->tenant_id, 404);
        $this->loans->update($loan, $request->validated(), $request->user());

        return new LoanResource($this->withCounts(Loan::query()->with('installments'))->findOrFail($loan->id));
    }

    public function destroy(Request $request, Loan $loan)
    {
        abort_unless($loan->tenant_id === $request->user()->tenant_id, 404);
        $this->loans->delete($loan, $request->user());

        return response()->noContent();
    }

    /**
     * The repayment schedule for terms typed in, without saving anything.
     */
    public function preview(StoreLoanRequest $request)
    {
        $rows = $this->loans->preview($request->validated());

        return response()->json(['data' => $rows, 'summary' => [
            'instalments' => count($rows),
            'total_interest' => number_format(array_sum(array_column($rows, 'interest_amount')), 2, '.', ''),
            'total_to_pay' => number_format(array_sum(array_column($rows, 'payment_amount')), 2, '.', ''),
            'first_payment' => $rows[0]['payment_amount'] ?? null,
        ]]);
    }

    public function activate(Request $request, string $id)
    {
        $data = $request->validate([
            'account_code' => ['required', 'string', Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $request->user()->tenant_id)],
            'date' => ['sometimes', 'nullable', 'date'],
        ]);

        $loan = $this->loans->activate($this->find($request, $id), $data['account_code'], $request->user(), isset($data['date']) ? new \DateTimeImmutable($data['date']) : null);

        return new LoanResource($this->withCounts(Loan::query()->with('installments'))->findOrFail($loan->id));
    }

    public function payInstallment(Request $request, string $id, int $sequence)
    {
        $data = $request->validate([
            'payment_date' => ['sometimes', 'nullable', 'date'],
            'payment_account_code' => ['sometimes', 'nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        return new LoanInstallmentResource($this->loans->payInstallment($this->find($request, $id), $sequence, $data, $request->user()));
    }

    public function earlyRepayment(Request $request, string $id)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['reduce_term', 'reduce_installment', 'payoff'])],
            'amount' => ['required_unless:mode,payoff', 'nullable', 'numeric', 'gt:0'],
            'date' => ['sometimes', 'nullable', 'date'],
            'payment_account_code' => ['sometimes', 'nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $loan = $this->loans->repayEarly($this->find($request, $id), $data, $request->user());

        return new LoanResource($this->withCounts(Loan::query()->with('installments'))->findOrFail($loan->id));
    }

    public function cancel(Request $request, string $id)
    {
        $loan = $this->loans->cancel($this->find($request, $id), $request->user());

        return new LoanResource($this->withCounts(Loan::query()->with('installments'))->findOrFail($loan->id));
    }

    /**
     * What is owed and what is lent, per kind, and what falls due soon or is late.
     */
    public function summary(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $planned = fn () => LoanInstallment::query()->where('loan_installments.tenant_id', $tenant)->where('loan_installments.status', 'planned')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'));

        $kinds = Loan::query()->where('tenant_id', $tenant)->where('status', 'active')
            ->selectRaw('kind, count(*) as loans, coalesce(sum(outstanding_principal), 0) as outstanding, coalesce(sum(interest_paid), 0) as interest_paid')->groupBy('kind')->get()->keyBy('kind');

        return response()->json(['data' => [
            'by_kind' => collect(['borrowed', 'granted'])->mapWithKeys(fn ($kind) => [$kind => [
                'active_loans' => (int) ($kinds[$kind]->loans ?? 0),
                'outstanding_principal' => $money($kinds[$kind]->outstanding ?? 0),
                'interest_paid' => $money($kinds[$kind]->interest_paid ?? 0),
            ]]),
            'overdue' => ['installments' => $planned()->whereDate('due_date', '<', today())->count(), 'amount' => $money($planned()->whereDate('due_date', '<', today())->sum('payment_amount'))],
            'due_next_30_days' => ['installments' => $planned()->whereBetween('due_date', [today()->toDateString(), today()->addDays(30)->toDateString()])->count(), 'amount' => $money($planned()->whereBetween('due_date', [today()->toDateString(), today()->addDays(30)->toDateString()])->sum('payment_amount'))],
            'draft_loans' => Loan::query()->where('tenant_id', $tenant)->where('status', 'draft')->count(),
        ]]);
    }

    /**
     * Instalments due within `?days=` days (30 by default) or already late, soonest first.
     */
    public function due(Request $request)
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', 'min:0', 'max:365'], 'kind' => ['sometimes', Rule::in(['borrowed', 'granted'])]]);

        $query = LoanInstallment::query()->where('loan_installments.tenant_id', $request->user()->tenant_id)->where('loan_installments.status', 'planned')
            ->whereDate('due_date', '<=', today()->addDays((int) ($data['days'] ?? 30))->toDateString())
            ->whereHas('loan', fn ($q) => $q->where('status', 'active')->when($data['kind'] ?? null, fn ($inner, $kind) => $inner->where('kind', $kind)))
            ->with('loan:id,number,name,kind,counterparty_name')
            ->orderBy('due_date')->orderBy('sequence');

        return LoanInstallmentResource::collection($query->paginate($request->integer('per_page', 50)))->additional([
            'loans' => 'each row belongs to the loan in the loan field',
        ]);
    }

    private function find(Request $request, string $id): Loan
    {
        return Loan::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    private function withCounts($query)
    {
        return $query->withCount(['installments', 'paidInstallments']);
    }
}
