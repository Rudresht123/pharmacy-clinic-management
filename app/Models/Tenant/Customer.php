<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody the organization serves.
 *
 * Organization-wide by design: there is no location on this record, so one
 * person's history across every store adds up rather than fragmenting. The
 * clinic module is expected to extend this row rather than start a separate
 * patients table.
 */
class Customer extends Model
{
    use RecordsHistory, SoftDeletes;

    public const MALE = 'male';

    public const FEMALE = 'female';

    public const OTHER = 'other';

    /** Mirrors the database CHECK. */
    public const GENDERS = [self::MALE, self::FEMALE, self::OTHER];

    protected $connection = 'organization';

    protected $table = 'customers';

    protected $fillable = [
        'registered_location_id',
        'name',
        'phone',
        'email',
        'date_of_birth',
        'gender',
        'address',
        'city',
        'state',
        'pincode',
        'notes',
        'is_active',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /**
     * The branch that first signed this customer up.
     *
     * Provenance only — it never limits who may see or serve them.
     */
    public function registeredLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'registered_location_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Null when no date of birth is on file. */
    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
