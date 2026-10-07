<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\GlobalNotificationTemplate;
use App\Models\Tenant\Location;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Services\Notifications\NotificationTemplateResolver;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The wording of push and in-app notifications — Global → Organisation →
 * Branch.
 *
 * Inside the communication module, as everything about what patients are told
 * is. Two levels are edited here:
 *
 *   organisation  `communication.manage` (organisation-scoped) — what every
 *                 branch says unless it says otherwise
 *   branch        `communication.branch_templates` AT THAT BRANCH, or the
 *                 organisation capability, which reaches every branch
 *
 * The global level is the platform's (super admin), and only shown here.
 *
 * VALIDATED ON SAVE: a template may use only the variables its type declares
 * (NotificationTypes). A typo is refused with the name of the variable, rather
 * than reaching a patient as literal braces.
 */
class NotificationTemplateController extends BaseApiController
{
    public function __construct(
        private readonly NotificationTemplateResolver $resolver,
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * Every type, each level's version, and what is actually sent — at the
     * branch named by `branch_id`, or organisation-wide.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($branchId !== null && ! $this->branches->canUse($this->actor(), $branchId)) {
            abort(403, 'You do not work at that branch.');
        }

        $own = NotificationTemplate::query()
            ->whereNull('location_id')
            ->when($branchId !== null, fn ($query) => $query->orWhere('location_id', $branchId))
            ->get();

        $global = GlobalNotificationTemplate::query()->get()->keyBy('template_key');

        $types = [];

        foreach (NotificationTypes::all() as $key => $type) {
            $organization = $own->first(fn ($row) => $row->template_key === $key && $row->location_id === null);
            $branch = $branchId === null
                ? null
                : $own->first(fn ($row) => $row->template_key === $key && $row->location_id === $branchId);

            $types[] = [
                'key' => $key,
                'label' => $type['label'],
                'audience' => $type['audience'],
                'variables' => collect($type['variables'])
                    ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
                    ->values(),
                'effective' => $this->resolver->resolve($key, $branchId),
                'global' => $this->level($global->get($key)),
                'organization' => $this->level($organization),
                'branch' => $this->level($branch),
                'default' => ['title' => $type['title'], 'body' => $type['body']],
            ];
        }

        return $this->ok(['branch_id' => $branchId, 'types' => $types]);
    }

    /** The organisation's own version. */
    public function updateOrganization(Request $request, string $key): JsonResponse
    {
        $data = $this->validated($request, $key);

        NotificationTemplate::query()->updateOrCreate(
            ['template_key' => $key, 'location_id' => null],
            $data + ['updated_by' => $this->actor()?->getKey(), 'created_by' => $this->actor()?->getKey()],
        );

        return $this->index($request);
    }

    /** Back to the global wording. */
    public function destroyOrganization(Request $request, string $key): JsonResponse
    {
        $this->assertType($key);

        NotificationTemplate::query()
            ->where('template_key', $key)
            ->whereNull('location_id')
            ->get()
            ->each
            ->delete();

        return $this->index($request);
    }

    /** One branch's own version. */
    public function updateBranch(Request $request, string $key, Location $location): JsonResponse
    {
        $this->assertMayEditBranch($request, $location);

        $data = $this->validated($request, $key);

        NotificationTemplate::query()->updateOrCreate(
            ['template_key' => $key, 'location_id' => $location->id],
            $data + ['updated_by' => $this->actor()?->getKey(), 'created_by' => $this->actor()?->getKey()],
        );

        $request->merge(['branch_id' => $location->id]);

        return $this->index($request);
    }

    /** Back to the organisation's wording, at this branch. */
    public function destroyBranch(Request $request, string $key, Location $location): JsonResponse
    {
        $this->assertType($key);
        $this->assertMayEditBranch($request, $location);

        NotificationTemplate::query()
            ->where('template_key', $key)
            ->where('location_id', $location->id)
            ->get()
            ->each
            ->delete();

        $request->merge(['branch_id' => $location->id]);

        return $this->index($request);
    }

    /**
     * @return array{title: string, body: string, is_active: bool}
     */
    private function validated(Request $request, string $key): array
    {
        $this->assertType($key);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $errors = [];
        $allowed = implode(', ', array_map(fn ($name) => '{{'.$name.'}}', NotificationTypes::variables($key)));

        foreach (['title', 'body'] as $field) {
            $unknown = NotificationTypes::unknownIn($key, $data[$field]);

            if ($unknown !== []) {
                $errors[$field] = sprintf(
                    '%s cannot be filled in this notification. Use only %s.',
                    implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)),
                    $allowed,
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function assertType(string $key): void
    {
        if (! NotificationTypes::has($key)) {
            abort(404, 'There is no such notification.');
        }
    }

    /**
     * A branch's wording is that branch's to change — or head office's, which
     * reaches every branch. Working there is checked as well as the
     * capability, so a role cannot reach a branch its holder does not work at.
     */
    private function assertMayEditBranch(Request $request, Location $location): void
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $this->actor();

        $may = $organization !== null && $user !== null && $this->branches->canUse($user, $location->id) && (
            $this->permission->allows($organization, $user, 'communication.manage')
            || $this->permission->allows($organization, $user, 'communication.branch_templates', $location->id)
        );

        if (! $may) {
            abort(403, 'You may not change this branch’s notifications.');
        }
    }

    /** @return array{title: string, body: string, is_active: bool}|null */
    private function level(mixed $row): ?array
    {
        return $row === null ? null : [
            'title' => $row->title,
            'body' => $row->body,
            'is_active' => (bool) $row->is_active,
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
