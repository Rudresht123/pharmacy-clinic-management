<?php

namespace App\Models\Tenant\Concerns;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What every laboratory list shares: on or off, an order, and a soft delete
 * that says who and why.
 *
 * Departments, sample types, units and the rest are short organisation-wide
 * lists the test master and the bench pick from. A removed one keeps naming
 * itself on whatever already used it, which is why nothing here is ever
 * deleted for real.
 *
 * A model using this declares `LABEL`, the column a person reads it by.
 */
trait IsLabList
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public function initializeIsLabList(): void
    {
        $this->mergeCasts([
            'is_active' => 'boolean',
            'position' => 'integer',
        ]);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeInListOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy(static::LABEL)->orderBy('id');
    }

    /** What a person reads this row by: a name, a symbol, a reason. */
    public function label(): string
    {
        return (string) $this->getAttribute(static::LABEL);
    }

    protected function historyLabel(): ?string
    {
        return $this->label();
    }
}
