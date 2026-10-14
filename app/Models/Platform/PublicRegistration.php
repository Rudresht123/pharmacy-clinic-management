<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sign-up from the marketing website, before it becomes an organization.
 * The website only ever sees `reference`.
 */
class PublicRegistration extends Model
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const PAID = 'paid';

    public const PROVISIONING = 'provisioning';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'idempotency_key',
        'status',
        'plan_id',
        'billing_cycle',
        'start_trial',
        'addons',
        'organization_name',
        'contact_name',
        'email',
        'phone',
        'subdomain',
        'organization_type_id',
        'quote',
        'organization_id',
        'failure_reason',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'start_trial' => 'boolean',
            'addons' => 'array',
            'quote' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration) {
            $registration->reference ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'public_registration_id');
    }
}
