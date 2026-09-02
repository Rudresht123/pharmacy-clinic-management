<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Support\Fields\UserFields;
use App\Repositories\Tenant\Contracts\TenantUserRepositoryInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Editing somebody already in the organization.
 *
 * Password is optional here — leaving it blank keeps the one they have.
 */
class UpdateTenantUserRequest extends FormRequest
{
    use MergesFieldSettings;

    public function authorize(): bool
    {
        return true;
    }

    private function target(): ?User
    {
        return $this->route('user');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:191'],

            'email' => [
                'required', 'string', 'email', 'max:191',
                Rule::unique(User::class, 'email')
                    ->ignore($this->target()?->id)
                    ->whereNull('deleted_at'),
            ],

            'password' => [
                'nullable', 'string', 'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],

            'role' => ['required', 'string', Rule::in(User::ROLES)],

            // The branch they work at. Null for the owner, who works
            // across the whole network.
            'location_id' => [
                'nullable', 'integer',
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
     * The rules that depend on who is being edited, and by whom.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->target();

            if (! $target) {
                return;
            }

            $signedIn = Auth::guard('web')->user();
            $editingSelf = $signedIn && $signedIn->getKey() === $target->getKey();

            $losingOwnership = $target->isOwner()
                && ($this->input('role') !== User::OWNER || $this->boolean('is_active') === false);

            /*
             * An organization with nobody who can manage it is locked out of
             * its own workspace, and nobody inside it could undo that — only
             * a platform admin editing the database directly. So the last
             * active owner cannot be demoted or switched off.
             */
            if ($losingOwnership) {
                $others = app(TenantUserRepositoryInterface::class)
                    ->otherActiveOwnerCount($target);

                if ($others === 0) {
                    $validator->errors()->add(
                        'role',
                        'This is the only active owner. Make somebody else an owner first.'
                    );
                }
            }

            // Even with another owner around, locking yourself out mid-edit
            // is almost never what was meant.
            if ($editingSelf && $this->boolean('is_active') === false) {
                $validator->errors()->add(
                    'is_active',
                    'You cannot deactivate your own account.'
                );
            }
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
        ];
    }
}
