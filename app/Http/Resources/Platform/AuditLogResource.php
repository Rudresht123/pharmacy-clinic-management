<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'event' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'entity_label' => $this->entity_label,

            /*
             * The name, not a join. The account may be gone — that is the
             * whole reason it is stored on the row — so the id is offered
             * only so a live one can still be linked to.
             */
            'actor_name' => $this->actor_name,
            'actor_id' => $this->platform_user_id,
            'actor_type' => 'platform',

            /*
             * Sent as an ordered list of {field, from, to} rather than two
             * loose objects, so the screen renders a diff without having to
             * work out which keys the two halves share.
             */
            'changes' => $this->changes(),

            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changes(): array
    {
        $before = $this->before ?? [];
        $after = $this->after ?? [];

        $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        return array_map(fn (string $field) => [
            'field' => $field,
            'from' => $before[$field] ?? null,
            'to' => $after[$field] ?? null,
        ], $fields);
    }
}
