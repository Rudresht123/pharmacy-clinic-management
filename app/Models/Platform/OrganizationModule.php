<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organization's entitlement to one module.
 *
 * Extends Record so the platform administrator who granted or revoked it is
 * stamped — an entitlement is a commercial decision and "who turned this
 * off" is the first question anybody asks.
 */
class OrganizationModule extends Record
{
    use RecordsHistory;

    protected $fillable = [
        'organization_id',
        'module_id',
        'is_enabled',
        'starts_at',
        'expires_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Switched on, started, and not yet run out.
     *
     * All three, because they fail for different reasons and a screen has to
     * be able to say which: revoked, scheduled, or lapsed.
     */
    public function isLive(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        // Compared to the end of the day it names: a subscription paid up to
        // the 30th is not over on the morning of the 30th.
        return $this->expires_at === null || $this->expires_at->endOfDay()->isFuture();
    }

    /** Why it is not live, for a screen that has to explain itself. */
    public function state(): string
    {
        if (! $this->is_enabled) {
            return 'revoked';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at !== null && ! $this->expires_at->endOfDay()->isFuture()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * A binding's history belongs to the organization it was sold to, not to
     * the binding row — which is deleted the moment the module is unassigned.
     */
    protected function historyOrganizationId(): ?int
    {
        return $this->organization_id;
    }

    /** What the log calls it, since a binding has no name of its own. */
    protected function historyLabel(): ?string
    {
        return $this->module?->name ?? "Module #{$this->module_id}";
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
