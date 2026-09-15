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
 * The prescription written at one visit.
 *
 * Drafted by the doctor who saw the patient, then issued. An issued one is
 * cancelled, never deleted; only a draft can be removed. Its number
 * (RX-00001) is taken by the database as the row is inserted.
 *
 * Status moves are PrescriptionService's (and, from Phase 5, dispensing's);
 * nothing sets `status` from a request.
 */
class Prescription extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PARTIALLY_DISPENSED = 'partially_dispensed';

    public const DISPENSED = 'dispensed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Every value the database CHECK permits. */
    public const STATUSES = [
        self::DRAFT, self::ISSUED, self::PARTIALLY_DISPENSED, self::DISPENSED, self::CANCELLED, self::EXPIRED,
    ];

    /** How long an issued prescription can be dispensed against, when the doctor does not say. */
    public const DEFAULT_VALID_DAYS = 30;

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'customer_id',
        'doctor_id',
        'appointment_id',
        'consultation_id',
        'prescription_date',
        'valid_until',
        'clinical_notes',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'customer_id' => 'integer',
            'doctor_id' => 'integer',
            'appointment_id' => 'integer',
            'consultation_id' => 'integer',
            'prescription_date' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_legacy' => 'boolean',
        ];
    }

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['clinical_notes'];
    }

    protected function historyLabel(): ?string
    {
        return $this->prescription_number;
    }

    /*
     * With history: a prescription keeps naming its patient, doctor and
     * branch after any of them is removed.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Not cancelled — the visit's one prescription, whatever state it is in.
     *
     * @param  Builder<Prescription>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', '<>', self::CANCELLED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * The lines in the consultation's old shape — drug, dose, frequency,
     * duration, notes — for the screens that still read it.
     *
     * @return list<array<string, mixed>>
     */
    public function asConsultationLines(): array
    {
        return $this->items->map(fn (PrescriptionItem $item) => $item->asConsultationLine())->values()->all();
    }
}
