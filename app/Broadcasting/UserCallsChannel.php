<?php

namespace App\Broadcasting;

use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Request;

/**
 * One person's video calls, live: a ring arriving, being answered or hung up,
 * and the nudges that a handshake step is waiting to be fetched.
 *
 * `private-org.{organization}.user.{user}.calls` — per login, like messages,
 * because a call can arrive while any screen is open. Kept apart from the
 * messages channel so the app can listen for calls for the whole session
 * without also taking every message event on screens that never show one.
 *
 * Only the person it is named for may join, and only in the organization they
 * signed in to — user ids repeat across clinics, so the uuid is compared
 * against the request's organization rather than trusted.
 */
class UserCallsChannel
{
    /** The channel's name, without the `private-` prefix. */
    public static function name(string $organizationUuid, int $userId): string
    {
        return "org.{$organizationUuid}.user.{$userId}.calls";
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
