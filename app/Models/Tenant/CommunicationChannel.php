<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One channel's connection — the account a clinic messages patients from.
 *
 * One row per channel, written by the migration so every screen has something
 * to read. Disconnected is a real state and the common one: a clinic that has
 * not set WhatsApp up should see that, not an account that looks live.
 */
class CommunicationChannel extends Model
{
    use RecordsHistory;

    public const WHATSAPP = 'whatsapp';

    public const EMAIL = 'email';

    public const SMS = 'sms';

    /** Every value the database CHECK permits. */
    public const CHANNELS = [self::WHATSAPP, self::EMAIL, self::SMS];

    protected $connection = 'organization';

    protected $table = 'communication_channels';

    protected $fillable = [
        'display_name',
        'handle',
        'provider',
        'provider_key',
        'credentials',
        'verified_at',
        'verification_error',
        'is_connected',
        'is_verified',
        'webhook_configured',
        'connected_at',
        'settings',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'is_verified' => 'boolean',
            'webhook_configured' => 'boolean',
            'connected_at' => 'datetime',
            'settings' => 'array',
            'verified_at' => 'datetime',

            /*
             * Encrypted at rest, and never decrypted anywhere but here.
             *
             * `encrypted:array` means the column holds ciphertext: somebody
             * with a psql prompt, a database backup or a replica sees a blob
             * rather than a clinic's API token. It also means the token
             * cannot be queried on, which is correct — nothing should ever
             * need to search for one.
             */
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * One channel's row, always.
     *
     * `firstOrCreate` for the one case the migration cannot cover: a tenant
     * database restored from before this table existed.
     */
    public static function forChannel(string $channel): self
    {
        return static::query()->firstOrCreate(['channel' => $channel]);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'channel', 'channel');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return ucfirst($this->channel).' connection';
    }
}
