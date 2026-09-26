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
 * What the doctor sent to the lab.
 *
 * Raised by the doctor who saw the patient, worked by a technician, and it
 * gates the visit until it is finished — a visit with bloods outstanding is
 * not a visit that is over, however complete the write-up is.
 *
 * Its number (LAB-00001) is taken by the database as the row is inserted.
 * Status moves are LabOrders'; nothing sets `status` from a request.
 */
class LabOrder extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    /** Ordered, nobody has picked it up. */
    public const PENDING = 'pending';

    /** A technician has it in hand. */
    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** Every value the database CHECK permits. */
    public const STATUSES = [self::PENDING, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];

    /**
     * Which states each one may move to.
     *
     * The same shape as the appointment's own table, and for the same reason:
     * a technician who can jump straight from ordered to completed has
     * skipped the step that says somebody is actually working on it.
     */
    public const TRANSITIONS = [
        self::PENDING => [self::IN_PROGRESS, self::CANCELLED],
        self::IN_PROGRESS => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** Nothing more will come of these; the visit stops waiting on them. */
    public const SETTLED = [self::COMPLETED, self::CANCELLED];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'customer_id',
        'doctor_id',
        'appointment_id',
        'consultation_id',
        'order_date',
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
            'order_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Why the tests were asked for stays out of the audit log.
     *
     * `activity_logs` is append-only and un-deletable by design, so clinical
     * free text written into it could never afterwards be corrected or
     * redacted. The log still records THAT an order was raised and by whom.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return ['clinical_notes'];
    }

    protected function historyLabel(): ?string
    {
        return $this->order_number;
    }

    /*
     * With history: an order keeps naming its patient, doctor and branch
     * after any of them is removed.
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
        return $this->hasMany(LabOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Still holding the visit up.
     *
     * @param  Builder<LabOrder>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::IN_PROGRESS]);
    }

    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::PENDING, self::IN_PROGRESS], true);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** "CBC, LFT" — what a queue row says without opening the order. */
    public function testSummary(): string
    {
        return $this->items->pluck('test_name')->implode(', ');
    }
}
