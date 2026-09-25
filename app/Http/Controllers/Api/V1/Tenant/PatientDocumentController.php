<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StorePatientDocumentRequest;
use App\Http\Resources\Tenant\PatientDocumentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\File;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Documents\DocumentCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patient documents.
 *
 * Three questions are asked of every request here, and the route can only
 * answer the first:
 *
 *   1. CAPABILITY   the route's `permission:documents.*` middleware.
 *   2. SENSITIVITY  clinical documents need `documents.view_clinical` on top
 *                   of `documents.view`, which is per-record and so is asked
 *                   here. It narrows the QUERY, not the result — a file a
 *                   pharmacist may not open must not be counted or named to
 *                   them either.
 *   3. OWNERSHIP    a document is reached through the patient it belongs to,
 *                   so `customers/{customer}/documents` cannot return another
 *                   patient's files, and a direct download re-derives the
 *                   patient from the row.
 *
 * The bytes live on the PRIVATE disk and are streamed by `download()` after
 * all three have passed. There is deliberately no URL on the resource: a
 * public or signed link is a way around the check that does not even leave a
 * refused request behind it.
 */
class PatientDocumentController extends BaseApiController
{
    /** What a phone camera or a hospital scanner actually produces. */
    private const ALLOWED_MIMES = 'pdf,jpg,jpeg,png,webp,heic,heif,tif,tiff,doc,docx';

    private const MAX_KILOBYTES = 20480;

    public function __construct(
        private readonly Permission $permission,
    ) {}

    /** The vocabulary, so a client never hardcodes it. */
    public function categories(Request $request): JsonResponse
    {
        $clinical = $this->mayReadClinical($request);

        return $this->ok(
            array_values(array_filter(
                DocumentCategories::all(),
                fn (array $category) => $clinical
                    || $category['sensitivity'] === DocumentCategories::ADMINISTRATIVE,
            )),
        );
    }

    /** One patient's folder, newest first. */
    public function index(Request $request, Customer $customer): JsonResponse
    {
        return $this->listing(
            $request,
            PatientDocument::query()->where('customer_id', $customer->getKey()),
        );
    }

    /** One visit's attachments — what came out of this appointment. */
    public function forAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        return $this->listing(
            $request,
            PatientDocument::query()->where('appointment_id', $appointment->getKey()),
        );
    }

    public function store(StorePatientDocumentRequest $request, Customer $customer): JsonResponse
    {
        $validated = $request->validated();
        $upload = $request->file('file');
        $organization = $request->attributes->get('tenant.organization');

        /*
         * Namespaced by organization and patient, under the PRIVATE disk.
         *
         * Laravel's generated name is kept rather than the uploaded one: the
         * original is recorded on the `files` row for display, and letting a
         * client name a path is how a path gets traversed.
         */
        $stored = $upload->store(
            "patient-documents/{$organization->getKey()}/{$customer->getKey()}",
            'local',
        );

        $document = DB::connection(TenantConnectionService::CONNECTION)
            ->transaction(function () use ($validated, $upload, $stored, $customer, $request) {
                $file = File::create([
                    'file_name' => $upload->getClientOriginalName(),
                    'file_path' => $stored,
                    'disk' => 'local',
                    'mime_type' => $upload->getClientMimeType(),
                    'file_size' => $upload->getSize(),
                    'extension' => $upload->getClientOriginalExtension(),
                ]);

                return PatientDocument::create([
                    'customer_id' => $customer->getKey(),
                    'appointment_id' => $validated['appointment_id'] ?? null,
                    'file_id' => $file->getKey(),
                    'location_id' => $request->attributes->get('tenant.branch'),
                    'category' => $validated['category'],
                    // Falls back to what the file was called, which is what
                    // somebody uploading three scans in a row actually wants.
                    'title' => $validated['title'] ?? $upload->getClientOriginalName(),
                    'notes' => $validated['notes'] ?? null,
                    'uploaded_by' => $request->user()?->getKey(),
                ]);
            });

        return $this->created(
            PatientDocumentResource::make($document->load(['file', 'uploader', 'location'])),
            'Document attached',
        );
    }

    /**
     * The bytes, streamed.
     *
     * Re-asks the sensitivity question rather than trusting that the caller
     * got here from a list they were allowed to see: an id is guessable, and
     * this is the only endpoint that hands over a medical record.
     */
    public function download(Request $request, PatientDocument $document): StreamedResponse|JsonResponse
    {
        if ($document->isClinical() && ! $this->mayReadClinical($request)) {
            return $this->fail('This document is not available to you.', 403);
        }

        $file = $document->file;

        if (! $file || ! Storage::disk($file->disk ?: 'local')->exists($file->file_path)) {
            return $this->fail('The stored file is missing.', 404);
        }

        return Storage::disk($file->disk ?: 'local')->download(
            $file->file_path,
            $file->file_name,
        );
    }

    /**
     * Take it out of the record, keeping the answer to "who and why".
     *
     * Soft: a document filed against the wrong patient has to stop being
     * readable at once and stay answerable for afterwards. The stored object
     * is left in place for the same reason — purging it would destroy the
     * evidence of what was removed.
     */
    public function destroy(Request $request, PatientDocument $document): JsonResponse
    {
        if ($document->isClinical() && ! $this->mayReadClinical($request)) {
            return $this->fail('This document is not available to you.', 403);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $document->deleteWithReason($validated['reason']);

        return $this->ok(null, 'Document removed');
    }

    /** One shape for both lists, so they cannot drift apart on visibility. */
    private function listing(Request $request, Builder $query): JsonResponse
    {
        $documents = $query
            ->readableBy($this->mayReadClinical($request))
            ->when(
                in_array($request->string('category')->toString(), DocumentCategories::keys(), true),
                fn (Builder $q) => $q->where('category', $request->string('category')->toString()),
            )
            ->with(['file', 'uploader', 'location'])
            ->latest()
            ->get();

        return $this->ok(PatientDocumentResource::collection($documents));
    }

    /** Level three, asked per record rather than per route — see the header. */
    private function mayReadClinical(Request $request): bool
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User) {
            return false;
        }

        return $this->permission->allows($organization, $user, 'documents.view_clinical');
    }
}
