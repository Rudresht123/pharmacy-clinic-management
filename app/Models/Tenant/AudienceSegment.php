<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who a campaign goes to, stored as rules rather than as a list.
 *
 * "Patients with diabetes" has to mean whoever that is on the morning the
 * campaign goes out. A saved list of ids would mean whoever it was the day
 * somebody built it, and would quietly stop including anybody diagnosed since.
 *
 * System segments ship with the module and cannot be removed — "All patients"
 * is not an opinion a clinic should be able to delete and then wonder where it
 * went.
 */
class AudienceSegment extends Model
{
    use RecordsHistory, SoftDeletes;

    protected $connection = 'organization';

    protected $table = 'audience_segments';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'tone',
        'filters',
        'is_system',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return $this->name;
    }
}
