<?php

namespace App\Services\Pharmacy\Sales;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Services\Opd\VisitWorkflow;
use App\Services\Pharmacy\Inventory\StockConflict;
use Illuminate\Support\Collection;

/**
 * The half of a sale that is a dispensing.
 *
 * A counter sale and a dispensing are the same bill — SalesService rings
 * both, stock moves the same way, the ledger reads the same. What a
 * dispensing does IN ADDITION is what lives here: it fulfils prescribed
 * lines, moves the prescription's own status, and tells the visit that the
 * pharmacy is finished with it.
 *
 * Held apart from SalesService so a standalone medical store never runs a
 * line of this, which is the same reason `prescription_id` is nullable on the
 * bill in the first place.
 *
 * WHY QUANTITIES ARE WRITTEN HERE AND NOWHERE ELSE. `dispensed_quantity` has
 * existed on the line since prescriptions shipped, read by the resource and
 * by the cancellation guard, and written by nothing at all — so a
 * prescription stayed `issued` forever however much of it had been handed
 * over. Everything downstream that asks "is the pharmacy done" was asking a
 * column nobody maintained.
 */
class Dispensing
{
    public function __construct(
        private readonly VisitWorkflow $visits,
    ) {}

    /**
     * The prescription this sale is against, locked and dispensable.
     *
     * Locked before anything is read from it, alongside the batches, so two
     * counters dispensing the same prescription queue rather than both
     * crediting the same lines.
     */
    public function lock(?int $prescriptionId): ?Prescription
    {
        if (! $prescriptionId) {
            return null;
        }

        $prescription = Prescription::query()
            ->whereKey($prescriptionId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertDispensable($prescription);

        return $prescription->load('items');
    }

    /**
     * The prescription behind a bill being cancelled.
     *
     * Deliberately WITHOUT the dispensable check. A prescription that reads
     * `dispensed` is exactly the one a cancellation has to walk back, and
     * refusing it here would leave the patient with nothing and the record
     * saying they had everything.
     */
    public function lockForReversal(?int $prescriptionId): ?Prescription
    {
        if (! $prescriptionId) {
            return null;
        }

        return Prescription::query()
            ->whereKey($prescriptionId)
            ->lockForUpdate()
            ->first()
            ?->load('items');
    }

    /**
     * Which prescribed line a sale line fulfils.
     *
     * An explicit `prescription_item_id` wins — a doctor may write the same
     * medicine twice at two doses, and only the counter knows which of them
     * is being handed over. Otherwise it is matched on the medicine, which is
     * right for every ordinary prescription and is what the screens send.
     *
     * Null is a legitimate answer: a pharmacist adding something off-script
     * to a prescription bill is selling it, not dispensing it, and it must
     * not credit a line nobody wrote.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $alreadyUsed  lines claimed by earlier items on this bill
     */
    public function match(Prescription $prescription, array $input, array $alreadyUsed = []): ?int
    {
        if (! empty($input['prescription_item_id'])) {
            $named = (int) $input['prescription_item_id'];

            $line = $prescription->items->firstWhere('id', $named);

            if (! $line) {
                throw StockConflict::because(
                    "That line is not on {$prescription->prescription_number}. Reload the prescription."
                );
            }

            return $named;
        }

        return $prescription->items
            ->reject(fn (PrescriptionItem $item) => $item->status === PrescriptionItem::CANCELLED)
            ->reject(fn (PrescriptionItem $item) => in_array($item->id, $alreadyUsed, true))
            ->firstWhere('medicine_id', (int) $input['medicine_id'])
            ?->id;
    }

    /**
     * Credit what was handed over, then ask the visit what it means.
     *
     * @param  Collection<int, PharmacySaleItem>  $saleItems
     */
    public function apply(Prescription $prescription, Collection $saleItems): void
    {
        $this->move($prescription, $saleItems, 1);
    }

    /**
     * Take it back off, because the bill was cancelled.
     *
     * The stock has already gone back on the shelf as correcting ledger rows;
     * this is the prescription's side of the same undo. Without it a
     * cancelled dispensing would leave the prescription reading `dispensed`
     * and the patient with nothing.
     *
     * @param  Collection<int, PharmacySaleItem>  $saleItems
     */
    public function reverse(Prescription $prescription, Collection $saleItems): void
    {
        $this->move($prescription, $saleItems, -1);
    }

    /**
     * @param  Collection<int, PharmacySaleItem>  $saleItems
     * @param  int  $sign  1 to dispense, -1 to take back
     */
    private function move(Prescription $prescription, Collection $saleItems, int $sign): void
    {
        $byLine = $saleItems
            ->filter(fn (PharmacySaleItem $item) => $item->prescription_item_id !== null)
            ->groupBy('prescription_item_id');

        if ($byLine->isEmpty() && $sign > 0) {
            // Nothing on this bill answers the prescription — an off-script
            // sale that happens to carry one. The prescription is untouched,
            // and so is the visit's pharmacy state.
            return;
        }

        foreach ($byLine as $lineId => $sold) {
            $line = PrescriptionItem::query()->whereKey($lineId)->lockForUpdate()->first();

            if (! $line) {
                continue;
            }

            $moved = (int) $sold->sum('quantity') * $sign;
            $now = max(0, (int) $line->dispensed_quantity + $moved);

            $ceiling = (int) $line->prescribed_quantity + (int) $line->over_dispense_allowance;

            /*
             * Caught here rather than by the database's CHECK, which would
             * surface as a 500 with a constraint name in it. A counter
             * handing over more than was prescribed needs a sentence it can
             * act on, and an allowance somebody senior can raise.
             */
            if ($line->prescribed_quantity !== null && $now > $ceiling) {
                throw StockConflict::because(sprintf(
                    '%s: %d prescribed, %d already dispensed, and this bill would make it %d.',
                    $line->medicine_name_snapshot,
                    $line->prescribed_quantity,
                    $line->dispensed_quantity,
                    $now,
                ));
            }

            $line->forceFill([
                'dispensed_quantity' => $now,
                'status' => $this->lineStatus($line, $now),
            ])->save();
        }

        $this->restate($prescription);
        $this->tellTheVisit($prescription);
    }

    /** Where one prescribed line stands, given what has gone out against it. */
    private function lineStatus(PrescriptionItem $line, int $dispensed): string
    {
        if ($line->status === PrescriptionItem::CANCELLED) {
            return PrescriptionItem::CANCELLED;
        }

        if ($dispensed <= 0) {
            return PrescriptionItem::PENDING;
        }

        return $line->prescribed_quantity !== null && $dispensed >= (int) $line->prescribed_quantity
            ? PrescriptionItem::DISPENSED
            : PrescriptionItem::PARTIALLY_DISPENSED;
    }

    /**
     * The prescription is whatever its lines add up to.
     *
     * Derived rather than stepped, so a cancelled bill walks the status back
     * down the same path it walked up — there is no sequence of dispensings
     * and reversals that can leave the header disagreeing with its lines.
     *
     * A line with no `prescribed_quantity` is an unlisted medicine the
     * counter cannot dispense against; it is excluded from the denominator
     * rather than holding the prescription open forever.
     */
    private function restate(Prescription $prescription): void
    {
        $lines = PrescriptionItem::query()
            ->where('prescription_id', $prescription->id)
            ->where('status', '<>', PrescriptionItem::CANCELLED)
            ->get();

        $dispensable = $lines->filter(fn (PrescriptionItem $line) => $line->prescribed_quantity !== null);

        $anyOut = $lines->contains(fn (PrescriptionItem $line) => (int) $line->dispensed_quantity > 0);

        $allOut = $dispensable->isNotEmpty() && $dispensable->every(
            fn (PrescriptionItem $line) => (int) $line->dispensed_quantity >= (int) $line->prescribed_quantity
        );

        $status = match (true) {
            $allOut => Prescription::DISPENSED,
            $anyOut => Prescription::PARTIALLY_DISPENSED,
            default => Prescription::ISSUED,
        };

        if ($status !== $prescription->status) {
            $prescription->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Report upward. The visit decides what it means.
     *
     * Fully dispensed may be the last thing the visit was waiting on — or it
     * may hand it straight to the till, if the bill went out unpaid. Neither
     * conclusion is the pharmacy's to draw.
     */
    private function tellTheVisit(Prescription $prescription): void
    {
        if (! $prescription->appointment_id) {
            return;
        }

        $appointment = Appointment::on('organization')->find($prescription->appointment_id);

        if ($appointment) {
            $this->visits->refresh($appointment);
        }
    }

    /** Only a signed, live, in-date prescription can be dispensed against. */
    private function assertDispensable(Prescription $prescription): void
    {
        if ($prescription->isDraft()) {
            throw StockConflict::because(
                "{$prescription->prescription_number} has not been issued yet. The doctor signs it off first."
            );
        }

        if ($prescription->status === Prescription::CANCELLED) {
            throw StockConflict::because("{$prescription->prescription_number} was cancelled.");
        }

        if ($prescription->status === Prescription::EXPIRED) {
            throw StockConflict::because(
                "{$prescription->prescription_number} expired on "
                .$prescription->valid_until?->format('d M Y').'. The patient needs a new one.'
            );
        }

        if ($prescription->status === Prescription::DISPENSED) {
            throw StockConflict::because(
                "{$prescription->prescription_number} has already been dispensed in full."
            );
        }
    }
}
