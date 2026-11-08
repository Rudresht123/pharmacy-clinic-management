<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabOrderItem
 */
class LabOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_name' => $this->test_name,
            'test_code' => $this->test_code,
            // The test-master test it is; null for a test typed by hand.
            'lab_test_id' => $this->lab_test_id,
            'department_name' => $this->relationLoaded('labTest') ? $this->labTest?->department?->name : null,
            'sample_type_name' => $this->relationLoaded('labTest') ? $this->labTest?->sampleType?->name : null,
            'specimen' => $this->specimen,
            'instructions' => $this->instructions,
            'status' => $this->status,

            'result_value' => $this->result_value,
            'result_unit' => $this->result_unit,
            'reference_range' => $this->reference_range,
            'is_abnormal' => (bool) $this->is_abnormal,

            // "14.2 g/dL (13.0–17.0)" — worked out once, on the server, so a
            // report and a screen never format the same reading differently.
            'result_text' => $this->resultText(),

            'notes' => $this->notes,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
        ];
    }
}
