<?php

namespace App\Support\Deletion;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Soft deletion that says who and why.
 *
 * For a model that uses SoftDeletes and has `deleted_by` and
 * `deletion_reason` columns. The row keeps the latest answer; the reason is
 * also written into the history entry for the delete (and the restore), so it
 * survives a restore that clears the row.
 *
 * Deletion is not a business status. A cancelled prescription is still a
 * prescription; a removed one is one nobody should be working from.
 */
trait HasDeletionAudit
{
    /**
     * Remove the record, recording who did it and why.
     *
     * @throws InvalidArgumentException when no reason is given
     */
    public function deleteWithReason(string $reason): bool
    {
        $reason = $this->deletionReason($reason);

        return $this->getConnection()->transaction(function () use ($reason) {
            // Quietly: this is part of the delete, not an edit of its own.
            $this->forceFill([
                'deleted_by' => Auth::guard('web')->id(),
                'deletion_reason' => $reason,
            ])->saveQuietly();

            $this->noteForHistory($reason);

            return (bool) $this->delete();
        });
    }

    /**
     * Bring the record back, recording why.
     *
     * Who may restore is the caller's question (a capability on the route);
     * this only makes sure the answer is on the record.
     *
     * @throws InvalidArgumentException when no reason is given
     */
    public function restoreWithReason(string $reason): bool
    {
        $reason = $this->deletionReason($reason);

        return $this->getConnection()->transaction(function () use ($reason) {
            // Saved together with deleted_at by restore(); both columns are
            // ignored by the history diff, so no separate "updated" entry.
            $this->forceFill([
                'deleted_by' => null,
                'deletion_reason' => null,
            ]);

            $this->noteForHistory($reason);

            return (bool) $this->restore();
        });
    }

    private function deletionReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return mb_substr($reason, 0, 500);
    }

    private function noteForHistory(string $reason): void
    {
        if (method_exists($this, 'withHistoryNote')) {
            $this->withHistoryNote($reason);
        }
    }
}
