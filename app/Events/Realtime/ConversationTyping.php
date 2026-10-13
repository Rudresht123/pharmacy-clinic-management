<?php

namespace App\Events\Realtime;

use App\Broadcasting\UserMessagesChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One side is writing — told to the other side, so their screen can say
 * "typing…" for a few seconds.
 *
 * Nothing is stored: it is true for as long as it keeps being said, and the
 * phone that hears it lets it lapse on its own.
 */
class ConversationTyping implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     * @param  array{conversation_id: int, side: string}  $payload
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
        return 'conversation.typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
