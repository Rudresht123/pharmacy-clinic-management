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
            'role' => $this->role,
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
