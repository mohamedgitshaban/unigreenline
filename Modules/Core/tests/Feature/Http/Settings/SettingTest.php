<?php

namespace Modules\Core\Tests\Feature\Http\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\TenantSetting;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingTest extends TestCase
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'show' => ['getJson', '/api/v1/settings'],
            'update' => ['putJson', '/api/v1/settings'],
            'upload an image' => ['postJson', '/api/v1/settings/images'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_returns_401_when_no_token_is_provided(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesWithoutSettingsUpdate(): array
    {
        return ['Sales Rep' => ['Sales Rep'], 'Accountant' => ['Accountant'], 'Auditor' => ['Auditor']];
    }

    #[DataProvider('rolesWithoutSettingsUpdate')]
    public function test_returns_403_when_saving_settings_without_the_settings_update_permission(string $role): void
    {
        $this->actingAsRole($role);

        $this->putJson('/api/v1/settings', ['primary_color' => '#1A7F37'])->assertForbidden();

        $this->assertDatabaseCount('tenant_settings', 0);
    }

    #[DataProvider('rolesWithoutSettingsUpdate')]
    public function test_returns_403_when_uploading_an_image_without_the_settings_update_permission(string $role): void
    {
        Storage::fake('public');
        $this->actingAsRole($role);

        $this->postJson('/api/v1/settings/images', ['image' => UploadedFile::fake()->image('logo.png')])->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('rolesWithoutSettingsUpdate')]
    public function test_every_signed_in_user_can_read_the_settings(string $role): void
    {
        $this->actingAsRole($role);
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'primary_color' => '#1A7F37']);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#1A7F37');
    }

    public function test_returns_every_field_as_null_before_anything_is_saved_without_creating_a_row(): void
    {
        $this->actingAsRole('Sales Rep');

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertExactJson(['data' => [
                'logo_path' => null,
                'logo_url' => null,
                'wide_logo_path' => null,
                'wide_logo_url' => null,
                'favicon_path' => null,
                'favicon_url' => null,
                'primary_color' => null,
                'secondary_color' => null,
                'accent_color' => null,
            ]]);

        $this->assertDatabaseCount('tenant_settings', 0);
    }

    public function test_returns_the_saved_settings_of_the_users_own_company_with_image_urls(): void
    {
        $this->actingAsRole('Sales Rep');
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'logo_path' => 'branding/own/logo.png',
            'wide_logo_path' => 'branding/own/wide.png',
            'favicon_path' => 'branding/own/favicon.ico',
            'primary_color' => '#1A7F37',
            'secondary_color' => '#0B3D91',
            'accent_color' => '#FF8800',
        ]);
        TenantSetting::factory()->create(['primary_color' => '#000000', 'logo_path' => 'branding/other/logo.png']);

        $response = $this->getJson('/api/v1/settings')->assertOk();

        $response->assertJsonPath('data.logo_path', 'branding/own/logo.png')
            ->assertJsonPath('data.wide_logo_path', 'branding/own/wide.png')
            ->assertJsonPath('data.favicon_path', 'branding/own/favicon.ico')
            ->assertJsonPath('data.primary_color', '#1A7F37')
            ->assertJsonPath('data.secondary_color', '#0B3D91')
            ->assertJsonPath('data.accent_color', '#FF8800');
        $this->assertStringEndsWith('/branding/own/logo.png', $response->json('data.logo_url'));
        $this->assertStringEndsWith('/branding/own/wide.png', $response->json('data.wide_logo_url'));
        $this->assertStringEndsWith('/branding/own/favicon.ico', $response->json('data.favicon_url'));
    }

    public function test_uploads_an_image_to_the_public_disk_in_the_companys_folder_and_returns_201(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');

        $response = $this->postJson('/api/v1/settings/images', ['image' => UploadedFile::fake()->image('logo.png')])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'logo.png')
            ->assertJsonPath('data.mime_type', 'image/png');

        $path = $response->json('data.path');
        $this->assertStringStartsWith("branding/{$this->tenant->id}/", $path);
        $this->assertStringEndsWith($path, $response->json('data.url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_accepts_an_ico_file_as_a_favicon_upload(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');

        $response = $this->postJson('/api/v1/settings/images', ['image' => UploadedFile::fake()->create('favicon.ico', 10, 'image/x-icon')])
            ->assertCreated();

        $this->assertStringEndsWith('.ico', $response->json('data.path'));
    }

    /**
     * @return array<string, array{0: UploadedFile}>
     */
    public static function unacceptableUploads(): array
    {
        return [
            'a pdf' => [UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
            'an svg, which can carry scripts' => [UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')],
            'an image over 2 MB' => [UploadedFile::fake()->image('big.png')->size(2049)],
        ];
    }

    #[DataProvider('unacceptableUploads')]
    public function test_returns_422_when_the_upload_is_not_an_image_of_an_accepted_kind_or_size(UploadedFile $file): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/settings/images', ['image' => $file])->assertJsonValidationErrors('image');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_returns_422_when_no_image_is_sent(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/settings/images')->assertJsonValidationErrors(['image' => 'The image field is required.']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesWithSettingsUpdate(): array
    {
        return ['Administrator' => ['Administrator'], 'Owner' => ['Owner']];
    }

    #[DataProvider('rolesWithSettingsUpdate')]
    public function test_saves_the_images_and_colours_that_were_uploaded_first_and_returns_200(string $role): void
    {
        Storage::fake('public');
        $this->actingAsRole($role);
        $logo = $this->postJson('/api/v1/settings/images', ['image' => UploadedFile::fake()->image('logo.png')])->json('data.path');
        $wide = $this->storeImage('wide.png');
        $favicon = $this->storeImage('favicon.png');

        $response = $this->putJson('/api/v1/settings', [
            'logo_path' => $logo,
            'wide_logo_path' => $wide,
            'favicon_path' => $favicon,
            'primary_color' => '#1A7F37',
            'secondary_color' => '#0b3d91',
            'accent_color' => '#FF8800',
        ])->assertOk();

        $response->assertJsonPath('data.logo_path', $logo)
            ->assertJsonPath('data.wide_logo_path', $wide)
            ->assertJsonPath('data.favicon_path', $favicon)
            ->assertJsonPath('data.primary_color', '#1A7F37')
            ->assertJsonPath('data.secondary_color', '#0b3d91')
            ->assertJsonPath('data.accent_color', '#FF8800');
        $this->assertStringEndsWith($logo, $response->json('data.logo_url'));
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $this->tenant->id, 'logo_path' => $logo, 'wide_logo_path' => $wide, 'favicon_path' => $favicon,
            'primary_color' => '#1A7F37', 'secondary_color' => '#0b3d91', 'accent_color' => '#FF8800',
        ]);
        Storage::disk('public')->assertExists([$logo, $wide, $favicon]);
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.logo_path', $logo);
    }

    public function test_leaves_the_fields_that_are_not_sent_unchanged(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $logo = $this->storeImage('logo.png');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'logo_path' => $logo, 'primary_color' => '#1A7F37']);

        $this->putJson('/api/v1/settings', ['secondary_color' => '#0B3D91'])
            ->assertOk()
            ->assertJsonPath('data.logo_path', $logo)
            ->assertJsonPath('data.primary_color', '#1A7F37')
            ->assertJsonPath('data.secondary_color', '#0B3D91');

        Storage::disk('public')->assertExists($logo);
        $this->assertDatabaseCount('tenant_settings', 1);
    }

    public function test_null_clears_a_field_and_deletes_the_image_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $logo = $this->storeImage('logo.png');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'logo_path' => $logo, 'primary_color' => '#1A7F37']);

        $this->putJson('/api/v1/settings', ['logo_path' => null, 'primary_color' => null])
            ->assertOk()
            ->assertJsonPath('data.logo_path', null)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.primary_color', null);

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'logo_path' => null, 'primary_color' => null]);
        Storage::disk('public')->assertMissing($logo);
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $old = $this->storeImage('old.png');
        $new = $this->storeImage('new.png');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'logo_path' => $old]);

        $this->putJson('/api/v1/settings', ['logo_path' => $new])->assertOk()->assertJsonPath('data.logo_path', $new);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_sending_the_same_image_path_again_keeps_the_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $logo = $this->storeImage('logo.png');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'logo_path' => $logo]);

        $this->putJson('/api/v1/settings', ['logo_path' => $logo, 'primary_color' => '#1A7F37'])->assertOk();

        Storage::disk('public')->assertExists($logo);
    }

    public function test_keeps_an_image_file_that_another_field_still_uses(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $shared = $this->storeImage('shared.png');
        $new = $this->storeImage('new.png');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'logo_path' => $shared, 'favicon_path' => $shared]);

        $this->putJson('/api/v1/settings', ['logo_path' => $new])->assertOk();

        Storage::disk('public')->assertExists($shared);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'logo_path' => $new, 'favicon_path' => $shared]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function imagePathsNotFromTheUploadEndpoint(): array
    {
        return [
            'a file that was never uploaded' => ['branding/{tenant}/neverUploaded.png', false],
            'a path that climbs out of the folder' => ['branding/{tenant}/../escape.png', true],
            'a file outside the branding folder' => ['expense-receipts/{tenant}/receipt.png', true],
            'a file of a kind the upload does not accept' => ['branding/{tenant}/script.svg', true],
        ];
    }

    #[DataProvider('imagePathsNotFromTheUploadEndpoint')]
    public function test_returns_422_when_an_image_path_did_not_come_from_the_upload_endpoint(string $path, bool $fileExists): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $path = str_replace('{tenant}', $this->tenant->id, $path);
        if ($fileExists) {
            Storage::disk('public')->put($path, 'x');
        }

        $this->putJson('/api/v1/settings', ['logo_path' => $path])
            ->assertJsonValidationErrors(['logo_path' => 'The logo path must be a file uploaded via POST /api/v1/settings/images.']);

        $this->assertDatabaseCount('tenant_settings', 0);
    }

    public function test_returns_422_when_an_image_path_belongs_to_another_company(): void
    {
        Storage::fake('public');
        $this->actingAsRole('Administrator');
        $other = Tenant::factory()->create();
        $foreign = UploadedFile::fake()->image('logo.png')->store(TenantSetting::imageDirectory($other->id), 'public');

        $this->putJson('/api/v1/settings', ['favicon_path' => $foreign])->assertJsonValidationErrors('favicon_path');

        $this->assertDatabaseCount('tenant_settings', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidColours(): array
    {
        return [
            'without the hash' => ['1A7F37'],
            'in the short form' => ['#1A7'],
            'with non-hex digits' => ['#GG7F37'],
            'with an alpha channel' => ['#1A7F3780'],
            'as a colour name' => ['green'],
        ];
    }

    #[DataProvider('invalidColours')]
    public function test_returns_422_when_a_colour_is_not_a_six_digit_hex_value(string $colour): void
    {
        $this->actingAsRole('Administrator');

        $this->putJson('/api/v1/settings', ['primary_color' => $colour])
            ->assertJsonValidationErrors(['primary_color' => 'The primary color must be a hex colour such as #1A7F37.']);

        $this->assertDatabaseCount('tenant_settings', 0);
    }

    public function test_validates_every_colour_field(): void
    {
        $this->actingAsRole('Administrator');

        $this->putJson('/api/v1/settings', ['secondary_color' => 'blue', 'accent_color' => '#12'])
            ->assertJsonValidationErrors(['secondary_color', 'accent_color'])
            ->assertJsonMissingValidationErrors('primary_color');
    }

    public function test_saving_one_company_leaves_another_companys_settings_alone(): void
    {
        $this->actingAsRole('Administrator');
        $other = TenantSetting::factory()->create(['primary_color' => '#000000']);

        $this->putJson('/api/v1/settings', ['primary_color' => '#1A7F37'])->assertOk();

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $other->tenant_id, 'primary_color' => '#000000']);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'primary_color' => '#1A7F37']);
    }

    public function test_records_the_first_save_as_an_insert_and_later_ones_as_updates_in_the_audit_log(): void
    {
        $admin = $this->actingAsRole('Administrator');

        $this->putJson('/api/v1/settings', ['primary_color' => '#1A7F37'])->assertOk();
        $this->putJson('/api/v1/settings', ['primary_color' => '#0B3D91'])->assertOk();

        $setting = TenantSetting::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'TenantSetting', 'entity_id' => $setting->id, 'operation' => 'INSERT',
            'user_id' => $admin->id, 'tenant_id' => $this->tenant->id,
        ]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'TenantSetting', 'entity_id' => $setting->id, 'operation' => 'UPDATE']);
    }

    public function test_does_not_write_an_audit_entry_when_nothing_changed(): void
    {
        $this->actingAsRole('Administrator');
        TenantSetting::factory()->create(['tenant_id' => $this->tenant->id, 'primary_color' => '#1A7F37']);

        $this->putJson('/api/v1/settings', ['primary_color' => '#1A7F37'])->assertOk();

        $this->assertDatabaseMissing('audit_log', ['entity_type' => 'TenantSetting']);
    }

    private function storeImage(string $name): string
    {
        return UploadedFile::fake()->image($name)->store(TenantSetting::imageDirectory($this->tenant->id), 'public');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
