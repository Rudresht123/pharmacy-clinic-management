<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserCallsChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A call changed state — it started ringing, was answered, or ended — told to
 * the logins on either end of it.
 *
 * Sent NOW rather than queued: a ring that arrives after the queue gets round
 * to it has already been missed. Raised after the commit, and a failure to
 * send never undoes the change (see VideoCalls::tell).
 *
 * PLAIN VALUES ONLY — the row is VideoCalls::describe(), the same shape the
 * HTTP endpoints return, so a phone updates from a push exactly as it would
 * from a fetch. Never TURN credentials: those are per login, and only ever
 * handed out over HTTP.
 */
class VideoCallUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds  the logins to tell
     * @param  array<string, mixed>  $call  VideoCalls::describe()
     */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly array $userIds,
        public readonly array $call,
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
        return 'call.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->call;
    }
}
