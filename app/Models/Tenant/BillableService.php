<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named thing an invoice can charge for.
 *
 * The doctor's sitting fee, an injection, a dressing, a certificate.
 * Organisation-scoped by default (`location_id` null) and everybody's invoice
 * pulls from the same list, the way the medicine master works — a service
 * renamed at head office reads the same at every branch.
 *
 * A branch may override with its own row (same `code`, different price at
 * that branch); the resolver picks the branch's row first, then the
 * organisation default. Nothing else is per-branch.
 */
class BillableService extends Model
{
    use RecordsHistory, SoftDeletes;

    /**
     * Charged once, when a patient's record is opened.
     *
     * Its own kind rather than a `service` with a convention attached,
     * because the software actually treats it differently: it is billed at
     * the desk before there is a visit to attach it to, and it is charged
     * once per patient rather than once per attendance.
     */
    public const KIND_REGISTRATION = 'registration';

    public const KIND_CONSULTATION = 'consultation';

    public const KIND_PROCEDURE = 'procedure';

    public const KIND_SERVICE = 'service';

    public const KIND_CUSTOM = 'custom';

    public const KINDS = [
        self::KIND_REGISTRATION,
        self::KIND_CONSULTATION,
        self::KIND_PROCEDURE,
        self::KIND_SERVICE,
        self::KIND_CUSTOM,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'name',
        'code',
        'kind',
        'default_price',
        'tax_percent',
        'active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Rows available to a branch: its own overrides, plus the org default. */
    public function scopeForBranch(Builder $query, ?int $locationId): Builder
    {
        return $query->where(function (Builder $q) use ($locationId) {
            $q->whereNull('location_id');

            if ($locationId !== null) {
                $q->orWhere('location_id', $locationId);
            }
        });
    }

    protected function historyLabel(): ?string
    {
        return $this->name;
    }
}
