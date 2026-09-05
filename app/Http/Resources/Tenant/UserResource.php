<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // The account's kind — owner or staff. Not the same question as
            // `role_id` below, which is what they may do.
            'role' => $this->role,

            'role_id' => $this->role_id,
            'role_name' => $this->whenLoaded(
                'permissionRole',
                fn () => $this->permissionRole?->name,
                null,
            ),

            /*
             * Where they work, and what they hold at each. The relationship
             * `location_id` could not express — a doctor at three clinics is
             * three rows, each with its own role.
             */
            'branches' => $this->whenLoaded(
                'memberships',
                fn () => $this->memberships->map(fn ($membership) => [
                    'location_id' => $membership->location_id,
                    'location' => $membership->location?->name,
                    'role_id' => $membership->role_id,
                    'role' => $membership->role?->name,
                    'is_primary' => $membership->is_primary,
                ])->values(),
                [],
            ),

            'location_id' => $this->location_id,
            'location' => $this->whenLoaded(
                'location',
                fn () => $this->location
                    ? ['id' => $this->location->id, 'name' => $this->location->name]
                    : null
            ),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'custom_fields' => $this->custom_fields ?? [],
        ];
    }
}
