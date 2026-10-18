<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Manufacturing\Http\Requests\UpdateManufacturingSettingsRequest;
use Modules\Manufacturing\Http\Resources\ManufacturingSettingResource;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Services\ManufacturingSettingsService;

class ManufacturingSettingsController extends Controller
{
    public function __construct(private readonly ManufacturingSettingsService $settings) {}

    public function show(Request $request)
    {
        return new ManufacturingSettingResource(ManufacturingSetting::forTenant($request->user()->tenant_id));
    }

    public function update(UpdateManufacturingSettingsRequest $request)
    {
        $settings = $this->settings->update($request->user()->tenant_id, $request->validated(), [
            'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip(),
        ]);

        return new ManufacturingSettingResource($settings);
    }
}
