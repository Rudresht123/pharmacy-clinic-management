<?php

namespace App\Broadcasting;

use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Support\Facades\Request;

/**
 * Who may listen to one doctor's queue at one branch.
 *
 * `private-org.{organization}.branch.{branch}.doctor.{doctor}.queue` — one
 * channel per doctor, so a screen hears only the queues it asked for and a
 * doctor calling a patient reaches no other doctor's listeners.
 *
 * Checked the same way as BranchPharmacyChannel, word by word: the organization
 * this request authenticated against, a branch this person works at, then that
 * they may see this queue — the whole branch's, or their own if they are this
 * doctor.
 *
 * Televisions do not come through here: they have no login. They prove the
 * display token to DisplayController::authorizeChannel instead, and get a
 * signature for the same channel only if that doctor is on that screen.
 */
class DoctorQueueChannel
{
    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** The channel's name, without the `private-` prefix Echo adds. */
    public static function name(string $organizationUuid, int $locationId, int $doctorId): string
    {
        return "org.{$organizationUuid}.branch.{$locationId}.doctor.{$doctorId}.queue";
    }

    public function join(mixed $user, string $organization, string $branch, string $doctor): bool
    {
        if (! $user instanceof User || ! ctype_digit($branch) || ! ctype_digit($doctor)) {
            return false;
        }

        $current = Request::instance()->attributes->get('tenant.organization');

        if (! $current instanceof Organization || $current->uuid !== $organization) {
            return false;
        }

        $locationId = (int) $branch;

        if (! $this->branches->canUse($user, $locationId)) {
            return false;
        }

        if ($this->permission->allows($current, $user, 'queue.board', $locationId)) {
            return true;
        }

        return $user->userable_type === Doctor::class
            && (int) $user->userable_id === (int) $doctor
            && $this->permission->allows($current, $user, 'queue.my_console', $locationId);
    }
}
