<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\ApAgingReportService;

class ReportController extends Controller
{
    public function __construct(private readonly ApAgingReportService $apAging) {}

    public function apAging(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        return response()->json([
            'data' => $this->apAging->generate($request->user()->tenant_id),
        ]);
    }
}
