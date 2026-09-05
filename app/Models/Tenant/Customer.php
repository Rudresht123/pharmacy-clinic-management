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

    /**
     * What a patient number looks like: P-00001.
     *
     * Short enough to read out over a counter and long enough not to run out.
     * Per organization, because each one has its own database — there is no
     * shared sequence to collide with.
     */
    public const PREFIX = 'P-';

    public const CODE_LENGTH = 5;

    protected $connection = 'organization';

    protected $table = 'customers';

    protected $fillable = [
        'registered_location_id',

        /*
         * Fillable so an organization migrating from another system can carry
         * its own numbers across. Left out, the repository allocates the next
         * one — which is what every patient registered through the product
         * gets.
         */
        'code',

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
