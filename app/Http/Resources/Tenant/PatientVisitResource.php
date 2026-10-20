<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Appointment;
use Illuminate\Http\Request;

/**
 * An appointment as a patient's history reads it — the appointment itself,
 * exactly as the OPD screens return it, plus what was written up at it.
 *
 * The write-up is the same shape the patient file's visits endpoint gives
 * (CustomerController::visits), so one screen's reading of a consultation is
 * every screen's. Null when nobody wrote the visit up — a fact about the
 * visit, not a gap to paper over.
 *
 * @mixin Appointment
 */
class PatientVisitResource extends AppointmentResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'chief_complaint' => $this->consultation->chief_complaint,
                'diagnoses' => $this->consultation->diagnoses ?? [],
                'advice' => $this->consultation->advice,
                'notes' => $this->consultation->notes,
                'follow_up_days' => $this->consultation->follow_up_days,
                'vitals' => $this->consultation->vitals ?? [],
                'prescription' => $this->consultation->prescriptionLines(),
                'investigations' => $this->consultation->investigations ?? [],
            ] : null),
        ];
    }
}
