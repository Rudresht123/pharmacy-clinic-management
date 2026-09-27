<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tests a lab offers, and what each one costs.
 *
 * A PRICE LIST, not a taxonomy. Reference ranges, specimen handling and the
 * reading itself stay on the order item where the technician writes them —
 * this exists so a completed lab order can put a number on a bill.
 *
 * DELIBERATELY OPTIONAL. LabOrderItem's own docblock is right that a doctor
 * typing "CBC" must not be blocked because nobody has set a catalogue up, and
 * that has not changed: an order line with no catalogue entry is ordered and
 * run exactly as before, and simply carries no charge to the invoice. A
 * clinic that never prices its tests never has to open this screen.
 */
class LabTestCatalog extends Model
{
    use RecordsHistory, SoftDeletes;

    protected $connection = 'organization';

    protected $table = 'lab_test_catalog';

    protected $fillable = [
        'location_id',
        'name',
        'code',
        'specimen',
        'price',
        'tax_percent',
        'active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
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

    /** The organisation's list, plus this branch's own overrides. */
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
