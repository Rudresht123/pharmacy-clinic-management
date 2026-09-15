<?php

namespace App\Models\Tenant;

use App\Support\Deletion\HistoricalRecordException;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goods received note: stock arriving at a store, from a supplier, as an
 * opening balance, or back from a patient.
 *
 * Posted when saved; cancelled, never deleted. Its number (GRN-00001) is
 * taken by the database as the row is inserted.
 */
class StockInward extends Model
{
    use RecordsHistory;

    public const PURCHASE = 'purchase';

    public const OPENING_BALANCE = 'opening_balance';

    public const RETURN_FROM_PATIENT = 'return_from_patient';

    public const TYPES = [self::PURCHASE, self::OPENING_BALANCE, self::RETURN_FROM_PATIENT];

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    /** The ledger movement each kind of receipt writes. */
    public const MOVEMENT_FOR = [
        self::PURCHASE => StockMovement::PURCHASE,
        self::OPENING_BALANCE => StockMovement::OPENING_BALANCE,
        self::RETURN_FROM_PATIENT => StockMovement::RETURN_FROM_PATIENT,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_store_id',
        'location_id',
        'supplier_id',
        'inward_type',
        'supplier_invoice_no',
        'supplier_invoice_date',
        'received_date',
        'total_amount',
        'notes',
        'idempotency_key',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'supplier_invoice_date' => 'date',
            'received_date' => 'date',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new HistoricalRecordException('A goods received note is cancelled, never deleted.'));
    }

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['idempotency_key'];
    }

    protected function historyLabel(): ?string
    {
        return $this->inward_number;
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockInwardItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'pharmacy_store_id')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
