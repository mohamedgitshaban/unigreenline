<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Analytics\Http\Resources\ExpiryBatchResource;
use Modules\Analytics\Services\DashboardService;
use Modules\Analytics\Services\ExpiryAnalyticsService;
use Modules\Analytics\Services\StockAnalyticsService;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ExpiryAnalyticsService $expiryAnalytics,
        private readonly StockAnalyticsService $stockAnalytics,
    ) {}

    public function dashboard(Request $request)
    {
        $this->authorize('analytics.view');

        return response()->json(['data' => $this->dashboard->generate($request->user()->tenant_id)]);
    }

    public function expiry(Request $request)
    {
        $this->authorize('analytics.view');

        $batches = $this->expiryAnalytics->generate($request->user()->tenant_id, $request->integer('per_page', 15));

        return ExpiryBatchResource::collection($batches);
    }

    public function stock(Request $request)
    {
        $this->authorize('analytics.view');

        return response()->json(
            $this->stockAnalytics->generate($request->user()->tenant_id, $request->integer('per_page', 15), $request->integer('page', 1))
        );
    }
}
