<?php

namespace App\Models\Platform;

use App\Support\History\RecordsHistory;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform's own wording for one notification type — the bottom of the
 * Global → Organisation → Branch chain.
 *
 * Master database, edited by the super admin, one row per type. Seeded with
 * the code defaults in NotificationTypes, so an organisation that customises
 * nothing reads exactly what it always has.
 */
class GlobalNotificationTemplate extends Model
{
    use RecordsHistory;

    protected $table = 'notification_templates';

    protected $fillable = [
        'template_key',
        'title',
        'body',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function historyLabel(): ?string
    {
        return NotificationTypes::find((string) $this->template_key)['label'] ?? $this->template_key;
    }
}
