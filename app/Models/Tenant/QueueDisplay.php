<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One television on a wall.
 *
 * What it shows is decided here; who may open it is the secret in its URL, of
 * which this row keeps only the hash, so a leaked database backup opens nothing.
 * It follows that the URL can only be shown at the moment it is generated:
 * lose it and the answer is to rotate, the same bargain as an API key.
 */
class QueueDisplay extends Model
{
    use RecordsHistory, SoftDeletes;

    /** The waiting room: every doctor at the branch, or the ones chosen. */
    public const RECEPTION = 'reception';

    /** One department's doctors, for a floor or a wing. */
    public const DEPARTMENT = 'department';

    /** The screen outside one room. */
    public const DOCTOR = 'doctor';

    /** The lab's own bench, by order rather than by doctor. */
    public const LABORATORY = 'laboratory';

    public const TYPES = [self::RECEPTION, self::DEPARTMENT, self::DOCTOR, self::LABORATORY];

    public const LAYOUTS = ['grid', 'list'];

    public const THEMES = ['dark', 'light'];

    public const LANGUAGES = ['en', 'hi'];

    /** Seen within this long counts as switched on. It polls every minute. */
    public const ONLINE_WITHIN_SECONDS = 150;

    protected $connection = 'organization';

    /** Everything but the hash, which only QueueDisplays may write. */
    protected $fillable = [
        'location_id',
        'department_id',
        'display_name',
        'display_type',
        'layout',
        'theme',
        'audio_enabled',
        'audio_languages',
        'is_active',
        'expires_at',
        'created_by',
    ];

    protected $hidden = ['secure_display_token_hash'];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'department_id' => 'integer',
            'audio_enabled' => 'boolean',
            'audio_languages' => 'array',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /** The doctors picked for it, in the order they appear on the wall. */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'queue_display_doctors', 'queue_display_id', 'doctor_id')
            ->withPivot('sort_order')
            ->orderBy('queue_display_doctors.sort_order');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Why this screen may not open, or null when it may. */
    public function refusal(): ?string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            ! $this->is_active => 'inactive',
            default => null,
        };
    }

    public function isOnline(): bool
    {
        return $this->last_connected_at !== null
            && $this->last_connected_at->diffInSeconds(now()) <= self::ONLINE_WITHIN_SECONDS;
    }

    protected function historyLabel(): ?string
    {
        return mb_substr((string) $this->display_name, 0, 191);
    }
}
