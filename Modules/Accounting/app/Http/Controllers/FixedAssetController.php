<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Requests\StoreFixedAssetRequest;
use Modules\Accounting\Http\Resources\DepreciationLineResource;
use Modules\Accounting\Http\Resources\FixedAssetResource;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\FixedAssetDepreciation;
use Modules\Accounting\Services\FixedAssetService;
use Modules\Accounting\Support\DepreciationScheduleCalculator;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;

class FixedAssetController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['category', 'assignedUser'];

    /** Everything a single asset's page shows, including its depreciation board (for the next line due). */
    private const DETAIL = ['category', 'assignedUser', 'depreciations'];

    public function __construct(private readonly FixedAssetService $assets) {}

    public function index(Request $request)
    {
        $query = FixedAsset::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', array_filter(explode(',', (string) $request->query('status')))))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->when($request->filled('assigned_user_id'), fn ($q) => $q->where('assigned_user_id', $request->query('assigned_user_id')));

        $this->applyFilters($request, $query, ['number', 'name', 'serial_number', 'reference', 'location']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Number', 'Name', 'Category', 'Status', 'Acquired', 'Original Value', 'Accumulated Depreciation', 'Book Value', 'Method', 'Periods'],
            fn (FixedAsset $a) => [$a->number, $a->name, $a->category?->name, $a->status, $a->acquisition_date->toDateString(), $a->original_value, $a->accumulated_depreciation, $a->bookValue(), $a->method, $a->duration_periods],
            'fixed-assets',
        )) {
            return $export;
        }

        return FixedAssetResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new FixedAssetResource($this->find($request, $id)->load(self::DETAIL)->loadCount('depreciations'));
    }

    public function schedule(Request $request, string $id)
    {
        $asset = $this->find($request, $id);
        $query = $asset->depreciations()->getQuery();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return DepreciationLineResource::collection($query->paginate($request->integer('per_page', 100)))->additional([
            'summary' => [
                'original_value' => $asset->original_value, 'salvage_value' => $asset->salvage_value, 'accumulated_depreciation' => $asset->accumulated_depreciation,
                'book_value' => number_format($asset->bookValue(), 2, '.', ''),
                'planned' => number_format((float) $asset->depreciations()->where('status', 'planned')->sum('amount'), 2, '.', ''),
            ],
        ]);
    }

    public function store(StoreFixedAssetRequest $request)
    {
        $asset = $this->assets->create($request->validated(), $request->user());

        return (new FixedAssetResource($asset->load(self::DETAIL)->loadCount('depreciations')))->response()->setStatusCode(201);
    }

    public function update(StoreFixedAssetRequest $request, FixedAsset $asset)
    {
        $this->sameTenant($request, $asset);

        return new FixedAssetResource($this->assets->update($asset, $request->validated(), $request->user())->load(self::DETAIL)->loadCount('depreciations'));
    }

    public function destroy(Request $request, FixedAsset $asset)
    {
        $this->sameTenant($request, $asset);
        $this->assets->delete($asset, $request->user());

        return response()->noContent();
    }

    public function preview(StoreFixedAssetRequest $request)
    {
        $lines = $this->assets->preview($request->validated(), $request->user());

        return response()->json(['data' => $lines, 'summary' => ['periods' => count($lines), 'total' => number_format(array_sum(array_column($lines, 'amount')), 2, '.', '')]]);
    }

    public function activate(Request $request, string $id)
    {
        $data = $request->validate([
            'acquisition_account_code' => ['sometimes', 'nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        return new FixedAssetResource($this->assets->activate($this->find($request, $id), $data['acquisition_account_code'] ?? null, $request->user())->load(self::DETAIL)->loadCount('depreciations'));
    }

    public function postDepreciation(Request $request, string $id, string $line)
    {
        $asset = $this->find($request, $id);
        $row = FixedAssetDepreciation::query()->where('fixed_asset_id', $asset->id)->findOrFail($line);

        $this->assets->postLine($asset, $row, $request->user());

        return new DepreciationLineResource($row->refresh());
    }

    /**
     * Books every depreciation due up to a date (today by default) for all running assets of the company.
     */
    public function depreciate(Request $request)
    {
        $data = $request->validate(['date' => ['sometimes', 'nullable', 'date']]);

        $result = $this->assets->depreciateDue($request->user()->tenant_id, CarbonImmutable::parse($data['date'] ?? now()), $request->user());

        return response()->json(['data' => $result]);
    }

    public function modify(Request $request, string $id)
    {
        $data = $request->validate([
            'remaining_periods' => ['required', 'integer', 'min:1', 'max:600'],
            'salvage_value' => ['sometimes', 'numeric', 'min:0'],
            'method' => ['sometimes', Rule::in(DepreciationScheduleCalculator::METHODS)],
            'declining_factor' => ['sometimes', 'numeric', 'gt:0', 'max:1'],
        ]);

        return new FixedAssetResource($this->assets->modify($this->find($request, $id), $data, $request->user())->load(self::DETAIL)->loadCount('depreciations'));
    }

    public function pause(Request $request, string $id)
    {
        return new FixedAssetResource($this->assets->pause($this->find($request, $id), $request->user())->load(self::DETAIL));
    }

    public function resume(Request $request, string $id)
    {
        $data = $request->validate(['date' => ['sometimes', 'nullable', 'date']]);

        return new FixedAssetResource($this->assets->resume($this->find($request, $id), CarbonImmutable::parse($data['date'] ?? now()), $request->user())->load(self::DETAIL));
    }

    public function dispose(Request $request, string $id)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'sale_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'proceeds_account_code' => ['sometimes', 'nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where('tenant_id', $request->user()->tenant_id)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return new FixedAssetResource($this->assets->dispose($this->find($request, $id), $data, $request->user())->load(self::DETAIL));
    }

    public function cancel(Request $request, string $id)
    {
        return new FixedAssetResource($this->assets->cancel($this->find($request, $id), $request->user())->load(self::DETAIL));
    }

    /**
     * What the company owns: counts, cost, depreciation so far and what is left, in total and per category.
     */
    public function summary(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $live = FixedAsset::query()->where('fixed_assets.tenant_id', $tenant)->whereIn('status', FixedAsset::LIVE);

        $byStatus = FixedAsset::query()->where('tenant_id', $tenant)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $totals = (clone $live)->selectRaw('count(*) as assets, coalesce(sum(original_value), 0) as cost, coalesce(sum(accumulated_depreciation), 0) as accumulated')->first();
        $byCategory = (clone $live)->join('asset_categories', 'asset_categories.id', '=', 'fixed_assets.category_id')
            ->selectRaw('asset_categories.id, asset_categories.name, count(*) as assets, sum(original_value) as cost, sum(accumulated_depreciation) as accumulated')
            ->groupBy('asset_categories.id', 'asset_categories.name')->orderBy('asset_categories.name')->get();

        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $year = FixedAssetDepreciation::query()->where('tenant_id', $tenant)->where('status', 'posted')->whereYear('depreciation_date', now()->year)->sum('amount');

        return response()->json(['data' => [
            'by_status' => $byStatus,
            'assets_on_books' => (int) $totals->assets,
            'cost' => $money($totals->cost),
            'accumulated_depreciation' => $money($totals->accumulated),
            'book_value' => $money((float) $totals->cost - (float) $totals->accumulated),
            'depreciation_this_year' => $money($year),
            'due_now' => [
                'lines' => FixedAssetDepreciation::query()->where('tenant_id', $tenant)->where('status', 'planned')->whereDate('depreciation_date', '<=', today())->whereHas('asset', fn ($q) => $q->where('status', 'running'))->count(),
            ],
            'by_category' => $byCategory->map(fn ($row) => [
                'category' => ['id' => $row->id, 'name' => $row->name], 'assets' => (int) $row->assets, 'cost' => $money($row->cost),
                'accumulated_depreciation' => $money($row->accumulated), 'book_value' => $money((float) $row->cost - (float) $row->accumulated),
            ])->values(),
        ]]);
    }

    /**
     * Depreciation still to come, month by month (`?months=` ahead, 12 by default).
     */
    public function forecast(Request $request)
    {
        $data = $request->validate(['months' => ['sometimes', 'integer', 'min:1', 'max:120']]);
        $months = (int) ($data['months'] ?? 12);
        $from = now()->startOfMonth();

        $rows = FixedAssetDepreciation::query()->where('tenant_id', $request->user()->tenant_id)->where('status', 'planned')
            ->whereBetween('depreciation_date', [$from->toDateString(), $from->copy()->addMonths($months)->subDay()->toDateString()])
            ->whereHas('asset', fn ($q) => $q->whereIn('status', ['running', 'paused']))
            ->select(DB::raw("date_format(depreciation_date, '%Y-%m') as month"), DB::raw('sum(amount) as amount'), DB::raw('count(*) as line_count'))
            ->groupBy('month')->orderBy('month')->get()->keyBy('month');

        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $from->copy()->addMonths($i)->format('Y-m');
            $series[] = ['month' => $month, 'amount' => number_format((float) ($rows[$month]->amount ?? 0), 2, '.', ''), 'lines' => (int) ($rows[$month]->line_count ?? 0)];
        }

        return response()->json(['data' => $series, 'summary' => ['total' => number_format(array_sum(array_column($series, 'amount')), 2, '.', '')]]);
    }

    private function find(Request $request, string $id): FixedAsset
    {
        return FixedAsset::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function sameTenant(Request $request, FixedAsset $asset): void
    {
        abort_unless($asset->tenant_id === $request->user()->tenant_id, 404);
    }
}
