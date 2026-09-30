<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Models\Account;
use Modules\Core\Http\Controllers\Concerns\Exportable;

class AccountController extends Controller
{
    use Exportable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('code');

        if ($export = $this->exportIfRequested(
            $request, 'accounting.export', $query,
            ['ID', 'Code', 'Name', 'Type', 'Level', 'Balance', 'Active'],
            fn (Account $a) => [$a->id, $a->code, $a->name, $a->type, $a->level, $a->balance, $a->active ? 'Yes' : 'No'],
            'chart-of-accounts',
        )) {
            return $export;
        }

        $accounts = $query->paginate($request->integer('per_page', 100));

        return AccountResource::collection($accounts);
    }
}
