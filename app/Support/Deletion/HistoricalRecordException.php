<?php

namespace App\Support\Deletion;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Somebody tried to erase a record that other records, or the audit trail,
 * still point at.
 *
 * A 409 rather than a 403: the person may well be allowed to remove things —
 * it is this record's history that makes erasing it impossible. The JSON
 * error contract in bootstrap/app.php renders any HttpException, so nothing
 * there needs to know about this class.
 */
class HistoricalRecordException extends ConflictHttpException
{
    public function __construct(string $message = 'This record has history and cannot be permanently deleted. Remove it instead; it can be restored later.')
    {
        parent::__construct($message);
    }
}
