<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserMessagesChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One side has read a conversation up to a message — told to the other side,
 * so the ticks under what they sent turn to "read".
 */
class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     * @param  array{conversation_id: int, side: string, last_read_id: int}  $payload
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
        return 'conversation.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
