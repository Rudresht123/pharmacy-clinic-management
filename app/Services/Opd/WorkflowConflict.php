<?php

namespace App\Services\Opd;

use RuntimeException;

/**
 * A workflow move that the state does not allow.
 *
 * Its own class rather than a bare RuntimeException so a controller can tell
 * "this desk is a step behind" from a genuine fault, and answer 422 with the
 * message rather than 500 with none.
 *
 * Every message here is written to be read by whoever clicked, standing at a
 * counter with somebody in front of them: it says what happened instead of
 * what they wanted, and who did it where that is known. "Invalid state
 * transition" is not something a receptionist can act on.
 */
class WorkflowConflict extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
