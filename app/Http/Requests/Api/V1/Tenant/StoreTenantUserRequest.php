<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\ChecksRoleGrant;
use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Fields\UserFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * The owner adding somebody to the organization.
 *
 * Authorization is the `tenant.owner` middleware on the route.
 */
class StoreTenantUserRequest extends FormRequest
{
    use ChecksRoleGrant, MergesFieldSettings;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:191'],

            /*
             * The model class, not the string 'users' — a string table name
             * resolves against the default connection, which is the master
             * database, and would check the wrong table entirely.
             */
            'email' => [
                'required', 'string', 'email', 'max:191',
                Rule::unique(User::class, 'email')->whereNull('deleted_at'),
            ],

            // Matches what the organization setup form promises its owner.
            'password' => [
                'required', 'string', 'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],

            'role' => ['required', 'string', Rule::in(User::ROLES)],

            /*
             * The set of capabilities they hold — level three of the
             * permission flow. Required for staff and refused for an owner,
             * who bypasses roles entirely; giving one to an owner would look
             * like a limit that is not enforced anywhere.
             */
            'role_id' => [
                'nullable', 'integer',
                'required_if:role,'.User::STAFF,
                'prohibited_if:role,'.User::OWNER,
                Rule::exists(Role::class, 'id'),
            ],

            /*
             * Where they work. THREE placements, not two:
             *
             *   owner            null — runs the whole network
             *   organization staff null — works for the organization itself:
             *                     the accountant, the network manager, whoever
             *                     sits at head office rather than at a counter
             *   branch staff     a branch — works there and nowhere else
             *
             * So null is deliberately allowed for staff. It was nearly made
             * required, which would have left an organization no way to
             * describe its own people — every real chain has some.
             *
             * The owner is refused one because they have no branch by
             * definition, not because nobody thought of it.
             */
            'location_id' => [
                'nullable', 'integer',
                'prohibited_if:role,'.User::OWNER,
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];

        return $this->withFieldSettings(
            $rules,
            EntityFieldSetting::ENTITY_USER,
            UserFields::all(),
        );
    }

    /**
     * Only an owner may make somebody else an owner.
     *
     * `people.create` is delegatable, which is the point of it — but without
     * this, delegating it would be handing over every other permission at the
     * same time, since the holder could simply create themselves a second
     * account with no role at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $signedIn = Auth::guard('web')->user();

            if ($this->input('role') === User::OWNER && $signedIn && ! $signedIn->isOwner()) {
                $validator->errors()->add(
                    'role',
                    'Only an owner can make somebody else an owner.'
                );
            }

            $this->validateRoleGrant($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Somebody in your organization already uses this address.',
            'password.confirmed' => 'The two passwords do not match.',
            'role.in' => 'Choose either owner or staff.',
            'role_id.required_if' => 'Choose the role this person holds.',
            'role_id.prohibited_if' => 'An owner is not limited by a role.',
            'role_id.exists' => 'That role no longer exists.',
            'location_id.prohibited_if' => 'An owner works across every branch.',
        ];
    }
}
