<?php

namespace Tests\Support;

use App\Services\Clinic\ClinicEvent;
use App\Services\Documents\DocumentAutomation;
use App\Services\Documents\DocumentRules;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Support\Facades\DB;

/**
 * Automation that records rather than acts, and fails on request.
 *
 * A subclass rather than a mock so it keeps the real signature — if
 * `handle()` ever changes shape, this stops compiling instead of silently
 * asserting against a method the dispatcher no longer calls.
 *
 * Bind it before anything resolves the service under test, so the
 * dispatcher that service receives is built around this:
 *
 *     $this->app->instance(DocumentAutomation::class, $recorder);
 */
class RecordingDocumentAutomation extends DocumentAutomation
{
    /** @var list<string> event keys, in the order they arrived */
    public array $seen = [];

    /** @var list<ClinicEvent> */
    public array $events = [];

    /**
     * The tenant connection's transaction depth at the moment each event
     * arrived. Zero is the whole promise of after-commit: anything else means
     * automation ran while the money could still roll back.
     *
     * @var list<int>
     */
    public array $levels = [];

    public ?\Throwable $fail = null;

    public function __construct()
    {
        parent::__construct(new DocumentRules);
    }

    public function handle(ClinicEvent $event): void
    {
        $this->seen[] = $event->key;
        $this->events[] = $event;
        $this->levels[] = DB::connection(TenantConnectionService::CONNECTION)->transactionLevel();

        if ($this->fail !== null) {
            throw $this->fail;
        }
    }

    public function forget(): void
    {
        $this->seen = [];
        $this->events = [];
        $this->levels = [];
    }

    /** @return list<ClinicEvent> */
    public function of(string $key): array
    {
        return array_values(array_filter($this->events, fn (ClinicEvent $event) => $event->key === $key));
    }
}
