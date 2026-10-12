<?php

namespace App\Broadcasting;

use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Request;

/**
 * One person's own messages, live.
 *
 * `private-org.{organization}.user.{user}.messages` — a channel per login
 * rather than per conversation, so the inbox and every open chat hear new
 * messages through one subscription. Only the person it is named for may
 * join, and only in the organization they signed in to: users are numbered
 * per tenant database, so user 7 exists in every clinic, and the uuid in the
 * name is compared against the request's organization rather than trusted.
 *
 * Nothing more is asked here on purpose. What arrives on it is only ever that
 * person's own threads, and what they may DO with them is still decided by
 * the HTTP routes, behind their capabilities.
 */
class UserMessagesChannel
{
    /** The channel's name, without the `private-` prefix. */
    public static function name(string $organizationUuid, int $userId): string
    {
        return "org.{$organizationUuid}.user.{$userId}.messages";
    }

    public function join(mixed $user, string $organization, string $userId): bool
    {
        if (! $user instanceof User || ! ctype_digit($userId)) {
            return false;
        }

        $current = Request::instance()->attributes->get('tenant.organization');

        return $current instanceof Organization
            && $current->uuid === $organization
            && (int) $userId === (int) $user->getKey();
    }
}
