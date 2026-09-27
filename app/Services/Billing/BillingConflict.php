<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * A billing action the invoice does not allow.
 *
 * Same shape as WorkflowConflict / StockConflict: its own class so a controller
 * can answer 422 with the message rather than 500, and every message here is
 * written for whoever clicked — "That invoice is already cancelled" beats
 * "invalid transition".
 */
class BillingConflict extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
