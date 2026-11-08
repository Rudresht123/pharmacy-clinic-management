<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabTestParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabTestParameter
 */
class LabTestParameterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'section' => $this->section,
            'result_type' => $this->result_type,
            'unit_id' => $this->unit_id,
            'unit_symbol' => $this->whenLoaded('unit', fn () => $this->unit?->symbol),
            'decimals' => $this->decimals,
            'choices' => $this->choices,
            'normal_value' => $this->normal_value,
            'method_id' => $this->method_id,
            'method_name' => $this->whenLoaded('method', fn () => $this->method?->name),
            'is_required' => (bool) $this->is_required,
            'is_printed' => (bool) $this->is_printed,
            'position' => $this->position,
            'ranges' => LabReferenceRangeResource::collection($this->whenLoaded('ranges')),
        ];
    }
}
