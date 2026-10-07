<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organisation's — or one branch's — own wording for a notification type.
 *
 * `location_id` NULL is the organisation's version; set, it is that branch's,
 * the same convention document templates use. Sparse: no row means the level
 * below decides (branch → organisation → global → code default), resolved in
 * one place — App\Services\Notifications\NotificationTemplateResolver.
 *
 * Records its own history: what every patient at a branch is told is worth
 * being able to trace back to who changed it.
 */
class NotificationTemplate extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $table = 'notification_templates';

    protected $fillable = [
        'template_key',
        'location_id',
        'title',
        'body',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function historyLabel(): ?string
    {
        return NotificationTypes::find((string) $this->template_key)['label'] ?? $this->template_key;
    }
}
