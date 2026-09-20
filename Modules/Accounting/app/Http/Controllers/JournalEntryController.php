<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Resources\JournalEntryResource;
use Modules\Accounting\Models\JournalEntry;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', JournalEntry::class);

        $entries = JournalEntry::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('entry_date', '>=', $request->date('start_date')))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('entry_date', '<=', $request->date('end_date')))
            ->with('lines')
            ->latest('entry_date')
            ->paginate($request->integer('per_page', 15));

        return JournalEntryResource::collection($entries);
    }
}
