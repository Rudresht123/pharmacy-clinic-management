<?php

namespace App\Http\Resources\Tenant\Portal;

use App\Models\Tenant\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A patient's own prescription, as the portal's list and detail screens read
 * it.
 *
 * @mixin Prescription
 */
class PortalPrescriptionResource extends JsonResource
{
    private const STATUS_LABELS = [
        Prescription::DRAFT => 'Draft',
        Prescription::ISSUED => 'Active',
        Prescription::PARTIALLY_DISPENSED => 'Partially dispensed',
        Prescription::DISPENSED => 'Dispensed',
        Prescription::CANCELLED => 'Cancelled',
        Prescription::EXPIRED => 'Expired',
    ];

    /** Still something to act on, as opposed to settled or withdrawn. */
    private const ACTIVE_STATUSES = [Prescription::ISSUED, Prescription::PARTIALLY_DISPENSED];

    public function toArray(Request $request): array
    {
        $diagnoses = $this->consultation?->diagnoses;

        return [
            'id' => (string) $this->id,
            'number' => $this->prescription_number,
            'date' => $this->prescription_date?->format('d M Y') ?? '',
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name) ?: 'Doctor',
            'specialty' => $this->whenLoaded('doctor', fn () => $this->doctor?->specialisation) ?: '',
            'clinic_name' => $this->whenLoaded('location', fn () => $this->location?->name) ?: '',
            'diagnosis' => is_array($diagnoses) && $diagnoses !== [] ? implode(', ', $diagnoses) : '',
            'status' => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status),
            'is_active' => in_array($this->status, self::ACTIVE_STATUSES, true),
            'medicines' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) {
                $line = $item->asConsultationLine();

                return [
                    'name' => $line['drug'],
                    'dosage' => implode(' · ', array_filter([$line['dose'], $line['frequency'], $line['duration']])),
                    'qty' => $item->prescribed_quantity !== null ? (string) $item->prescribed_quantity : '',
                ];
            })->values(), []),
            // The doctor's free-text notes, one bullet a line. Never the
            // placeholder advice an earlier version of this screen showed for
            // every prescription that had none of its own.
            'instructions' => $this->clinical_notes
                ? array_values(array_filter(array_map('trim', explode("\n", $this->clinical_notes))))
                : [],
        ];
    }
}
