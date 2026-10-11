<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\StoreAccountRequest;
use Modules\Accounting\Http\Requests\UpdateAccountRequest;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Models\Account;
use Modules\Core\Exceptions\RecordInUseException;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;

class AccountController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    /**
     * AccountResource nests `parent` recursively; the seeded chart is at
     * most 3 levels deep, so three hops load the whole chain.
     */
    private const RELATIONS = [
        'parent.parent.parent',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS);

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

    public function show(Request $request, string $id)
    {
        $account = Account::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $account);

        return new AccountResource($account);
    }

    public function store(StoreAccountRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['level'] = $this->levelFor($data['parent_id'] ?? null);

        $account = Account::create($data);

        $this->recordAudit($request, 'accounting', 'Account', $account, 'INSERT', null, $account->only(['code', 'name', 'type', 'parent_id', 'active']));

        return (new AccountResource($account->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $data = $request->validated();

        if (array_key_exists('parent_id', $data)) {
            $data['level'] = $this->levelFor($data['parent_id']);
        }

        $this->updateRecord($request, $account, $data, 'accounting', 'Account');

        return new AccountResource($account->load(self::RELATIONS));
    }

    public function destroy(Request $request, Account $account)
    {
        return $this->destroyRecord($request, $account, 'accounting', 'Account', function (Account $account) {
            if ($account->isSystemAccount()) {
                throw new RecordInUseException('System accounts used by automatic postings cannot be deleted.');
            }

            if ($account->children()->exists()) {
                throw new RecordInUseException('This account has child accounts. Move or delete them first.');
            }
        });
    }

    private function levelFor(?string $parentId): int
    {
        return $parentId === null ? 1 : Account::query()->whereKey($parentId)->value('level') + 1;
    }
}
