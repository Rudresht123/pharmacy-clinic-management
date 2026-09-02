<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    use SoftDeletes;

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
     * What a user may actually pick today.
     *
     * CLINIC is reserved by the spec but unused at launch, so the schema
     * accepts it while validation and the UI do not offer it yet.
     */
    public const SELECTABLE_TYPES = [
        self::RETAIL_STORE,
        self::WHOLESALE_STORE,
        self::WAREHOUSE,
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

    /** True once the drug licence on file is out of date. */
    public function hasExpiredLicence(): bool
    {
        return $this->drug_license_expiry_date !== null
            && $this->drug_license_expiry_date->isPast();
    }
}
