<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Requests\UpdateSettingRequest;
use Modules\Core\Http\Requests\UploadSettingImageRequest;
use Modules\Core\Http\Resources\SettingResource;
use Modules\Core\Models\TenantSetting;
use Modules\Core\Services\SettingService;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Open to every signed-in user: the client needs the logo and colours to draw the app for all of them.
     */
    public function show(Request $request)
    {
        return new SettingResource(TenantSetting::forTenant($request->user()->tenant_id));
    }

    public function update(UpdateSettingRequest $request)
    {
        $settings = $this->settings->update($request->user()->tenant_id, $request->validated(), [
            'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip(),
        ]);

        return new SettingResource($settings);
    }

    /**
     * Step one of setting a logo, wide logo or favicon: store the image and
     * hand back its path, which the client then sends in `PUT /settings`.
     */
    public function uploadImage(UploadSettingImageRequest $request)
    {
        $image = $request->file('image');
        $path = $image->store(TenantSetting::imageDirectory($request->user()->tenant_id), TenantSetting::IMAGE_DISK);

        return response()->json(['data' => [
            'path' => $path,
            'url' => TenantSetting::imageUrl($path),
            'original_name' => $image->getClientOriginalName(),
            'mime_type' => $image->getMimeType(),
            'size' => $image->getSize(),
        ]], 201);
    }
}
