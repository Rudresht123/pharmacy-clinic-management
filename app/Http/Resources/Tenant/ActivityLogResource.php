<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'event' => $this->event,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'entity_label' => $this->entity_label,

            /*
             * The name, not a join. The account may be gone — that is why it
             * is stored on the row — so the id is offered only so a live one
             * can still be linked to.
             */
            'actor_name' => $this->actor_name,
            'actor_id' => $this->user_id,

            /*
             * Whether this organization's own staff made the change, or a
             * platform administrator did. Different people, and the reader
             * has to be able to tell.
             */
            'actor_type' => $this->actor_type,

            // An ordered {field, from, to} list, so the screen renders a diff
            // without working out which keys the two halves share.
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
