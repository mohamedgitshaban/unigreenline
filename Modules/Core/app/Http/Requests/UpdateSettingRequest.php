<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\TenantSetting;
use Modules\Core\Rules\UploadedFilePath;

class UpdateSettingRequest extends FormRequest
{
    /**
     * The route's `settings.update` permission is the gate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional: one left out stays as it is, null clears it.
     * The image fields take the `path` returned by POST /settings/images.
     */
    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $settingId = TenantSetting::forTenant($tenantId)->id;

        $rules = [];

        foreach (TenantSetting::IMAGE_COLUMNS as $column) {
            $rules[$column] = ['sometimes', 'nullable', 'string', new UploadedFilePath(
                TenantSetting::imageDirectory($tenantId),
                TenantSetting::class,
                $column,
                'POST /api/v1/settings/images',
                TenantSetting::IMAGE_EXTENSIONS,
                $settingId,
                TenantSetting::IMAGE_DISK,
            )];
        }

        foreach (['primary_color', 'secondary_color', 'accent_color'] as $column) {
            $rules[$column] = ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_color.regex' => 'The :attribute must be a hex colour such as #1A7F37.',
            'secondary_color.regex' => 'The :attribute must be a hex colour such as #1A7F37.',
            'accent_color.regex' => 'The :attribute must be a hex colour such as #1A7F37.',
        ];
    }
}
