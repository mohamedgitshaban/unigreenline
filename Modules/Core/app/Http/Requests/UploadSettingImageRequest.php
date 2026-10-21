<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\TenantSetting;

class UploadSettingImageRequest extends FormRequest
{
    /**
     * The route's `settings.update` permission is the gate.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:'.implode(',', TenantSetting::IMAGE_EXTENSIONS), 'max:2048'],
        ];
    }
}
