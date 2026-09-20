<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Services\ArAgingReportService;

class ReportController extends Controller
{
    public function __construct(private readonly ArAgingReportService $arAging) {}

    public function arAging(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return response()->json([
            'data' => $this->arAging->generate($request->user()->tenant_id),
        ]);
    }
}
