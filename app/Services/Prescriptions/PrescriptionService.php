<?php

namespace App\Services\Prescriptions;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Writing, issuing and cancelling a visit's prescription.
 *
 * Who may do each is the Policy's question; this answers whether the
 * prescription is in a state that allows it (409 when it is not), and keeps
 * the snapshot rule: a line's medicine details are copied when the medicine
 * is chosen and never rewritten.
 */
class PrescriptionService
{
    /** What a request may set on a line, besides which medicine it is. */
    private const LINE_FIELDS = [
        'dose_amount', 'dose_unit', 'morning', 'afternoon', 'evening', 'night', 'frequency',
        'food_timing', 'duration', 'duration_unit', 'route', 'prescribed_quantity', 'instructions',
    ];

    /**
     * Start the visit's prescription as a draft.
     *
     * The visit's consultation row is made if the doctor has not written
     * notes yet, so a prescription always belongs to a write-up.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Appointment $appointment, array $data): Prescription
    {
        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            throw new ConflictHttpException('This visit did not happen, so there is nothing to prescribe against.');
        }

        try {
            return $this->transaction(function () use ($appointment, $data) {
                $this->assertNoneLive($appointment);

                $consultation = Consultation::query()->firstOrCreate(
                    ['appointment_id' => $appointment->id],
                    ['customer_id' => $appointment->customer_id, 'doctor_id' => $appointment->doctor_id],
                );

                $prescription = Prescription::query()->create([
                    'location_id' => $appointment->location_id,
                    'customer_id' => $appointment->customer_id,
                    'doctor_id' => $appointment->doctor_id,
                    'appointment_id' => $appointment->id,
                    'consultation_id' => $consultation->id,
                    'prescription_date' => now()->toDateString(),
                    'valid_until' => $data['valid_until'] ?? null,
                    'clinical_notes' => $data['clinical_notes'] ?? null,
                ]);

                // Reads back the number the database gave it.
                $prescription->refresh();

                $this->syncItems($prescription, $data['items'] ?? []);

                return $prescription;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request started one for this visit at the same moment.
            $this->assertNoneLive($appointment);

            throw new ConflictHttpException('This visit already has a prescription. Reload and edit that one.');
        }
    }

    /**
     * Change a draft. Lines left out of `items` are removed from it; a line
     * sent with its `id` is edited in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Prescription $prescription, array $data): Prescription
    {
        return $this->transaction(function () use ($prescription, $data) {
            $locked = $this->lock($prescription);

            $this->assertDraft($locked, 'edited');

            $locked->fill(Arr::only($data, ['valid_until', 'clinical_notes']))->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items'] ?? []);
            }

            return $locked;
        });
    }

    /** Sign it off: from here it is the pharmacy's to dispense, and it can no longer be edited. */
    public function issue(Prescription $prescription, ?string $validUntil = null): Prescription
    {
        return $this->transaction(function () use ($prescription, $validUntil) {
            $locked = $this->lock($prescription);

            $this->assertDraft($locked, 'issued again');

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => ['Add at least one medicine before issuing the prescription.'],
                ]);
            }

            $validUntil ??= $locked->valid_until?->toDateString()
                ?? $locked->prescription_date->copy()->addDays(Prescription::DEFAULT_VALID_DAYS)->toDateString();

            $locked->forceFill([
                'status' => Prescription::ISSUED,
                'issued_at' => now(),
                'valid_until' => $validUntil,
            ])->save();

            return $locked;
        });
    }

    /**
     * Withdraw an issued prescription, with a reason. Refused once anything
     * has been dispensed against it — that stock has left the building.
     */
    public function cancel(Prescription $prescription, string $reason): Prescription
    {
        return $this->transaction(function () use ($prescription, $reason) {
            $locked = $this->lock($prescription);

            if ($locked->status === Prescription::CANCELLED) {
                throw new ConflictHttpException("{$locked->prescription_number} is already cancelled.");
            }

            if ($locked->isDraft()) {
                throw new ConflictHttpException("{$locked->prescription_number} has not been issued. Remove the draft instead.");
            }

            $dispensed = in_array($locked->status, [Prescription::PARTIALLY_DISPENSED, Prescription::DISPENSED], true)
                || $locked->items()->where('dispensed_quantity', '>', 0)->exists();

            if ($dispensed) {
                throw new ConflictHttpException(
                    "Medicines have already been dispensed against {$locked->prescription_number}, so it can't be cancelled. Reverse the dispensing first."
                );
            }

            $actor = Auth::guard('web')->user();

            $locked->forceFill([
                'status' => Prescription::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor instanceof User ? $actor->id : null,
                'cancellation_reason' => $reason,
            ])->save();

            foreach ($locked->items()->get() as $item) {
                $item->forceFill(['status' => PrescriptionItem::CANCELLED])->save();
            }

            return $locked;
        });
    }

    /** Remove a draft, with a reason. An issued prescription is cancelled instead. */
    public function remove(Prescription $prescription, string $reason): void
    {
        $this->transaction(function () use ($prescription, $reason) {
            $locked = $this->lock($prescription);

            if (! $locked->isDraft()) {
                throw new ConflictHttpException(
                    "{$locked->prescription_number} has been issued, so it is cancelled rather than removed."
                );
            }

            foreach ($locked->items()->get() as $item) {
                $item->deleteWithReason($reason);
            }

            $locked->deleteWithReason($reason);
        });
    }

    /**
     * Make the draft's lines match the request.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncItems(Prescription $prescription, array $lines): void
    {
        $current = $prescription->items()->with('medicine')->get()->keyBy('id');

        $medicines = Medicine::query()
            ->whereIn('id', collect($lines)->pluck('medicine_id')->filter()->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $item = null;

            if (! empty($line['id'])) {
                $item = $current->get((int) $line['id']) ?? throw ValidationException::withMessages([
                    "items.{$index}.id" => ['That line is not on this prescription. Reload and try again.'],
                ]);
            }

            $medicineId = ! empty($line['medicine_id']) ? (int) $line['medicine_id'] : null;
            $medicine = $medicineId ? $medicines->get($medicineId) : null;
            $chosen = $item === null || $item->medicine_id !== $medicineId;

            // A deleted medicine is refused by validation; an inactive one is refused here, for new choices only.
            if ($medicineId && $chosen && ! $medicine?->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.medicine_id" => [
                        $medicine
                            ? "{$medicine->displayName()} is inactive, so it can't be prescribed. Pick another."
                            : 'That medicine has been removed from the catalogue. Pick another.',
                    ],
                ]);
            }

            $values = Arr::only($line, self::LINE_FIELDS);
            $values['medicine_id'] = $medicineId;
            $values['sort_order'] = $index;
            $values['frequency'] = ($values['frequency'] ?? null) ?: PrescriptionItem::frequencyFromPattern($values);

            if ($chosen) {
                $values += $this->snapshot($medicine, $line['medicine_name'] ?? null);
                $values['route'] = ($values['route'] ?? null) ?: $medicine?->route;
            } elseif ($medicineId === null && filled($line['medicine_name'] ?? null)) {
                // An unlisted line's name is the doctor's own words, still theirs to correct in a draft.
                $values['medicine_name_snapshot'] = mb_substr(trim((string) $line['medicine_name']), 0, 191);
            }

            if ($medicineId !== null && empty($values['prescribed_quantity'])) {
                $baseUnit = ($medicine ?? $item?->medicine)?->base_unit;

                $values['prescribed_quantity'] = PrescriptionItem::suggestQuantity($values, $baseUnit)
                    ?? throw ValidationException::withMessages([
                        "items.{$index}.prescribed_quantity" => ['How many should be dispensed? The dose and duration do not say.'],
                    ]);
            }

            if ($item) {
                $item->fill($values)->save();
            } else {
                $item = $prescription->items()->create($values);
            }

            $kept[] = $item->id;
        }

        foreach ($current->except($kept) as $gone) {
            $gone->deleteWithReason('Removed from the draft');
        }
    }

    /**
     * What a line copies from its medicine, once.
     *
     * @return array<string, ?string>
     */
    private function snapshot(?Medicine $medicine, ?string $unlistedName): array
    {
        if ($medicine) {
            return [
                'medicine_name_snapshot' => mb_substr($medicine->displayName(), 0, 191),
                'generic_name_snapshot' => $medicine->generic_name,
                'strength_snapshot' => $medicine->strength,
                'dosage_form_snapshot' => $medicine->dosage_form,
            ];
        }

        return [
            'medicine_name_snapshot' => mb_substr(trim((string) $unlistedName), 0, 191),
            'generic_name_snapshot' => null,
            'strength_snapshot' => null,
            'dosage_form_snapshot' => null,
        ];
    }

    private function assertNoneLive(Appointment $appointment): void
    {
        $existing = Prescription::query()->live()->where('appointment_id', $appointment->id)->first();

        if ($existing) {
            throw new ConflictHttpException("This visit already has {$existing->prescription_number}. Edit that one instead.");
        }
    }

    private function assertDraft(Prescription $prescription, string $what): void
    {
        if (! $prescription->isDraft()) {
            throw new ConflictHttpException(
                "{$prescription->prescription_number} has been {$prescription->status} and can't be {$what}. Cancel it and write a new one if it needs to change."
            );
        }
    }

    private function lock(Prescription $prescription): Prescription
    {
        return Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
    }

    private function transaction(callable $callback): mixed
    {
        return (new Prescription)->getConnection()->transaction($callback);
    }
}
