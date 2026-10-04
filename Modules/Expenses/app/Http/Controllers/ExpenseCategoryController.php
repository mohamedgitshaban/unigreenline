<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Expenses\Http\Requests\StoreExpenseCategoryRequest;
use Modules\Expenses\Http\Requests\UpdateExpenseCategoryRequest;
use Modules\Expenses\Http\Resources\ExpenseCategoryResource;
use Modules\Expenses\Models\ExpenseCategory;

/**
 * No delete: categories referenced by expenses must stay, so retire one by
 * setting `active` to false — inactive categories can't take new expenses.
 */
class ExpenseCategoryController extends Controller
{
    use Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $query = ExpenseCategory::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('account');

        $this->applyFilters($request, $query, ['name', 'description', 'account.name', 'account.code']);

        $this->applySorting($request, $query);

        $categories = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ExpenseCategoryResource::collection($categories);
    }

    public function store(StoreExpenseCategoryRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $category = ExpenseCategory::create($data);

        return (new ExpenseCategoryResource($category->load('account')))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory)
    {
        $expenseCategory->update($request->validated());

        return new ExpenseCategoryResource($expenseCategory->load('account'));
    }
}
