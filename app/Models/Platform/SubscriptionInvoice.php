<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * What the platform asked an organization to pay. Issued ones are never
 * edited: the lines are a snapshot of what was charged and why.
 *
 * Not the tenant's App\Models\Tenant\Invoice — that is a clinic billing a
 * patient, in the clinic's own database.
 */
class SubscriptionInvoice extends Record
{
    use RecordsHistory;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_UPGRADE = 'upgrade';

    public const KIND_ADDON = 'addon';

    public const KIND_OVERAGE = 'overage';

    public const KIND_MANUAL = 'manual';

    protected $fillable = [
        'number',
        'organization_id',
        'public_registration_id',
        'subscription_id',
        'kind',
        'status',
        'currency',
        'lines',
        'subtotal',
        'tax_mode',
        'tax_rate_bp',
        'tax',
        'total',
        'period_start',
        'period_end',
        'billing_cycle',
        'fulfilment',
        'fulfilled_at',
        'bill_to',
        'issued_at',
        'due_at',
        'paid_at',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'subtotal' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'fulfilment' => 'array',
            'fulfilled_at' => 'datetime',
            'bill_to' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $invoice) {
            $invoice->uuid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'invoice_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::ISSUED);
    }

    public function isPayable(): bool
    {
        return $this->status === self::ISSUED && $this->total > 0;
    }

    protected function historyOrganizationId(): ?int
    {
        return $this->organization_id;
    }

    protected function historyLabel(): ?string
    {
        return 'Subscription invoice '.($this->number ?? '(draft)');
    }
}
