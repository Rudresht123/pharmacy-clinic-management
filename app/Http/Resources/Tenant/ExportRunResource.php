<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\ExportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One export run as the tray shows it: what it is, how far it has got and
 * about how long is left, then whether the file is ready.
 *
 * @mixin ExportRun
 */
class ExportRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = $this->total_rows;
        $done = min($this->processed_rows, $total);

        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'format' => $this->format,
            'title' => $this->title,
            'noun' => $this->noun,
            'status' => $this->shownStatus(),
            'total_rows' => $total,
            'processed_rows' => $done,
            'percent' => match (true) {
                $this->status === ExportRun::COMPLETED => 100,
                $total <= 0 => 0,
                // 99 at most until the file is closed: every row read is not the file written.
                default => min(99, (int) floor($done * 100 / $total)),
            },
            'seconds_left' => $this->secondsLeft(),
            // Every row is read and the file is being closed (zipped, or the PDF finished).
            'finishing' => $this->status === ExportRun::RUNNING && $total > 0 && $done >= $total,
            'file_name' => $this->file_name,
            'file_size' => $this->isReady() ? $this->file_size : null,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'downloaded_at' => $this->downloaded_at?->toIso8601String(),
        ];
    }
}
