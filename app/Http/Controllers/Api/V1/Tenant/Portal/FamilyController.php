<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Requests\Api\V1\Tenant\Portal\SaveFamilyMemberRequest;
use App\Http\Resources\Tenant\Portal\PortalFamilyMemberResource;
use App\Services\Portal\PatientFamily;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The people a patient books for.
 *
 * @group Patient Portal > Family
 */
class FamilyController extends PortalController
{
    public function __construct(
        private readonly PatientFamily $family,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(PortalFamilyMemberResource::collection($this->family->members($this->patient($request))));
    }

    public function store(SaveFamilyMemberRequest $request): JsonResponse
    {
        try {
            $link = $this->family->add($this->patient($request), $request->validated());
        } catch (RuntimeException $exception) {
            // Already in the family, or the family is full.
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->created(PortalFamilyMemberResource::make($link), 'Family member added');
    }

    public function update(SaveFamilyMemberRequest $request, int $member): JsonResponse
    {
        try {
            $link = $this->family->find($this->patient($request), $member);
        } catch (ModelNotFoundException) {
            return $this->fail('That family member was not found.', 404);
        }

        return $this->ok(
            PortalFamilyMemberResource::make($this->family->update($link, $request->validated())),
            'Family member updated',
        );
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        try {
            $this->family->remove($this->family->find($this->patient($request), $member));
        } catch (ModelNotFoundException) {
            // Caught first: it is itself a RuntimeException, and somebody
            // else's relative must read as missing, not as refused.
            return $this->fail('That family member was not found.', 404);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(null, 'Removed from your family');
    }
}
