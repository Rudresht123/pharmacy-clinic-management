<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A doctor the organization's patients are seen by.
 *
 * Has no branch. Where a doctor works is what their schedules say, with the
 * day and the hours attached — see the relation below rather than looking
 * for a `location_id`.
 */
class Doctor extends Model
{
    use RecordsHistory, SoftDeletes;

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService.
     */
    protected $connection = 'organization';

    protected $fillable = [
        'name',
        'code',
        'specialisation',
        'qualification',
        'registration_no',
        'phone',
        'email',
        'default_consultation_fee',
        'is_active',
        'notes',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_consultation_fee' => 'decimal:2',
            'custom_fields' => 'array',
        ];
    }

    /**
     * The account this doctor signs in with, if they have one.
     *
     * morphOne rather than morphMany, and a partial unique index on
     * `users(userable_type, userable_id)` enforces it: two accounts for one
     * doctor would make "who wrote this prescription" ambiguous.
     *
     * Null is a perfectly ordinary answer — a visiting consultant who never
     * touches the software is exactly why doctors are not just users.
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'userable');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    /**
     * The branches this doctor sits at.
     *
     * Derived from the schedules, because that is the only place the
     * relationship exists. A doctor with no schedule works nowhere, which is
     * the truth rather than an omission.
     */
    public function locations()
    {
        return Location::on($this->getConnectionName())
            ->whereIn('id', $this->schedules()->select('location_id'))
            ->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
