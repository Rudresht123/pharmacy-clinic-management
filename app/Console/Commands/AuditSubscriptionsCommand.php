<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Platform\Subscription;
use App\Services\Subscriptions\SubscriptionClock;
use Illuminate\Console\Command;

/**
 * Read-only: which organizations have no subscription, and which are — or
 * are about to be — locked.
 *
 * Run straight after a migration or a bulk change, before anybody notices
 * from the other side. Changes nothing; exits non-zero when it finds an
 * organization with no current subscription, since that should not exist.
 */
class AuditSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:audit {--within=7 : Also list subscriptions locking within this many days}';

    protected $description = 'List organizations without a subscription, and locked or soon-to-lock subscriptions';

    public function handle(SubscriptionClock $clock): int
    {
        $missing = Organization::query()
            ->whereDoesntHave('subscriptions', fn ($q) => $q->where('is_current', true))
            ->orderBy('id')
            ->get(['id', 'uuid', 'organization_name', 'status']);

        $this->info("Organizations without a current subscription: {$missing->count()}");

        foreach ($missing as $organization) {
            $this->line("  #{$organization->id} {$organization->organization_name} ({$organization->status})");
        }

        $within = now()->addDays(max(0, (int) $this->option('within')));
        $locked = [];
        $soon = [];

        Subscription::query()->where('is_current', true)->with('organization')->orderBy('id')
            ->each(function (Subscription $subscription) use ($clock, $within, &$locked, &$soon) {
                $name = $subscription->organization?->organization_name ?? "#{$subscription->organization_id}";

                if ($clock->isLocked($subscription)) {
                    $locked[] = [$name, $clock->status($subscription), optional($clock->locksAt($subscription))->toDateTimeString()];

                    return;
                }

                $locksAt = $clock->locksAt($subscription);

                if ($locksAt !== null && $locksAt <= $within) {
                    $soon[] = [$name, $clock->status($subscription), $locksAt->toDateTimeString()];
                }
            });

        $this->info('Locked now: '.count($locked));

        if ($locked !== []) {
            $this->table(['Organization', 'Status', 'Locked at'], $locked);
        }

        $this->info('Locking within '.$this->option('within').' day(s): '.count($soon));

        if ($soon !== []) {
            $this->table(['Organization', 'Status', 'Locks at'], $soon);
        }

        return $missing->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
