<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Database\Factories\TenantSettingFactory;

/**
 * A company's own settings — for now its branding: logo, wide logo, favicon
 * and colours. Read through forTenant(); the row only exists once something
 * has been saved, until then every field reads as null.
 *
 * Images live on the public disk, since a logo or favicon is shown to anyone
 * the company shows it to and the client needs a plain URL for `<img>` and
 * `<link rel="icon">`.
 */
#[Fillable([
    'tenant_id', 'logo_path', 'wide_logo_path', 'favicon_path', 'primary_color', 'secondary_color', 'accent_color',
])]
class TenantSetting extends Model
{
    use HasFactory, HasUlids;

    public const IMAGE_DISK = 'public';

    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'ico'];

    /**
     * The columns holding an uploaded image's path.
     *
     * @var list<string>
     */
    public const IMAGE_COLUMNS = ['logo_path', 'wide_logo_path', 'favicon_path'];

    public static function forTenant(string $tenantId): self
    {
        return self::query()->firstOrNew(['tenant_id' => $tenantId]);
    }

    /**
     * Where a tenant's uploaded branding images are stored, on the public disk.
     */
    public static function imageDirectory(string $tenantId): string
    {
        return "branding/{$tenantId}";
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(self::IMAGE_DISK)->url($path);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): TenantSettingFactory
    {
        return TenantSettingFactory::new();
    }
}
