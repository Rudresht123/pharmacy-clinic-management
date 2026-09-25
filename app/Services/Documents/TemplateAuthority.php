<?php

namespace App\Services\Documents;

use App\Models\Platform\Organization;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Documents\TemplateConfig;

/**
 * Who may change which template, and what they may change in it.
 *
 * The brief's authority model in one place, because it is asked in three:
 * listing templates, saving one, and publishing a version. Spread across those
 * three it would be three chances to word the same rule differently.
 *
 * The rule:
 *
 *   ORGANIZATION DEFAULT  (location_id IS NULL)
 *     `documents.template_org` only. It is what every branch inherits, so a
 *     branch manager changing it would be changing everybody's letterhead.
 *
 *   A BRANCH'S OWN
 *     `documents.template_edit` at that branch, AND the branch has to be one
 *     they actually work at — or `documents.template_org`, which reaches
 *     every branch.
 *
 *   LOCKED FIELDS
 *     Whoever holds `documents.template_org` locks paths on the organization
 *     default. A branch editing its own template may not CHANGE a locked path.
 *     Checked against what was changed rather than what was sent, so a client
 *     echoing a locked value back unchanged is not an attempt to change it.
 */
class TemplateAuthority
{
    public const ORG = 'documents.template_org';

    public const EDIT = 'documents.template_edit';

    public const VIEW = 'documents.template_view';

    public const PUBLISH = 'documents.template_publish';

    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
        private readonly TemplateResolver $resolver,
    ) {}

    /** Organization-wide authority: every branch's template, and the locks. */
    public function isOrganizationAdmin(Organization $organization, User $user): bool
    {
        return $this->permission->allows($organization, $user, self::ORG);
    }

    /**
     * May this person write a template for this place?
     *
     * `$locationId` null means the organization default.
     */
    public function mayWrite(Organization $organization, User $user, ?int $locationId): bool
    {
        if ($this->isOrganizationAdmin($organization, $user)) {
            return true;
        }

        // The organization's default is not a branch's to rewrite.
        if ($locationId === null) {
            return false;
        }

        return $this->branches->canUse($user, $locationId)
            && $this->permission->allows($organization, $user, self::EDIT, $locationId);
    }

    /** May they put a version into use here? A separate decision from editing. */
    public function mayPublish(Organization $organization, User $user, ?int $locationId): bool
    {
        if ($this->isOrganizationAdmin($organization, $user)) {
            return true;
        }

        if ($locationId === null) {
            return false;
        }

        return $this->branches->canUse($user, $locationId)
            && $this->permission->allows($organization, $user, self::PUBLISH, $locationId);
    }

    /**
     * May they even see it?
     *
     * A template is a letterhead rather than a patient record, so reading is
     * the loosest of the three — but another branch's is still somebody
     * else's, and listing it would let one branch's choices leak into another's
     * view of its own settings.
     */
    public function mayRead(Organization $organization, User $user, ?int $locationId): bool
    {
        if ($this->isOrganizationAdmin($organization, $user)) {
            return true;
        }

        // Everybody inherits the organization default, so everybody who may
        // read templates at all may read it.
        if ($locationId === null) {
            return $this->permission->allows($organization, $user, self::VIEW);
        }

        return $this->branches->canUse($user, $locationId)
            && $this->permission->allows($organization, $user, self::VIEW, $locationId);
    }

    /**
     * Which locked paths this edit would change.
     *
     * Empty when the edit is allowed. Anything in the list is a path the
     * organization locked and this person may not touch.
     *
     * Deliberately compares BEFORE and AFTER rather than inspecting the
     * payload: a form that posts the whole config back — which is what a
     * template editor does — sends every locked value on every save, and
     * refusing those would make a locked header lock the entire template.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function lockedViolations(
        Organization $organization,
        User $user,
        DocumentTemplate $template,
        array $before,
        array $after,
    ): array {
        // The organization's own locks do not bind the organization.
        if ($this->isOrganizationAdmin($organization, $user)) {
            return [];
        }

        // Nor do they bind the default itself — which only an organization
        // administrator can reach anyway.
        if ($template->isOrganizationDefault()) {
            return [];
        }

        $locks = $this->resolver->locksFor((string) $template->document_type);

        if ($locks === []) {
            return [];
        }

        $changed = TemplateConfig::changedPaths($before, $after);
        $violations = [];

        foreach ($changed as $path) {
            foreach ($locks as $lock) {
                // A locked section locks everything beneath it: locking
                // `header` locks `header.legal_name` without listing it.
                if ($path === $lock || str_starts_with($path, $lock.'.')) {
                    $violations[] = $path;

                    break;
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * A readable label for a locked path, for the message somebody reads.
     *
     * "You cannot change header.legal_name" is a stack trace; "Registered
     * clinic name is locked by your organisation" is an answer.
     */
    public function labelFor(string $path): string
    {
        foreach (TemplateConfig::lockablePaths() as $lockable) {
            if ($lockable['path'] === $path) {
                return $lockable['label'];
            }
        }

        // A locked section reported against a leaf beneath it.
        foreach (TemplateConfig::lockablePaths() as $lockable) {
            if (str_starts_with($path, $lockable['path'].'.')) {
                return $lockable['label'];
            }
        }

        return $path;
    }
}
