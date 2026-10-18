<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $request, $query,
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

    /**
     * Which business flow feeds each account (see config/config.php), and how
     * many journal lines each has actually received — so an account that is
     * meant to be fed automatically but has never been posted to, or a
     * custom account no flow knows about, stands out.
     */
    public function connections(Request $request)
    {
        $map = config('accounting.connections', []);

        $postings = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.tenant_id', $request->user()->tenant_id)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COUNT(*) as line_count')
            ->pluck('line_count', 'account_id');

        $accounts = Account::query()->where('tenant_id', $request->user()->tenant_id)->orderBy('code')->get();

        return response()->json(['data' => $accounts->map(function (Account $account) use ($map, $postings) {
            $connection = $map[$account->code] ?? ['kind' => 'custom', 'used_by' => []];
            $lines = (int) ($postings[$account->id] ?? 0);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'kind' => $connection['kind'],
                'used_by' => $connection['used_by'],
                'posting_lines' => $lines,
                'balance' => $account->balance,
                // posted: wired but never used yet; custom: no automatic flow, only manual entries.
                'attention' => match (true) {
                    $connection['kind'] === 'posted' && $lines === 0 => 'wired but never posted to',
                    $connection['kind'] === 'custom' && $lines === 0 => 'custom account with no postings',
                    default => null,
                },
            ];
        })->values()]);
    }
}
