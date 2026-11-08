<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A test from the master: on a list, its own columns and what it points at by
 * name; on its own page, its parameters with their ranges and its branch prices.
 *
 * @mixin LabTest
 */
class LabTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $name = fn (string $relation) => $this->whenLoaded($relation, fn () => $this->{$relation}?->name);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,

            'department_id' => $this->department_id,
            'department_name' => $name('department'),
            'category_id' => $this->category_id,
            'category_name' => $name('category'),
            'sample_type_id' => $this->sample_type_id,
            'sample_type_name' => $name('sampleType'),
            'container_id' => $this->container_id,
            'container_name' => $name('container'),
            'method_id' => $this->method_id,
            'method_name' => $name('method'),

            'sample_volume' => $this->sample_volume,
            'description' => $this->description,
            'collection_instructions' => $this->collection_instructions,
            'processing_instructions' => $this->processing_instructions,
            'result_layout' => $this->result_layout,
            'report_template' => $this->report_template,
            'tat_hours' => $this->tat_hours,

            'price' => (float) $this->price,
            'tax_percent' => (float) $this->tax_percent,
            'hsn_code' => $this->hsn_code,

            'is_outsourced' => (bool) $this->is_outsourced,
            'outsource_lab_id' => $this->outsource_lab_id,
            'outsource_lab_name' => $name('outsourceLab'),
            'outsource_cost' => $this->outsource_cost === null ? null : (float) $this->outsource_cost,

            'is_active' => (bool) $this->is_active,
            'position' => $this->position,

            // Moved across from the old price list rather than written here.
            'from_old_price_list' => $this->legacy_catalog_id !== null,

            'parameters_count' => $this->whenCounted('parameters'),
            'branch_prices_count' => $this->whenCounted('branchPrices'),
            'parameters' => LabTestParameterResource::collection($this->whenLoaded('parameters')),
            'branch_prices' => $this->whenLoaded('branchPrices', fn () => $this->branchPrices
                ->sortBy(fn ($price) => $price->location?->name)
                ->values()
                ->map(fn ($price) => [
                    'location_id' => $price->location_id,
                    'location_name' => $price->location?->name,
                    'price' => (float) $price->price,
                    'tax_percent' => $price->tax_percent === null ? null : (float) $price->tax_percent,
                ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
