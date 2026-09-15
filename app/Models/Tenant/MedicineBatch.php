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
 * One lot of one medicine in one store: its expiry, its prices, and how many
 * base units are on the shelf.
 *
 * `quantity_available` is written only by StockMovementService, in the same
 * transaction as the ledger row that explains it. Nothing else — not a
 * controller, not a form, not tinker — sets it.
 */
class MedicineBatch extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    public const RECALLED = 'recalled';

    public const EXHAUSTED = 'exhausted';

    public const EXPIRED = 'expired';

    /** Every value the database CHECK permits. */
    public const STATUSES = [self::ACTIVE, self::BLOCKED, self::RECALLED, self::EXHAUSTED, self::EXPIRED];

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_store_id',
        'medicine_id',
        'supplier_id',
        'stock_inward_item_id',
        'batch_number',
        'expiry_date',
        'manufacture_date',
        'purchase_price',
        'selling_price',
        'mrp',
        'quantity_received',
        'received_date',
    ];

    protected function casts(): array
    {
        return [
            'pharmacy_store_id' => 'integer',
            'medicine_id' => 'integer',
            'supplier_id' => 'integer',
            'expiry_date' => 'date',
            'manufacture_date' => 'date',
            'received_date' => 'date',
            'blocked_at' => 'datetime',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'quantity_received' => 'integer',
            'quantity_available' => 'integer',
            'damaged_quantity' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    /**
     * The ledger is the history of every quantity; repeating each one here
     * would put two diverging accounts of the same stock in two logs. Status,
     * prices and dates are still recorded.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return ['quantity_available', 'damaged_quantity', 'returned_quantity'];
    }

    protected function historyLabel(): ?string
    {
        return mb_substr("Batch {$this->batch_number}", 0, 191);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'pharmacy_store_id')->withTrashed();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Past its expiry on the clinic's own calendar.
     *
     * A batch is dispensable while its expiry date is AFTER today; on the
     * expiry date itself it is already treated as expired.
     */
    public function isPastExpiry(): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->lte(now()->startOfDay());
    }

    /** Stock may leave it for a patient or another store. */
    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && ! $this->isPastExpiry();
    }

    /** @param  Builder<MedicineBatch>  $query */
    public function scopeForStore(Builder $query, int $storeId): void
    {
        $query->where('pharmacy_store_id', $storeId);
    }

    /**
     * What a counter may hand over, first expiry first.
     *
     * @param  Builder<MedicineBatch>  $query
     */
    public function scopeAvailableForDispensing(Builder $query, int $storeId, int $medicineId): void
    {
        $query->where('pharmacy_store_id', $storeId)
            ->where('medicine_id', $medicineId)
            ->where('status', self::ACTIVE)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>', now()->toDateString())
            ->orderBy('expiry_date')
            ->orderBy('id');
    }
}
