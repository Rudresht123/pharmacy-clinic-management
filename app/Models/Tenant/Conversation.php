<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One doctor and one patient, writing to each other.
 *
 * Exactly one per pair (a unique index), so whichever of them writes first
 * opens it and the other finds the same thread. What each side has read is a
 * pointer to the last message id they saw — see the migration.
 */
class Conversation extends Model
{
    public const DOCTOR = 'doctor';

    public const PATIENT = 'patient';

    public const SIDES = [self::DOCTOR, self::PATIENT];

    protected $connection = 'organization';

    protected $fillable = [
        'doctor_id',
        'customer_id',
        'last_message_at',
        'doctor_last_read_id',
        'patient_last_read_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'doctor_last_read_id' => 'integer',
            'patient_last_read_id' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    /** The column holding what [side] has read up to. */
    public static function readColumn(string $side): string
    {
        return $side === self::DOCTOR ? 'doctor_last_read_id' : 'patient_last_read_id';
    }

    public static function otherSide(string $side): string
    {
        return $side === self::DOCTOR ? self::PATIENT : self::DOCTOR;
    }
}
