<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody intending to see a doctor.
 *
 * An intent, not an outcome. What happened in the room is a visit, arriving
 * in Phase 3; nothing clinical belongs here.
 */
class Appointment extends Model
{
    use RecordsHistory, SoftDeletes;

    /** Booked ahead, with a promised time. */
    public const BOOKED = 'booked';

    /** Turned up without one. */
    public const WALK_IN = 'walk_in';

    public const TYPES = [self::BOOKED, self::WALK_IN];

    /*
    |--------------------------------------------------------------------------
    | 1. THE VISIT — `status`
    |--------------------------------------------------------------------------
    |
    | Where the whole episode has got to. The SYSTEM owns this column: nothing
    | outside VisitWorkflow writes it, and no screen offers it as a button.
    | "This visit is finished" is a conclusion drawn from the prescription, the
    | lab and the till — never a thing a person at a desk asserts.
    |
    | `booked` is the only one a walk-in skips: they are checked in the moment
    | they are recorded, because they are standing there.
    |
    | The three `awaiting_*` states are what a consultation ending actually
    | produces. Before them, a doctor finishing meant the visit was over, which
    | was untrue of every patient who then had to collect medicines.
    */
    public const STATUS_BOOKED = 'booked';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_AWAITING_PHARMACY = 'awaiting_pharmacy';

    public const STATUS_AWAITING_LAB = 'awaiting_lab';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_AWAITING_PHARMACY,
        self::STATUS_AWAITING_LAB,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /** Downstream of the doctor, and the reason the visit is still open. */
    public const AWAITING = [
        self::STATUS_AWAITING_PHARMACY,
        self::STATUS_AWAITING_LAB,
        self::STATUS_AWAITING_PAYMENT,
    ];

    /**
     * Which states each one may move to.
     *
     * Written down rather than left to whichever controller happens to be
     * called: without it, a completed consultation can be checked in again
     * and the day's figures stop meaning anything.
     *
     * Note what is NOT here. `checked_in` no longer reaches `in_consultation`
     * by itself — that move is the consultation starting, and it is gated on
     * the queue as well as on the visit. And no state reaches `completed`
     * except from downstream of a finished consultation: the old
     * `in_consultation → completed` edge was the "Done" button, and it is
     * gone.
     */
    public const TRANSITIONS = [
        self::STATUS_BOOKED => [self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CHECKED_IN => [self::STATUS_IN_CONSULTATION, self::STATUS_CANCELLED],

        // Where the doctor finishing can land, all four decided by
        // VisitWorkflow rather than chosen by anybody.
        self::STATUS_IN_CONSULTATION => [
            self::STATUS_AWAITING_PHARMACY,
            self::STATUS_AWAITING_LAB,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],

        /*
         * The waiting rooms reach each other, and that is not sloppiness.
         *
         * A visit waiting on the pharmacy becomes one waiting on payment the
         * moment the medicines are handed over and the bill is not settled.
         * Each of them can also go back into the room: a reopened
         * consultation is a doctor correcting themselves, which must stay
         * possible while the patient is still in the building.
         */
        self::STATUS_AWAITING_PHARMACY => [
            self::STATUS_AWAITING_LAB,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_COMPLETED,
            self::STATUS_IN_CONSULTATION,
        ],
        self::STATUS_AWAITING_LAB => [
            self::STATUS_AWAITING_PHARMACY,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_COMPLETED,
            self::STATUS_IN_CONSULTATION,
        ],
        self::STATUS_AWAITING_PAYMENT => [
            self::STATUS_AWAITING_PHARMACY,
            self::STATUS_AWAITING_LAB,
            self::STATUS_COMPLETED,
            self::STATUS_IN_CONSULTATION,
        ],

        /*
         * A finished visit can be reopened, on the day it happened.
         *
         * A doctor who hits Complete on the wrong row — or who realises the
         * patient is still in the room — had nowhere to go: the write-up is
         * only reachable while somebody is in consultation, so a mis-click hid
         * the notes as well as the patient.
         *
         * It is now the DOCTOR's to do, behind their own capability, and it is
         * held to today. A visit completed last week staying closed is the
         * point of recording it at all.
         */
        self::STATUS_COMPLETED => [self::STATUS_IN_CONSULTATION],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    /*
    |--------------------------------------------------------------------------
    | 2. THE QUEUE — `queue_status`
    |--------------------------------------------------------------------------
    |
    | The reception desk's column, and theirs alone. NULL before they arrive
    | and NULL again once the doctor is finished with them: somebody who has
    | left the department is not in a queue, and a nullable column says that
    | without a fourth value meaning "not applicable".
    |
    | `called` is the state this whole redesign was missing. Without it,
    | calling a patient and starting their consultation were the same act, so
    | the desk was doing the doctor's job.
    */
    public const QUEUE_WAITING = 'waiting';

    public const QUEUE_CALLED = 'called';

    public const QUEUE_WITH_DOCTOR = 'with_doctor';

    public const QUEUE_STATUSES = [self::QUEUE_WAITING, self::QUEUE_CALLED, self::QUEUE_WITH_DOCTOR];

    /**
     * Receptionist: waiting → called. Doctor: called → with_doctor.
     *
     * The split down the middle of this table is the role boundary, and it is
     * enforced by two different capabilities on two different routes.
     */
    public const QUEUE_TRANSITIONS = [
        self::QUEUE_WAITING => [self::QUEUE_CALLED],
        self::QUEUE_CALLED => [self::QUEUE_WITH_DOCTOR, self::QUEUE_WAITING],
        self::QUEUE_WITH_DOCTOR => [],
    ];

    /*
    |--------------------------------------------------------------------------
    | 3. THE CONSULTATION — `consultation_status`
    |--------------------------------------------------------------------------
    |
    | The doctor's column, and theirs alone. Never null: every visit has a
    | write-up that has or has not been started.
    */
    public const CONSULT_NOT_STARTED = 'not_started';

    public const CONSULT_IN_PROGRESS = 'in_progress';

    public const CONSULT_COMPLETED = 'completed';

    public const CONSULT_STATUSES = [
        self::CONSULT_NOT_STARTED,
        self::CONSULT_IN_PROGRESS,
        self::CONSULT_COMPLETED,
    ];

    /**
     * What the patient does next, once the doctor has finished.
     *
     * Cached on the row by VisitWorkflow rather than worked out per read:
     * answering it means asking the prescription, the lab and the till, and
     * the queue screen renders forty rows at a time.
     */
    public const NEXT_PHARMACY = 'pharmacy';

    public const NEXT_LABORATORY = 'laboratory';

    public const NEXT_BILLING = 'billing';

    public const NEXT_FOLLOW_UP = 'follow_up';

    public const NEXT_NONE = 'none';

    public const NEXT_ACTIONS = [
        self::NEXT_PHARMACY,
        self::NEXT_LABORATORY,
        self::NEXT_BILLING,
        self::NEXT_FOLLOW_UP,
        self::NEXT_NONE,
    ];

    protected $connection = 'organization';

    /**
     * Deliberately WITHOUT the three workflow columns.
     *
     * `queue_status`, `consultation_status` and `next_action` are written by
     * VisitWorkflow with forceFill, and by nothing else. Leaving them out
     * means a request body that names one cannot set it even if a future
     * controller forgets to strip it — the state machine is the only door in.
     *
     * `status` stays, because booking creates a row with one and the seeders
     * and demo data build days wholesale. No route accepts it either way:
     * every move through the workflow is its own verb, and the database's
     * own CHECK is what makes an impossible combination impossible.
     */
    protected $fillable = [
        'customer_id',
        'doctor_id',
        'location_id',
        'doctor_schedule_id',
        'appointment_date',
        'type',
        'status',
        'slot_at',
        'token_no',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'token_no' => 'integer',
            'checked_in_at' => 'datetime',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'visit_completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The sitting this came out of, while that sitting still exists. */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Whether the desk may move the queue on to this. */
    public function queueCanMoveTo(string $queueStatus): bool
    {
        return in_array($queueStatus, self::QUEUE_TRANSITIONS[$this->queue_status] ?? [], true);
    }

    /* ------------------------------------------------------- what is true */

    /** In the department, not yet called. */
    public function isWaiting(): bool
    {
        return $this->queue_status === self::QUEUE_WAITING;
    }

    /** Called to the room, not yet seen. The state the desk produces. */
    public function isCalled(): bool
    {
        return $this->queue_status === self::QUEUE_CALLED;
    }

    public function isWithDoctor(): bool
    {
        return $this->queue_status === self::QUEUE_WITH_DOCTOR;
    }

    public function consultationStarted(): bool
    {
        return $this->consultation_status !== self::CONSULT_NOT_STARTED;
    }

    public function consultationCompleted(): bool
    {
        return $this->consultation_status === self::CONSULT_COMPLETED;
    }

    /** Finished as far as anybody in the building is concerned. */
    public function isClosed(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
            true,
        );
    }

    /** The consultation is done and something downstream is not. */
    public function isAwaiting(): bool
    {
        return in_array($this->status, self::AWAITING, true);
    }

    /* ------------------------------------------------------------- scopes */

    /** Still to be seen — what the queue shows. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CHECKED_IN, self::STATUS_IN_CONSULTATION]);
    }

    /**
     * Anybody the desk is holding: waiting, called, or in the room.
     *
     * Read off `queue_status` rather than `status`, because that is the
     * column the desk's screen is actually about — a visit can be awaiting
     * the pharmacy and no longer in any queue at all.
     */
    public function scopeInQueue(Builder $query): Builder
    {
        return $query->whereNotNull('queue_status');
    }

    /** Called and not yet seen — the doctor's list. */
    public function scopeCalled(Builder $query): Builder
    {
        return $query->where('queue_status', self::QUEUE_CALLED);
    }

    /** The doctor has finished and the episode has not. */
    public function scopeAwaiting(Builder $query): Builder
    {
        return $query->whereIn('status', self::AWAITING);
    }

    /**
     * What the doctor wrote up, once they have.
     *
     * One per visit — the unique index on `appointment_id` makes a second
     * impossible, so this is a hasOne rather than a list somebody has to pick
     * the newest from.
     */
    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    /**
     * The visit's prescription, unless it was cancelled.
     *
     * One live one per visit, guaranteed by a partial unique index — so this
     * is the same hasOne shape as the consultation rather than a list.
     */
    public function livePrescription(): HasOne
    {
        return $this->hasOne(Prescription::class)->where('status', '<>', Prescription::CANCELLED);
    }

    /**
     * Every lab order raised at this visit.
     *
     * A list, unlike the prescription: a doctor who orders bloods, reads them
     * and then orders a scan has done two separate things.
     */
    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }

    /** The bills raised against this visit, dispensings included. */
    public function sales(): HasMany
    {
        return $this->hasMany(PharmacySale::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    public function consultationStarter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultation_started_by');
    }

    public function consultationCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultation_completed_by');
    }

    /** Cancelled bookings free their slot; nothing else does. */
    public function scopeHoldingASlot(Builder $query): Builder
    {
        return $query->whereNotNull('slot_at')->where('status', '<>', self::STATUS_CANCELLED);
    }

    /**
     * "Asha Rane · 14 Sep 2026 · 10:30", for the audit log.
     *
     * Named so a cancelled appointment still says whose it was long after
     * the row is gone.
     */
    protected function historyLabel(): ?string
    {
        $who = $this->customer?->name ?? "Patient #{$this->customer_id}";
        $when = $this->appointment_date?->format('d M Y') ?? '';
        $at = $this->slot_at ? ' · '.substr($this->slot_at, 0, 5) : '';

        return trim("{$who} · {$when}{$at}");
    }
}
