<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\PlatformUser;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Tenant\User;

/**
 * Who made a subscription change: a Super Admin, the clinic's own owner, or
 * the system (the scheduler, a verified webhook). Written onto every event.
 */
final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly ?string $name,
    ) {}

    public static function platform(PlatformUser $user): self
    {
        return new self(SubscriptionEvent::ACTOR_PLATFORM, (int) $user->getKey(), $user->name ?? $user->email);
    }

    public static function tenant(User $user): self
    {
        return new self(SubscriptionEvent::ACTOR_TENANT, (int) $user->getKey(), $user->name ?? $user->email);
    }

    public static function system(string $name = 'System'): self
    {
        return new self(SubscriptionEvent::ACTOR_SYSTEM, null, $name);
    }

    /** The Super Admin, when it was one — for created_by-style columns. */
    public function platformUserId(): ?int
    {
        return $this->type === SubscriptionEvent::ACTOR_PLATFORM ? $this->id : null;
    }
}
