<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabReferenceRange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reference range. Limits travel as written ("13.5", not 13.500000), so a
 * form shows what the pathologist typed and nothing is lost to a float.
 *
 * @mixin LabReferenceRange
 */
class LabReferenceRangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sex' => $this->sex,
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'age_unit' => $this->age_unit,
            'low' => LabReferenceRange::number($this->low),
            'high' => LabReferenceRange::number($this->high),
            'critical_low' => LabReferenceRange::number($this->critical_low),
            'critical_high' => LabReferenceRange::number($this->critical_high),
            'normal_text' => $this->normal_text,
            'display_text' => $this->display_text,
            'notes' => $this->notes,
            'position' => $this->position,

            // "Female, 18–60 years" and "12 – 15.5": what a screen and a report say.
            'applies_to' => $this->appliesToText(),
            'printed' => $this->printed(),
        ];
    }
}
