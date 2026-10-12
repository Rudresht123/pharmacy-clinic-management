<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserMessagesChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was written — told to both people in the conversation.
 *
 * The sender hears it too, so a second device of theirs shows what they sent
 * from the first. Sent NOW, not queued, for the reason ConsultationCompleted
 * gives: a queued broadcast is a job that has to find its way back to the
 * right tenant database, for something whose whole value is arriving at once.
 *
 * PLAIN VALUES ONLY — the row is Messenger's, the same shape the messages
 * endpoint returns, so a client inserts a pushed message exactly as a fetched
 * one.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly array $userIds,
        public readonly array $payload,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel(UserMessagesChannel::name($this->organizationUuid, $userId)),
            $this->userIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
