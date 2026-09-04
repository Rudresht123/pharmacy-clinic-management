<?php

namespace App\Models\Platform;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogue of licensable modules.
 *
 * A synced mirror of App\Support\Modules\ModuleRegistry, which stays
 * authoritative: this table exists so an entitlement can point at a row and
 * so a module can be retired platform-wide, not so anybody can invent a
 * module by inserting one. `php artisan modules:sync` reconciles it.
 */
class Module extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'group',
        'icon',
        'is_core',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Modules an organization must be sold; core ones are never bound. */
    public function scopeBindable(Builder $query): Builder
    {
        return $query->where('is_core', false);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(OrganizationModule::class);
    }

    /**
     * What this module lets somebody do, straight from the registry.
     *
     * Read from code rather than stored, so a capability added in a release
     * is available the moment it deploys — no sync, no migration, and no way
     * for the two to disagree.
     *
     * @return list<array{key: string, name: string}>
     */
    public function capabilities(): array
    {
        return ModuleRegistry::find($this->key)['capabilities'] ?? [];
    }
}
