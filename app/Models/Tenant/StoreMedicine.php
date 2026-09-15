<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "This store stocks this medicine", with the levels that make it low.
 *
 * Configuration, not stock: there is no quantity here. Removing a row says
 * the store no longer keeps the medicine; adding it again brings the same
 * row back rather than starting a second history.
 */
class StoreMedicine extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_store_id',
        'medicine_id',
        'reorder_level',
        'minimum_stock_level',
        'maximum_stock_level',
        'preferred_supplier_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pharmacy_store_id' => 'integer',
            'medicine_id' => 'integer',
            'reorder_level' => 'integer',
            'minimum_stock_level' => 'integer',
            'maximum_stock_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'pharmacy_store_id')->withTrashed();
    }

    /** With history: a configuration row outlives a medicine that was removed. */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    protected function historyLabel(): ?string
    {
        $label = trim(($this->medicine?->displayName() ?? 'Medicine').' at '.($this->store?->name ?? 'store'));

        return mb_substr($label, 0, 191);
    }
}
