<?php

namespace App\Services\Queue;

use App\Models\Tenant\LabOrder;
use Illuminate\Support\Collection;

/**
 * What a laboratory's television shows.
 *
 * By order rather than by doctor: the bench works through everybody's patients
 * together. A patient is shown by their visit's token, or by the order number
 * where the order came without one. Never a name, and never which test.
 */
class LabQueue
{
    private const READY_SHOWN = 12;

    /**
     * @return array{waiting: list<string>, in_progress: list<string>, ready: list<string>}
     */
    public function state(int $locationId, string $date): array
    {
        $orders = LabOrder::query()
            ->with('appointment')
            ->where('location_id', $locationId)
            ->where('order_date', $date)
            ->where('status', '<>', LabOrder::CANCELLED)
            ->orderBy('created_at')
            ->get();

        return [
            'waiting' => $this->labels($orders->where('status', LabOrder::PENDING)),
            'in_progress' => $this->labels($orders->where('status', LabOrder::IN_PROGRESS)),
            'ready' => $this->labels(
                $orders->where('status', LabOrder::COMPLETED)->sortByDesc('completed_at')->take(self::READY_SHOWN)
            ),
        ];
    }

    /**
     * @param  Collection<int, LabOrder>  $orders
     * @return list<string>
     */
    private function labels(Collection $orders): array
    {
        return $orders
            ->map(fn (LabOrder $order) => $order->appointment?->tokenLabel() ?? (string) $order->order_number)
            ->unique()
            ->values()
            ->all();
    }
}
