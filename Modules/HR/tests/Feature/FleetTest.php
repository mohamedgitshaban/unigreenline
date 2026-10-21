<?php

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Models\Employee;
use Modules\HR\Models\FleetServiceType;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;
use Modules\HR\Models\VehicleService;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->hr = $this->user('HR Manager');
        Sanctum::actingAs($this->hr);
    }

    private function user(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);

        return $user;
    }

    private function vehicle(array $overrides = []): string
    {
        return $this->postJson('/api/v1/vehicles', $overrides + ['license_plate' => 'ABC-1234', 'brand' => 'Toyota', 'model' => 'Hilux', 'vehicle_type' => 'truck', 'fuel_type' => 'diesel', 'odometer' => 10000])->assertCreated()->json('data.id');
    }

    private function serviceType(): string
    {
        return collect($this->getJson('/api/v1/fleet-service-types')->json('data'))->firstWhere('name', 'Oil change')['id'];
    }

    public function test_a_vehicle_is_registered_with_a_first_odometer_reading(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2025-01-10']);

        $this->getJson("/api/v1/vehicles/{$id}")->assertOk()->assertJsonPath('data.license_plate', 'ABC-1234')->assertJsonPath('data.status', 'active')->assertJsonPath('data.odometer', '10000.0')->assertJsonPath('data.driver', null);
        $this->getJson("/api/v1/vehicle-odometer-logs?vehicle_id={$id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.logged_on', '2025-01-10')->assertJsonPath('data.0.notes', 'Initial reading');
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Vehicle', 'entity_id' => $id, 'operation' => 'INSERT']);
    }

    public function test_a_plate_is_unique_in_the_company_and_the_values_are_checked(): void
    {
        $this->vehicle();

        $this->postJson('/api/v1/vehicles', ['license_plate' => 'ABC-1234'])->assertUnprocessable()->assertJsonValidationErrors('license_plate');
        $this->postJson('/api/v1/vehicles', [])->assertUnprocessable()->assertJsonValidationErrors('license_plate');
        $this->postJson('/api/v1/vehicles', ['license_plate' => 'X-1', 'fuel_type' => 'steam', 'model_year' => 1800, 'odometer' => -5])->assertUnprocessable()->assertJsonValidationErrors(['fuel_type', 'model_year', 'odometer']);
        Vehicle::factory()->create(['license_plate' => 'FOREIGN-1']);
        $this->postJson('/api/v1/vehicles', ['license_plate' => 'FOREIGN-1'])->assertCreated();
    }

    public function test_the_odometer_is_changed_only_through_readings_not_by_editing_the_vehicle(): void
    {
        $id = $this->vehicle();

        $this->putJson("/api/v1/vehicles/{$id}", ['odometer' => 1, 'color' => 'White'])->assertOk()->assertJsonPath('data.color', 'White')->assertJsonPath('data.odometer', '10000.0');
    }

    public function test_assigning_a_driver_closes_the_previous_turn_and_keeps_the_history(): void
    {
        $id = $this->vehicle();
        $omar = Employee::factory()->recycle($this->tenant)->create(['name' => 'Omar']);
        $sara = Employee::factory()->recycle($this->tenant)->create(['name' => 'Sara']);

        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => $omar->id, 'date' => '2026-01-01'])->assertOk()->assertJsonPath('data.driver.name', 'Omar');
        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => $sara->id, 'date' => '2026-03-01'])->assertOk()->assertJsonPath('data.driver.name', 'Sara');

        $history = $this->getJson("/api/v1/vehicles/{$id}/assignments")->assertOk()->json('data');
        $this->assertSame(['Sara', 'Omar'], array_column(array_column($history, 'employee'), 'name'));
        $this->assertNull($history[0]['ends_on']);
        $this->assertSame('2026-02-28', $history[1]['ends_on']);

        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => null, 'date' => '2026-06-01'])->assertOk()->assertJsonPath('data.driver', null);
        $this->assertSame('2026-05-31', $this->getJson("/api/v1/vehicles/{$id}/assignments")->json('data.0.ends_on'));
        $this->getJson('/api/v1/vehicles?driver_employee_id='.$omar->id)->assertJsonCount(0, 'data');
    }

    public function test_only_an_active_employee_of_the_company_can_drive_and_a_sold_vehicle_cannot_be_assigned(): void
    {
        $id = $this->vehicle();
        $gone = Employee::factory()->recycle($this->tenant)->create(['status' => 'inactive']);

        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => $gone->id])->assertUnprocessable()->assertJsonPath('message', 'Only an active employee can be given a vehicle.');
        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => Employee::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", [])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        $this->putJson("/api/v1/vehicles/{$id}", ['status' => 'sold'])->assertOk();
        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => Employee::factory()->recycle($this->tenant)->create()->id])->assertUnprocessable()->assertJsonPath('message', 'A sold vehicle cannot be assigned.');
    }

    public function test_a_driver_with_a_login_hears_when_a_vehicle_is_assigned(): void
    {
        $id = $this->vehicle();
        $driver = $this->user('Employee');
        $driver->givePermissionTo('vehicles.view');
        $employee = Employee::factory()->recycle($this->tenant)->create(['user_id' => $driver->id]);

        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => $employee->id])->assertOk();

        $note = Notification::query()->where('user_id', $driver->id)->sole();
        $this->assertSame('Vehicle updated', $note->title);
        $this->assertSame("/api/v1/vehicles/{$id}", $note->link_url);
    }

    public function test_odometer_readings_must_fit_between_the_neighbouring_ones(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2026-01-01']);

        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-02-01', 'value' => 10500])->assertCreated();
        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-03-01', 'value' => 11200])->assertCreated();
        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.odometer', '11200.0');

        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-04-01', 'value' => 11000])->assertUnprocessable()->assertJsonPath('message', 'The odometer cannot go backwards: it read 11200.0 on 2026-03-01.');
        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-02-15', 'value' => 11500])->assertUnprocessable();
        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-02-15', 'value' => 10800])->assertCreated();
        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.odometer', '11200.0');

        $this->getJson("/api/v1/vehicle-odometer-logs?vehicle_id={$id}&from=2026-02-10")->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'value' => 12000, 'logged_on' => now()->addDay()->toDateString()])->assertUnprocessable();
    }

    public function test_refuelling_works_out_the_amount_or_the_price_and_logs_the_odometer(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2026-01-01']);

        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-02-01', 'liters' => 40, 'price_per_liter' => 12.5, 'odometer' => 10400])
            ->assertCreated()->assertJsonPath('data.amount', '500.00')->assertJsonPath('data.price_per_liter', '12.500');
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-02-10', 'liters' => 30, 'amount' => 360])
            ->assertCreated()->assertJsonPath('data.price_per_liter', '12.000');

        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.odometer', '10400.0');
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-02-11', 'liters' => 10])->assertUnprocessable()->assertJsonPath('message', 'Give the price per litre or the total amount.');
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-02-12', 'liters' => 10, 'amount' => 100, 'odometer' => 10000])->assertUnprocessable();

        $this->putJson('/api/v1/vehicle-fuel-logs/'.$this->getJson("/api/v1/vehicle-fuel-logs?vehicle_id={$id}")->json('data.1.id'), ['liters' => 50])->assertOk()->assertJsonPath('data.amount', '625.00');
        $this->getJson("/api/v1/vehicle-fuel-logs?vehicle_id={$id}")->assertJsonPath('data.0.fueled_on', '2026-02-10');
        $this->get('/api/v1/vehicle-fuel-logs?export=csv')->assertOk()->assertDownload();
    }

    public function test_services_planned_then_done_with_cost_odometer_and_the_next_due_date(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2026-01-01']);
        $type = $this->serviceType();

        $serviceId = $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $type, 'service_date' => now()->addDays(5)->toDateString(), 'description' => '10k service'])
            ->assertCreated()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.service_type.name', 'Oil change')->json('data.id');
        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.status', 'in_maintenance');
        $this->getJson('/api/v1/vehicle-services?due=1')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/vehicle-services/{$serviceId}/mark-done", ['cost' => 850, 'odometer' => 10300, 'next_due_date' => now()->addMonths(6)->toDateString(), 'next_due_odometer' => 15300])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.cost', '850.00')->assertJsonPath('data.odometer', '10300.0');

        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.status', 'active')->assertJsonPath('data.odometer', '10300.0');
        $this->postJson("/api/v1/vehicle-services/{$serviceId}/mark-done")->assertUnprocessable()->assertJsonPath('message', 'This service is already done.');
        $this->getJson('/api/v1/vehicle-services?due=1')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/vehicle-services?vehicle_id={$id}&status=done")->assertJsonCount(1, 'data');
    }

    public function test_a_service_can_be_recorded_as_already_done(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2026-01-01']);

        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => '2026-02-01', 'status' => 'done', 'cost' => 400, 'odometer' => 10200])->assertCreated();

        $this->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.status', 'active')->assertJsonPath('data.odometer', '10200.0');
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => FleetServiceType::factory()->create()->id, 'service_date' => '2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors('service_type_id');
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => '2026-02-01', 'status' => 'done', 'odometer' => 5])->assertUnprocessable();
    }

    public function test_contracts_close_and_the_expiring_ones_can_be_listed(): void
    {
        $id = $this->vehicle();
        $soon = $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'insurance', 'vendor_name' => 'Misr Insurance', 'starts_on' => now()->subYear()->addDays(10)->toDateString(), 'expires_on' => now()->addDays(10)->toDateString(), 'cost' => 3650])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'registration', 'starts_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'starts_on' => '2026-02-01', 'expires_on' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        $this->getJson('/api/v1/vehicle-contracts?expiring_within=30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $soon);
        $this->getJson('/api/v1/vehicle-contracts?type=registration')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/vehicle-contracts/{$soon}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/v1/vehicle-contracts/{$soon}/close")->assertUnprocessable();
        $this->getJson('/api/v1/vehicle-contracts?expiring_within=30')->assertJsonCount(0, 'data');
        $this->get('/api/v1/vehicle-contracts?export=csv')->assertOk()->assertDownload();
    }

    public function test_costs_add_up_fuel_services_and_the_share_of_each_contract_in_the_period(): void
    {
        $id = $this->vehicle(['odometer' => 5000, 'acquisition_date' => '2026-02-20']);
        $this->postJson('/api/v1/vehicle-odometer-logs', ['vehicle_id' => $id, 'logged_on' => '2026-03-10', 'value' => 5600])->assertCreated();
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-03-02', 'liters' => 50, 'amount' => 600])->assertCreated();
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-03-08', 'liters' => 50, 'amount' => 650])->assertCreated();
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => '2026-05-08', 'liters' => 99, 'amount' => 9999])->assertCreated();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => '2026-03-05', 'status' => 'done', 'cost' => 300])->assertCreated();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => '2026-03-06', 'status' => 'cancelled', 'cost' => 7777])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'cost' => 3650, 'frequency' => 'yearly'])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'leasing', 'starts_on' => '2026-03-01', 'expires_on' => '2027-02-28', 'cost' => 100, 'frequency' => 'monthly'])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'registration', 'starts_on' => '2026-03-04', 'expires_on' => '2026-03-04', 'cost' => 250, 'frequency' => 'once'])->assertCreated();

        $costs = $this->getJson("/api/v1/vehicles/{$id}/costs?period=custom&from=2026-03-01&to=2026-03-10")->assertOk()->json('data');

        $this->assertEquals(1250, $costs['total']['fuel_cost']);
        $this->assertEquals(100, $costs['total']['fuel_liters']);
        $this->assertEquals(300, $costs['total']['service_cost']);
        // yearly 3650 over 365 days = 10 a day for 10 days = 100; monthly 100 = 1200 a year = 3.2877 a day for 10 days = 32.88; once = 250
        $this->assertEquals(382.88, $costs['total']['contract_cost']);
        $this->assertEquals(1932.88, $costs['total']['total_cost']);
        $this->assertEquals(600.0, $costs['total']['distance']);
        $this->assertEquals(3.221, $costs['total']['cost_per_distance']);
        $this->assertEquals(6.0, $costs['total']['distance_per_liter']);
        $this->assertSame('2026-03', $costs['by_month'][0]['month']);
        $this->assertEquals(1932.88, $costs['by_month'][0]['total_cost']);
    }

    public function test_the_fleet_analysis_ranks_vehicles_and_raises_alerts(): void
    {
        $cheap = $this->vehicle(['license_plate' => 'CHEAP-1']);
        $dear = $this->vehicle(['license_plate' => 'DEAR-1']);
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $cheap, 'fueled_on' => now()->toDateString(), 'liters' => 10, 'amount' => 100])->assertCreated();
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $dear, 'fueled_on' => now()->toDateString(), 'liters' => 100, 'amount' => 1500])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $dear, 'starts_on' => now()->subYear()->toDateString(), 'expires_on' => now()->subDay()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $cheap, 'starts_on' => now()->subMonth()->toDateString(), 'expires_on' => now()->addDays(10)->toDateString()])->assertCreated();

        $data = $this->getJson('/api/v1/fleet/analysis?period=month')->assertOk()->json('data');

        $this->assertSame(['DEAR-1', 'CHEAP-1'], array_column(array_column($data['vehicles'], 'vehicle'), 'license_plate'));
        $this->assertEquals(1600, $data['totals']['fuel_cost']);
        $this->assertSame(2, $data['vehicles_by_status']['active']);
        $this->assertSame(1, $data['alerts']['contracts_expiring_30_days']);
        $this->assertSame(1, $data['alerts']['contracts_expired']);
        $this->assertSame(2, $data['alerts']['vehicles_without_driver']);
    }

    public function test_reminders_tell_fleet_managers_and_the_driver_once_a_day(): void
    {
        $id = $this->vehicle(['license_plate' => 'REM-1', 'acquisition_date' => '2026-01-01']);
        $driver = $this->user('Employee');
        $driver->givePermissionTo('vehicles.view');
        $this->postJson("/api/v1/vehicles/{$id}/assign-driver", ['employee_id' => Employee::factory()->recycle($this->tenant)->create(['user_id' => $driver->id])->id])->assertOk();
        Notification::query()->delete();

        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'insurance', 'starts_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(12)->toDateString(), 'reminder_days' => 30])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'type' => 'leasing', 'starts_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(200)->toDateString(), 'reminder_days' => 30])->assertCreated();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => now()->addDays(3)->toDateString()])->assertCreated();
        Notification::query()->delete();

        $this->artisan('fleet:remind')->assertSuccessful();

        $types = Notification::query()->where('user_id', $this->hr->id)->pluck('type')->sort()->values()->all();
        $this->assertSame(['fleet.contract_expiring', 'fleet.service_due'], $types);
        $this->assertSame(2, Notification::query()->where('user_id', $driver->id)->count(), 'the driver hears too');
        $this->assertSame("/api/v1/vehicles/{$id}", Notification::query()->where('user_id', $this->hr->id)->first()->link_url);

        $this->artisan('fleet:remind')->assertSuccessful();
        $this->assertSame(2, Notification::query()->where('user_id', $this->hr->id)->count(), 'only once a day');
    }

    public function test_a_finished_service_reminds_when_the_next_one_is_due_unless_it_was_done_again(): void
    {
        $id = $this->vehicle(['license_plate' => 'REM-2', 'acquisition_date' => '2026-01-01']);
        $type = $this->serviceType();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $type, 'service_date' => '2026-02-01', 'status' => 'done', 'next_due_date' => now()->addDays(5)->toDateString()])->assertCreated();
        Notification::query()->delete();

        $this->artisan('fleet:remind')->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('user_id', $this->hr->id)->where('type', 'fleet.service_due')->count());

        Notification::query()->delete();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $type, 'service_date' => '2026-03-01', 'status' => 'done'])->assertCreated();
        VehicleService::query()->update(['last_reminded_on' => null]);
        $this->artisan('fleet:remind')->assertSuccessful();
        $this->assertSame(0, Notification::query()->where('type', 'fleet.service_due')->count(), 'a later service of the same kind was done');
    }

    public function test_a_vehicle_with_records_cannot_be_deleted_and_one_without_can(): void
    {
        $used = $this->vehicle(['license_plate' => 'USED-1']);
        $free = $this->vehicle(['license_plate' => 'FREE-1', 'odometer' => 0]);
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $used, 'fueled_on' => '2026-02-01', 'liters' => 10, 'amount' => 100])->assertCreated();

        $this->deleteJson("/api/v1/vehicles/{$used}")->assertStatus(409);
        $this->deleteJson("/api/v1/vehicles/{$free}")->assertNoContent();
    }

    public function test_service_types_start_with_a_standard_list_and_are_protected_while_used(): void
    {
        $types = $this->getJson('/api/v1/fleet-service-types')->assertOk()->json('data');

        $this->assertSame(['Battery', 'Brakes', 'General inspection', 'Oil change', 'Repair', 'Tyres'], array_column($types, 'name'));

        $id = $this->vehicle();
        $this->postJson('/api/v1/vehicle-services', ['vehicle_id' => $id, 'service_type_id' => $this->serviceType(), 'service_date' => '2026-02-01'])->assertCreated();
        $this->deleteJson('/api/v1/fleet-service-types/'.$this->serviceType())->assertStatus(409);
        $newId = $this->postJson('/api/v1/fleet-service-types', ['name' => 'Windscreen', 'category' => 'repair'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/fleet-service-types', ['name' => 'Windscreen'])->assertUnprocessable();
        $this->deleteJson("/api/v1/fleet-service-types/{$newId}")->assertNoContent();
    }

    public function test_lists_search_filter_and_export(): void
    {
        $this->vehicle(['license_plate' => 'AAA-1', 'brand' => 'Toyota']);
        $this->vehicle(['license_plate' => 'BBB-2', 'brand' => 'Isuzu', 'fuel_type' => 'gasoline']);

        $this->getJson('/api/v1/vehicles?search=isuzu')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/vehicles?fuel_type=diesel')->assertJsonCount(1, 'data')->assertJsonPath('data.0.license_plate', 'AAA-1');
        $this->getJson('/api/v1/vehicles?status=active,sold')->assertJsonCount(2, 'data')->assertJsonPath('data.0.services_count', 0);
        $this->get('/api/v1/vehicles?export=csv')->assertOk()->assertDownload();
    }

    public function test_fleet_data_stays_inside_the_company_and_needs_permissions(): void
    {
        $foreign = Vehicle::factory()->create();
        $foreignContract = VehicleContract::query()->create(['tenant_id' => $foreign->tenant_id, 'vehicle_id' => $foreign->id, 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31']);

        $this->getJson("/api/v1/vehicles/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/vehicles/{$foreign->id}", ['color' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/vehicles/{$foreign->id}/assign-driver", ['employee_id' => null])->assertNotFound();
        $this->getJson("/api/v1/vehicles/{$foreign->id}/costs")->assertNotFound();
        $this->postJson("/api/v1/vehicle-contracts/{$foreignContract->id}/close")->assertNotFound();
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $foreign->id, 'fueled_on' => '2026-02-01', 'liters' => 1, 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');

        Sanctum::actingAs($this->user('Sales Rep'));
        $this->getJson('/api/v1/vehicles')->assertForbidden();
        $this->getJson('/api/v1/fleet/analysis')->assertForbidden();
        $this->postJson('/api/v1/vehicle-fuel-logs', [])->assertForbidden();
    }

    public function test_the_hr_dashboard_shows_the_fleet(): void
    {
        $id = $this->vehicle(['acquisition_date' => '2026-01-01']);
        $this->postJson('/api/v1/vehicle-fuel-logs', ['vehicle_id' => $id, 'fueled_on' => now()->toDateString(), 'liters' => 20, 'amount' => 300])->assertCreated();
        $this->postJson('/api/v1/vehicle-contracts', ['vehicle_id' => $id, 'starts_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(5)->toDateString()])->assertCreated();

        $data = $this->getJson('/api/v1/dashboards/hr?features=fleet')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertSame(1, $kpis['vehicles']['value']);
        $this->assertEquals(300, $kpis['fleet_fuel_cost']['value']);
        $this->assertSame(1, $kpis['contracts_expiring']['value']);
        $this->assertSame('ABC-1234', $data['tables'][0]['rows'][0]['vehicle']);
    }
}
