<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One branch's own wording of a template — the branch level of
 * Global → Organisation → Branch. See the migration for why WhatsApp
 * templates take none.
 *
 * Records its history: what a branch's patients are told is worth being able
 * to trace back to whoever changed it.
 */
class MessageTemplateVariant extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $table = 'message_template_variants';

    protected $fillable = [
        'message_template_id',
        'location_id',
        'subject',
        'content',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function historyLabel(): ?string
    {
        return ($this->template?->name ?? 'Template').' · '.($this->location?->name ?? 'branch');
    }
}
