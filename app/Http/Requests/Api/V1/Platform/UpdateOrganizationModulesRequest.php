<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Platform\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole set of an organization's bindings, submitted at once.
 *
 * A full replacement rather than a patch per module: the screen shows every
 * bindable module together, and an administrator who unticks one is saying
 * "this is the new arrangement". Sending only the changes would leave the
 * server unable to tell a removal from a field the client forgot.
 */
class UpdateOrganizationModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modules' => ['present', 'array'],

            /*
             * Only bindable modules may appear. Core ones are not a choice,
             * and letting one through here would create a row that says an
             * organization "has" something it can never not have — and could
             * then be expired.
             */
            'modules.*.key' => [
                'required',
                'string',
                /*
                 * A closure, not ->where('is_core', false): the rule's own
                 * where() stringifies its value, and Postgres refuses '' as a
                 * boolean — the query blows up rather than failing validation.
                 */
                Rule::exists(Module::class, 'key')->where(
                    fn ($query) => $query->where('is_core', false)->where('is_active', true)
                ),
            ],

            'modules.*.is_enabled' => ['required', 'boolean'],
            'modules.*.starts_at' => ['nullable', 'date'],

            /*
             * Deliberately not `after:today`. A subscription that lapsed last
             * month is a fact to record, and an administrator correcting the
             * history of one must be able to enter a date that has passed.
             */
            'modules.*.expires_at' => ['nullable', 'date', 'after_or_equal:modules.*.starts_at'],

            'modules.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'modules.*.key.exists' => 'That module cannot be assigned.',
            'modules.*.expires_at.after_or_equal' => 'The end date cannot fall before the start date.',
        ];
    }

    public function attributes(): array
    {
        return [
            'modules.*.key' => 'module',
            'modules.*.starts_at' => 'start date',
            'modules.*.expires_at' => 'end date',
        ];
    }
}
