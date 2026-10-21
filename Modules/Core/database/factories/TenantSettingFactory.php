<?php

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\TenantSetting;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    protected $model = TenantSetting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'logo_path' => null,
            'wide_logo_path' => null,
            'favicon_path' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'accent_color' => null,
        ];
    }
}
