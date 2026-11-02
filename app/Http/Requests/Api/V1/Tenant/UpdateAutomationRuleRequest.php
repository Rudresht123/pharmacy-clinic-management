<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Communication\RuleRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changing an automation rule: its wording, its template, its timing — and
 * who it tells, on To, CC and BCC (RuleRecipients).
 *
 * The lists are checked against what the RULE is, which the route already
 * named, so the questions a list can get wrong are answered here, by field:
 *
 *   - a channel's own lists only: no CC on a push, no list at all on WhatsApp
 *   - a staff alert never names the patient — it is written for staff — and
 *     the patient's own message never drops them from To
 *   - a role or a person that exists, and is staff; an address that is one
 *   - a rule switched on tells somebody
 *   - its template is written for the same reader as the rule
 *
 * Who may change rules at all is the route's (`communication.manage`).
 */
class UpdateAutomationRuleRequest extends FormRequest
{
    /** Per list: enough for a whole department, not a mailing list. */
    private const MAX_PER_LIST = 25;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'lead_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'message_template_id' => [
                'nullable',
                Rule::exists(MessageTemplate::class, 'id')->whereNull('deleted_at'),
            ],

            'recipients' => ['sometimes', 'array:to,cc,bcc'],
            'recipients.*' => ['array', 'max:'.self::MAX_PER_LIST],
            'recipients.*.*' => ['array'],
            'recipients.*.*.type' => ['required', Rule::in(RuleRecipients::TYPES)],
            'recipients.*.*.id' => ['nullable', 'integer', 'min:1'],
            'recipients.*.*.address' => ['nullable', 'email:rfc', 'max:150'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rule = $this->rule();

            if ($this->has('recipients')) {
                $this->checkLists($validator, $rule, (array) $this->input('recipients'));
            }

            $this->checkSomebody($validator, $rule);
            $this->checkTemplate($validator, $rule);
        }];
    }

    /**
     * The lists as they will be saved — the channel's own fields, each entry
     * down to what names it, each named once. Null when none were sent.
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    public function recipients(): ?array
    {
        if (! $this->has('recipients')) {
            return null;
        }

        $sent = (array) $this->input('recipients');
        $lists = [];
        $seen = [];

        foreach (RuleRecipients::fieldsFor((string) $this->rule()->channel) as $field) {
            $lists[$field] = [];

            foreach ((array) ($sent[$field] ?? []) as $entry) {
                $clean = ['type' => (string) $entry['type']];

                if (in_array($clean['type'], [RuleRecipients::ROLE, RuleRecipients::USER], true)) {
                    $clean['id'] = (int) $entry['id'];
                }

                if ($clean['type'] === RuleRecipients::EMAIL) {
                    $clean['address'] = mb_strtolower(trim((string) $entry['address']));
                }

                $key = implode(':', $clean);

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $lists[$field][] = $clean;
                }
            }
        }

        return $lists;
    }

    public function rule(): AutomationRule
    {
        return $this->route('rule');
    }

    /** @param  array<string, mixed>  $sent */
    private function checkLists(Validator $validator, AutomationRule $rule, array $sent): void
    {
        $channel = (string) $rule->channel;
        $fields = RuleRecipients::fieldsFor($channel);

        foreach ($sent as $field => $entries) {
            if (in_array($field, $fields, true) || $entries === []) {
                continue;
            }

            $validator->errors()->add("recipients.{$field}", $fields === []
                ? 'A WhatsApp message goes to the patient\'s own number, and nobody else\'s.'
                : 'An App notification has nobody on copy — put everybody on Send to.');

            return;
        }

        foreach ($fields as $field) {
            foreach ((array) ($sent[$field] ?? []) as $index => $entry) {
                $problem = $this->problemWith($rule, (array) $entry);

                if ($problem !== null) {
                    $validator->errors()->add("recipients.{$field}.{$index}", $problem);
                }
            }
        }

        if (! $rule->isForStaff() && $fields !== []) {
            $onTo = collect((array) ($sent[RuleRecipients::TO] ?? []))->contains(fn ($entry) => ($entry['type'] ?? null) === RuleRecipients::PATIENT);

            if (! $onTo) {
                $validator->errors()->add('recipients.to', 'This is the patient\'s own message, so the patient stays on To. Copy others in beside them.');
            }
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function problemWith(AutomationRule $rule, array $entry): ?string
    {
        $type = (string) $entry['type'];
        $id = (int) ($entry['id'] ?? 0);

        return match (true) {
            $type === RuleRecipients::PATIENT && $rule->isForStaff() => 'A staff alert is written for staff — the patient has their own rule for this.',

            $type === RuleRecipients::EMAIL && $rule->channel !== CommunicationChannel::EMAIL => 'An address can only be copied in on an email.',
            $type === RuleRecipients::EMAIL && blank($entry['address'] ?? null) => 'Type the email address.',

            $type === RuleRecipients::ROLE && ! $this->staffRole($id) => 'That role was not found.',

            $type === RuleRecipients::USER && ! User::query()->whereKey($id)->where('is_active', true)->whereIn('role', [User::OWNER, User::STAFF])->exists() => 'That member of staff was not found, or no longer has access.',

            default => null,
        };
    }

    private function staffRole(int $id): bool
    {
        $role = $id > 0 ? Role::query()->find($id) : null;

        return $role !== null && ! $role->isForPatients();
    }

    /** A rule left switched on has to tell somebody. */
    private function checkSomebody(Validator $validator, AutomationRule $rule): void
    {
        if (! $this->boolean('is_enabled', (bool) $rule->is_enabled)) {
            return;
        }

        $lists = $this->recipients() ?? RuleRecipients::of($rule);
        $named = array_sum(array_map('count', $lists));

        if ($named === 0 && RuleRecipients::fieldsFor((string) $rule->channel) !== []) {
            $validator->errors()->add('recipients.to', 'Choose who this rule tells before switching it on.');
        }
    }

    /** Staff wording for a staff alert, the patient's for the patient's message. */
    private function checkTemplate(Validator $validator, AutomationRule $rule): void
    {
        $id = $this->has('message_template_id') ? $this->input('message_template_id') : null;

        if ($id === null) {
            return;
        }

        $audience = MessageTemplate::query()->whereKey($id)->value('audience');

        if ($audience !== null && $audience !== $rule->audience) {
            $validator->errors()->add('message_template_id', $rule->isForStaff()
                ? 'That template is written for patients. Choose one written for staff.'
                : 'That template is written for staff. Choose one written for patients.');
        }
    }
}
