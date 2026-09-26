<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One test on a lab order, and what it came back as.
 *
 * Free text rather than a catalogue entry: there is no test master, and
 * inventing one here would be a second thing nobody maintains. A doctor
 * typing "CBC" must not be blocked because nobody has set a catalogue up.
 *
 * `status`, the result fields and `completed_at` are the technician's to
 * write, never a doctor's and never a request's.
 */
class LabOrderItem extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::COMPLETED, self::CANCELLED];

    /** Where the sample comes from — a shortlist, not a taxonomy. */
    public const SPECIMENS = ['blood', 'urine', 'stool', 'swab', 'tissue', 'imaging', 'other'];

    protected $connection = 'organization';

    /**
     * What the DOCTOR may set. The result columns are absent on purpose:
     * they are entered by whoever ran the test, through their own endpoint.
     */
    protected $fillable = [
        'lab_order_id',
        'test_name',
        'test_code',
        'specimen',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'lab_order_id' => 'integer',
            'is_abnormal' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * A result is clinical, and the audit log cannot be redacted.
     *
     * The log still records that the line was completed and by whom — the
     * order's own entry carries that — but not the reading itself.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return ['result_value', 'result_unit', 'reference_range', 'notes'];
    }

    protected function historyLabel(): ?string
    {
        return mb_substr((string) $this->test_name, 0, 191);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** "14.2 g/dL (13.0–17.0)" — the reading as a report would print it. */
    public function resultText(): ?string
    {
        if ($this->result_value === null) {
            return null;
        }

        $reading = trim($this->result_value.' '.($this->result_unit ?? ''));

        return $this->reference_range ? "{$reading} ({$this->reference_range})" : $reading;
    }
}
