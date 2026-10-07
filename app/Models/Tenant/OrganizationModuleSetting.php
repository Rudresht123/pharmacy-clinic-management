<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * A module the organisation has switched off for itself.
 *
 * The owner's level, between what the super admin sold (`organization_modules`)
 * and what each branch opts out of (`location_modules`). Sparse: a row means
 * off everywhere, and no row means the module runs wherever a branch has not
 * said otherwise.
 *
 * Records its own history, like ModuleLock: the row IS the event — "the
 * organisation stopped using the pharmacy" — and switching the module back on
 * deletes it, which the log records as the other half.
 */
class OrganizationModuleSetting extends Model
{
    use RecordsHistory;

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'organization_module_settings';

    protected $fillable = [
        'module_key',
        'is_enabled',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * The modules switched off for the whole organisation.
     *
     * @return list<string>
     */
    public static function offKeys(): array
    {
        return static::query()
            ->where('is_enabled', false)
            ->pluck('module_key')
            ->all();
    }

    /** Names the module in the activity log rather than showing a key. */
    protected function historyLabel(): ?string
    {
        return ModuleRegistry::find((string) $this->module_key)['name'] ?? $this->module_key;
    }
}
