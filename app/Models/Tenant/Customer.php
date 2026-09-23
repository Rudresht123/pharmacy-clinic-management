<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
        'district',
        'state',
        'country',
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

    /**
     * This patient's own portal login, when they have signed in to the app.
     *
     * One at most — the same partial unique index on (userable_type,
     * userable_id) that gives a doctor one login gives a patient one too.
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'userable');
    }

    /**
     * Matches a phone number however it was typed at the desk.
     *
     * Customers' numbers are free text — "98765 43210", "+91 9876543210",
     * "09876543210" all turn up — so they are compared on their last ten
     * digits, which is the part a person actually owns.
     */
    public function scopeWithPhone(Builder $query, string $digits): Builder
    {
        return $query->whereRaw(
            "right(regexp_replace(coalesce(phone, ''), '\\D', '', 'g'), 10) = ?",
            [substr($digits, -10)],
        );
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
