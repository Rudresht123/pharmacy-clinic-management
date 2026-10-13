<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserCallsChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The other phone has a handshake step waiting: fetch it.
 *
 * A NUDGE, NOT THE STEP. A video offer runs to several kilobytes and Reverb's
 * HTTP API turns away anything over 10 KB, so the signal itself is stored and
 * this says only that it exists. The phone asks for everything past the last
 * one it saw — which also means a nudge lost to a dropped socket costs a
 * moment's delay, not the call.
 */
class VideoCallSignalled implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  list<int>  $userIds  the logins on the receiving end */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly array $userIds,
        public readonly int $callId,
        public readonly int $signalId,
        public readonly string $type,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel(UserCallsChannel::name($this->organizationUuid, $userId)),
            $this->userIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'call.signal';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'signal_id' => $this->signalId,
            'type' => $this->type,
        ];
    }
}
