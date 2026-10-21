<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Database\Factories\UserFactory;
use Modules\Core\Support\DataScope;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['tenant_id', 'manager_id', 'name', 'email', 'password', 'avatar_initials', 'status', 'data_scope'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login' => 'datetime',
            'password' => 'hashed',
            'mfa_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The reporting tree is cached for the request; any change to a user invalidates it.
        static::saved(fn () => app(DataScope::class)->forget());
        static::deleted(fn () => app(DataScope::class)->forget());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * The user ids whose records this user may see (themselves and their team), or null for no restriction.
     *
     * @return array<int, string>|null
     */
    public function visibleUserIds(): ?array
    {
        return app(DataScope::class)->visibleUserIds($this);
    }

    /**
     * Whether a record owned by `$ownerId` is within this user's scope.
     */
    public function canSeeOwnedBy(?string $ownerId): bool
    {
        return app(DataScope::class)->canSee($this, $ownerId);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
