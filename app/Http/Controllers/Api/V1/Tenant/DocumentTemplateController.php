<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveDocumentTemplateRequest;
use App\Http\Resources\Tenant\DocumentTemplateResource;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\DocumentTemplateVersion;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\TemplateAuthority;
use App\Services\Documents\TemplateResolver;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\Placeholders;
use App\Support\Documents\TemplateConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The letterhead: what a printed document looks like, per branch.
 *
 * Every authority question goes through TemplateAuthority, which is the one
 * place the "organization administrator vs branch manager" rule is written —
 * listing, saving and publishing all ask it rather than each re-deciding what
 * the rule was.
 *
 * VERSIONS ARE NOT EDITED, THEY ARE ADDED TO. Saving writes to the template's
 * unpublished draft version, creating one if the last version is already
 * published. So V1 stays exactly as it was the day a prescription was printed
 * from it, and the edit becomes V2 — which is the whole point of the module.
 */
class DocumentTemplateController extends BaseApiController
{
    public function __construct(
        private readonly TemplateAuthority $authority,
        private readonly TemplateResolver $resolver,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The catalogue: what this organization can print, and what it needs.
     *
     * Types whose module is not running here are absent — a template editor
     * offering "Lab report" in a system with no lab module would be offering
     * a document that can never be generated.
     */
    public function types(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User) {
            return $this->ok([]);
        }

        $branch = $request->attributes->get('tenant.branch');
        $modules = app(Permission::class)
            ->modulesAt($organization, $branch === null ? null : (int) $branch);

        return $this->ok(array_map(
            fn (array $type) => [
                ...$type,
                'placeholders' => Placeholders::forType($type['key']),
                'defaults' => TemplateConfig::defaultsFor($type['key']),
            ],
            DocumentTypes::available($modules),
        ));
    }

    /** The paths an organization may lock, for the locks panel. */
    public function lockable(): JsonResponse
    {
        return $this->ok(TemplateConfig::lockablePaths());
    }

    /**
     * Templates for one place.
     *
     * `location_id` in the query says which branch; absent means the caller's
     * own. An organization administrator may ask about any branch; anybody
     * else is held to theirs — and the answer always includes the
     * organization's defaults, because that is what they actually print with
     * where they have not customised.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User) {
            return $this->ok([]);
        }

        $asked = $request->query('location_id');
        $branch = $asked !== null && $asked !== ''
            ? (int) $asked
            : ($request->attributes->get('tenant.branch') === null
                ? null
                : (int) $request->attributes->get('tenant.branch'));

        if (! $this->authority->mayRead($organization, $user, $branch)) {
            abort(403, 'You cannot read templates for that branch.');
        }

        $templates = DocumentTemplate::query()
            ->with(['location', 'activeVersion'])
            ->forBranch($branch)
            ->when(
                DocumentTypes::supports((string) $request->query('document_type')),
                fn (Builder $query) => $query->where('document_type', $request->query('document_type')),
            )
            ->orderBy('document_type')
            ->orderByRaw('location_id IS NULL DESC')
            ->get();

        return $this->ok(DocumentTemplateResource::collection($templates));
    }

    public function show(Request $request, DocumentTemplate $template): JsonResponse
    {
        $this->mustRead($request, $template);

        $template->load(['location', 'activeVersion', 'versions.publisher']);

        $draft = $this->draftVersion($template, false);

        return $this->ok([
            'template' => DocumentTemplateResource::make($template),

            /*
             * What the editor opens on: the draft if one exists, otherwise the
             * active version, otherwise the type's defaults. Never null —
             * a template screen that opens on nothing is a screen somebody has
             * to guess at.
             */
            'config' => $draft?->config
                ?? $template->activeVersion?->config
                ?? TemplateConfig::defaultsFor((string) $template->document_type),

            'has_unpublished_changes' => $draft !== null,

            /* What this branch may not change. Empty for the organization's
               own default, and for an organization administrator. */
            'locked_fields' => $this->locksFor($request, $template),

            'placeholders' => Placeholders::forType((string) $template->document_type),
        ]);
    }

    public function store(SaveDocumentTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = (string) $data['document_type'];
        $location = $data['location_id'] ?? null;

        $existing = DocumentTemplate::query()
            ->where('document_type', $type)
            ->where('location_id', $location)
            ->first();

        if ($existing) {
            return $this->fail(
                $location === null
                    ? 'Your organisation already has a default for this document.'
                    : 'This branch already has a template for this document.',
                409,
            );
        }

        $template = DB::connection(TenantConnectionService::CONNECTION)->transaction(
            function () use ($data, $type, $location) {
                $template = DocumentTemplate::create([
                    'location_id' => $location,
                    'document_type' => $type,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'status' => DocumentTemplate::DRAFT,
                ]);

                $template->forceFill(['created_by' => Auth::guard('web')->id()])->saveQuietly();

                DocumentTemplateVersion::create([
                    'document_template_id' => $template->getKey(),
                    'version' => 1,
                    'config' => TemplateConfig::sanitise((array) $data['config'], $type),
                ]);

                return $template;
            },
        );

        return $this->created(
            DocumentTemplateResource::make($template->load(['location', 'versions'])),
            'Template created. Publish it when you are ready to print with it.',
        );
    }

    /**
     * Save the draft.
     *
     * Writes to the unpublished version, creating one when the last is
     * published. Never touches a published version: it is what somebody's
     * printed document was made from.
     */
    public function update(SaveDocumentTemplateRequest $request, DocumentTemplate $template): JsonResponse
    {
        $data = $request->validated();
        $config = TemplateConfig::sanitise((array) $data['config'], (string) $template->document_type);

        DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($template, $data, $config) {
            $template->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $template->forceFill(['updated_by' => Auth::guard('web')->id()])->saveQuietly();

            $draft = $this->draftVersion($template, true);

            $draft->update(['config' => $config]);
        });

        return $this->ok(
            DocumentTemplateResource::make($template->fresh()->load(['location', 'activeVersion', 'versions'])),
            'Draft saved.',
        );
    }

    /**
     * Put the draft into use.
     *
     * The moment that matters: from here on, every document of this type
     * printed at this branch is made from this version, and every document
     * already printed keeps pointing at the one before it.
     */
    public function publish(Request $request, DocumentTemplate $template): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User
            || ! $this->authority->mayPublish($organization, $user, $template->location_id)) {
            abort(403, 'You cannot publish templates for that branch.');
        }

        $draft = $this->draftVersion($template, false);

        if (! $draft) {
            return $this->fail('There is nothing unpublished to put into use.', 422);
        }

        /*
         * Re-checked at publish, not only at save. A template saved before a
         * release renamed a placeholder would otherwise go live printing
         * blanks — and publishing is the last moment anybody looks.
         */
        $unknown = Placeholders::unknownIn($draft->config ?? [], (string) $template->document_type);

        if ($unknown !== []) {
            return $this->fail(
                'This template uses placeholders this document cannot fill: '
                .implode(', ', array_map(fn ($token) => '{{'.$token.'}}', $unknown)),
                422,
            );
        }

        DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($template, $draft) {
            $draft->forceFill([
                'published_at' => now(),
                'published_by' => Auth::guard('web')->id(),
            ])->save();

            $template->forceFill([
                'active_version_id' => $draft->getKey(),
                'status' => DocumentTemplate::ACTIVE,
                'updated_by' => Auth::guard('web')->id(),
            ])->save();
        });

        return $this->ok(
            DocumentTemplateResource::make($template->fresh()->load(['location', 'activeVersion', 'versions'])),
            "Version {$draft->version} is now in use.",
        );
    }

    /**
     * Which paths a branch may not change.
     *
     * Organization-wide authority only, and written on the ORGANIZATION
     * DEFAULT's version — a branch that could set its own locks could unlock
     * anything.
     */
    public function lock(Request $request, DocumentTemplate $template): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User
            || ! $this->authority->isOrganizationAdmin($organization, $user)) {
            abort(403, 'Only an organisation administrator can lock template fields.');
        }

        if (! $template->isOrganizationDefault()) {
            return $this->fail(
                'Locks are set on the organisation’s default, not on one branch’s copy.',
                422,
            );
        }

        $validated = $request->validate([
            'locked_fields' => ['present', 'array'],
            'locked_fields.*' => ['string', Rule::in(TemplateConfig::lockableKeys())],
        ]);

        $version = $template->activeVersion ?? $this->draftVersion($template, true);

        $version->forceFill(['locked_fields' => array_values(array_unique($validated['locked_fields']))])->save();

        return $this->ok(
            DocumentTemplateResource::make($template->fresh()->load(['location', 'activeVersion', 'versions'])),
            'Locks updated.',
        );
    }

    /**
     * Reset a branch back to the organization's default.
     *
     * Removes the branch's override entirely rather than copying the default
     * into it — so the branch goes back to INHERITING, and a later change to
     * the organization default reaches it. Copying would freeze it at today's
     * default, which is not what "reset to default" means to anybody.
     *
     * Soft, with a reason: documents already printed point at this template's
     * versions, and those have to stay readable.
     */
    public function destroy(Request $request, DocumentTemplate $template): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User
            || ! $this->authority->mayWrite($organization, $user, $template->location_id)) {
            abort(403, 'You cannot remove that template.');
        }

        if ($template->isOrganizationDefault()) {
            return $this->fail(
                'The organisation’s default cannot be removed — every branch that has not customised prints from it.',
                422,
            );
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $template->deleteWithReason($validated['reason']);

        return $this->ok(null, 'This branch now prints from the organisation’s default.');
    }

    /**
     * A PDF of what is on screen — rendered, never stored.
     *
     * Takes the config from the REQUEST rather than the record, so somebody
     * editing sees what they are typing. Sample values, never a real patient:
     * a preview of a medical document carrying somebody's actual name would be
     * the one thing it must not be.
     */
    public function preview(Request $request, DocumentService $documents): Response|JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(DocumentTypes::keys())],
            'config' => ['required', 'array'],
            'location_id' => ['nullable', 'integer', Rule::exists(Location::class, 'id')],
        ]);

        $type = (string) $validated['document_type'];
        $organization = $request->attributes->get('tenant.organization');

        $branchId = $validated['location_id'] ?? $request->attributes->get('tenant.branch');

        // A branch id in a preview is still a branch id: it decides which
        // logo is fetched, so it is checked like any other.
        if ($branchId !== null && ! $this->branches->canUse($request->user(), (int) $branchId)) {
            abort(403, 'You do not work at that branch.');
        }

        $samples = [...Placeholders::samplesFor($type), ...$this->sampleSettlement($type)];

        $pdf = $documents->preview(
            $type,
            TemplateConfig::sanitise((array) $validated['config'], $type),
            ['values' => $samples, 'tables' => $this->sampleTables($type)],
            $branchId ? Location::find($branchId) : null,
            $organization,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
        ]);
    }

    /**
     * How the sample bill was paid.
     *
     * NOT PLACEHOLDERS, which is why these are not in Placeholders. Nothing in
     * a template names the payment mode or the PAID stamp — the layout draws
     * them from the record whenever a bill has been settled, so there is no
     * token for somebody to insert and therefore no sample value either.
     *
     * Without them the preview of an invoice showed a letterhead, a patient
     * and a set of charges, and then stopped — no stamp, no payment panel, no
     * signature boxes. That is half the sheet missing from the picture
     * somebody is editing against.
     *
     * @return array<string, string>
     */
    private function sampleSettlement(string $type): array
    {
        if (! in_array($type, ['clinic_invoice', 'pharmacy_invoice', 'payment_receipt'], true)) {
            return [];
        }

        return [
            'payment_method' => 'UPI',
            'payment_reference' => 'UPI1234567890',
            'payment_date' => '24 Sep 2026, 4:16 PM',
            'payment_state' => 'PAID',
        ];
    }

    /**
     * Obviously-fake rows, so a preview shows the shape without pretending.
     *
     * @return array<string, array{columns: list<string>, rows: list<list<string>>}>
     */
    private function sampleTables(string $type): array
    {
        return match ($type) {
            'prescription' => ['prescription_items' => [
                'columns' => ['Medicine', 'Dose', 'Frequency', 'Duration', 'Notes'],
                'rows' => [
                    ['Amoxicillin 500mg', '1-0-1', 'Twice daily', '5 days', 'After food'],
                    ['Paracetamol 650mg', '1-1-1', 'As needed', '3 days', 'If fever'],
                ],
            ]],
            /*
             * THE CONSOLIDATED BILL, grouped the way a real one is.
             *
             * It was missing entirely, so the preview of the one document the
             * whole billing module exists to produce showed a letterhead, a
             * patient panel and a set of totals with no charges between them.
             * The shape here mirrors DocumentPayload::fromInvoice() — same
             * columns, same category bands — because a preview that does not
             * exercise the grouped table is a preview that cannot show whether
             * the grouped table prints.
             */
            'clinic_invoice' => ['invoice_items' => [
                'columns' => ['#', 'Service / item', 'HSN/SAC', 'Qty', 'Rate', 'Tax', 'Amount'],
                'rows' => [],
                'groups' => [
                    ['title' => 'Registration', 'rows' => [
                        ['1', 'Registration fee', '999316', '1', '100.00', '0%', '₹100.00'],
                    ]],
                    ['title' => 'Consultation', 'rows' => [
                        ['2', 'Consultation — General Medicine', '999312', '1', '500.00', '0%', '₹500.00'],
                        ['3', 'Injection', '999319', '1', '100.00', '0%', '₹100.00'],
                    ]],
                    ['title' => 'Laboratory', 'rows' => [
                        ['4', 'Complete Blood Count (CBC)', '998346', '1', '250.00', '0%', '₹250.00'],
                    ]],
                    ['title' => 'Pharmacy', 'rows' => [
                        ['5', 'Emeset 4 mg (Tablet)', '300490', '20', '8.50', '5%', '₹170.00'],
                        ['6', 'Dispo Van 5 ml (Syrup)', '300490', '33', '12.00', '5%', '₹396.00'],
                    ]],
                ],
            ]],
            'pharmacy_invoice', 'payment_receipt' => ['invoice_items' => [
                'columns' => ['Item', 'Batch', 'Qty', 'Rate', 'Amount'],
                'rows' => [
                    ['Amoxicillin 500mg', 'B2231', '10', '₹9.20', '₹92.00'],
                    ['Paracetamol 650mg', 'B1187', '15', '₹2.07', '₹31.05'],
                ],
            ]],
            'document_cover' => ['document_list' => [
                'columns' => ['Document', 'Kind', 'Added'],
                'rows' => [
                    ['Star Health card', 'Insurance', '12 Aug 2026'],
                    ['Lab report', 'Lab report', '02 Sep 2026'],
                ],
            ]],
            default => [],
        };
    }

    /**
     * The version being edited.
     *
     * The newest UNPUBLISHED one. `$create` makes a fresh version when the
     * newest is published — which is what turns an edit after publishing into
     * V2 rather than a rewrite of V1.
     */
    private function draftVersion(DocumentTemplate $template, bool $create): ?DocumentTemplateVersion
    {
        $newest = $template->versions()->first();

        if ($newest && ! $newest->isPublished()) {
            return $newest;
        }

        if (! $create) {
            return null;
        }

        return DocumentTemplateVersion::create([
            'document_template_id' => $template->getKey(),
            'version' => $template->nextVersionNumber(),
            'config' => $newest?->config ?? TemplateConfig::defaultsFor((string) $template->document_type),
            'locked_fields' => $newest?->locked_fields,
        ]);
    }

    /** @return list<string> */
    private function locksFor(Request $request, DocumentTemplate $template): array
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User
            || $template->isOrganizationDefault()
            || $this->authority->isOrganizationAdmin($organization, $user)) {
            return [];
        }

        return $this->resolver->locksFor((string) $template->document_type);
    }

    private function mustRead(Request $request, DocumentTemplate $template): void
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User
            || ! $this->authority->mayRead($organization, $user, $template->location_id)) {
            /*
             * 404 rather than 403 for another branch's, matching how a staff
             * record out of reach behaves: 403 would confirm the id exists to
             * somebody who may not see it.
             */
            abort(404, 'Resource not found.');
        }
    }
}
