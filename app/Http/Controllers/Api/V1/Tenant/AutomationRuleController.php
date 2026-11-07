<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Controllers\Api\V1\Tenant\Concerns\AsksAboutChannels;
use App\Http\Requests\Api\V1\Tenant\UpdateAutomationRuleRequest;
use App\Http\Resources\Tenant\AutomationRuleResource;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\AutomationRuleBranchRecipients;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Communication\RuleRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
    use AsksAboutChannels;

    public function index(Request $request): JsonResponse
    {
        $channel = $request->string('channel')->toString();

        $query = $this->withOwnBranchChoice(
            AutomationRule::query()->with('template')->whereIn('channel', $this->readableChannels($request)),
            $request,
        );

        if ($channel !== '') {
            $query->forChannel($channel);
        }

        // In the same order each channel's own screen shows them.
        return $this->ok(AutomationRuleResource::collection($query->orderBy('sort_order')->orderBy('id')->get()));
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
     *
     * Somebody working at one branch chooses from that branch's own staff.
     */
    public function recipients(Request $request): JsonResponse
    {
        $roles = Role::query()->orderBy('name')->orderBy('id')->get()
            ->reject(fn (Role $role) => $role->isForPatients())
            ->unique('name')
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->values();

        $branch = $this->ownBranch($request);

        $staff = User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::OWNER, User::STAFF])
            ->when($branch !== null, fn ($query) => $query->whereIn(
                'id',
                BranchMembership::query()->where('location_id', $branch)->select('user_id'),
            ))
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

    /**
     * Who gets one message: all the Recipients screen and a template's form write.
     *
     * A route of its own so those ticks reach this and nothing else about the rule,
     * like its template or its switch.
     *
     * The rule is the organisation's. Somebody working at one branch saves that
     * branch's own choice beside it, so no other branch's messages change.
     */
    public function saveRecipients(UpdateAutomationRuleRequest $request, AutomationRule $rule): JsonResponse
    {
        $recipients = $request->recipients();

        if ($recipients === null) {
            return $this->respond(null, 'Say who this message goes to.', [], 422);
        }

        $branch = $this->ownBranch($request);

        if ($branch !== null) {
            $this->refuseStaffFromElsewhere($recipients, $branch, $rule);

            AutomationRuleBranchRecipients::query()->updateOrCreate(
                ['automation_rule_id' => $rule->getKey(), 'location_id' => $branch],
                ['recipients' => $recipients, 'updated_by' => Auth::guard('web')->id()],
            );

            return $this->ok($this->asTheyStand($rule, $branch), 'Saved');
        }

        $rule->recipients = $recipients;
        $rule->updated_by = Auth::guard('web')->id();
        $rule->save();

        return $this->ok(AutomationRuleResource::make($rule->load('template')), 'Saved');
    }

    /**
     * A branch chooses from its own staff. Somebody already on the message, who this
     * branch did not add, is not this save's business: taking them off must stay possible.
     *
     * @param  array<string, list<array<string, mixed>>>  $recipients
     */
    private function refuseStaffFromElsewhere(array $recipients, int $branch, AutomationRule $rule): void
    {
        $already = collect(RuleRecipients::of($rule, $rule->branchRecipients()->where('location_id', $branch)->first()?->recipients))
            ->flatten(1)
            ->where('type', RuleRecipients::USER)
            ->pluck('id');

        foreach ($recipients as $field => $entries) {
            foreach ($entries as $index => $entry) {
                if ($entry['type'] !== RuleRecipients::USER || $already->contains($entry['id'])) {
                    continue;
                }

                $here = BranchMembership::query()
                    ->where('user_id', $entry['id'])
                    ->where('location_id', $branch)
                    ->exists();

                if (! $here) {
                    throw ValidationException::withMessages([
                        "recipients.{$field}.{$index}" => 'That person does not work at this branch.',
                    ]);
                }
            }
        }
    }

    /** Back to the organisation's choice at this person's branch. */
    public function forgetRecipients(Request $request, AutomationRule $rule): JsonResponse
    {
        $branch = $this->ownBranch($request);

        abort_if($branch === null, 422, 'This is the organisation\'s own choice, so there is nothing to go back to.');

        AutomationRuleBranchRecipients::query()
            ->where('automation_rule_id', $rule->getKey())
            ->where('location_id', $branch)
            ->get()
            ->each
            ->delete();

        return $this->ok($this->asTheyStand($rule, $branch), 'Back to the organisation\'s choice');
    }

    private function asTheyStand(AutomationRule $rule, int $branch): AutomationRuleResource
    {
        return AutomationRuleResource::make($rule->load([
            'template',
            'branchRecipients' => fn ($own) => $own->where('location_id', $branch),
        ]));
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
