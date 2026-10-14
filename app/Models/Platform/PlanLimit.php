<?php

namespace App\Models\Platform;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One limit or monthly quota on a plan. A NULL value is unlimited.
 */
class PlanLimit extends Model
{
    use RecordsHistory;

    protected $fillable = ['plan_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function historyLabel(): ?string
    {
        return "Plan limit: {$this->key}";
    }
}
