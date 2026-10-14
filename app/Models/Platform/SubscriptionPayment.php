<?php

namespace App\Models\Platform;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to pay a subscription invoice.
 *
 * `verified_at` is set by the server alone — a checked Razorpay signature, a
 * checked webhook, or an administrator recording money they have seen. A
 * browser reporting success is never a payment.
 */
class SubscriptionPayment extends Model
{
    use RecordsHistory;

    public const RAZORPAY = 'razorpay';

    public const MANUAL = 'manual';

    public const CREATED = 'created';

    public const CAPTURED = 'captured';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'method',
        'status',
        'currency',
        'amount',
        'gateway_order_id',
        'gateway_payment_id',
        'verified_at',
        'verified_via',
        'reference',
        'failure_reason',
        'payload',
        'idempotency_key',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'verified_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->uuid ??= (string) Str::ulid();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /** The raw gateway payload is evidence, not something to diff in a log. */
    protected function historyExcept(): array
    {
        return ['payload'];
    }

    protected function historyOrganizationId(): ?int
    {
        return $this->organization_id;
    }

    protected function historyLabel(): ?string
    {
        return "Subscription payment ({$this->method})";
    }
}
