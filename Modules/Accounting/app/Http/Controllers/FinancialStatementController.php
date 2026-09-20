<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\IncomeStatementRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FinancialStatementService;

class FinancialStatementController extends Controller
{
    public function __construct(private readonly FinancialStatementService $financialStatements) {}

    public function balanceSheet(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        return response()->json([
            'data' => $this->financialStatements->balanceSheet($request->user()->tenant_id),
        ]);
    }

    public function incomeStatement(IncomeStatementRequest $request)
    {
        return response()->json([
            'data' => $this->financialStatements->incomeStatement(
                $request->user()->tenant_id,
                $request->validated('start_date'),
                $request->validated('end_date'),
            ),
        ]);
    }
}
