<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Models\Account;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('code')
            ->paginate($request->integer('per_page', 100));

        return AccountResource::collection($accounts);
    }
}
