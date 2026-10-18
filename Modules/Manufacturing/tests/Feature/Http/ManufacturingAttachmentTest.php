<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingAttachment;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

class ManufacturingAttachmentTest extends ManufacturingTestCase
{
    private Bom $bom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->bom = $this->bom($this->product('Tonic'), [[$this->product('Herb'), 1]]);
        $this->actingAsRole('Administrator');
    }

    public function test_a_file_can_be_attached_listed_downloaded_and_removed(): void
    {
        $id = $this->postJson('/api/v1/manufacturing/attachments', [
            'attachable_type' => 'bom', 'attachable_id' => $this->bom->id, 'description' => 'Blending instructions',
            'file' => UploadedFile::fake()->create('instructions.pdf', 120, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.original_name', 'instructions.pdf')->assertJsonPath('data.description', 'Blending instructions')->json('data.id');

        $attachment = ManufacturingAttachment::findOrFail($id);
        Storage::assertExists($attachment->path);
        $this->assertStringStartsWith("manufacturing-attachments/{$this->tenant->id}/", $attachment->path);

        $this->getJson("/api/v1/manufacturing/attachments?attachable_type=bom&attachable_id={$this->bom->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->get("/api/v1/manufacturing/attachments/{$id}/download")->assertOk()->assertDownload('instructions.pdf');

        $this->deleteJson("/api/v1/manufacturing/attachments/{$id}")->assertNoContent();
        Storage::assertMissing($attachment->path);
        $this->assertDatabaseMissing('manufacturing_attachments', ['id' => $id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'ManufacturingAttachment', 'entity_id' => $id, 'operation' => 'DELETE']);
    }

    public function test_files_can_be_attached_to_orders_and_other_records(): void
    {
        $orderId = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id])->json('data.id');

        foreach ([['production_order', $orderId], ['bom', $this->bom->id]] as [$type, $id]) {
            $this->postJson('/api/v1/manufacturing/attachments', ['attachable_type' => $type, 'attachable_id' => $id, 'file' => UploadedFile::fake()->image('photo.png')])->assertCreated();
        }

        $this->getJson("/api/v1/manufacturing/attachments?attachable_type=production_order&attachable_id={$orderId}")->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(ProductionOrder::find($orderId));
    }

    public function test_only_safe_types_and_sizes_are_accepted(): void
    {
        $base = ['attachable_type' => 'bom', 'attachable_id' => $this->bom->id];

        $this->postJson('/api/v1/manufacturing/attachments', $base + ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/manufacturing/attachments', $base + ['file' => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/v1/manufacturing/attachments', ['attachable_type' => 'invoice', 'attachable_id' => $this->bom->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/v1/manufacturing/attachments', $base)->assertUnprocessable();
    }

    public function test_another_companys_records_and_files_are_not_reachable(): void
    {
        $foreignBom = Bom::factory()->create();
        $foreign = ManufacturingAttachment::create([
            'tenant_id' => $foreignBom->tenant_id, 'attachable_type' => 'bom', 'attachable_id' => $foreignBom->id,
            'path' => 'manufacturing-attachments/x/y.pdf', 'original_name' => 'y.pdf', 'size' => 1,
        ]);

        $this->postJson('/api/v1/manufacturing/attachments', ['attachable_type' => 'bom', 'attachable_id' => $foreignBom->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertNotFound();
        $this->getJson("/api/v1/manufacturing/attachments?attachable_type=bom&attachable_id={$foreignBom->id}")->assertNotFound();
        $this->get("/api/v1/manufacturing/attachments/{$foreign->id}/download")->assertNotFound();
        $this->deleteJson("/api/v1/manufacturing/attachments/{$foreign->id}")->assertNotFound();
    }

    public function test_permissions(): void
    {
        $this->actingAsRole('Warehouse Employee');
        $this->postJson('/api/v1/manufacturing/attachments', ['attachable_type' => 'bom', 'attachable_id' => $this->bom->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertCreated();
        $id = ManufacturingAttachment::query()->value('id');
        $this->deleteJson("/api/v1/manufacturing/attachments/{$id}")->assertForbidden();   // adding is an edit; deleting is a delete

        $this->actingAsRole('Sales Rep');
        $this->getJson("/api/v1/manufacturing/attachments?attachable_type=bom&attachable_id={$this->bom->id}")->assertForbidden();
    }
}
