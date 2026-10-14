<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Tenant\MessageLog;
use App\Services\Tenancy\TenantConnectionService;
use Throwable;

/**
 * Where WhatsApp and email sending meets the subscription — three calls, made
 * by WhatsAppManager, EmailManager and the delivery-receipt recorder:
 *
 *   gate()     before queueing: may this go at all? (quota, overage, lock)
 *   charge()   once the provider ACCEPTED it: one usage record per message
 *              log row, however many times the job ran
 *   failed()   the provider later said it failed: reverse the charge, unless
 *              the rate bills failures
 *
 * Test sends (`is_test`) are never gated or charged: proving a connection is
 * not clinic traffic. Metering never breaks a send — a ledger error is
 * reported and the message still goes; the reconciliation report is how a gap
 * is found.
 */
class CommunicationMetering
{
    public function __construct(
        private readonly UsageMeter $meter,
    ) {}

    /** Null when it may go; otherwise why not, for the log row. */
    public function gate(Organization $organization, string $channel, array $extra = []): ?string
    {
        if (! empty($extra['is_test']) || ! UsageMeter::isMetered($channel)) {
            return null;
        }

        $authorization = $this->meter->authorize($organization, $channel);

        return $authorization->allowed ? null : $authorization->message;
    }

    public function charge(Organization $organization, MessageLog $log): void
    {
        if ($log->is_test || ! in_array($log->status, [MessageLog::SENT, MessageLog::DELIVERED, MessageLog::READ], true)) {
            return;
        }

        try {
            $this->meter->record(
                $organization,
                $log->channel,
                'message_log',
                (int) $log->id,
                1,
                provider: $log->provider,
                category: $log->template?->category,
                meta: ['template' => $log->template_name],
                occurredAt: $log->sent_at,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(Organization $organization, MessageLog $log): void
    {
        if ($log->is_test) {
            return;
        }

        try {
            $this->meter->reverse($organization, $log->channel, 'message_log', (int) $log->id, (string) ($log->failure_reason ?? 'Delivery failed.'));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The organization whose database is connected now — for code that only has the tenant. */
    public function currentOrganization(): ?Organization
    {
        $request = app()->bound('request') ? request()->attributes->get('tenant.organization') : null;

        if ($request instanceof Organization) {
            return $request;
        }

        $database = app(TenantConnectionService::class)->current();

        return $database === null ? null : Organization::query()->where('database_name', $database)->first();
    }
}
