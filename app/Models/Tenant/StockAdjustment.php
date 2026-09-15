<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A correction to one batch's stock — damage, a write-off, a count that
 * disagreed with the books — with the reason it happened.
 *
 * Append-only, by trigger. Its ledger row points back at it through
 * reference_type / reference_id.
 */
class StockAdjustment extends Model
{
    use RecordsHistory;

    public const INCREASE = 'increase';

    public const DECREASE = 'decrease';

    public const DIRECTIONS = [self::INCREASE, self::DECREASE];

    public const DAMAGE = 'damage';

    public const EXPIRY_WRITEOFF = 'expiry_writeoff';

    public const COUNT_CORRECTION = 'count_correction';

    public const LOSS = 'loss';

    public const OTHER = 'other';

    public const REASON_CODES = [self::DAMAGE, self::EXPIRY_WRITEOFF, self::COUNT_CORRECTION, self::LOSS, self::OTHER];

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_store_id',
        'location_id',
        'medicine_id',
        'medicine_batch_id',
        'direction',
        'quantity',
        'reason_code',
        'reason',
        'idempotency_key',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['idempotency_key'];
    }

    protected function historyLabel(): ?string
    {
        return $this->adjustment_number;
    }

    /**
     * The ledger movement this adjustment writes. Damage and write-offs are
     * named as such, so a report can tell them from a miscount.
     */
    public function movementType(): string
    {
        if ($this->direction === self::INCREASE) {
            return StockMovement::ADJUSTMENT_INCREASE;
        }

        return match ($this->reason_code) {
            self::DAMAGE => StockMovement::DAMAGE,
            self::EXPIRY_WRITEOFF => StockMovement::EXPIRY_WRITEOFF,
            default => StockMovement::ADJUSTMENT_DECREASE,
        };
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id')->withTrashed();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }
}
