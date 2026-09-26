<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'scope' => $this->scope,

            /*
             * Null means the organization wrote it, and every branch may use
             * it. Set means it belongs to that branch and is offered nowhere
             * else — the client shows which, because "why can I not edit this"
             * has to be answerable on the screen.
             */
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location?->name, null),
            'icon' => $this->icon ?: Role::DEFAULT_ICON,

            'capabilities' => $this->whenLoaded(
                'capabilities',
                fn () => $this->capabilityKeys(),
                [],
            ),

            /*
             * The subset no branch may take away for itself. A separate list
             * rather than a flag per capability, because every screen that
             * reads `capabilities` treats it as a set of keys, and changing
             * its shape to carry one extra boolean would rewrite all of them.
             */
            'locked' => $this->whenLoaded(
                'capabilities',
                fn () => $this->lockedCapabilityKeys(),
                [],
            ),

            /*
             * Whether branches may customise it at all — organization-wide and
             * branch-assigned. The client needs this to decide whether to
             * offer the lock column, and saying so here keeps that rule in one
             * place rather than reimplemented in the screen.
             */
            'is_customisable_by_branch' => $this->isCustomisableByBranch(),

            /*
             * How many people hold it, counting BOTH ways it can be held: on
             * `users.role_id` for head office, and on a membership for branch
             * staff. Counting only the first made every branch role read as
             * held by nobody, and the delete button offer to remove a role
             * three people were using.
             */
            'users_count' => $this->when(
                $this->users_count !== null || $this->memberships_count !== null,
                fn () => (int) ($this->users_count ?? 0) + (int) ($this->memberships_count ?? 0),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
