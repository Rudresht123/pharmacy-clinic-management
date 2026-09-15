<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;

/**
 * A batch's standing: blocked, recalled, unblocked, removed, restored.
 *
 * None of these moves stock. A blocked or recalled batch keeps its quantity
 * on the books — it is still physically on the shelf — and simply stops
 * being dispensable or transferable until someone unblocks it or writes it
 * off with an adjustment.
 */
class BatchService
{
    /** Where each status may be reached from. */
    private const FROM = [
        MedicineBatch::BLOCKED => [MedicineBatch::ACTIVE, MedicineBatch::EXHAUSTED],
        MedicineBatch::RECALLED => [MedicineBatch::ACTIVE, MedicineBatch::EXHAUSTED, MedicineBatch::BLOCKED],
        MedicineBatch::ACTIVE => [MedicineBatch::BLOCKED, MedicineBatch::RECALLED],
    ];

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly Lots $lots,
    ) {}

    /** Block, recall, or unblock (`active`), with a reason. */
    public function changeStatus(MedicineBatch $batch, string $to, string $reason): MedicineBatch
    {
        return $batch->getConnection()->transaction(function () use ($batch, $to, $reason) {
            $locked = $this->stock->lock([$batch->id])->first();

            if (! $locked) {
                throw StockConflict::because("Batch {$batch->batch_number} has been removed.");
            }

            if (! in_array($locked->status, self::FROM[$to] ?? [], true)) {
                throw StockConflict::because(sprintf(
                    'Batch %s is %s; it cannot be %s.',
                    $locked->batch_number,
                    $locked->status,
                    ['blocked' => 'blocked', 'recalled' => 'recalled', 'active' => 'unblocked'][$to] ?? $to,
                ));
            }

            $actor = Auth::guard('web')->user();

            if ($to === MedicineBatch::ACTIVE) {
                // Unblocked into whatever the stock and the calendar say it is.
                $locked->forceFill([
                    'status' => match (true) {
                        $locked->quantity_available === 0 => MedicineBatch::EXHAUSTED,
                        $locked->isPastExpiry() => MedicineBatch::EXPIRED,
                        default => MedicineBatch::ACTIVE,
                    },
                    'blocked_reason' => null,
                    'blocked_by' => null,
                    'blocked_at' => null,
                ]);
            } else {
                $locked->forceFill([
                    'status' => $to,
                    'blocked_reason' => $reason,
                    'blocked_by' => $actor instanceof User ? $actor->id : null,
                    'blocked_at' => now(),
                ]);
            }

            $locked->withHistoryNote($reason)->save();

            return $locked;
        });
    }

    /** Only an empty batch is removed; one with stock is blocked instead. */
    public function remove(MedicineBatch $batch, string $reason): void
    {
        $batch->getConnection()->transaction(function () use ($batch, $reason) {
            $locked = $this->stock->lock([$batch->id])->first();

            if ($locked && $locked->quantity_available > 0) {
                throw StockConflict::because(sprintf(
                    'Batch %s still holds %d. Block it, or adjust the stock out, before removing it.',
                    $locked->batch_number,
                    $locked->quantity_available,
                ));
            }

            $locked?->deleteWithReason($reason);
        });
    }

    public function restore(MedicineBatch $batch, string $reason): MedicineBatch
    {
        if (! $batch->trashed()) {
            throw StockConflict::because("Batch {$batch->batch_number} has not been removed.");
        }

        if ($this->lots->find($batch->pharmacy_store_id, $batch->medicine_id, $batch->batch_number)) {
            throw StockConflict::because("Another batch {$batch->batch_number} is in this store now. Remove or rename it first.");
        }

        $batch->getConnection()->transaction(fn () => $batch->restoreWithReason($reason));

        return $batch->refresh();
    }
}
