<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Expenses\Http\Requests\RejectExpenseRequest;
use Modules\Expenses\Http\Requests\StoreExpenseRequest;
use Modules\Expenses\Http\Requests\UpdateExpenseRequest;
use Modules\Expenses\Http\Requests\UploadExpenseReceiptRequest;
use Modules\Expenses\Http\Resources\ExpenseResource;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Services\CreateExpenseService;
use Modules\Expenses\Services\DeleteExpenseService;
use Modules\Expenses\Services\ExpenseApprovalService;
use Modules\Expenses\Services\UpdateExpenseService;

class ExpenseController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['category.account', 'warehouse', 'supplier', 'createdBy', 'approvedBy'];

    public function __construct(
        private readonly CreateExpenseService $createExpense,
        private readonly UpdateExpenseService $updateExpense,
        private readonly DeleteExpenseService $deleteExpense,
        private readonly ExpenseApprovalService $expenseApproval,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'payee', 'reference', 'description', 'category.name', 'supplier.name', 'warehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['expenses.export', 'accounting.export'], $query,
            ['ID', 'Date', 'Category', 'Account', 'Amount', 'Payment Method', 'Status', 'Payee', 'Supplier', 'Warehouse', 'Reference', 'Description'],
            fn (Expense $e) => [
                $e->id, $e->expense_date->toDateString(), $e->category->name, $e->category->account->code, $e->amount,
                $e->payment_method, $e->status, $e->payee, $e->supplier?->name, $e->warehouse?->name, $e->reference, $e->description,
            ],
            'expenses',
        )) {
            return $export;
        }

        $expenses = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ExpenseResource::collection($expenses);
    }

    public function show(Request $request, Expense $expense)
    {
        abort_unless($expense->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('view', $expense);

        return new ExpenseResource($expense->load(self::RELATIONS));
    }

    public function store(StoreExpenseRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $expense = $this->createExpense->create($data, $this->actor($request));

        return (new ExpenseResource($expense->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense = $this->updateExpense->update($expense, $request->validated(), $this->actor($request));

        return new ExpenseResource($expense->load(self::RELATIONS));
    }

    public function destroy(Request $request, Expense $expense)
    {
        // Checked outside the policy: Administrator's Gate::before bypass
        // would otherwise let a destructive action cross tenants.
        abort_unless($expense->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('delete', $expense);

        $this->deleteExpense->delete($expense, $this->actor($request));

        return response()->noContent();
    }

    public function approve(Request $request, Expense $expense)
    {
        abort_unless($expense->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('approve', $expense);

        $expense = $this->expenseApproval->approve($expense, $this->actor($request));

        return new ExpenseResource($expense->load(self::RELATIONS));
    }

    public function reject(RejectExpenseRequest $request, Expense $expense)
    {
        $expense = $this->expenseApproval->reject($expense, $request->validated('reason'), $this->actor($request));

        return new ExpenseResource($expense->load(self::RELATIONS));
    }

    /**
     * Step one of attaching a receipt: store the file and hand back its
     * path, which the client then sends as `receipt_path` on create/update.
     */
    public function uploadReceipt(UploadExpenseReceiptRequest $request)
    {
        $receipt = $request->file('receipt');
        $path = $receipt->store(Expense::receiptDirectory($request->user()->tenant_id));

        return response()->json(['data' => [
            'receipt_path' => $path,
            'original_name' => $receipt->getClientOriginalName(),
            'mime_type' => $receipt->getMimeType(),
            'size' => $receipt->getSize(),
        ]], 201);
    }

    public function downloadReceipt(Request $request, Expense $expense)
    {
        abort_unless($expense->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('view', $expense);

        abort_if($expense->receipt_path === null || ! Storage::exists($expense->receipt_path), 404, 'This expense has no receipt.');

        return Storage::download($expense->receipt_path, "receipt-{$expense->id}.".pathinfo($expense->receipt_path, PATHINFO_EXTENSION));
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ];
    }
}
