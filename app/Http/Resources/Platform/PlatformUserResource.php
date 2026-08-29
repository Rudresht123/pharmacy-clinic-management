<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformUser
 */
class PlatformUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The auto-increment id is never exposed; §5 puts the ULID in URLs.
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,

            // Drives what the panel renders; the server still authorises
            // every request on its own.
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->map(fn ($role) => [
                    'code' => $role->code,
                    'name' => $role->name,
                ])->all(),
                []
            ),

            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
