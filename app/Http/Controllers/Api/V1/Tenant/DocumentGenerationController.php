<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\PatientDocumentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Services\Documents\DocumentService;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Documents\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Print a document and file it against the patient.
 *
 * The record is named by id and the KIND of record is decided by the document
 * type, not by the request — so a caller cannot ask for a prescription to be
 * rendered from a pharmacy sale, and cannot name a table this endpoint does
 * not serve.
 *
 * The branch is never taken from the payload either. It is the branch the
 * request is already acting in, which ResolveActingBranch has checked against
 * the caller's memberships — so the letterhead on what comes out is the
 * letterhead of a branch they actually work at.
 *
 * There is deliberately NO regenerate. A generated PDF is a medical record the
 * moment it exists and somebody may already be holding it; a wrong one is
 * removed with a reason and a new one printed, which leaves both in the audit
 * trail.
 */
class DocumentGenerationController extends BaseApiController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly TenantBranchAccess $branches,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(DocumentTypes::keys())],
            'subject_id' => ['required', 'integer'],
        ]);

        $type = DocumentTypes::find($validated['document_type']);
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization) {
            abort(403, 'This action is not available to you.');
        }

        $subject = $this->subject($type['subject'], (int) $validated['subject_id']);

        if (! $subject) {
            // 404 rather than 422: an id that is not there and an id that is
            // somebody else's should be indistinguishable.
            abort(404, 'Resource not found.');
        }

        $this->mustReach($request, $subject);

        $branchId = $request->attributes->get('tenant.branch');
        $branch = $branchId === null ? null : Location::find((int) $branchId);

        try {
            $document = $this->documents->generate(
                $validated['document_type'],
                $subject,
                $organization,
                $branch,
            );
        } catch (RuntimeException $e) {
            // The service's refusals are answers somebody can act on —
            // "publish a template first" — so they are shown rather than
            // swallowed into a generic failure.
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            PatientDocumentResource::make($document->load(['file', 'uploader', 'location'])),
            'Document ready.',
        );
    }

    /**
     * The record this document is made from.
     *
     * A closed match on the type's declared subject — never a class name from
     * the request, which is how an endpoint like this becomes a way to read
     * any table in the database.
     */
    private function subject(string $subjectType, int $id): ?Model
    {
        return match ($subjectType) {
            DocumentTypes::SUBJECT_CUSTOMER => Customer::find($id),
            DocumentTypes::SUBJECT_APPOINTMENT => Appointment::with(['customer', 'doctor', 'consultation'])->find($id),
            DocumentTypes::SUBJECT_PRESCRIPTION => Prescription::with(['customer', 'doctor', 'items'])->find($id),
            DocumentTypes::SUBJECT_SALE => PharmacySale::with(['customer', 'items', 'payments'])->find($id),
            default => null,
        };
    }

    /**
     * Refuse a record belonging to a branch this person does not work at.
     *
     * A patient is organization-wide here and has no branch of their own, so
     * only the records that carry one are checked — which is the honest
     * reading of a shared patient register rather than a gap.
     */
    private function mustReach(Request $request, Model $subject): void
    {
        $locationId = match (true) {
            $subject instanceof Appointment,
            $subject instanceof Prescription,
            $subject instanceof PharmacySale => $subject->location_id,
            default => null,
        };

        if ($locationId !== null && ! $this->branches->canUse($request->user(), (int) $locationId)) {
            abort(404, 'Resource not found.');
        }
    }
}
