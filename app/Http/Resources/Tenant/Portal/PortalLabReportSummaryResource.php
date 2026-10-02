<?php

namespace App\Http\Resources\Tenant\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lab order, as much as the home screen's preview card needs.
 *
 * The full result-by-result read belongs to the dedicated lab reports screen,
 * not yet built; this is deliberately thin.
 */
class PortalLabReportSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date?->toDateString(),
            'test_summary' => $this->testSummary(),
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),
        ];
    }
}
