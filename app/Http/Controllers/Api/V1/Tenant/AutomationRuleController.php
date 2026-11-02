<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\UpdateAutomationRuleRequest;
use App\Http\Resources\Tenant\AutomationRuleResource;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Communication\RuleRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * When a message goes out without anybody asking — and who it tells.
 *
 * Only updating: the rules are the clinic events the application knows how to
 * react to, so they are seeded rather than invented. A clinic chooses whether
 * each one sends, what it sends, how far ahead, and to whom on To, CC and BCC
 * — not what the events are.
 *
 * @group Tenant > Communications & Automation
 */
class AutomationRuleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $channel = $request->string('channel')->toString();

        $query = AutomationRule::query()->with('template');

        if ($channel !== '') {
            $query->forChannel($channel);
        }

        return $this->ok(AutomationRuleResource::collection($query->get()));
    }

    /**
     * What a rule can name: the places on a record, the roles, the staff.
     *
     * Its own list rather than the People and Roles screens', because those
     * are `users.view` and whoever sets up messages may not hold it. Names
     * and whether there is an address — nothing else about anybody, and no
     * patient ever.
     *
     * Each role name once. Every branch keeps its own "Receptionist", and a
     * rule naming the role means whoever holds it at the visit's branch
     * (RecipientResolver::holding), so the list offers the job, not each copy.
     */
    public function recipients(): JsonResponse
    {
        $roles = Role::query()->orderBy('name')->orderBy('id')->get()
            ->reject(fn (Role $role) => $role->isForPatients())
            ->unique('name')
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->values();

        $staff = User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::OWNER, User::STAFF])
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'has_email' => filled($user->email)]);

        return $this->ok([
            'places' => collect(RuleRecipients::PLACES)
                ->map(fn (string $label, string $type) => ['type' => $type, 'label' => $label])
                ->values(),
            'roles' => $roles,
            'staff' => $staff,
        ]);
    }

    public function update(UpdateAutomationRuleRequest $request, AutomationRule $rule): JsonResponse
    {
        $validated = collect($request->validated())->except('recipients')->all();

        if (($recipients = $request->recipients()) !== null) {
            $validated['recipients'] = $recipients;
        }

        /*
         * A rule cannot be switched on without something to send. The database
         * allows it — choosing the template afterwards is a reasonable order
         * to work in — but switching one on and having it silently send
         * nothing at 8am is not something to discover from the log.
         */
        $template = array_key_exists('message_template_id', $validated)
            ? $validated['message_template_id']
            : $rule->message_template_id;

        if (($validated['is_enabled'] ?? $rule->is_enabled) && $template === null) {
            return $this->respond(null, 'Choose the template this rule sends before switching it on.', [], 422);
        }

        if ($template !== null && ($validated['is_enabled'] ?? $rule->is_enabled)) {
            $sendable = MessageTemplate::query()->sendable()->whereKey($template)->exists();

            if (! $sendable) {
                return $this->respond(
                    null,
                    'That template is not approved yet, so this rule cannot send it.',
                    [],
                    422,
                );
            }
        }

        $rule->fill($validated);
        $rule->updated_by = Auth::guard('web')->id();
        $rule->save();

        return $this->ok(
            AutomationRuleResource::make($rule->load('template')),
            $rule->is_enabled ? 'Rule switched on' : 'Rule switched off',
        );
    }
}
