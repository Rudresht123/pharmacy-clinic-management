<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'parent_name' => $this->whenLoaded('parent', fn () => $this->parent?->name),
            'is_top_level' => $this->isTopLevel(),
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'doctors_count' => $this->whenCounted('doctors'),
            'staff_count' => $this->whenCounted('staff'),
            'children_count' => $this->whenCounted('children'),
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
