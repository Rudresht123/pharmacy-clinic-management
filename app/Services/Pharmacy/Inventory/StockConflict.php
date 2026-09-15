<?php

namespace App\Services\Pharmacy\Inventory;

use App\Models\Tenant\MedicineBatch;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The request was valid, but the stock has moved on — a 409 whose message
 * says what is actually there, so the person can choose again.
 *
 * Rendered by the JSON error contract in bootstrap/app.php like any other
 * HttpException, so nothing there needs to know about it.
 */
class StockConflict extends ConflictHttpException
{
    public static function insufficient(MedicineBatch $batch, int $asked): self
    {
        return new self(sprintf(
            'Batch %s has %d left; %d %s asked for.',
            $batch->batch_number,
            $batch->quantity_available,
            $asked,
            $asked === 1 ? 'was' : 'were',
        ));
    }

    public static function unusable(MedicineBatch $batch): self
    {
        $why = $batch->isPastExpiry() ? 'has expired' : "is {$batch->status}";

        return new self("Batch {$batch->batch_number} {$why}, so no stock can leave it.");
    }

    public static function because(string $message): self
    {
        return new self($message);
    }
}
