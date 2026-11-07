<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who gets one message at one branch, where the branch has chosen for itself.
 * Without a row the branch follows the organisation's choice on the rule.
 *
 * Records its history: who is told about a patient is worth being able to trace
 * back to whoever changed it.
 */
class AutomationRuleBranchRecipients extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $table = 'automation_rule_branch_recipients';

    protected $fillable = [
        'automation_rule_id',
        'location_id',
        'recipients',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'recipients' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function historyLabel(): ?string
    {
        return ($this->rule?->title ?? 'Message').' · '.($this->location?->name ?? 'branch');
    }
}
