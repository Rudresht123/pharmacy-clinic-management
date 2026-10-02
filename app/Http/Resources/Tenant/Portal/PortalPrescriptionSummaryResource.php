<?php

namespace App\Http\Resources\Tenant\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A prescription, as much as the home screen's preview card needs.
 *
 * The full line-by-line read belongs to the dedicated prescriptions screen,
 * not yet built; this is deliberately thin.
 */
class PortalPrescriptionSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'prescription_date' => $this->prescription_date?->toDateString(),
            'status' => $this->status,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),
            'doctor_specialisation' => $this->whenLoaded('doctor', fn () => $this->doctor?->specialisation),
        ];
    }
}
