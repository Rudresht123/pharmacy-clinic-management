<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organisation-setup section the admin signed off, and when.
 *
 * Only the sections that are a decision rather than data live here — see
 * App\Services\Tenant\OrganizationSetup for how the rest are read.
 */
class SetupStep extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'step',
        'completed_at',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
        ];
    }

    protected function historyLabel(): ?string
    {
        return "Setup: {$this->step}";
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
