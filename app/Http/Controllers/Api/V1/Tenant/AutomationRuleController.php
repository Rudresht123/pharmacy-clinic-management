<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\AutomationRuleResource;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * When a message goes out without anybody asking.
 *
 * Only updating: the rules are the clinic events the application knows how to
 * react to, so they are seeded rather than invented. A clinic chooses whether
 * each one sends, what it sends, and how far ahead — not what the events are.
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

    public function update(Request $request, AutomationRule $rule): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'lead_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'message_template_id' => [
                'nullable',
                Rule::exists(MessageTemplate::class, 'id')->whereNull('deleted_at'),
            ],
        ]);

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
