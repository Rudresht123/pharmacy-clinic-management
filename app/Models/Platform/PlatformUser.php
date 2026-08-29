<?php

namespace App\Models\Platform;

use Database\Factories\Platform\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * An administrator of the platform itself — Build Spec §18.
 *
 * Authenticates on the `platform` guard only. There is deliberately no
 * relationship to App\Models\User: a tenant user must never be able to sign
 * in here, and an admin must never be able to sign in to a tenant.
 */
class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'ip_allowlist',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'ip_allowlist' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The spec exposes `uuid` rather than the auto-increment id, so a row
        // is never created without one.
        static::creating(function (self $user) {
            $user->uuid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformRole::class,
            'platform_role_user',
            'platform_user_id',
            'platform_role_id'
        );
    }

    /**
     * True when the admin holds any one of the given role codes.
     *
     * A Super Admin passes every check — §18 gives that role everything,
     * so callers never have to list it alongside the role they want.
     */
    public function hasRole(string ...$codes): bool
    {
        $held = $this->roles->pluck('code');

        return $held->contains(PlatformRole::SUPER_ADMIN)
            || $held->intersect($codes)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->pluck('code')->contains(PlatformRole::SUPER_ADMIN);
    }

    /**
     * Blocks a deactivated admin at login instead of relying on every later
     * check to remember.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
