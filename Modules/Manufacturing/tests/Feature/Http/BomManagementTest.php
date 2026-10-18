<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use LogicException;
use Modules\Inventory\Models\Unit;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomRevision;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Services\BomSelector;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

class BomManagementTest extends ManufacturingTestCase
{
    public function test_a_new_bom_gets_a_code_version_one_and_a_first_revision(): void
    {
        $this->actingAsRole('Administrator');
        [$tonic, $herb, $bottle] = [$this->product('Tonic'), $this->product('Herb'), $this->product('Bottle')];

        $response = $this->postJson('/api/v1/boms', [
            'product_id' => $tonic->id,
            'name' => 'Tonic recipe',
            'output_qty_cartons' => 5,
            'lines' => [
                ['component_product_id' => $herb->id, 'qty_cartons' => 2, 'scrap_pct' => 5],
                ['component_product_id' => $bottle->id, 'qty_cartons' => 5, 'optional' => true],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'BOM00001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.lines.0.scrap_pct', '5.00')
            ->assertJsonPath('data.lines.1.optional', true);

        $bom = Bom::find($response->json('data.id'));
        $revision = $bom->revisions()->sole();
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame(2, count($revision->snapshot['lines']));
    }

    public function test_document_numbers_follow_the_companys_prefix_and_never_repeat(): void
    {
        $this->actingAsRole('Administrator');
        $this->putJson('/api/v1/manufacturing/settings', ['bom_prefix' => 'RCP', 'number_padding' => 3])->assertOk();

        $a = $this->postJson('/api/v1/boms', $this->payload('A'))->assertCreated()->json('data.code');
        $b = $this->postJson('/api/v1/boms', $this->payload('B'))->assertCreated()->json('data.code');

        $this->assertSame(['RCP001', 'RCP002'], [$a, $b]);
    }

    public function test_a_line_can_be_entered_in_a_packaging_unit_and_is_stored_as_cartons(): void
    {
        $this->actingAsRole('Administrator');
        $herb = $this->product('Herb', ['carton_qty' => 30]);
        $packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $package = Unit::factory()->recycle($this->tenant)->create(['name' => 'Package']);
        $carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
        $herb->units()->createMany([
            ['unit_id' => $packet->id, 'position' => 1, 'contains' => null, 'base_qty' => 1],
            ['unit_id' => $package->id, 'position' => 2, 'contains' => 10, 'base_qty' => 10],
            ['unit_id' => $carton->id, 'position' => 3, 'contains' => 3, 'base_qty' => 30],
        ]);

        // 6 packages = 60 packets = 2 cartons.
        $this->postJson('/api/v1/boms', [
            'product_id' => $this->product('Tonic')->id, 'name' => 'Unit recipe',
            'lines' => [['component_product_id' => $herb->id, 'unit_id' => $package->id, 'unit_qty' => 6]],
        ])->assertCreated()
            ->assertJsonPath('data.lines.0.qty_cartons', 2)
            ->assertJsonPath('data.lines.0.unit_qty', 6);

        // 7 packages = 70 packets is not a whole number of cartons.
        $this->postJson('/api/v1/boms', [
            'product_id' => $this->product('Tonic 2')->id, 'name' => 'Bad unit recipe',
            'lines' => [['component_product_id' => $herb->id, 'unit_id' => $package->id, 'unit_qty' => 7]],
        ])->assertUnprocessable();
    }

    public function test_a_recipe_that_would_make_a_product_a_component_of_itself_through_other_recipes_is_refused(): void
    {
        $this->actingAsRole('Administrator');
        [$a, $b, $c] = [$this->product('A'), $this->product('B'), $this->product('C')];
        $this->bom($a, [[$b, 1]]);   // A is made of B
        $this->bom($b, [[$c, 1]]);   // B is made of C

        // C made of A closes the loop A → B → C → A.
        $this->postJson('/api/v1/boms', [
            'product_id' => $c->id, 'name' => 'Loop', 'lines' => [['component_product_id' => $a->id, 'qty_cartons' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        // A recipe that merely shares a component is fine.
        $this->postJson('/api/v1/boms', [
            'product_id' => $c->id, 'name' => 'Fine', 'lines' => [['component_product_id' => $this->product('D')->id, 'qty_cartons' => 1]],
        ])->assertCreated();
    }

    public function test_editing_a_recipe_into_a_loop_is_refused_too(): void
    {
        $this->actingAsRole('Administrator');
        [$a, $b] = [$this->product('A'), $this->product('B')];
        $this->bom($a, [[$b, 1]]);
        $bomB = $this->bom($b, [[$this->product('Water'), 1]]);

        $this->putJson("/api/v1/boms/{$bomB->id}", ['lines' => [['component_product_id' => $a->id, 'qty_cartons' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_editing_an_approved_recipe_makes_a_new_version_and_keeps_the_old_revision_untouched(): void
    {
        $this->actingAsRole('Administrator');
        [$tonic, $herb] = [$this->product('Tonic'), $this->product('Herb')];
        $bom = $this->bom($tonic, [[$herb, 2]], ['code' => 'BOM00001']);
        app(\Modules\Manufacturing\Services\BomRevisionService::class)->snapshot($bom, 'Created');

        $this->putJson("/api/v1/boms/{$bom->id}", ['lines' => [['component_product_id' => $herb->id, 'qty_cartons' => 3]]])
            ->assertOk()->assertJsonPath('data.version', 2);

        // A cosmetic edit does not make a version.
        $this->putJson("/api/v1/boms/{$bom->id}", ['notes' => 'just a note'])->assertOk()->assertJsonPath('data.version', 2);

        $revisions = $bom->revisions()->get();
        $this->assertSame([1, 2], $revisions->pluck('revision_no')->all());
        $this->assertSame(2, $revisions[0]->snapshot['lines'][0]['qty_cartons']);
        $this->assertSame(3, $revisions[1]->snapshot['lines'][0]['qty_cartons']);

        $this->getJson("/api/v1/boms/{$bom->id}/revisions")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/boms/{$bom->id}/revisions/{$revisions[0]->id}")->assertOk()->assertJsonPath('data.snapshot.lines.0.qty_cartons', 2);
    }

    public function test_a_stored_revision_can_never_be_changed_or_deleted(): void
    {
        $bom = $this->bom($this->product('Tonic'), [[$this->product('Herb'), 1]]);
        $revision = app(\Modules\Manufacturing\Services\BomRevisionService::class)->snapshot($bom);

        $this->expectException(LogicException::class);
        $revision->update(['change_note' => 'rewritten']);
    }

    public function test_when_approval_is_required_a_recipe_stays_a_draft_until_approved_and_edits_send_it_back(): void
    {
        $this->actingAsRole('Administrator');
        $this->putJson('/api/v1/manufacturing/settings', ['require_bom_approval' => true])->assertOk();
        $tonic = $this->product('Tonic');
        $herb = $this->product('Herb');

        $id = $this->postJson('/api/v1/boms', ['product_id' => $tonic->id, 'name' => 'Needs approval', 'lines' => [['component_product_id' => $herb->id, 'qty_cartons' => 1]]])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $selector = app(BomSelector::class);
        $this->assertNull($selector->forProduct($this->tenant->id, $tonic->id));
        $this->assertSame(0, BomRevision::query()->where('bom_id', $id)->count());

        $this->postJson("/api/v1/boms/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($id, $selector->forProduct($this->tenant->id, $tonic->id)->id);
        $this->assertSame(1, BomRevision::query()->where('bom_id', $id)->count());
        $this->postJson("/api/v1/boms/{$id}/approve")->assertUnprocessable();

        $this->putJson("/api/v1/boms/{$id}", ['output_qty_cartons' => 4])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 2);
        $this->assertNull($selector->forProduct($this->tenant->id, $tonic->id));
    }

    public function test_archiving_hides_a_recipe_from_selection_and_restoring_brings_it_back(): void
    {
        $this->actingAsRole('Administrator');
        $tonic = $this->product('Tonic');
        $bom = $this->bom($tonic, [[$this->product('Herb'), 1]]);

        $this->postJson("/api/v1/boms/{$bom->id}/archive")->assertOk()->assertJsonPath('data.active', false);
        $this->assertNull(app(BomSelector::class)->forProduct($this->tenant->id, $tonic->id));
        $this->postJson("/api/v1/boms/{$bom->id}/archive")->assertUnprocessable();

        $this->postJson("/api/v1/boms/{$bom->id}/restore")->assertOk()->assertJsonPath('data.active', true);
        $this->assertSame($bom->id, app(BomSelector::class)->forProduct($this->tenant->id, $tonic->id)->id);
    }

    public function test_alternative_recipes_are_chosen_by_priority_and_validity_window(): void
    {
        $tonic = $this->product('Tonic');
        $herb = $this->product('Herb');
        $standard = $this->bom($tonic, [[$herb, 1]], ['priority' => 10]);
        $preferred = $this->bom($tonic, [[$herb, 2]], ['priority' => 5]);
        $expired = $this->bom($tonic, [[$herb, 3]], ['priority' => 1, 'effective_to' => now()->subDay()->toDateString()]);
        $future = $this->bom($tonic, [[$herb, 4]], ['priority' => 1, 'effective_from' => now()->addMonth()->toDateString()]);

        $selector = app(BomSelector::class);

        $this->assertSame($preferred->id, $selector->forProduct($this->tenant->id, $tonic->id)->id);
        $this->assertSame([$preferred->id, $standard->id], $selector->alternatives($this->tenant->id, $tonic->id)->pluck('id')->all());
        $this->assertSame($future->id, $selector->forProduct($this->tenant->id, $tonic->id, now()->addMonths(2))->id);
        $this->assertFalse($expired->isUsableOn());
    }

    public function test_the_components_endpoint_explodes_sub_assemblies_with_scrap(): void
    {
        $this->actingAsRole('Administrator');
        [$tonic, $syrup, $herb, $sugar, $bottle] = [$this->product('Tonic'), $this->product('Syrup'), $this->product('Herb'), $this->product('Sugar'), $this->product('Bottle')];
        $syrupBom = $this->bom($syrup, [[$herb, 4], [$sugar, 2]], ['output_qty_cartons' => 2]);
        $tonicBom = $this->bom($tonic, [[$syrup, 3, 10.0], [$bottle, 5]], ['output_qty_cartons' => 5]);

        $rows = collect($this->getJson("/api/v1/boms/{$tonicBom->id}/components?qty=10")->assertOk()->json('data.components'));

        // 10 cartons = 2 runs: 6 syrup + 10% scrap = 6.6; 10 bottles.
        $syrupRow = $rows->firstWhere('product.name', 'Syrup');
        $this->assertSame('assembly', $syrupRow['kind']);
        $this->assertEquals(6.6, $syrupRow['qty_cartons']);
        $this->assertSame(7, $syrupRow['required_cartons']);
        $this->assertSame($syrupBom->id, $syrupRow['bom_id']);
        $this->assertEquals(10, $rows->firstWhere('product.name', 'Bottle')['qty_cartons']);

        // 6.6 syrup = 3.3 runs of the syrup recipe: herb 4 x 3.3 = 13.2, sugar 2 x 3.3 = 6.6.
        $this->assertEquals(13.2, $rows->firstWhere('product.name', 'Herb')['qty_cartons']);
        $this->assertSame(2, $rows->firstWhere('product.name', 'Herb')['level']);
        $this->assertEquals(6.6, $rows->firstWhere('product.name', 'Sugar')['qty_cartons']);
    }

    public function test_by_product_rules(): void
    {
        $this->actingAsRole('Administrator');
        [$tonic, $herb, $residue, $other] = [$this->product('Tonic'), $this->product('Herb'), $this->product('Residue'), $this->product('Other')];
        $base = ['product_id' => $tonic->id, 'name' => 'With by-product', 'lines' => [['component_product_id' => $herb->id, 'qty_cartons' => 1]]];

        $this->postJson('/api/v1/boms', $base + ['by_products' => [['product_id' => $tonic->id, 'qty_cartons' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/boms', $base + ['by_products' => [['product_id' => $herb->id, 'qty_cartons' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/boms', $base + ['by_products' => [
            ['product_id' => $residue->id, 'qty_cartons' => 1, 'cost_share_pct' => 70],
            ['product_id' => $other->id, 'qty_cartons' => 1, 'cost_share_pct' => 40],
        ]])->assertUnprocessable()->assertJsonValidationErrors('by_products');

        $this->postJson('/api/v1/boms', $base + ['by_products' => [['product_id' => $residue->id, 'qty_cartons' => 2, 'cost_share_pct' => 10]]])
            ->assertCreated()->assertJsonPath('data.by_products.0.qty_cartons', 2)->assertJsonPath('data.by_products.0.cost_share_pct', '10.00');
    }

    public function test_permissions_gate_approval_and_archiving(): void
    {
        $bom = $this->bom($this->product('Tonic'), [[$this->product('Herb'), 1]]);

        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/boms')->assertOk();
        $this->postJson("/api/v1/boms/{$bom->id}/archive")->assertForbidden();
        $this->postJson("/api/v1/boms/{$bom->id}/approve")->assertForbidden();
        // Cutting a revision is an edit, which this role may do.
        $this->postJson("/api/v1/boms/{$bom->id}/revisions", ['change_note' => 'Q4 review'])->assertCreated()->assertJsonPath('data.revision_no', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        return ['product_id' => $this->product($name)->id, 'name' => "Recipe {$name}", 'lines' => [['component_product_id' => $this->product("Part {$name}")->id, 'qty_cartons' => 1]]];
    }
}
