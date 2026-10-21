<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Models\TenantSetting;

/**
 * @mixin TenantSetting
 */
class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'logo_path' => $this->logo_path,
            'logo_url' => TenantSetting::imageUrl($this->logo_path),
            'wide_logo_path' => $this->wide_logo_path,
            'wide_logo_url' => TenantSetting::imageUrl($this->wide_logo_path),
            'favicon_path' => $this->favicon_path,
            'favicon_url' => TenantSetting::imageUrl($this->favicon_path),
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'accent_color' => $this->accent_color,
        ];
    }
}
