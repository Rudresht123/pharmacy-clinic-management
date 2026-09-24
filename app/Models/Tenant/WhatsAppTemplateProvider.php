<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What one provider calls one of the clinic's templates.
 *
 * `appointment_confirmation` is the clinic's name for a message and the only
 * name business code ever uses. Digiware knows it by a name of its own, Meta
 * by another, Twilio by an opaque Content SID — three more facts that belong
 * beside the template rather than inside it.
 *
 * `status` is the PROVIDER's opinion and not the clinic's: Digiware may have
 * approved a template Meta has not, and a clinic running both needs to know
 * which one it can actually send today.
 */
class WhatsAppTemplateProvider extends Model
{
    use SoftDeletes;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Mapped, but the clinic has turned it off for this provider. */
    public const DISABLED = 'disabled';

    /** Every value the database CHECK permits. */
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::DISABLED];

    protected $connection = 'organization';

    protected $table = 'whatsapp_template_providers';

    protected $fillable = [
        'message_template_id',
        'provider',
        'provider_template_id',
        'provider_template_name',
        'language',
        'status',
        'rejection_reason',
        'synced_at',
    ];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    /** Mappings this provider may actually send. */
    public function scopeSendable(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider)->where('status', self::APPROVED);
    }

    /**
     * What to ask the provider for.
     *
     * The id wins where there is one, because a provider that issues ids
     * (Twilio) will not accept the name; a provider that uses names
     * (Digiware, Meta) has no id and falls through to it.
     */
    public function reference(): ?string
    {
        return $this->provider_template_id ?: $this->provider_template_name;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
