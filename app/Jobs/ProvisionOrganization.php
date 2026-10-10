<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Services\Platform\OrganizationProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Builds a new organization's tenant: database, schema, roles, owner login,
 * welcome email — the steps OrganizationProvisioningService records one row
 * at a time.
 *
 * ON A QUEUE because the schema alone is a hundred migrations and growing.
 * Run inside the request that created the organization, it outlived the web
 * server's timeout — and an administrator watching a spinner had no way to
 * tell a slow migration from a hung one. The request now returns at once and
 * the organization's page shows each step, and each migration, as it lands.
 *
 * Its own queue (`provisioning`) on its own connection, whose lease outlasts
 * the job: a job still running when the default connection's 90-second
 * `retry_after` expired would be handed to a second worker.
 */
class ProvisionOrganization implements ShouldQueue
{
    use Queueable;

    /** A whole schema in one go: minutes, not the worker's default sixty seconds. */
    public int $timeout = 1800;

    /*
     * One attempt. A failed provisioning is retried by an administrator, and
     * resumes from the step that failed — never silently by the queue, which
     * would only fail the same way again.
     */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $requestedBy = null,
    ) {
        $this->onQueue('provisioning');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Two workers must never build the same tenant at the same time.
        return [
            (new WithoutOverlapping((string) $this->organizationId))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function handle(OrganizationProvisioningService $provisioning): void
    {
        $organization = Organization::query()->find($this->organizationId);

        // Deleted since it was queued, or already finished by an earlier run.
        if (! $organization || ! $provisioning->isUnfinished($organization)) {
            return;
        }

        $provisioning->runProvisioningSteps($organization, $this->requestedBy);
    }

    /**
     * The worker gave up — an exception, a timeout, a killed process. A step
     * that threw has already recorded itself; this catches the ones that never
     * got the chance, so the page stops saying "in progress" for ever.
     */
    public function failed(?Throwable $exception): void
    {
        app(OrganizationProvisioningService::class)->markFailed(
            $this->organizationId,
            $exception?->getMessage(),
            $this->requestedBy,
        );
    }
}
