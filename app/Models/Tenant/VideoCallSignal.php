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

    /**
     * "Start again from a fresh offer." Sent by a phone that has lost its end
     * of the connection — the app was closed mid-call and reopened — since
     * only the doctor's side makes offers, and the doctor's phone may not
     * notice the patient has gone for half a minute.
     */
    public const RESTART = 'restart';

    public const TYPES = [self::OFFER, self::ANSWER, self::CANDIDATES, self::RESTART];

    /** The steps that carry a session description. */
    public const WITH_SDP = [self::OFFER, self::ANSWER];

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
