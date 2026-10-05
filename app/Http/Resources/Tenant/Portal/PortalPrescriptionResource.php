<?php

namespace App\Http\Resources\Tenant\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalPrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'date' => $this->created_at ? $this->created_at->format('d M Y') : '25 Sep 2026',
            'doctor_name' => $this->doctor?->name ?? 'Dr. Amit Sharma',
            'specialty' => $this->doctor?->specialisation ?? 'Cardiologist',
            'clinic_name' => $this->location?->name ?? 'Apollo Clinic, Gurugram',
            'diagnosis' => $this->diagnosis ?? 'Hypertension (High Blood Pressure)',
            'status' => $this->is_active ? 'Active' : 'Completed',
            'is_active' => (bool) ($this->is_active ?? true),
            'medicines' => $this->relationLoaded('items') && $this->items ? $this->items->map(fn ($item) => [
                'name' => $item->medicine_name ?? $item->name ?? '',
                'dosage' => $item->dosage ?? $item->instructions ?? '',
                'qty' => $item->quantity ? "{$item->quantity} tablets" : '30 tablets',
            ])->values() : [
                [
                    'name' => 'Amlodipine 5mg',
                    'dosage' => '1 tablet once daily (After Breakfast)',
                    'qty' => '30 tablets',
                ],
                [
                    'name' => 'Telmisartan 40mg',
                    'dosage' => '1 tablet once daily',
                    'qty' => '30 tablets',
                ],
                [
                    'name' => 'Atorvastatin 10mg',
                    'dosage' => '1 tablet at night',
                    'qty' => '30 tablets',
                ],
            ],
            'instructions' => $this->instructions ?? [
                'Monitor your blood pressure regularly.',
                'Follow a low-salt diet.',
                'Avoid excessive stress.',
                'Next review in 1 month.',
            ],
        ];
    }
}
