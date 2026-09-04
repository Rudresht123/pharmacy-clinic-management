<?php

namespace App\Services\Permissions;

use App\Models\Tenant\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which people a person may see and act on.
 *
 * `people.view` and `people.edit` say somebody may administer staff. They do
 * not say WHICH staff, and until this existed the answer was "all of them" —
 * so an owner delegating staff administration to a branch manager was handing
 * over the whole network, and the owner's own account with it.
 *
 * Two rules, and the second is the one that matters:
 *
 *   1. A non-owner sees the people at their own branch, and themselves.
 *   2. A non-owner may never act on an OWNER. Not to rename them, not to
 *      deactivate them, and above all not to set their password.
 *
 * Without the second rule, `people.edit` was account takeover: the request
 * refuses promoting somebody to owner and refuses demoting the last one, but
 * nothing stopped a holder from PUTting the owner's own row with a new
 * password — role unchanged, still active, every guard satisfied.
 *
 * Separate from Permission, which answers "may this act be done at all".
 * This answers "to whom". Both are needed on a write and neither substitutes
 * for the other — the same division as Permission and TenantBranchAccess.
 */
class StaffScope
{
    /**
     * The capability that lifts branch scope.
     *
     * Organization HR administers the whole network; a branch manager does
     * not. Held as a capability rather than inferred from having no branch,
     * so it is something an owner grants on purpose.
     */
    public const ACROSS_BRANCHES = 'people.across_branches';

    /**
     * Narrow a staff query to the people this actor may see.
     *
     * Applied in the repository rather than the controller so a second screen
     * listing staff cannot forget it.
     */
    public function apply(Builder $query, ?User $actor): Builder
    {
        if (! $actor || $this->reachesEveryBranch($actor)) {
            return $query;
        }

        /*
         * Their own branch, plus themselves — somebody must always be able to
         * find their own record, and a staff member with no branch would
         * otherwise disappear from their own list.
         */
        return $query->where(function (Builder $scoped) use ($actor) {
            $scoped->where('id', $actor->getKey());

            if ($actor->location_id !== null) {
                $scoped->orWhere('location_id', $actor->location_id);
            }
        });
    }

    /**
     * Whether this actor may act on this person at all.
     *
     * Asked before an update or a delete, and before a `show` — a record
     * outside somebody's scope answers 404 rather than 403, so an id is never
     * confirmed to exist to somebody who may not see it.
     */
    public function canManage(?User $actor, User $target): bool
    {
        if (! $actor) {
            return false;
        }

        if ($actor->isOwner()) {
            return true;
        }

        /*
         * The rule that closes the takeover. An owner's record is out of
         * reach of everybody except another owner, whatever capabilities have
         * been delegated — there is no sense in which "manage staff" was ever
         * meant to include "reset the account holder's password".
         */
        if ($target->isOwner()) {
            return false;
        }

        if ($actor->getKey() === $target->getKey()) {
            return true;
        }

        if ($this->reachesEveryBranch($actor)) {
            return true;
        }

        // Same branch, and a branch they actually have. Two nulls are not a
        // match: it would make every head-office account mutually editable.
        return $actor->location_id !== null
            && $actor->location_id === $target->location_id;
    }

    /**
     * Whether this actor may put somebody on this role.
     *
     * Nobody may grant what they do not hold. Without this, delegating
     * `people.edit` delegates everything: put a junior on the most powerful
     * role, sign in as them, and the limit somebody carefully set is gone.
     *
     * @param  list<string>  $roleCapabilities  what the role being assigned holds
     * @param  list<string>  $actorCapabilities  what the actor holds
     */
    public function canGrant(?User $actor, array $roleCapabilities, array $actorCapabilities): bool
    {
        if (! $actor) {
            return false;
        }

        // The owner already holds the whole pool, so the check would always
        // pass — said outright rather than relied on.
        if ($actor->isOwner()) {
            return true;
        }

        return array_diff($roleCapabilities, $actorCapabilities) === [];
    }

    /**
     * Does this person administer staff beyond their own branch?
     *
     * Reads the role directly rather than going through Permission, because
     * the question is about the person rather than about a branch — and
     * because Permission would need a branch to answer at all.
     */
    private function reachesEveryBranch(User $actor): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        // Defensive: a capability retired from the registry must stop working
        // rather than linger as a key nothing recognises but everything honours.
        if (! ModuleRegistry::supportsCapability(self::ACROSS_BRANCHES)) {
            return false;
        }

        return $actor->permissionRole?->grants(self::ACROSS_BRANCHES) ?? false;
    }
}
