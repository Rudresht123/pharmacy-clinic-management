<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Models\Platform\Organization;
use App\Support\Tenancy\SubdomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one piece of tenant login lets an organization's own staff see before
 * they've authenticated at all: their clinic's name/logo, so the sign-in
 * screen reads as "your workspace" rather than generic vendor marketing.
 *
 * Public and unauthenticated on purpose — a subdomain existing at all is
 * already visible to anyone who visits it, and a name/logo isn't sensitive.
 */
class BrandingController extends BaseApiController
{
    public function show(Request $request): JsonResponse
    {
        $subdomain = SubdomainResolver::fromHost($request->getHost());

        $organization = $subdomain
            ? Organization::where('subdomain', $subdomain)->first()
            : null;

        if (! $organization) {
            return $this->fail('Unknown organization.', 404);
        }

        return $this->ok(OrganizationSummaryResource::make($organization));
    }
}
