<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\StoreJournalEntryRequest;
use Modules\Accounting\Http\Resources\JournalEntryResource;
use Modules\Accounting\Http\Resources\JournalLineResource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\ManualJournalEntryService;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;

class JournalEntryController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = [
        'createdBy',
        'createdBy.roles.permissions',
        'createdBy.permissions',
    ];

    /**
     * JournalLineResource nests AccountResource, which nests `parent`
     * recursively; the seeded chart is at most 3 levels deep.
     */
    private const LINE_RELATIONS = ['account.parent.parent.parent'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', JournalEntry::class);

        $query = JournalEntry::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('entry_date', '>=', $request->date('start_date')))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('entry_date', '<=', $request->date('end_date')));

        $query->with(self::RELATIONS)
            ->withCount('lines')
            // Only the export flattens lines into its rows; JSON pages them separately.
            ->when(in_array($request->query('export'), ['csv', 'xlsx'], true), fn ($q) => $q->with('lines'));

        $this->applyFilters($request, $query, ['id', 'ref', 'description', 'lines.account_code']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Ref', 'Description', 'Entry Date', 'Posted', 'Lines (Account Code Dr/Cr Amount)'],
            // One row per entry, not per line — keeps every export in this
            // build to the same "one query row in, one spreadsheet row out"
            // shape. Line detail is summarized rather than omitted.
            fn (JournalEntry $e) => [
                $e->id, $e->ref, $e->description, $e->entry_date->toDateString(), $e->posted ? 'Yes' : 'No',
                $e->lines->map(fn ($l) => $l->account_code.' '.($l->debit > 0 ? "Dr {$l->debit}" : "Cr {$l->credit}"))->implode('; '),
            ],
            'journal-entries',
        )) {
            return $export;
        }

        $entries = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return JournalEntryResource::collection($entries);
    }

    public function show(Request $request, string $id)
    {
        $journalEntry = JournalEntry::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->withCount('lines')
            ->findOrFail($id);

        $this->authorize('view', $journalEntry);

        return new JournalEntryResource($journalEntry);
    }

    /**
     * The entry's debit/credit lines as their own paginated list.
     */
    public function lines(Request $request, string $id)
    {
        $journalEntry = JournalEntry::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $journalEntry);

        $query = $journalEntry->lines()->getQuery()->with(self::LINE_RELATIONS);

        $this->applyFilters($request, $query, ['account_code', 'account_name']);

        $this->applySorting($request, $query);

        $lines = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return JournalLineResource::collection($lines);
    }

    /**
     * A manual entry (opening balances, capital, fixed assets, other income,
     * corrections). Balanced, dated, on leaf accounts; immutable once posted.
     */
    public function store(StoreJournalEntryRequest $request, ManualJournalEntryService $manualEntries)
    {
        $entry = $manualEntries->create($request->user()->tenant_id, $request->validated(), $this->actor($request));

        return (new JournalEntryResource($entry->load(self::RELATIONS)->loadCount('lines')))->response()->setStatusCode(201);
    }

    /**
     * Cancels a manual entry by posting its mirror image. Optional `entry_date`
     * (defaults to today) is the reversal's date.
     */
    public function reverse(Request $request, string $id, ManualJournalEntryService $manualEntries)
    {
        $entry = JournalEntry::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $request->validate(['entry_date' => ['sometimes', 'date']]);

        $reversal = $manualEntries->reverse($entry, $request->input('entry_date'), $this->actor($request));

        return new JournalEntryResource($reversal->load(self::RELATIONS)->loadCount('lines'));
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ];
    }
}
