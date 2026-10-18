<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use Modules\Sales\Models\SalesOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CrmUpdateDeleteImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @return array<string, array{0: string, 1: class-string, 2: array<string, mixed>, 3: string}>
     */
    public static function editableRecords(): array
    {
        return [
            'lead' => ['leads', Lead::class, ['name' => 'Renamed lead'], 'name'],
            'campaign' => ['campaigns', Campaign::class, ['name' => 'Renamed campaign'], 'name'],
            'complaint' => ['complaints', Complaint::class, ['priority' => 'high'], 'priority'],
            'visit' => ['visits', CustomerVisit::class, ['notes' => 'Revisit next week'], 'notes'],
            'customer' => ['customers', Customer::class, ['name' => 'Renamed customer'], 'name'],
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('editableRecords')]
    public function test_edits_and_deletes_a_record_and_audits_both(string $path, string $model, array $change, string $field): void
    {
        $this->actingAsRole('Administrator');
        $record = $model::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/{$path}/{$record->id}", $change)->assertOk()->assertJsonPath("data.{$field}", $change[$field]);
        $this->assertSame($change[$field], $record->fresh()->{$field});

        $entity = class_basename($model);
        $this->assertDatabaseHas('audit_log', ['entity_type' => $entity, 'entity_id' => $record->id, 'operation' => 'UPDATE']);

        $this->deleteJson("/api/v1/{$path}/{$record->id}")->assertNoContent();
        $this->assertDatabaseMissing($record->getTable(), ['id' => $record->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => $entity, 'entity_id' => $record->id, 'operation' => 'DELETE']);
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('editableRecords')]
    public function test_edit_and_delete_follow_the_permissions_an_admin_assigns(string $path, string $model, array $change): void
    {
        $record = $model::factory()->recycle($this->tenant)->create();

        // Customer Service can edit CRM records but has no delete permission.
        $user = $this->actingAsRole('Customer Service');
        $this->putJson("/api/v1/{$path}/{$record->id}", $change)->assertOk();
        $this->deleteJson("/api/v1/{$path}/{$record->id}")->assertForbidden();

        // Once an admin grants crm.delete to the role, the same user can delete.
        $user->roles->first()->givePermissionTo("{$path}.delete");
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->deleteJson("/api/v1/{$path}/{$record->id}")->assertNoContent();

        // Auditor has crm.view but not crm.edit.
        $other = $model::factory()->recycle($this->tenant)->create();
        $this->actingAsRole('Auditor');
        $this->putJson("/api/v1/{$path}/{$other->id}", $change)->assertForbidden();
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('editableRecords')]
    public function test_another_tenants_record_is_not_found(string $path, string $model, array $change): void
    {
        $this->actingAsRole('Administrator');
        $foreign = $model::factory()->create();

        $this->putJson("/api/v1/{$path}/{$foreign->id}", $change)->assertNotFound();
        $this->deleteJson("/api/v1/{$path}/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas($foreign->getTable(), ['id' => $foreign->id]);
    }

    public function test_a_customer_with_sales_orders_cannot_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $customer = Customer::factory()->recycle($this->tenant)->create();
        SalesOrder::factory()->recycle($this->tenant)->create(['customer_id' => $customer->id]);

        $this->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This Customer is referenced by other records and cannot be deleted.');
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_imports_customers_creating_new_ones_and_skipping_existing_by_default(): void
    {
        $this->actingAsRole('Administrator');
        $rep = User::factory()->recycle($this->tenant)->create(['email' => 'rep@example.com']);
        Customer::factory()->recycle($this->tenant)->create(['name' => 'Existing Farm', 'city' => 'Old']);
        $csv = "Name,Type,Phone,City,Credit Limit,Sales Rep Email\n"
            ."Nile Clinic,Clinic,01000000000,Cairo,5000,rep@example.com\n"
            ."Existing Farm,Farm,,Giza,,\n";

        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Nile Clinic', 'phone' => '01000000000', 'credit_limit' => '5000.00', 'sales_rep_id' => $rep->id]);
        $this->assertDatabaseHas('customers', ['name' => 'Existing Farm', 'city' => 'Old']);
    }

    public function test_update_existing_updates_matches_without_blanking_other_fields(): void
    {
        $this->actingAsRole('Administrator');
        Customer::factory()->recycle($this->tenant)->create(['name' => 'Existing Farm', 'city' => 'Old', 'phone' => '0111']);
        $csv = "Name,Type,City,Phone\nExisting Farm,Farm,Giza,\n";

        $this->postJson('/api/v1/customers/import', ['update_existing' => true, 'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Existing Farm', 'city' => 'Giza', 'phone' => '0111']);
    }

    public function test_one_invalid_row_imports_nothing(): void
    {
        $this->actingAsRole('Administrator');
        $csv = "Name,Type,Email\nGood One,Clinic,\nBad One,Spaceship,not-an-email\n";

        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonStructure(['errors' => [['row', 'errors' => ['type', 'email']]]]);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_sales_rep_imports_customers_under_their_own_name_only(): void
    {
        $rep = $this->actingAsRole('Sales Rep');
        $other = User::factory()->recycle($this->tenant)->create(['email' => 'other@example.com']);

        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "Name,Type\nMine,Clinic\n")])->assertOk();
        $this->assertDatabaseHas('customers', ['name' => 'Mine', 'sales_rep_id' => $rep->id]);

        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "Name,Type,Sales Rep Email\nTheirs,Clinic,{$other->email}\n")])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('customers', ['name' => 'Theirs']);
    }

    public function test_importing_requires_create_permission_and_a_real_spreadsheet(): void
    {
        $this->actingAsRole('Auditor');
        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "Name,Type\nX,Clinic\n")])->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->postJson('/api/v1/customers/import', [])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "Name,Type\n")])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The file has no data rows.');
        $this->get('/api/v1/customers/import-template')->assertOk()->assertDownload('customers-import-template.xlsx');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
