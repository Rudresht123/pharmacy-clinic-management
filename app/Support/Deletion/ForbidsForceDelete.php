<?php

namespace App\Support\Deletion;

/**
 * Permanent deletion is refused for this model.
 *
 * For records other records point at — a medicine that has been prescribed,
 * a batch that has moved stock. Soft deletion takes them out of every
 * working screen; erasing them would leave prescriptions and ledger rows
 * pointing at nothing, and remove exactly what an inspector asks to see.
 *
 * Hooks the `forceDeleting` model event, so it holds for forceDelete() on an
 * instance. It cannot see a mass delete run on the query builder, and
 * forceDeleteQuietly() skips events by design — neither is used for these
 * models, and the foreign keys (restrict) are the backstop.
 */
trait ForbidsForceDelete
{
    public static function bootForbidsForceDelete(): void
    {
        static::forceDeleting(function () {
            throw new HistoricalRecordException;
        });
    }
}
