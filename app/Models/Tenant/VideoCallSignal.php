<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of the WebRTC handshake, from one phone to the other.
 *
 * An `offer` or `answer` carries a session description; `candidates` carries
 * network addresses the sender can be reached on. Kept only while the call
 * lasts: they hold both phones' IP addresses, which have no business outliving
 * the conversation they were for.
 */
class VideoCallSignal extends Model
{
    public const OFFER = 'offer';

    public const ANSWER = 'answer';

    public const CANDIDATES = 'candidates';

    public const TYPES = [self::OFFER, self::ANSWER, self::CANDIDATES];

    public const UPDATED_AT = null;

    protected $connection = 'organization';

    protected $fillable = [
        'video_call_id',
        'from_side',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(VideoCall::class, 'video_call_id');
    }
}
