<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Manufacturing\Models\ManufacturingAttachment;

/**
 * Files attached to recipes, orders, operations, routings and quality checks: drawings, work instructions,
 * certificates, photos. Stored on the default disk under the company's own folder; downloaded only through here.
 */
class ManufacturingAttachmentController extends Controller
{
    use ManagesRecords;

    public function index(Request $request)
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(ManufacturingAttachment::OWNERS))],
            'attachable_id' => ['required', 'string'],
        ]);

        $this->ownerOrFail($request, $data['attachable_type'], $data['attachable_id']);

        $files = ManufacturingAttachment::query()
            ->where('tenant_id', $request->user()->tenant_id)->where('attachable_type', $data['attachable_type'])->where('attachable_id', $data['attachable_id'])
            ->with('uploader:id,name')->orderBy('created_at')->get();

        return response()->json(['data' => $files->map(fn ($f) => $this->data($f))->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(ManufacturingAttachment::OWNERS))],
            'attachable_id' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt', 'max:10240'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $this->ownerOrFail($request, $data['attachable_type'], $data['attachable_id']);

        $file = $request->file('file');
        $attachment = ManufacturingAttachment::create([
            'tenant_id' => $request->user()->tenant_id,
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => $data['attachable_id'],
            'path' => $file->store(ManufacturingAttachment::directory($request->user()->tenant_id)),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'description' => $data['description'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        $this->recordAudit($request, 'manufacturing', 'ManufacturingAttachment', $attachment, 'INSERT', null, [
            'on' => $data['attachable_type'], 'id' => $data['attachable_id'], 'file' => $attachment->original_name,
        ]);

        return response()->json(['data' => $this->data($attachment->load('uploader:id,name'))], 201);
    }

    public function download(Request $request, ManufacturingAttachment $attachment)
    {
        abort_unless($attachment->tenant_id === $request->user()->tenant_id, 404);
        abort_unless(Storage::exists($attachment->path), 404, 'The file is no longer stored.');

        return Storage::download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, ManufacturingAttachment $attachment)
    {
        abort_unless($attachment->tenant_id === $request->user()->tenant_id, 404);

        Storage::delete($attachment->path);
        $attachment->delete();
        $this->recordAudit($request, 'manufacturing', 'ManufacturingAttachment', $attachment, 'DELETE', ['file' => $attachment->original_name], null);

        return response()->noContent();
    }

    /**
     * The record a file belongs to must be this company's.
     */
    private function ownerOrFail(Request $request, string $type, string $id): void
    {
        $model = ManufacturingAttachment::OWNERS[$type];

        abort_unless($model::query()->where('tenant_id', $request->user()->tenant_id)->whereKey($id)->exists(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ManufacturingAttachment $f): array
    {
        return [
            'id' => $f->id, 'attachable_type' => $f->attachable_type, 'attachable_id' => $f->attachable_id,
            'original_name' => $f->original_name, 'mime_type' => $f->mime_type, 'size' => $f->size, 'description' => $f->description,
            'uploaded_by' => $f->uploader ? ['id' => $f->uploader->id, 'name' => $f->uploader->name] : null, 'created_at' => $f->created_at,
        ];
    }
}
