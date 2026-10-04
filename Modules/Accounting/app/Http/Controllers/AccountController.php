<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Models\Account;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;

class AccountController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with([
            'parent',
        ]);

        $this->applyFilters($request, $query, ['code', 'name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'accounting.export', $query,
            ['ID', 'Code', 'Name', 'Type', 'Level', 'Balance', 'Active'],
            fn (Account $a) => [$a->id, $a->code, $a->name, $a->type, $a->level, $a->balance, $a->active ? 'Yes' : 'No'],
            'chart-of-accounts',
        )) {
            return $export;
        }

        $accounts = $query->paginate($request->integer('per_page', 100))->withQueryString();

        return AccountResource::collection($accounts);
    }
}
