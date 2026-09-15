<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of the stock ledger: a signed quantity against one batch, with
 * the quantity before and after, and the document that caused it.
 *
 * Append-only. A trigger refuses UPDATE and DELETE at the database; the
 * model refuses them earlier with a clearer message. A wrong row is answered
 * by a compensating row that points back at it (`reverses_movement_id`).
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const OPENING_BALANCE = 'opening_balance';

    public const PURCHASE = 'purchase';

    public const STOCK_INWARD = 'stock_inward';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const DISPENSING = 'dispensing';

    public const DISPENSING_REVERSAL = 'dispensing_reversal';

    public const RETURN_FROM_PATIENT = 'return_from_patient';

    public const SUPPLIER_RETURN = 'supplier_return';

    public const DAMAGE = 'damage';

    public const EXPIRY_WRITEOFF = 'expiry_writeoff';

    public const ADJUSTMENT_INCREASE = 'adjustment_increase';

    public const ADJUSTMENT_DECREASE = 'adjustment_decrease';

    public const CORRECTION = 'correction';

    /** Every value the database CHECK permits. */
    public const TYPES = [
        self::OPENING_BALANCE, self::PURCHASE, self::STOCK_INWARD, self::TRANSFER_IN, self::TRANSFER_OUT,
        self::DISPENSING, self::DISPENSING_REVERSAL, self::RETURN_FROM_PATIENT, self::SUPPLIER_RETURN,
        self::DAMAGE, self::EXPIRY_WRITEOFF, self::ADJUSTMENT_INCREASE, self::ADJUSTMENT_DECREASE,
        self::CORRECTION,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'pharmacy_store_id',
        'medicine_id',
        'medicine_batch_id',
        'movement_type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'reverses_movement_id',
        'reason',
        'notes',
        'performed_by',
        'performed_by_name',
        'movement_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:2',
            'reference_id' => 'integer',
            'movement_date' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The stock ledger is append-only; record a compensating movement.'));
        static::deleting(fn () => throw new LogicException('The stock ledger is append-only; record a compensating movement.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id')->withTrashed();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'pharmacy_store_id')->withTrashed();
    }
}
