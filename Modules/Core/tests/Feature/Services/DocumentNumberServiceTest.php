<?php

namespace Modules\Core\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Core\Services\DocumentNumberService;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_count_up_without_gaps_per_company_and_kind(): void
    {
        $numbers = app(DocumentNumberService::class);
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();

        $this->assertSame('INV-000001', $numbers->next($first->id, 'invoice', 'INV-'));
        $this->assertSame('INV-000002', $numbers->next($first->id, 'invoice', 'INV-'));
        $this->assertSame('SO-000001', $numbers->next($first->id, 'sales_order', 'SO-'));
        $this->assertSame('INV-000001', $numbers->next($second->id, 'invoice', 'INV-'));
    }

    public function test_peeking_shows_the_next_number_without_using_it(): void
    {
        $numbers = app(DocumentNumberService::class);
        $tenant = Tenant::factory()->create();

        $this->assertSame('INV-000001', $numbers->peek($tenant->id, 'invoice', 'INV-'));
        $this->assertSame('INV-000001', $numbers->peek($tenant->id, 'invoice', 'INV-'));
        $this->assertSame('INV-000001', $numbers->next($tenant->id, 'invoice', 'INV-'));
        $this->assertSame('INV-000002', $numbers->peek($tenant->id, 'invoice', 'INV-'));
    }

    public function test_starting_at_a_number_only_ever_moves_the_counter_forward(): void
    {
        $numbers = app(DocumentNumberService::class);
        $tenant = Tenant::factory()->create();

        $numbers->startAt($tenant->id, 'invoice', 10);
        $numbers->startAt($tenant->id, 'invoice', 5);

        $this->assertSame('INV-000010', $numbers->next($tenant->id, 'invoice', 'INV-'));
    }

    public function test_the_prefix_and_padding_are_applied(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('PRD-00001', app(DocumentNumberService::class)->next($tenant->id, 'product', 'PRD-', 5));
    }
}
