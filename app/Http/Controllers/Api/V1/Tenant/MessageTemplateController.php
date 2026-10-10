<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\MessageTemplateResource;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageTemplate;
use App\Support\Communication\MessageVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The wording a clinic is allowed to send.
 *
 * Writing is `communication.manage` because a template is what every patient
 * on the register will read; reading is `communication.view`, since the
 * screens that list them are the ones a receptionist opens.
 *
 * @group Tenant > Communications & Automation
 */
class MessageTemplateController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $channel = $request->string('channel')->toString();

        $query = MessageTemplate::query();

        if ($channel !== '') {
            $query->forChannel($channel);
        }

        return $this->ok(MessageTemplateResource::collection($query->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $template = MessageTemplate::query()->create([
            ...$validated,
            'created_by' => Auth::guard('web')->id(),
            'updated_by' => Auth::guard('web')->id(),
        ]);

        return $this->created(MessageTemplateResource::make($template), 'Template created');
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $validated = $this->validated($request, $template);

        $template->fill($validated);
        $template->updated_by = Auth::guard('web')->id();
        $template->save();

        return $this->ok(MessageTemplateResource::make($template), 'Template saved');
    }

    /**
     * Removing a template that something still automates is refused.
     *
     * The database would refuse it anyway — the rule's foreign key restricts —
     * but a 500 is not an answer. A clinic needs to be told which rule is
     * holding it, because that is what they have to change first.
     */
    public function destroy(Request $request, MessageTemplate $template): JsonResponse
    {
        $rule = AutomationRule::query()
            ->where('message_template_id', $template->id)
            ->first();

        if ($rule !== null) {
            return $this->respond(
                null,
                "\"{$rule->title}\" still sends this template. Switch that rule off first.",
                [],
                422,
            );
        }

        $template->deleted_by = Auth::guard('web')->id();
        $template->deletion_reason = $request->string('reason')->toString() ?: null;
        $template->save();
        $template->delete();

        return $this->ok(null, 'Template removed');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MessageTemplate $template = null): array
    {
        $channel = (string) $request->input('channel', $template?->channel);

        $validated = $request->validate([
            'channel' => [$template ? 'sometimes' : 'required', Rule::in(CommunicationChannel::CHANNELS)],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:60'],
            'template_type' => ['nullable', 'string', 'max:40'],

            // WhatsApp has no subject; email cannot go without one, and an
            // App notification's subject is the title on the phone.
            'subject' => [$channel === CommunicationChannel::PUSH ? 'required' : 'nullable', 'string', 'max:255'],

            // A phone shows a notification in a few lines; anything longer is
            // cut off on the lock screen, mid-sentence.
            'content' => ['required', 'string', ...($channel === CommunicationChannel::PUSH ? ['max:500'] : [])],

            'status' => ['sometimes', Rule::in(MessageTemplate::STATUSES)],
            'icon' => ['nullable', 'string', 'max:60'],
            'tone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer'],
        ], [
            'subject.required' => 'An app notification needs a title.',
        ]);

        $this->assertKnownVariables($channel, $validated);

        return $validated;
    }

    /**
     * Refuse a `{{variable}}` nothing fills, naming it — for the channels whose
     * wording the clinic writes itself. WhatsApp's placeholders are numbered
     * and the provider's, so they are not checked here.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertKnownVariables(string $channel, array $validated): void
    {
        if (! in_array($channel, CommunicationChannel::REWORDABLE, true)) {
            return;
        }

        $errors = [];

        foreach (['subject', 'content'] as $field) {
            $unknown = MessageVariables::unknownIn($validated[$field] ?? null);

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
    }
}
