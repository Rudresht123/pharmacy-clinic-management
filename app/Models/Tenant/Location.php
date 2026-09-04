<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical place an organization operates from — a store, a warehouse,
 * later a clinic. The kind is `type`; nothing here is store-specific.
 *
 * Extends Model rather than App\Models\Record on purpose. Record stamps
 * created_by/updated_by from `Auth::guard('platform')->id()` first, which on
 * a tenant row would write a *platform* user's id into a column meaning
 * "tenant users.id" — the same ambiguity config/auth.php documents for
 * sessions.user_id. Audit columns can be added later behind a trait that
 * reads only the `web` guard.
 */
class Location extends Model
{
    use RecordsHistory, SoftDeletes;

    public const RETAIL_STORE = 'RETAIL_STORE';

    public const WHOLESALE_STORE = 'WHOLESALE_STORE';

    public const WAREHOUSE = 'WAREHOUSE';

    public const CLINIC = 'CLINIC';

    public const DOCTOR_VISITING_LOCATION = 'DOCTOR_VISITING_LOCATION';

    /** Every value the database CHECK constraint permits. */
    public const TYPES = [
        self::RETAIL_STORE,
        self::WHOLESALE_STORE,
        self::WAREHOUSE,
        self::CLINIC,
        self::DOCTOR_VISITING_LOCATION,
    ];

    /**
     * What a user may actually pick.
     *
     * CLINIC was held back while it had nothing behind it. OPD is what it
     * was reserved for: a doctor's sitting happens at a clinic, so the type
     * is now offered.
     *
     * Every value in TYPES is selectable today. The two lists stay separate
     * rather than collapsing into one, because the CHECK constraint and the
     * dropdown answer different questions — the schema must go on accepting
     * a value that validation later stops offering, or existing rows become
     * unsavable.
     */
    public const SELECTABLE_TYPES = [
        self::RETAIL_STORE,
        self::WHOLESALE_STORE,
        self::WAREHOUSE,
        self::CLINIC,
        self::DOCTOR_VISITING_LOCATION,
    ];

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'locations';

    protected $fillable = [
        'name',
        'code',
        'type',
        'is_active',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'gstin',
        'drug_license_no',
        'drug_license_expiry_date',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'drug_license_expiry_date' => 'date',
            'custom_fields' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * This branch's decisions about the organization's modules.
     *
     * Sparse: a row exists only where something was switched off here. Read
     * through App\Services\Permissions\Permission rather than directly, so the
     * "no row means inherited" rule lives in one place.
     */
    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(LocationModule::class);
    }

    /**
     * Who works here, and what they hold here.
     *
     * Through memberships rather than a `users.location_id` column, so
     * somebody can work at this branch and another without either being the
     * one true answer.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_users', 'location_id', 'user_id')
            ->withPivot(['role_id', 'is_primary'])
            ->withTimestamps();
    }

    /** True once the drug licence on file is out of date. */
    public function hasExpiredLicence(): bool
    {
        /*
         * Compared date to date, not to this instant. isPast() measures
         * against now, so a licence expiring today — which is valid for the
         * whole of today — read as already expired from midnight onwards.
         */
        return $this->drug_license_expiry_date !== null
            && $this->drug_license_expiry_date->startOfDay()->lt(now()->startOfDay());
    }
}
