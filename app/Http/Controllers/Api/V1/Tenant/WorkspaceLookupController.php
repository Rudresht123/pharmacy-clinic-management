<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Models\Platform\Organization;
use Illuminate\Http\JsonResponse;

/**
 * Finds an organization by the code its staff were given.
 *
 * The web app never needs this: a browser arrives already on
 * `{subdomain}.{main_domain}`, so BrandingController can read the workspace off
 * the Host header. A phone has no such host — it is launched from an icon and
 * has to be told which of many organizations it is signing in to before it can
 * address any of them. The code is what it asks for, and this is what turns
 * that code into a workspace.
 *
 * Public and unauthenticated, like BrandingController and for the same reason:
 * a name and a logo are what an organization prints on its own front door.
 *
 * It is, however, the one endpoint that will confirm whether a given code
 * exists, so it is rate limited at the route. The fields it returns are fixed
 * by OrganizationSummaryResource and deliberately dull — name, code, subdomain,
 * logo. Nothing operational, and in particular never `database_name`.
 */
class WorkspaceLookupController extends BaseApiController
{
    public function show(string $code): JsonResponse
    {
        $code = trim($code);

        $organization = Organization::query()
            // Eager-loaded so the resource does not fire a second query for the
            // one extra field it needs.
            ->with('organizationType')
            // Matched case-insensitively. The code is typed by hand on a phone
            // keyboard that capitalises the first letter on its own, and
            // refusing "Br123" when "br123" is registered would be a puzzle
            // with no clue attached.
            ->whereRaw('LOWER(organization_code) = ?', [mb_strtolower($code)])
            ->first();

        // One answer for "no such code", "not finished setting up" and "no
        // longer active". They are different to us and identical to the person
        // typing: in every case this is not a workspace they can sign in to,
        // and spelling out which would hand an outsider a census of
        // organizations that exist but are suspended.
        if (
            ! $organization
            || ! $organization->is_active
            || ! $organization->is_setup_completed
            || $organization->status !== Organization::ACTIVE
        ) {
            return $this->fail('No active workspace with that code.', 404);
        }

        return $this->ok(OrganizationSummaryResource::make($organization));
    }
}
