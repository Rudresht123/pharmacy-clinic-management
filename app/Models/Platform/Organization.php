<?php

namespace App\Models\Platform;

use App\Models\Record;
use Database\Factories\Platform\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Record
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Lifecycle — Build Spec §5, §9
    |--------------------------------------------------------------------------
    |
    | PENDING       created, nothing provisioned yet
    | PROVISIONING  the job is running
    | ACTIVE        live; the owner can sign in
    | SUSPENDED     logins blocked, data untouched, reversible
    | CANCELLED     subscription closed, read-only window then blocked
    | FAILED        provisioning stopped part-way; retryable
    |
    */
    public const PENDING = 'pending';
    public const PROVISIONING = 'provisioning';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    public const STATUSES = [
        self::PENDING,
        self::PROVISIONING,
        self::ACTIVE,
        self::SUSPENDED,
        self::CANCELLED,
        self::FAILED,
    ];

    protected $table = 'organizations';

    protected $fillable = [
        'organization_name',
        'slug',
        'tenant_key',
        'organization_code',
        'organization_type_id',
        'subdomain',
        'database_name',
        'legal_name',
        'gstin',
        'drug_license_no',
        'contact_person_name',
        'email',
        'phone_number',
        'address',
        'profile_image',
        'is_active',
        'setup_token',
        'setup_token_expires_at',
        'is_setup_completed',
        'setup_completed_at',
        'status',
        'plan_id',
        'trial_ends_at',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'timezone',
        'currency',
        'country',
        'notes',
    ];

    protected $casts = [
        'organization_type_id' => 'integer',
        'plan_id' => 'integer',
        'is_active' => 'boolean',
        'is_setup_completed' => 'boolean',
        'setup_token_expires_at' => 'datetime',
        'setup_completed_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $hidden = [
        'setup_token',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // §5: the ULID is the only id ever exposed, so a row is never created
        // without one.
        static::creating(function (self $organization) {
            $organization->uuid ??= (string) Str::ulid();
        });
    }

    /** URLs carry the ULID, never the auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function organizationType(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrganizationStatusHistory::class)->latest();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(OrganizationContact::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    public function featureFlagOverrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class);
    }

    public function tenantDatabase(): HasOne
    {
        return $this->hasOne(TenantDatabase::class);
    }

    public function provisionEvents(): HasMany
    {
        return $this->hasMany(TenantProvisionEvent::class);
    }

    public function migrationState(): HasOne
    {
        return $this->hasOne(TenantMigrationState::class);
    }

    public function migrationRuns(): HasMany
    {
        return $this->hasMany(TenantMigrationRun::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(TenantBackup::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(OrganizationStat::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** True while provisioning has not finished, either way. */
    public function isProvisioned(): bool
    {
        return ! in_array($this->status, [self::PENDING, self::PROVISIONING, self::FAILED], true);
    }

    /**
     * Whether the tenant's people may sign in.
     *
     * Cancelled is deliberately absent: §9 gives a cancelled organization a
     * read-only window before it is blocked, which is a separate decision
     * from this one.
     */
    public function canSignIn(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /*
    |--------------------------------------------------------------------------
    | Generators
    |--------------------------------------------------------------------------
    */

    /**
     * A URL-safe, unique slug — used for the subdomain and the database name.
     *
     * Example: "Gyan International Academy" => gyan-international-academy,
     * then -1, -2 and so on if that is taken.
     */
    public static function generateSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 55, '');
        $slug = $base;
        $counter = 1;

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * A stable, Postgres-identifier-safe key that the tenant database name is
     * derived from — unlike `organization_name`, this never changes after
     * creation, so the database it points at never has to move.
     *
     * Example: "Gyan International Academy" => gyan_international_academy,
     * then _1, _2 and so on if that is taken.
     */
    public static function generateTenantKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 36, '');

        // The DB-name CHECK constraint requires a leading letter.
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'org_' . $base;
        }

        $key = $base;
        $counter = 1;

        while (self::withTrashed()->where('tenant_key', $key)->exists()) {
            $key = "{$base}_{$counter}";
            $counter++;
        }

        return $key;
    }

    /**
     * Generate the tenant database name from its (immutable) tenant key.
     *
     * Example: tenant_key "gyan_international_academy"
     * => hms_tenant_gyan_international_academy
     *
     * Next duplicate:
     * => hms_tenant_gyan_international_academy_1
     */
    public static function generateDatabaseName(string $tenantKey): string
    {
        $baseName = 'hms_tenant_' . $tenantKey;

        $databaseName = $baseName;
        $counter = 1;

        while (self::where('database_name', $databaseName)->exists()) {
            $databaseName = "{$baseName}_{$counter}";
            $counter++;
        }

        return $databaseName;
    }

    /**
     * Generate unique organization code.
     *
     * Example:
     * Gyan International Academy
     * => GYA4821
     */
    public static function generateOrganizationCode(string $organizationName): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $organizationName), 0, 3));

        do {
            $code = $prefix . random_int(1000, 9999);
        } while (self::where('organization_code', $code)->exists());

        return $code;
    }
}
