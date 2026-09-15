<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place stock is kept and dispensed from, at one branch.
 *
 * Record-level access is PharmacyStorePolicy's: the store's branch has to be
 * one the person works at, and the capability has to hold there.
 */
class PharmacyStore extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const HOSPITAL_PHARMACY = 'hospital_pharmacy';

    public const OPD_COUNTER = 'opd_counter';

    public const IPD_PHARMACY = 'ipd_pharmacy';

    public const EMERGENCY = 'emergency';

    public const RETAIL = 'retail';

    public const CENTRAL = 'central';

    /** Every value the database CHECK constraint permits. */
    public const TYPES = [
        self::HOSPITAL_PHARMACY,
        self::OPD_COUNTER,
        self::IPD_PHARMACY,
        self::EMERGENCY,
        self::RETAIL,
        self::CENTRAL,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'name',
        'code',
        'store_type',
        'is_default',
        'pharmacist_user_id',
        'address',
        'phone',
        'drug_license_no',
        'drug_license_expiry_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'drug_license_expiry_date' => 'date',
        ];
    }

    /** The branch. Read with history, so a store at a removed branch still names it. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_user_id');
    }

    /** Who removed it, while it is removed. */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** Which medicines this store stocks, and at what levels. */
    public function storeMedicines(): HasMany
    {
        return $this->hasMany(StoreMedicine::class);
    }

    /** @param  Builder<PharmacyStore>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Usable right now: active, not removed (the soft-delete scope already
     * says so), and at a branch that is itself active.
     *
     * @param  Builder<PharmacyStore>  $query
     */
    public function scopeOperational(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('location', fn (Builder $branch) => $branch->where('is_active', true));
    }

    /** @param  Builder<PharmacyStore>  $query */
    public function scopeForBranch(Builder $query, int $locationId): void
    {
        $query->where('location_id', $locationId);
    }

    /**
     * The licence this store trades under: its own when it holds one, the
     * branch's otherwise.
     *
     * @return array{number: ?string, expires_on: ?string, own: bool}
     */
    public function licence(): array
    {
        if ($this->drug_license_no) {
            return [
                'number' => $this->drug_license_no,
                'expires_on' => $this->drug_license_expiry_date?->toDateString(),
                'own' => true,
            ];
        }

        return [
            'number' => $this->location?->drug_license_no,
            'expires_on' => $this->location?->drug_license_expiry_date?->toDateString(),
            'own' => false,
        ];
    }
}
