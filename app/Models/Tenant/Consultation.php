<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened in the room.
 *
 * The appointment says somebody was due and turned up; this says what the
 * doctor found. One per appointment — a second would be two answers to "what
 * was the diagnosis".
 */
class Consultation extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'appointment_id',
        'customer_id',
        'doctor_id',
        'chief_complaint',
        'diagnoses',
        'vitals',
        'prescription',
        'investigations',
        'advice',
        'notes',
        'follow_up_days',
    ];

    protected function casts(): array
    {
        return [
            'diagnoses' => 'array',
            'vitals' => 'array',
            'prescription' => 'array',
            'investigations' => 'array',
            'follow_up_days' => 'integer',
        ];
    }

    /**
     * Clinical free text stays out of the audit log.
     *
     * `activity_logs` is append-only and un-deletable by design, so a
     * complaint, a diagnosis or a note written into it could never afterwards
     * be corrected or redacted — which is exactly what a patient record must
     * allow. The log still records THAT a consultation was written and by
     * whom; the words themselves live only on this row, where they can be
     * edited.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return [
            'chief_complaint',
            'diagnoses',
            'vitals',
            'prescription',
            'investigations',
            'advice',
            'notes',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** "Asha Rane · 14 Sep 2026", for the audit log. */
    protected function historyLabel(): ?string
    {
        $who = $this->customer?->name ?? 'Patient';
        $when = $this->created_at?->format('d M Y') ?? '';

        return trim("{$who} · {$when}");
    }
}
