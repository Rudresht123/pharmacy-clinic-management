<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserMessagesChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One side's phone has received a conversation up to a message — told to the
 * other side, so the single tick under what they sent becomes two.
 */
class ConversationDelivered implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     * @param  array{conversation_id: int, side: string, last_delivered_id: int}  $payload
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
        return 'conversation.delivered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
