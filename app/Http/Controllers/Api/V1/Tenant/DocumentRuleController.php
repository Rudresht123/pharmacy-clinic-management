<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\DocumentRule;
use App\Models\Tenant\Location;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Clinic\ClinicEvents;
use App\Support\Documents\DocumentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which documents the clinic wants made on its own — Settings › Automatic
 * documents.
 *
 * WHAT IS OFFERED IS DECIDED HERE, not by the screen. An event is offered only
 * where its module runs, and a document only where the event carries its
 * record and the document's own modules run: a receipt on a payment, never a
 * prescription on one. The screen draws exactly this list, and saving checks
 * against the same list, so a rule that could never fire cannot be made.
 *
 * SAVED ONE SCOPE AT A TIME — every branch, or one branch — and the whole set
 * for that scope at once. A screen of ticks saved as a set is what the person
 * is looking at; saving rule by rule would leave half a change in place when
 * the third request of five failed.
 *
 * `documents.template_org` for both reading and saving: a rule decides what
 * every branch's counter prints, which is the organisation administrator's
 * call, the same as its letterhead.
 */
class DocumentRuleController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->overview($request));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['present', 'nullable', 'integer', Rule::exists(Location::class, 'id')],
            'rules' => ['present', 'array'],
            'rules.*.event_key' => ['required', 'string'],
            'rules.*.document_type' => ['required', 'string'],
        ]);

        $offered = $this->offered($request);
        $wanted = [];

        foreach ($validated['rules'] as $index => $rule) {
            $documents = $offered[$rule['event_key']] ?? null;

            if ($documents === null || ! in_array($rule['document_type'], $documents, true)) {
                throw ValidationException::withMessages([
                    "rules.{$index}.document_type" => 'That document cannot be made on that event here.',
                ]);
            }

            // A tick sent twice is one rule.
            $wanted[$rule['event_key'].'|'.$rule['document_type']] = $rule;
        }

        $locationId = $validated['location_id'];

        DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($locationId, $wanted) {
            $existing = DocumentRule::query()
                ->when(
                    $locationId === null,
                    fn ($query) => $query->whereNull('location_id'),
                    fn ($query) => $query->where('location_id', $locationId),
                )
                ->get()
                ->keyBy(fn (DocumentRule $rule) => $rule->event_key.'|'.$rule->document_type);

            // Deleted one by one rather than in bulk, so each removal is in the
            // history with who took it away.
            foreach ($existing as $key => $rule) {
                if (! array_key_exists($key, $wanted)) {
                    $rule->delete();
                }
            }

            foreach ($wanted as $key => $rule) {
                if (! $existing->has($key)) {
                    DocumentRule::create([
                        'event_key' => $rule['event_key'],
                        'document_type' => $rule['document_type'],
                        'location_id' => $locationId,
                        'created_by' => Auth::guard('web')->id(),
                    ]);
                }
            }
        });

        return $this->ok($this->overview($request), 'Automatic documents saved');
    }

    /**
     * Everything the screen draws: what may be automated, where, and what is.
     *
     * @return array<string, mixed>
     */
    private function overview(Request $request): array
    {
        $offered = $this->offered($request);

        return [
            'events' => array_values(array_map(
                fn (string $key) => [
                    'key' => $key,
                    'label' => ClinicEvents::label($key),
                    'module' => ClinicEvents::module($key),
                    'documents' => array_map(
                        fn (string $type) => [
                            'key' => $type,
                            'name' => DocumentTypes::find($type)['name'] ?? $type,
                            'description' => DocumentTypes::find($type)['description'] ?? '',
                        ],
                        $offered[$key],
                    ),
                ],
                array_keys($offered),
            )),
            'branches' => Location::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Location $location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'code' => $location->code,
                ])
                ->all(),
            'rules' => DocumentRule::query()
                ->orderBy('id')
                ->get()
                ->map(fn (DocumentRule $rule) => [
                    'id' => $rule->id,
                    'event_key' => $rule->event_key,
                    'document_type' => $rule->document_type,
                    'location_id' => $rule->location_id,
                ])
                ->all(),
        ];
    }

    /**
     * Event key → the document types a rule on it may make, for this
     * organisation.
     *
     * Organisation-wide modules, not one branch's: a rule for every branch is
     * saved once, and a branch that has switched a module off is skipped when
     * the rule fires rather than refused here.
     *
     * @return array<string, list<string>>
     */
    private function offered(Request $request): array
    {
        $organization = $request->attributes->get('tenant.organization');
        $modules = $organization ? $this->permissions->modulesAt($organization, null) : [];
        $available = array_column(DocumentTypes::available($modules), 'key');

        $offered = [];

        foreach (ClinicEvents::keys() as $key) {
            if (! in_array(ClinicEvents::module($key), $modules, true)) {
                continue;
            }

            $documents = array_values(array_intersect(ClinicEvents::documentsFor($key), $available));

            if ($documents !== []) {
                $offered[$key] = $documents;
            }
        }

        return $offered;
    }
}
