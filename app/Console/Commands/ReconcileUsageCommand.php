<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Platform\UsageRecord;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\VideoCall;
use App\Services\Subscriptions\UsageMeter;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Read-only: does the usage ledger agree with what each clinic actually sent?
 *
 * For a month, per organization and channel, it compares what the tenant's own
 * records say went out — messages the provider accepted (not tests), video
 * calls that were answered — with the net charges in the ledger (recorded
 * minus reversed). A difference is printed for a person to look at; nothing
 * is changed, because the right fix depends on why.
 *
 * Counts only: no message, patient or call detail leaves the tenant database.
 */
class ReconcileUsageCommand extends Command
{
    protected $signature = 'subscriptions:reconcile-usage
        {--month= : YYYY-MM, default last month}
        {--org= : One organization, by uuid}';

    protected $description = 'Compare the usage ledger with each tenant\'s sent messages and answered calls';

    public function handle(TenantConnectionService $tenants): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $from = $month->copy();
        $to = $month->copy()->endOfMonth();

        $rows = [];
        $mismatches = 0;

        $organizations = Organization::query()
            ->whereNotNull('database_name')
            ->whereIn('status', [Organization::ACTIVE, Organization::SUSPENDED])
            ->when($this->option('org'), fn ($q, $uuid) => $q->where('uuid', $uuid))
            ->orderBy('id')
            ->get();

        foreach ($organizations as $organization) {
            try {
                $sent = $tenants->run($organization->database_name, fn () => [
                    'whatsapp' => $this->messages('whatsapp', $from, $to),
                    'email' => $this->messages('email', $from, $to),
                    'video' => VideoCall::query()
                        ->where('status', VideoCall::ENDED)
                        ->whereNotNull('answered_at')
                        ->whereBetween('ended_at', [$from, $to])
                        ->count(),
                ]);
            } catch (Throwable $e) {
                $this->error("{$organization->organization_name}: {$e->getMessage()}");

                continue;
            }

            foreach ($sent as $channel => $count) {
                if (! UsageMeter::isMetered($channel)) {
                    continue;
                }

                $records = UsageRecord::query()
                    ->where('organization_id', $organization->id)
                    ->where('channel', $channel)
                    ->whereBetween('occurred_at', [$from, $to]);

                // Events, not units: a video call is one record of many minutes.
                $charged = (clone $records)->where('status', UsageRecord::RECORDED)->count()
                    - (clone $records)->where('status', UsageRecord::REVERSED)->count();

                if ($charged !== $count) {
                    $mismatches++;
                    $rows[] = [$organization->organization_name, $channel, $count, $charged, $count - $charged];
                }
            }
        }

        $this->info("Usage for {$month->format('F Y')}: ".($mismatches === 0 ? 'the ledger agrees with every tenant.' : "{$mismatches} mismatch(es)."));

        if ($rows !== []) {
            $this->table(['Organization', 'Channel', 'Sent', 'Charged', 'Difference'], $rows);
        }

        return self::SUCCESS;
    }

    private function messages(string $channel, Carbon $from, Carbon $to): int
    {
        return MessageLog::query()
            ->where('channel', $channel)
            ->where('is_test', false)
            ->whereIn('status', [MessageLog::SENT, MessageLog::DELIVERED, MessageLog::READ])
            ->whereBetween('sent_at', [$from, $to])
            ->count();
    }
}
