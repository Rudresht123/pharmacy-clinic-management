<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\MessageTemplateVariant;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Communication\MessageVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * A branch's own wording of an email or App notification template — the
 * branch level of Global → Organisation → Branch.
 *
 * Who may write it:
 *   `communication.manage`            any branch (organisation-scoped)
 *   `communication.branch_templates`  that branch only, and only where the
 *                                     person works — checked here, because
 *                                     the route's gate asks about the acting
 *                                     branch and this one is in the URL.
 *
 * Not WhatsApp: its wording is what the provider approved under the template's
 * name, and a branch rewording it would be a message nobody approved.
 */
class MessageTemplateVariantController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** Every branch this person may see, with its own wording where it has one. */
    public function index(Request $request, MessageTemplate $template): JsonResponse
    {
        $allowed = $this->branches->allowed($this->actor());

        $variants = MessageTemplateVariant::query()
            ->where('message_template_id', $template->id)
            ->get()
            ->keyBy('location_id');

        $branches = Location::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok([
            'template_id' => $template->id,
            'rewordable' => in_array($template->channel, CommunicationChannel::REWORDABLE, true),
            'branches' => $branches->map(fn (Location $location) => [
                'location_id' => $location->id,
                'name' => $location->name,
                'variant' => $this->shape($variants->get($location->id)),
                'can_edit' => $this->mayEdit($request, $location),
            ])->values(),
        ]);
    }

    public function update(Request $request, MessageTemplate $template, Location $location): JsonResponse
    {
        $this->assertRewordable($template);
        $this->assertMayEdit($request, $location);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', ...($template->channel === CommunicationChannel::PUSH ? ['max:500'] : [])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $errors = [];

        foreach (['subject', 'content'] as $field) {
            $unknown = MessageVariables::unknownIn($data[$field] ?? null);

            if ($unknown !== []) {
                $errors[$field] = sprintf(
                    '%s cannot be filled in. Use only: %s.',
                    implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)),
                    implode(', ', array_map(fn ($name) => '{{'.$name.'}}', array_keys(MessageVariables::ALL))),
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        MessageTemplateVariant::query()->updateOrCreate(
            ['message_template_id' => $template->id, 'location_id' => $location->id],
            [
                'subject' => $data['subject'] ?? null,
                'content' => $data['content'],
                'is_active' => $request->boolean('is_active', true),
                'updated_by' => $this->actor()?->getKey(),
            ],
        );

        return $this->index($request, $template);
    }

    /** Back to the organisation's wording at this branch. */
    public function destroy(Request $request, MessageTemplate $template, Location $location): JsonResponse
    {
        $this->assertMayEdit($request, $location);

        MessageTemplateVariant::query()
            ->where('message_template_id', $template->id)
            ->where('location_id', $location->id)
            ->get()
            ->each
            ->delete();

        return $this->index($request, $template);
    }

    private function assertRewordable(MessageTemplate $template): void
    {
        if (! in_array($template->channel, CommunicationChannel::REWORDABLE, true)) {
            throw ValidationException::withMessages([
                'content' => 'A WhatsApp template is sent in the wording WhatsApp approved, so a branch cannot reword it.',
            ]);
        }
    }

    private function mayEdit(Request $request, Location $location): bool
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $this->actor();

        return $organization !== null
            && $user !== null
            && $this->branches->canUse($user, $location->id)
            && (
                $this->permission->allows($organization, $user, 'communication.manage')
                || $this->permission->allows($organization, $user, 'communication.branch_templates', $location->id)
            );
    }

    private function assertMayEdit(Request $request, Location $location): void
    {
        if (! $this->mayEdit($request, $location)) {
            abort(403, 'You may not change this branch’s wording.');
        }
    }

    /** @return array{subject: string|null, content: string, is_active: bool}|null */
    private function shape(?MessageTemplateVariant $variant): ?array
    {
        return $variant === null ? null : [
            'subject' => $variant->subject,
            'content' => $variant->content,
            'is_active' => $variant->is_active,
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
