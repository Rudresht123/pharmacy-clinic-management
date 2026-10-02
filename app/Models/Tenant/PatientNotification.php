<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the app told a patient about their own care -- an appointment
 * confirmed, or called off.
 *
 * Deliberately not a Record, like MessageLog: an event that happened, not
 * something anybody maintains, so there is no history and nothing here is
 * ever edited beyond marking it read.
 */
class PatientNotification extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'customer_id',
        'type',
        'title',
        'body',
        'appointment_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
