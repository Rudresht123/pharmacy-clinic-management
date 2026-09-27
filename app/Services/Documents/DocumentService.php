<?php

namespace App\Services\Documents;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\File;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Documents\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Print a document, and keep it.
 *
 * The whole flow, in the order the brief sets out:
 *
 *   resolve the template  →  resolve its version  →  read the record
 *   →  render  →  store the bytes  →  record what was printed and from what
 *
 * Two things are deliberate and neither is an optimisation.
 *
 * THE VERSION IS RECORDED, NOT THE TEMPLATE ALONE. A prescription printed in
 * September must still carry September's logo when it is read back, whatever
 * the branch has changed since — so the row points at the exact version, and
 * that version is immutable once published.
 *
 * THERE IS NO REGENERATE. A generated PDF is a medical record the moment it
 * exists; somebody may already be holding it. If one is wrong it is removed
 * with a reason and a new one is printed, which leaves both in the audit trail
 * rather than silently replacing a document that has left the building.
 */
class DocumentService
{
    public function __construct(
        private readonly TemplateResolver $templates,
        private readonly DocumentPayload $payloads,
        private readonly DocumentHtml $html,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Render one document and file it against the patient.
     *
     * @throws RuntimeException when this organization has no usable template
     */
    public function generate(
        string $documentType,
        Model $subject,
        Organization $organization,
        ?Location $branch,
    ): PatientDocument {
        $type = DocumentTypes::find($documentType);

        if ($type === null) {
            throw new RuntimeException('There is no such document type.');
        }

        $resolved = $this->templates->resolve($documentType, $branch?->getKey());

        if ($resolved === null) {
            throw new RuntimeException(
                'No active template for this document. Publish one before printing.',
            );
        }

        $version = $resolved['version'];
        $config = $version->config ?? [];

        $payload = $this->payloads->build($documentType, $subject, $branch, $organization);

        [$customerId, $appointmentId] = $this->subjectKeys($subject);

        /*
         * A walk-in sale has no patient record, so there is no file to put
         * the bill in — `patient_documents` hangs off a customer by
         * definition, and that is the whole reason its access rules work.
         *
         * Refused with the thing somebody can actually do about it rather
         * than a shrug. Printing a bill for a walk-in without filing it is a
         * real want and a separate piece of work: it needs a way to hand over
         * a PDF that was never stored, which this endpoint — whose answer is
         * a stored document — cannot be.
         */
        if ($customerId === null) {
            throw new RuntimeException(
                'This record has no patient on it, so there is nowhere to file the document. '
                .'Add the customer to it first.',
            );
        }

        $number = $this->numberFor($subject);
        $payload['values']['document_number'] = $number ?? '';

        $bytes = $this->pdf->render(
            $this->html->render($documentType, $config, $payload, $this->logo($config, $branch, $organization)),
            $config['layout'] ?? [],
        );

        $name = $this->filename($type['name'], $number);

        /*
         * The PRIVATE disk, namespaced the same way uploads are. A generated
         * prescription is exactly as medical as a scanned one, so it goes
         * through the same door and leaves by the same authorised endpoint.
         */
        $path = "patient-documents/{$organization->getKey()}/{$customerId}/"
            .bin2hex(random_bytes(16)).'.pdf';

        Storage::disk('local')->put($path, $bytes);

        try {
            return DB::connection(TenantConnectionService::CONNECTION)->transaction(
                function () use ($path, $name, $bytes, $customerId, $appointmentId, $branch, $type, $documentType, $resolved, $version, $number) {
                    $file = File::create([
                        'file_name' => $name,
                        'file_path' => $path,
                        'disk' => 'local',
                        'mime_type' => 'application/pdf',
                        'file_size' => strlen($bytes),
                        'extension' => 'pdf',
                    ]);

                    return PatientDocument::create([
                        'customer_id' => $customerId,
                        'appointment_id' => $appointmentId,
                        'file_id' => $file->getKey(),
                        'location_id' => $branch?->getKey(),
                        // The TYPE decides the template; the CATEGORY decides
                        // who may open it afterwards. A generated prescription
                        // is no less medical for having been produced here.
                        'category' => DocumentTypes::categoryFor($documentType),
                        'source' => PatientDocument::GENERATED,
                        'document_template_id' => $resolved['template']->getKey(),
                        'document_template_version_id' => $version->getKey(),
                        'title' => $number ? "{$type['name']} {$number}" : $type['name'],
                        'document_number' => $number,
                        'uploaded_by' => Auth::guard('web')->id(),
                    ]);
                },
            );
        } catch (\Throwable $e) {
            // The bytes are already on disk; a failed insert would otherwise
            // leave a file nothing points at.
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * A preview — rendered, never stored, never filed against anybody.
     *
     * Takes a config rather than a saved template, so somebody editing can see
     * what they are typing before it exists. Sample values, not a real
     * patient: a preview of a medical document that carried somebody's real
     * name would be the one thing it must never be.
     *
     * @param  array<string, mixed>  $config
     * @param  array{values: array<string, string>, tables: array<string, mixed>}  $payload
     */
    public function preview(
        string $documentType,
        array $config,
        array $payload,
        ?Location $branch,
        ?Organization $organization = null,
    ): string {
        return $this->pdf->render(
            $this->html->render(
                $documentType,
                $config,
                $payload,
                $this->logo($config, $branch, $organization),
            ),
            $config['layout'] ?? [],
        );
    }

    /**
     * The patient and the visit a document hangs off.
     *
     * Derived from the record, never taken from the request — a document filed
     * against a patient id the caller chose would be a document on the wrong
     * person's file.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function subjectKeys(Model $subject): array
    {
        return match (true) {
            $subject instanceof Customer => [$subject->getKey(), null],
            $subject instanceof Appointment => [$subject->customer_id, $subject->getKey()],
            $subject instanceof Prescription => [$subject->customer_id, $subject->appointment_id],
            $subject instanceof PharmacySale => [$subject->customer_id, null],
            /*
             * A clinic invoice: the patient is direct, and the visit hangs
             * off `appointment_id` when the invoice came from a
             * consultation-trigger (a manual invoice has no visit and lands
             * against the patient alone).
             */
            $subject instanceof Invoice => [$subject->customer_id, $subject->appointment_id],
            default => [null, null],
        };
    }

    /**
     * The number printed on it.
     *
     * The RECORD'S OWN number where it has one — RX-00412, the sale number —
     * because that is what somebody holding the paper will search for. No new
     * numbering scheme is invented for documents that have none; a
     * registration form is found by the patient, not by a number nobody quotes.
     */
    private function numberFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Prescription => $subject->prescription_number,
            $subject instanceof PharmacySale => $subject->sale_number,
            $subject instanceof Invoice => $subject->invoice_number,
            default => null,
        };
    }

    /** "Prescription RX-00412.pdf" — what a download should be called. */
    private function filename(string $typeName, ?string $number): string
    {
        $stem = $number ? "{$typeName} {$number}" : $typeName;

        return preg_replace('/[^A-Za-z0-9 _.-]/', '', $stem).'.pdf';
    }

    /**
     * The letterhead mark, as a data URI.
     *
     * Inlined rather than linked because the renderer has remote content
     * switched off — a template that could make the server fetch a URL is a
     * request made on somebody else's behalf.
     *
     * Falls back from the branch's own logo to the organization's, which is
     * the same inheritance the templates use. Null where neither exists or the
     * file has gone; the header then simply renders without one.
     */
    private function logo(array $config, ?Location $branch, ?Organization $organization): ?string
    {
        $source = $config['header']['logo_source'] ?? 'branch';

        if ($source === 'none' || ! ($config['header']['show_logo'] ?? false)) {
            return null;
        }

        $file = $source === 'branch' ? $branch?->logo : null;

        if ($file) {
            return $this->dataUri($file->disk ?: 'public', (string) $file->file_path, (string) $file->mime_type);
        }

        $path = $organization?->profile_image;

        if (! $path) {
            return null;
        }

        $master = \App\Models\File::find($path);

        return $master
            ? $this->dataUri($master->disk ?: 'public', (string) $master->file_path, (string) $master->mime_type)
            : null;
    }

    private function dataUri(string $disk, string $path, string $mime): ?string
    {
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $mime = $mime !== '' ? $mime : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }
}
