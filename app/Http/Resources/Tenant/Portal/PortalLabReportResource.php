<?php

namespace App\Http\Resources\Tenant\Portal;

use App\Models\Tenant\LabOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A patient's own lab order, as the portal's list and detail screens read it.
 *
 * @mixin LabOrder
 */
class PortalLabReportResource extends JsonResource
{
    private const STATUS_LABELS = [
        LabOrder::PENDING => 'Awaited',
        LabOrder::IN_PROGRESS => 'In progress',
        LabOrder::COMPLETED => 'Completed',
        LabOrder::CANCELLED => 'Cancelled',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'number' => $this->order_number,
            'date' => $this->order_date?->format('d M Y') ?? '',
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name) ?: 'Doctor',
            'clinic_name' => $this->whenLoaded('location', fn () => $this->location?->name) ?: '',
            'status' => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status),
            'is_complete' => $this->status === LabOrder::COMPLETED,
            'has_abnormal' => $this->whenLoaded(
                'items',
                fn () => $this->items->contains(fn ($item) => (bool) $item->is_abnormal),
                false,
            ),
            // "CBC, Lipid Profile" — what a list row shows without opening it.
            'tests_summary' => $this->whenLoaded('items', fn () => $this->testSummary()),
            'tests' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'name' => $item->test_name,
                'specimen' => $item->specimen,
                'status' => $item->status,
                'result' => $item->result_value,
                'unit' => $item->result_unit,
                'reference_range' => $item->reference_range,
                'is_abnormal' => (bool) $item->is_abnormal,
                // "14.2 g/dL (13.0–17.0)" — formatted once, on the server.
                'result_text' => $item->resultText(),
            ])->values(), []),
        ];
    }
}
