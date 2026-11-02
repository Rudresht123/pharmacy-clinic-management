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

    /** The desk's own thread with a patient — see the migration. */
    public const FRONT_DESK = 'front_desk';

    public const SIDES = [self::DOCTOR, self::PATIENT, self::FRONT_DESK];

    protected $connection = 'organization';

    protected $fillable = [
        'doctor_id',
        'branch_id',
        'customer_id',
        'last_message_at',
        'doctor_last_read_id',
        'patient_last_read_id',
        'doctor_delivered_id',
        'patient_delivered_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'doctor_last_read_id' => 'integer',
            'patient_last_read_id' => 'integer',
            'doctor_delivered_id' => 'integer',
            'patient_delivered_id' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** A desk thread, rather than a doctor's — the two never mix in one row. */
    public function isDeskThread(): bool
    {
        return $this->doctor_id === null;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    /**
     * The column holding what [side] has read up to.
     *
     * Staff's own pointer, whether this row is a doctor's thread or the
     * desk's — see the migration that added `branch_id`.
     */
    public static function readColumn(string $side): string
    {
        return $side === self::PATIENT ? 'patient_last_read_id' : 'doctor_last_read_id';
    }

    /** The column holding what [side]'s phone has received up to. */
    public static function deliveredColumn(string $side): string
    {
        return $side === self::PATIENT ? 'patient_delivered_id' : 'doctor_delivered_id';
    }

    /**
     * Who [mySide] is writing to, on this one thread.
     *
     * Only the patient's half is ambiguous: a doctor's own side is always
     * writing to the patient, and the desk's always is too, but the patient
     * is on one or the other depending on which kind of row this is.
     */
    public function counterpartSide(string $mySide): string
    {
        if ($mySide !== self::PATIENT) {
            return self::PATIENT;
        }

        return $this->isDeskThread() ? self::FRONT_DESK : self::DOCTOR;
    }
}
