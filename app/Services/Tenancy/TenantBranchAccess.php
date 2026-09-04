<?php

namespace App\Services\Tenancy;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;

/**
 * Which branches the signed-in person may act on.
 *
 * The organization's own database keeps one tenant's data away from another
 * — that isolation is physical and needs nobody to remember it. Inside one
 * organization there is no such wall: a branch id arriving in a request is
 * just a number, and until this service existed nothing checked that the
 * person sending it worked there.
 *
 * That did not matter while every screen was organization-wide. It matters
 * the moment a record belongs to a branch, which is why this lands before
 * appointments rather than with them.
 *
 * The rule is deliberately small:
 *
 *   owner  — every branch. They run the whole network and have no branch of
 *            their own, which is why they hold no memberships.
 *   staff  — the branches they are a member of, and no others.
 *
 * Reads memberships rather than the old `users.location_id`, so a doctor
 * sitting at three clinics may act at all three. Somebody with no memberships
 * — head office — may act on nothing branch-specific, which is correct: their
 * work is organization-wide, and reaching into a particular branch's day is a
 * different permission from having none of their own.
 *
 * Separate from Permission, which answers what may be DONE. This answers
 * WHERE. Both are needed on a branch-specific write.
 */
class TenantBranchAccess
{
    /**
     * Whether this person may act on this branch.
     *
     * A null branch is allowed: plenty of things are organization-wide, and
     * refusing them here would push every caller into asking twice.
     */
    public function canUse(?User $user, ?int $locationId): bool
    {
        if ($locationId === null) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->role === User::OWNER) {
            return true;
        }

        return $user->membershipAt($locationId) !== null;
    }

    /** The same question about whoever is signed in on the tenant guard. */
    public function currentCanUse(?int $locationId): bool
    {
        return $this->canUse(Auth::guard('web')->user(), $locationId);
    }

    /**
     * The branches this person may act on, or null for "all of them".
     *
     * Null rather than a list of every id: a query scoping itself can skip
     * the clause entirely, and an owner's list would otherwise have to be
     * fetched to say "no restriction".
     *
     * @return list<int>|null
     */
    public function allowed(?User $user): ?array
    {
        if (! $user || $user->role === User::OWNER) {
            return null;
        }

        // Every branch they are a member of. An empty list is a real answer —
        // head office holds no memberships and acts on no branch's day.
        return $user->memberships
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
