<?php

namespace App\Services\Laboratory;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use App\Models\Tenant\User;
use App\Services\Opd\VisitWorkflow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Raising a lab order, working it, and finishing it.
 *
 * Who may do each is the Policy's question; this answers whether the order is
 * in a state that allows it, and it is the only thing that writes a lab
 * order's status.
 *
 * EVERY COMPLETION ASKS THE VISIT AGAIN. A finished lab order may be the last
 * thing a visit was waiting on, and it is not this service's business to
 * decide that — it reports what happened and VisitWorkflow works out what it
 * means. The same contract the pharmacy has.
 */
class LabOrders
{
    public function __construct(
        private readonly VisitWorkflow $visits,
    ) {}

    /**
     * The doctor orders tests.
     *
     * A visit can have more than one order, unlike its prescription: bloods
     * ordered at the start and a scan ordered after reading them are two
     * clinical acts, each with its own author and moment.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Appointment $appointment, array $data): LabOrder
    {
        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            throw new ConflictHttpException('This visit did not happen, so there is nothing to order against.');
        }

        return $this->transaction(function () use ($appointment, $data) {
            // The write-up the order belongs to, made if the doctor has not
            // typed anything yet — the same rule the prescription follows.
            $consultation = Consultation::on('organization')->firstOrCreate(
                ['appointment_id' => $appointment->id],
                ['customer_id' => $appointment->customer_id, 'doctor_id' => $appointment->doctor_id],
            );

            $order = LabOrder::on('organization')->create([
                'location_id' => $appointment->location_id,
                'customer_id' => $appointment->customer_id,
                'doctor_id' => $appointment->doctor_id,
                'appointment_id' => $appointment->id,
                'consultation_id' => $consultation->id,
                'order_date' => now()->toDateString(),
                'clinical_notes' => $data['clinical_notes'] ?? null,
            ]);

            // Reads back the number the database gave it.
            $order->refresh();

            $this->syncItems($order, $data['items'] ?? []);

            /*
             * Asked straight away, because a doctor can order tests after
             * completing the consultation — reading a result and wanting
             * another is ordinary — and that has to pull the visit back out
             * of completed and into awaiting_lab.
             */
            $this->visits->refresh($appointment);

            return $order->load('items');
        });
    }

    /**
     * Change a pending order's tests.
     *
     * Only while nobody has picked it up. Once a technician is working, the
     * list they are working from must not change underneath them — a further
     * test is a further order.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LabOrder $order, array $data): LabOrder
    {
        return $this->transaction(function () use ($order, $data) {
            $locked = $this->lock($order);

            if ($locked->status !== LabOrder::PENDING) {
                throw new ConflictHttpException(
                    "{$locked->order_number} is {$locked->status} and can no longer be changed. Raise another order instead."
                );
            }

            if (array_key_exists('clinical_notes', $data)) {
                $locked->forceFill(['clinical_notes' => $data['clinical_notes']])->save();
            }

            if (array_key_exists('items', $data)) {
                $this->syncItems($locked, $data['items'] ?? []);
            }

            return $locked->load('items');
        });
    }

    /**
     * A technician picks the order up.
     *
     * Its own step rather than folding into completion: a lab with a backlog
     * and a lab that is slow look identical without it, and the desk can tell
     * a patient "they have started" rather than only "not yet".
     */
    public function start(LabOrder $order, ?User $actor = null): LabOrder
    {
        return $this->transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status === LabOrder::IN_PROGRESS) {
                throw new ConflictHttpException(
                    "{$locked->order_number} is already being worked on"
                    .($locked->starter?->name ? " by {$locked->starter->name}" : '').'.'
                );
            }

            $this->assertCanMoveTo($locked, LabOrder::IN_PROGRESS);

            $locked->forceFill([
                'status' => LabOrder::IN_PROGRESS,
                'started_at' => now(),
                'started_by' => $this->actorId($actor),
            ])->save();

            return $locked->load('items');
        });
    }

    /**
     * Record a reading against one test.
     *
     * Per line rather than per order, because they come back at their own
     * times: a CBC in twenty minutes and an LFT tomorrow are one order and
     * two results, and making the technician hold the first until the second
     * arrives is how results get written on paper instead.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(LabOrderItem $item, array $data): LabOrderItem
    {
        return $this->transaction(function () use ($item, $data) {
            $order = $this->lock($item->order);

            if (! in_array($order->status, [LabOrder::PENDING, LabOrder::IN_PROGRESS], true)) {
                throw new ConflictHttpException(
                    "{$order->order_number} is {$order->status}, so its results can no longer be changed."
                );
            }

            $fresh = LabOrderItem::on('organization')->whereKey($item->id)->firstOrFail();

            $fresh->forceFill([
                'result_value' => $data['result_value'],
                'result_unit' => $data['result_unit'] ?? null,
                'reference_range' => $data['reference_range'] ?? null,
                'is_abnormal' => (bool) ($data['is_abnormal'] ?? false),
                'notes' => $data['notes'] ?? null,
                'status' => LabOrderItem::COMPLETED,
                'completed_at' => now(),
            ])->save();

            return $fresh;
        });
    }

    /**
     * The lab is finished with this order.
     *
     * REFUSED WHILE A TEST IS STILL OPEN. "Complete" on an order with a blank
     * result is the lab telling the doctor something came back when nothing
     * did, and the visit would close on the strength of it.
     */
    public function complete(LabOrder $order, ?User $actor = null): LabOrder
    {
        return $this->transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status === LabOrder::COMPLETED) {
                throw new ConflictHttpException(
                    "{$locked->order_number} was already completed"
                    .($locked->completed_at ? ' at '.$locked->completed_at->format('g:i A') : '').'.'
                );
            }

            $this->assertCanMoveTo($locked, LabOrder::COMPLETED);

            $open = $locked->items()->where('status', LabOrderItem::PENDING)->pluck('test_name');

            if ($open->isNotEmpty()) {
                throw new ConflictHttpException(sprintf(
                    'No result yet for %s. Enter %s, or cancel the %s that will not be done.',
                    $open->implode(', '),
                    $open->count() === 1 ? 'it' : 'them',
                    $open->count() === 1 ? 'one' : 'ones',
                ));
            }

            $locked->forceFill([
                'status' => LabOrder::COMPLETED,
                'completed_at' => now(),
                'completed_by' => $this->actorId($actor),
            ])->save();

            $this->closeVisitIfReady($locked);

            return $locked->load('items');
        });
    }

    /** A test that will not be done — the sample was spoiled, or the patient left. */
    public function cancelItem(LabOrderItem $item, string $reason): LabOrderItem
    {
        return $this->transaction(function () use ($item, $reason) {
            $order = $this->lock($item->order);

            if (! in_array($order->status, [LabOrder::PENDING, LabOrder::IN_PROGRESS], true)) {
                throw new ConflictHttpException("{$order->order_number} is {$order->status}.");
            }

            $fresh = LabOrderItem::on('organization')->whereKey($item->id)->firstOrFail();

            $fresh->forceFill([
                'status' => LabOrderItem::CANCELLED,
                'notes' => $reason,
            ])->save();

            return $fresh;
        });
    }

    /** Withdraw the whole order, with a reason. */
    public function cancel(LabOrder $order, string $reason, ?User $actor = null): LabOrder
    {
        return $this->transaction(function () use ($order, $reason, $actor) {
            $locked = $this->lock($order);

            if ($locked->status === LabOrder::CANCELLED) {
                throw new ConflictHttpException("{$locked->order_number} is already cancelled.");
            }

            $this->assertCanMoveTo($locked, LabOrder::CANCELLED);

            $locked->forceFill([
                'status' => LabOrder::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $this->actorId($actor),
                'cancellation_reason' => $reason,
            ])->save();

            foreach ($locked->items()->where('status', LabOrderItem::PENDING)->get() as $item) {
                $item->forceFill(['status' => LabOrderItem::CANCELLED])->save();
            }

            // A withdrawn order is no longer work the visit is waiting on.
            $this->closeVisitIfReady($locked);

            return $locked->load('items');
        });
    }

    /**
     * Make the order's tests match the request.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncItems(LabOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => ['A lab order needs at least one test.'],
            ]);
        }

        $current = $order->items()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $item = ! empty($line['id'])
                ? ($current->get((int) $line['id']) ?? throw ValidationException::withMessages([
                    "items.{$index}.id" => ['That test is not on this order. Reload and try again.'],
                ]))
                : null;

            $values = [
                'test_name' => trim((string) $line['test_name']),
                'test_code' => $line['test_code'] ?? null,
                'specimen' => $line['specimen'] ?? null,
                'sort_order' => $index,
            ];

            if ($item) {
                $item->fill($values)->save();
            } else {
                $item = $order->items()->create($values);
            }

            $kept[] = $item->id;
        }

        foreach ($current->except($kept) as $gone) {
            $gone->deleteWithReason('Removed from the order');
        }
    }

    /**
     * Tell the visit that something changed here.
     *
     * Guarded on the visit still existing, because an order can outlive the
     * appointment being soft-deleted, and a missing visit is not a reason to
     * fail a result the lab has already produced.
     */
    private function closeVisitIfReady(LabOrder $order): void
    {
        $appointment = Appointment::on('organization')->find($order->appointment_id);

        if ($appointment) {
            $this->visits->refresh($appointment);
        }
    }

    private function assertCanMoveTo(LabOrder $order, string $status): void
    {
        if (! $order->canMoveTo($status)) {
            throw new ConflictHttpException(
                "A lab order that is {$order->status} cannot become {$status}."
            );
        }
    }

    private function actorId(?User $actor): ?int
    {
        $actor ??= Auth::guard('web')->user();

        return $actor instanceof User ? $actor->id : null;
    }

    private function lock(LabOrder $order): LabOrder
    {
        return LabOrder::on('organization')->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return (new LabOrder)->getConnection()->transaction($callback);
    }
}
