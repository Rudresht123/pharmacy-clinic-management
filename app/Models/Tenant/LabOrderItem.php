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
 * Free text FIRST, catalogue second. A doctor typing "CBC" must not be
 * blocked because nobody has set a catalogue up, and that is still true —
 * `lab_test_catalog_id` is nullable forever. What the catalogue adds is a
 * price: a line that came from it carries `price_snapshot`, which is what
 * the visit's invoice charges for. A freehand line carries none and bills
 * nothing.
 *
 * SNAPSHOT, not a lookup. The catalogue is edited; a bill is not. A test
 * repriced next month must not silently rewrite what a patient was charged
 * last week — the same rule `pharmacy_sale_items` follows with its own
 * snapshot columns.
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

        /*
         * Set by LabOrders when the doctor picked a catalogue entry. The
         * price is copied at that moment and never read back off the
         * catalogue — see the class docblock.
         */
        'lab_test_catalog_id',
        'price_snapshot',
        'tax_percent_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'lab_order_id' => 'integer',
            'is_abnormal' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
            'price_snapshot' => 'decimal:2',
            'tax_percent_snapshot' => 'decimal:2',
        ];
    }

    /** Whether this line puts anything on the bill. */
    public function isBillable(): bool
    {
        return $this->price_snapshot !== null && (float) $this->price_snapshot > 0;
    }

    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(LabTestCatalog::class, 'lab_test_catalog_id');
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
