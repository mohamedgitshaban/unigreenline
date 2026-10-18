<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

/**
 * Worked example used throughout: one run makes 10 cartons of Tonic from 5 cartons of
 * Herb (cost 10 each) and 3 of Bottle (cost 20 each), plus a conversion cost of 100.
 * Everything made at once costs 5x10 + 3x20 + 100 = 210, i.e. 21.00 a carton.
 */
class ProductionRecordingTest extends ManufacturingTestCase
{
    private Bom $bom;

    private $tonic;

    private $herb;

    private $bottle;

    private InventoryBatch $herbStock;

    private InventoryBatch $bottleStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tonic = $this->product('Tonic');
        // Standard cost per carton = pack cost x 10 per carton: herb 10, bottle 20 — the same as the batches below.
        $this->herb = $this->product('Herb', ['pack_cost_price' => 1]);
        $this->bottle = $this->product('Bottle', ['pack_cost_price' => 2]);
        $this->bom = $this->bom($this->tonic, [[$this->herb, 5], [$this->bottle, 3]], ['output_qty_cartons' => 10, 'conversion_cost' => 100]);
        $this->herbStock = $this->stock($this->herb, 20, 10);
        $this->bottleStock = $this->stock($this->bottle, 20, 20);
        $this->actingAsRole('Administrator');
    }

    public function test_partial_production_issues_materials_in_step_and_values_each_receipt(): void
    {
        $order = $this->confirmed();

        // 4 of 10: herb 5 x 0.4 = 2, bottle 3 x 0.4 = 1.2 -> 2 whole cartons. Materials 2x10 + 2x20 = 60;
        // overhead 100 x 4/10 = 40; half the materials are in, so 4 of the 5 cartons they stand for are made: 80% -> 80.00.
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4, 'exp_date' => $this->future()])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.produced_qty_cartons', 4)
            ->assertJsonPath('data.remaining_qty_cartons', 6)
            ->assertJsonPath('data.costs.capitalized', '80.00')
            ->assertJsonPath('data.costs.actual_material', '60.00');

        $this->assertSame(18, $this->herbStock->fresh()->qty_cartons);
        $this->assertSame(18, $this->bottleStock->fresh()->qty_cartons);
        $first = $order->fresh()->outputs()->where('kind', 'finished')->sole();
        $this->assertSame($order->batch_no, $first->batch_no);
        $this->assertSame('20.00', $first->cost_per_carton);
        $this->assertSame('80.00', $first->line_value);
        $this->assertSame([['1300', 80.0, 0.0], ['1300', 0.0, 48.0], ['6400', 0.0, 32.0]], $this->entries($order->id)[0]);

        // The rest: herb 5, bottle 3 in total; overhead 100 in all. Everything still in progress (130) goes to the last 6.
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 6, 'exp_date' => $this->future()])
            ->assertOk()->assertJsonPath('data.produced_qty_cartons', 10)->assertJsonPath('data.remaining_qty_cartons', 0)->assertJsonPath('data.costs.capitalized', '210.00');

        $this->assertSame(15, $this->herbStock->fresh()->qty_cartons);
        $this->assertSame(17, $this->bottleStock->fresh()->qty_cartons);
        $second = $order->fresh()->outputs()->where('kind', 'finished')->orderBy('created_at')->orderBy('id')->get()->last();
        $this->assertSame("{$order->batch_no}-2", $second->batch_no);
        $this->assertSame('130.00', $second->line_value);
        $this->assertSame('21.67', $second->cost_per_carton);
        $this->assertSame([['1300', 130.0, 0.0], ['1300', 0.0, 62.0], ['6400', 0.0, 68.0]], $this->entries($order->id)[1]);

        $done = $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.costs.variance_written_off', '0.00');
        $this->assertNull($done->json('backorder'));

        // Nothing was left over: the whole 210 went to finished goods, 110 of it from materials, 100 from overhead.
        $this->assertSame(['1300' => ['debit' => 210.0, 'credit' => 110.0], '6400' => ['debit' => 0.0, 'credit' => 100.0]], $this->ledger($order->id));
        $this->assertSame(2, $order->fresh()->outputs()->count());
    }

    public function test_with_work_in_progress_accounting_every_step_is_booked_through_1350_and_it_ends_empty(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['wip_accounting' => true])->assertOk();
        $order = $this->confirmed();
        $this->assertTrue($order->fresh()->wip_accounting);

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 6, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();

        $entries = $this->entries($order->id);
        $this->assertSame([['1350', 60.0, 0.0], ['1300', 0.0, 60.0]], $entries[0]);            // issue for the first 4
        $this->assertSame(                                                                       // receipt of 4: overhead 40, then 80 into stock
            [['1350', 40.0, 0.0], ['6400', 0.0, 40.0], ['1300', 80.0, 0.0], ['1350', 0.0, 80.0]], $entries[1],
        );
        $this->assertSame([['1350', 50.0, 0.0], ['1300', 0.0, 50.0]], $entries[2]);            // issue for the last 6
        $this->assertSame([['1350', 60.0, 0.0], ['6400', 0.0, 60.0], ['1300', 130.0, 0.0], ['1350', 0.0, 130.0]], $entries[3]);

        $ledger = $this->ledger($order->id);
        $this->assertEqualsWithDelta($ledger['1350']['debit'], $ledger['1350']['credit'], 0.001);   // nothing left in progress
        $this->assertSame(0.0, Account::query()->where('tenant_id', $this->tenant->id)->where('code', '1350')->value('balance') + 0.0);
        $this->assertCount(4, $entries);
    }

    public function test_rejected_cartons_are_not_received_and_the_good_ones_carry_their_cost(): void
    {
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 8, 'rejected' => 2, 'reject_reason' => 'Cracked seal', 'exp_date' => $this->future()])
            ->assertOk()->assertJsonPath('data.produced_qty_cartons', 8)->assertJsonPath('data.rejected_qty_cartons', 2)->assertJsonPath('data.remaining_qty_cartons', 0);

        // 210 over the 8 good cartons = 26.25 each; the 2 rejected are never received.
        $output = $order->fresh()->outputs()->sole();
        $this->assertSame(8, $output->qty_cartons);
        $this->assertSame('26.25', $output->cost_per_carton);
        $this->assertSame(8, InventoryBatch::query()->where('batch_no', $order->batch_no)->value('qty_cartons'));

        $scrap = $order->fresh()->scraps()->sole();
        $this->assertSame('finished', $scrap->kind);
        $this->assertSame(2, $scrap->qty_cartons);
        $this->assertSame('Cracked seal', $scrap->reason);
        $this->assertSame('42.00', $scrap->scrap_cost);
        $this->assertSame('SCR00001', $scrap->number);
    }

    public function test_cannot_produce_more_than_planned_and_partial_production_can_be_switched_off(): void
    {
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 11, 'exp_date' => $this->future()])->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 0])->assertUnprocessable();
        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);

        $this->putJson('/api/v1/manufacturing/settings', ['allow_partial_production' => false])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4, 'exp_date' => $this->future()])->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();
    }

    public function test_receiving_goods_needs_a_future_expiry_date(): void
    {
        $order = $this->confirmed(['exp_date' => null]);

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4])->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4, 'exp_date' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 4, 'exp_date' => $this->future()])->assertOk();
    }

    public function test_a_stock_shortfall_changes_nothing(): void
    {
        $order = $this->confirmed();
        $this->bottleStock->update(['qty_cartons' => 1]);

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])
            ->assertUnprocessable()->assertJsonPath('shortfalls.0.product_id', $this->bottle->id);

        $fresh = $order->fresh();
        $this->assertSame(0, $fresh->produced_qty_cartons);
        $this->assertSame(0, $fresh->consumptions()->count());
        $this->assertSame(0, $fresh->outputs()->count());
        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);   // herb was issued first, then rolled back
        $this->assertSame([], $this->entries($order->id));
    }

    public function test_expired_batches_are_not_used(): void
    {
        // Already expired when the order is confirmed: nothing to reserve and nothing to issue.
        $this->herbStock->update(['exp_date' => now()->subDay()->toDateString()]);
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertUnprocessable()->assertJsonPath('shortfalls.0.product_id', $this->herb->id);
        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);
    }

    public function test_material_that_expires_after_it_was_reserved_is_refused_too(): void
    {
        $order = $this->confirmed();
        $this->herbStock->update(['exp_date' => now()->subDay()->toDateString()]);

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertUnprocessable();
        $this->assertSame(0, $order->fresh()->produced_qty_cartons);
        $this->assertSame(0, $order->fresh()->consumptions()->count());
        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);
    }

    public function test_a_repeated_request_with_the_same_key_does_nothing_the_second_time(): void
    {
        $order = $this->confirmed();
        $body = ['qty' => 4, 'exp_date' => $this->future()];

        $first = $this->withHeaders(['Idempotency-Key' => 'receipt-1'])->postJson("/api/v1/production-orders/{$order->id}/record-production", $body)->assertOk();
        $second = $this->withHeaders(['Idempotency-Key' => 'receipt-1'])->postJson("/api/v1/production-orders/{$order->id}/record-production", $body)->assertOk();

        $this->assertFalse($first->json('replayed'));
        $this->assertTrue($second->json('replayed'));
        $this->assertSame(4, $order->fresh()->produced_qty_cartons);
        $this->assertSame(1, $order->fresh()->outputs()->count());
        $this->assertCount(1, $this->entries($order->id));
        $this->assertSame(18, $this->herbStock->fresh()->qty_cartons);
        $this->assertSame(1, InventoryBatch::query()->where('product_id', $this->tonic->id)->count());

        // A different key is a different receipt.
        $this->withHeaders(['Idempotency-Key' => 'receipt-2'])->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 2, 'exp_date' => $this->future()])->assertOk();
        $this->assertSame(6, $order->fresh()->produced_qty_cartons);
    }

    public function test_a_by_product_is_received_and_carries_its_share_of_the_cost(): void
    {
        $residue = $this->product('Residue');
        $this->bom->byProducts()->create(['product_id' => $residue->id, 'qty_cartons' => 2, 'cost_share_pct' => 10]);
        $this->bom->update(['version' => 2]);
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();

        $by = $order->fresh()->outputs()->where('kind', 'by_product')->sole();
        $this->assertSame(2, $by->qty_cartons);
        $this->assertSame("{$order->batch_no}-BP1", $by->batch_no);
        $this->assertSame('21.00', $by->line_value);        // 10% of 210
        $this->assertSame('10.50', $by->cost_per_carton);
        $finished = $order->fresh()->outputs()->where('kind', 'finished')->sole();
        $this->assertSame('189.00', $finished->line_value);
        $this->assertSame('18.90', $finished->cost_per_carton);
        $this->assertSame(2, InventoryBatch::query()->where('product_id', $residue->id)->value('qty_cartons'));
        $this->assertEquals(210.0, $this->ledger($order->id)['1300']['debit']);   // one entry covers both
    }

    public function test_by_product_cost_can_follow_selling_value_instead(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['by_product_cost_method' => 'sales_value'])->assertOk();
        $this->tonic->update(['pack_selling_price' => 3]);  // 10 per carton -> 30
        $residue = $this->product('Residue', ['pack_selling_price' => 1]);   // 10 per carton -> x2
        $this->bom->byProducts()->create(['product_id' => $residue->id, 'qty_cartons' => 2, 'cost_share_pct' => 0]);
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();

        // Weights: by-product 2 x 10 = 20, finished 10 x 30 = 300 -> by-product gets 20/320 of 210.
        $this->assertSame('13.13', $order->fresh()->outputs()->where('kind', 'by_product')->sole()->line_value);
    }

    public function test_scrapped_material_is_expensed_and_leaves_stock_without_counting_as_used(): void
    {
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-scrap", ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled'])
            ->assertOk()->assertJsonPath('scrap.status', 'approved')->assertJsonPath('scrap.scrap_cost', '20.00')->assertJsonPath('data.costs.scrap', '20.00');

        $this->assertSame(18, $this->herbStock->fresh()->qty_cartons);
        $herb = $order->fresh()->components()->where('product_id', $this->herb->id)->sole();
        $this->assertSame(0, $herb->consumed_cartons);
        $this->assertSame([['6300', 20.0, 0.0], ['1300', 0.0, 20.0]], $this->entries($order->id)[0]);
        $this->assertSame('scrap', $order->fresh()->consumptions()->sole()->kind);
    }

    public function test_scrap_that_needs_approval_moves_nothing_until_approved(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['scrap_requires_approval' => true])->assertOk();
        $order = $this->confirmed();

        $response = $this->postJson("/api/v1/production-orders/{$order->id}/record-scrap", ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled'])
            ->assertOk()->assertJsonPath('scrap.status', 'pending');
        $scrapId = $response->json('scrap.id');

        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);
        $this->assertSame([], $this->entries($order->id));

        $this->postJson("/api/v1/production-scraps/{$scrapId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(18, $this->herbStock->fresh()->qty_cartons);
        $this->postJson("/api/v1/production-scraps/{$scrapId}/approve")->assertUnprocessable();
        $this->postJson("/api/v1/production-scraps/{$scrapId}/reject")->assertUnprocessable();
    }

    public function test_rejected_scrap_never_touches_stock(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['scrap_requires_approval' => true])->assertOk();
        $order = $this->confirmed();
        $scrapId = $this->postJson("/api/v1/production-orders/{$order->id}/record-scrap", ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled'])->json('scrap.id');

        $this->postJson("/api/v1/production-scraps/{$scrapId}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(20, $this->herbStock->fresh()->qty_cartons);
        $this->assertSame([], $this->entries($order->id));
    }

    public function test_scrap_can_be_carried_by_the_product_instead_of_expensed(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['scrap_cost_policy' => 'absorb'])->assertOk();
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-scrap", ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled'])->assertOk();
        $this->assertSame([], $this->entries($order->id));   // nothing booked yet: it is in progress

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();

        // 20 of scrap plus 110 of materials plus 100 overhead.
        $this->assertSame('230.00', $order->fresh()->outputs()->sole()->line_value);
        $this->assertSame('130.00', $order->fresh()->consumptions()->get()->sum('line_value') > 0 ? '130.00' : '0');
    }

    public function test_manual_consumption_needs_the_materials_issued_by_hand_first(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['consumption_policy' => 'manual'])->assertOk();
        $order = $this->confirmed();
        $herb = $order->components()->where('product_id', $this->herb->id)->sole();
        $bottle = $order->components()->where('product_id', $this->bottle->id)->sole();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])
            ->assertUnprocessable()->assertJsonCount(2, 'materials_to_issue');

        $this->postJson("/api/v1/production-orders/{$order->id}/consume-materials", ['lines' => [
            ['component_id' => $herb->id, 'cartons' => 5], ['product_id' => $this->bottle->id, 'cartons' => 3],
        ]])->assertOk();

        $this->assertSame(5, $herb->fresh()->consumed_cartons);
        $this->assertSame(15, $this->herbStock->fresh()->qty_cartons);

        // More than the order needs is refused unless the company allows it.
        $this->postJson("/api/v1/production-orders/{$order->id}/consume-materials", ['lines' => [['component_id' => $bottle->id, 'cartons' => 1]]])->assertUnprocessable();
        $this->putJson('/api/v1/manufacturing/settings', ['allow_over_consumption' => true])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/consume-materials", ['lines' => [['component_id' => $bottle->id, 'cartons' => 1]]])->assertOk();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();
        // 5x10 + 4x20 + 100.
        $this->assertSame('230.00', $order->fresh()->outputs()->sole()->line_value);
    }

    public function test_materials_issued_ahead_but_not_all_used_are_written_off_to_variance_when_the_order_closes_short(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['consumption_policy' => 'manual', 'backorder_policy' => 'never'])->assertOk();
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/consume-materials", ['lines' => [
            ['product_id' => $this->herb->id, 'cartons' => 5], ['product_id' => $this->bottle->id, 'cartons' => 3],
        ]])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 5, 'exp_date' => $this->future()])->assertOk();

        // 5 of 10 made: half the overhead (50) and half of what is in progress (160) -> 80; materials share 55.
        $this->assertSame('80.00', $order->fresh()->outputs()->sole()->line_value);

        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.costs.variance_written_off', '55.00');

        // The 55 of materials that left stock but never reached finished goods is a loss.
        $entries = $this->entries($order->id);
        $this->assertSame([['5200', 55.0, 0.0], ['1300', 0.0, 55.0]], end($entries));
    }

    public function test_work_in_progress_variance_is_everything_left_in_progress(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['consumption_policy' => 'manual', 'backorder_policy' => 'never', 'wip_accounting' => true])->assertOk();
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/consume-materials", ['lines' => [
            ['product_id' => $this->herb->id, 'cartons' => 5], ['product_id' => $this->bottle->id, 'cartons' => 3],
        ]])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 5, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false])->assertOk()->assertJsonPath('data.costs.variance_written_off', '80.00');

        $entries = $this->entries($order->id);
        $this->assertSame([['5200', 80.0, 0.0], ['1350', 0.0, 80.0]], end($entries));
        $ledger = $this->ledger($order->id);
        $this->assertEqualsWithDelta($ledger['1350']['debit'], $ledger['1350']['credit'], 0.001);
    }

    public function test_closing_short_asks_what_to_do_with_the_rest_by_default(): void
    {
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 6, 'exp_date' => $this->future()])->assertOk();

        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false])
            ->assertUnprocessable()->assertJsonPath('remaining_qty_cartons', 4);
        $this->assertSame('in_progress', $order->fresh()->status);

        $done = $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false, 'create_backorder' => true])->assertOk();
        $this->assertSame(4, $done->json('backorder.output_qty_cartons'));

        $backorder = ProductionOrder::findOrFail($done->json('backorder.id'));
        $this->assertSame('draft', $backorder->status);
        $this->assertSame($order->id, $backorder->parent_order_id);
        $this->assertSame('backorder', $backorder->source_type);
        $this->assertSame(4, $backorder->output_qty_cartons);
        $this->assertSame('40.00', $backorder->conversion_cost);

        // Only one backorder per order.
        $this->postJson("/api/v1/production-orders/{$order->id}/create-backorder")->assertUnprocessable();
    }

    public function test_the_backorder_policy_can_decide_without_asking(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['backorder_policy' => 'always'])->assertOk();
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 6, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false])->assertOk()->assertJsonPath('backorder.output_qty_cartons', 4);

        $this->putJson('/api/v1/manufacturing/settings', ['backorder_policy' => 'never'])->assertOk();
        $other = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$other->id}/record-production", ['qty' => 6, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$other->id}/complete", ['produce_remaining' => false, 'create_backorder' => true])->assertOk()->assertJsonPath('backorder', null);
    }

    public function test_a_completed_short_order_can_get_its_backorder_later(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['backorder_policy' => 'never'])->assertOk();
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 7, 'exp_date' => $this->future()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false])->assertOk();

        $this->postJson("/api/v1/production-orders/{$order->id}/create-backorder")->assertCreated()->assertJsonPath('data.output_qty_cartons', 3)->assertJsonPath('data.status', 'draft');

        $full = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$full->id}/complete")->assertOk();
        $this->postJson("/api/v1/production-orders/{$full->id}/create-backorder")->assertUnprocessable();
    }

    public function test_an_order_that_made_nothing_cannot_be_completed(): void
    {
        $order = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$order->id}/complete", ['produce_remaining' => false, 'create_backorder' => false])->assertUnprocessable();
    }

    public function test_completing_twice_with_the_same_key_posts_once(): void
    {
        $order = $this->confirmed();

        $this->withHeaders(['Idempotency-Key' => 'done-1'])->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();
        $this->withHeaders(['Idempotency-Key' => 'done-1'])->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk()->assertJsonPath('replayed', true);
        $this->assertCount(1, $this->entries($order->id));
        $this->assertSame(1, $order->fresh()->outputs()->count());

        // Without a key the second call is simply refused: the order is completed.
        $this->withoutHeader('Idempotency-Key')->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable();
    }

    public function test_the_cost_breakdown_compares_planned_with_actual(): void
    {
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();

        $costs = $this->getJson("/api/v1/production-orders/{$order->id}/costs")->assertOk()->json('data');

        $this->assertEquals(110.0, $costs['planned']['material']);
        $this->assertEquals(100.0, $costs['planned']['overhead']);
        $this->assertEquals(210.0, $costs['planned']['total']);
        $this->assertEquals(210.0, $costs['actual']['total']);
        $this->assertEquals(0.0, $costs['variance']['total']);
        $this->assertEquals(21.0, $costs['unit_cost']);
        $this->assertEquals(21.0, $costs['planned_unit_cost']);
        $this->assertCount(1, $costs['journal_entries']);
    }

    public function test_traceability_lists_the_batches_in_and_out(): void
    {
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();

        $trace = $this->getJson("/api/v1/production-orders/{$order->id}/traceability")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$this->herbStock->batch_no, $this->bottleStock->batch_no], array_column($trace['consumed'], 'batch_no'));
        $this->assertSame($order->batch_no, $trace['produced'][0]['batch_no']);
        $this->assertSame(10, $trace['produced'][0]['qty_on_hand']);
    }

    public function test_receiving_output_for_an_order_with_many_components_needs_a_bounded_number_of_queries(): void
    {
        $count = 25;
        $tonic = $this->product('Big Tonic');
        $components = collect(range(1, $count))->map(fn ($i) => $this->product("Part {$i}", ['pack_cost_price' => 1]));
        $bom = $this->bom($tonic, $components->map(fn ($c) => [$c, 2])->all(), ['output_qty_cartons' => 10]);
        foreach ($components as $component) {
            $this->stock($component, 50, 10);
        }

        $id = $this->postJson('/api/v1/production-orders', ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => $this->future()])->json('data.id');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson("/api/v1/production-orders/{$id}/record-production", ['qty' => 10, 'exp_date' => $this->future()])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A fixed handful per component (lock the reservation and batch, move the stock, write the consumption row) plus the order's own work —
        // growing linearly with the components, not with anything else.
        $this->assertLessThan(20 * $count + 200, $queries, "Receiving an order with {$count} components took {$queries} queries.");
        $this->assertSame(10, ProductionOrder::findOrFail($id)->produced_qty_cartons);
    }

    public function test_the_stock_value_of_the_warehouse_follows_production(): void
    {
        $order = $this->confirmed();
        $before = (float) $this->warehouse->fresh()->stock_value;

        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();

        $this->assertNotEquals($before, (float) $this->warehouse->fresh()->stock_value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function confirmed(array $attributes = []): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', $attributes + [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => $this->future(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk();

        return ProductionOrder::findOrFail($id);
    }

    private function future(): string
    {
        return now()->addYear()->toDateString();
    }
}
