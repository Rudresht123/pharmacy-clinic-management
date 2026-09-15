<?php

namespace App\Policies;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\User;
use App\Policies\Concerns\AsksAboutBranch;

/**
 * May this person act on this batch? Asked about the branch of the store
 * the batch is in — a batch has no branch of its own.
 */
class MedicineBatchPolicy
{
    use AsksAboutBranch;

    public function view(User $user, MedicineBatch $batch): bool
    {
        return $this->mayAt($user, $batch->store->location_id, 'pharmacy.view');
    }

    /** Block, recall or unblock. */
    public function changeStatus(User $user, MedicineBatch $batch): bool
    {
        return $this->mayAt($user, $batch->store->location_id, 'pharmacy.batches');
    }

    /** Removing an empty batch. */
    public function delete(User $user, MedicineBatch $batch): bool
    {
        return $this->mayAt($user, $batch->store->location_id, 'pharmacy.batches');
    }

    public function restore(User $user, MedicineBatch $batch): bool
    {
        return $this->mayAt($user, $batch->store->location_id, 'pharmacy.restore');
    }
}
