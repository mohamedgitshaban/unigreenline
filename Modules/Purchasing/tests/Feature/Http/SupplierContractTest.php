<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class SupplierContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_uploads_a_contract_and_attaches_it_on_create(): void
    {
        $this->actingAsRole('Purchasing');

        $upload = $this->postJson('/api/v1/suppliers/contracts', [
            'contract' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ]);

        $upload->assertCreated();
        $path = $upload->json('data.contract_data');
        Storage::assertExists($path);

        $response = $this->postJson('/api/v1/suppliers', ['name' => 'Delta Pharma', 'contract_data' => $path]);

        $response->assertCreated();
        $response->assertJsonPath('data.contract_data', $path);
        $this->assertStringEndsWith("/api/v1/suppliers/{$response->json('data.id')}/contract", $response->json('data.contract_url'));
    }

    public function test_a_path_not_from_the_upload_endpoint_is_rejected(): void
    {
        $this->actingAsRole('Purchasing');
        $otherTenantPath = UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')
            ->store(Supplier::contractDirectory(Tenant::factory()->create()->id));

        $this->postJson('/api/v1/suppliers', ['name' => 'Delta Pharma', 'contract_data' => $otherTenantPath])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_data');
    }

    public function test_replacing_the_contract_on_update_deletes_the_old_file(): void
    {
        $this->actingAsRole('Purchasing');
        $directory = Supplier::contractDirectory($this->tenant->id);
        $oldPath = UploadedFile::fake()->create('old.pdf', 10, 'application/pdf')->store($directory);
        $newPath = UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')->store($directory);
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['contract_data' => $oldPath]);

        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['contract_data' => $newPath])
            ->assertOk()
            ->assertJsonPath('data.contract_data', $newPath);

        Storage::assertMissing($oldPath);
        Storage::assertExists($newPath);
    }

    public function test_downloads_the_contract(): void
    {
        $this->actingAsRole('Purchasing');
        $path = UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')->store(Supplier::contractDirectory($this->tenant->id));
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['contract_data' => $path]);

        $this->get("/api/v1/suppliers/{$supplier->id}/contract")
            ->assertOk()
            ->assertDownload("contract-{$supplier->id}.pdf");
    }

    public function test_downloading_another_tenants_contract_is_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $supplier = Supplier::factory()->create(['contract_data' => 'supplier-contracts/x/abc.pdf']);

        $this->getJson("/api/v1/suppliers/{$supplier->id}/contract")->assertNotFound();
    }

    public function test_role_without_purchasing_access_cannot_upload(): void
    {
        $this->actingAsRole('Sales Rep');

        $this->postJson('/api/v1/suppliers/contracts', [
            'contract' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
