<?php

namespace App\Services\Clinic;

use App\Services\Tenancy\TenantConnectionService;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Something that happened, and just enough to go and look it up.
 *
 * IDS, NOT DATA. The event carries references and nothing else — no patient
 * name, no amount, no diagnosis. Whatever reacts to this re-reads the record
 * from the tenant database, which means it can never act on a copy that went
 * stale between the commit and the reaction, and means nothing sensitive is
 * sitting in a log line or a serialized job payload.
 *
 * THE TENANT IS THE CONNECTION, not an id on the event.
 *
 * In a physical database-per-tenant system the organisation you are acting on
 * is whichever database the `organization` connection currently points at.
 * Carrying an `organization_id` beside that would be a second source of truth
 * for one fact, and the failure it invites is the worst one available here:
 * an id that says Apollo while the connection says Fortis. So the event
 * records the database it was raised against, and the dispatcher refuses to
 * run if that is no longer the database it would be writing to.
 */
final class ClinicEvent
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    private function __construct(
        public readonly string $key,
        public readonly string $database,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?int $customerId,
        public readonly ?int $locationId,
        public readonly Carbon $occurredAt,
        public readonly array $meta,
    ) {}

    /**
     * Raise an event about a record.
     *
     * The patient and the branch are read off the subject when it has them —
     * an invoice, a sale and a prescription all carry both — rather than
     * being passed in at every call site, where the fifth one eventually
     * passes the wrong one.
     *
     * @param  array<string, scalar|null>  $meta
     */
    public static function for(string $key, Model $subject, array $meta = []): self
    {
        if (! ClinicEvents::has($key)) {
            // A typo'd key is a rule that never fires, found months later by
            // somebody wondering why their receipts stopped. Fail at the call
            // site instead.
            throw new InvalidArgumentException("There is no clinic event called [{$key}].");
        }

        return new self(
            key: $key,
            database: self::currentDatabase(),
            subjectType: $subject->getMorphClass(),
            subjectId: (int) $subject->getKey(),
            customerId: self::intOrNull($subject->getAttribute('customer_id')),
            locationId: self::intOrNull($subject->getAttribute('location_id')),
            occurredAt: Carbon::now(),
            meta: $meta,
        );
    }

    /**
     * What makes this event this event, and not a second copy of it.
     *
     * A retried request, a double-tapped button and a replayed job all
     * produce the same string here. Nothing in Step 1 enforces it — there is
     * no document table to be unique against yet — but every call site
     * already produces it, so the constraint that lands in Step 3 goes on
     * `patient_documents` alone and no caller has to change.
     *
     * The payment id is in `meta` rather than the subject, because two
     * payments against one invoice are two events about the same invoice.
     */
    public function idempotencyKey(): string
    {
        $parts = [$this->key, $this->subjectType, (string) $this->subjectId];

        foreach (['payment_id', 'refund_id'] as $discriminator) {
            if (isset($this->meta[$discriminator])) {
                $parts[] = $discriminator.':'.$this->meta[$discriminator];
            }
        }

        return implode('|', $parts);
    }

    /** Safe for a log line: references only, never the record's contents. */
    public function forLog(): array
    {
        return [
            'event' => $this->key,
            'database' => $this->database,
            'subject' => $this->subjectType.'#'.$this->subjectId,
            'branch' => $this->locationId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }

    /** Whichever tenant database the organization connection points at now. */
    public static function currentDatabase(): string
    {
        return (string) Config::get(
            'database.connections.'.TenantConnectionService::CONNECTION.'.database',
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
