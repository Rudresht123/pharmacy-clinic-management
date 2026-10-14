<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every organization that exists today keeps working, exactly as it does.
 *
 * Subscriptions arrive with this release, and an organization without one
 * must not become an organization that is locked. So each one — whatever its
 * status, soft-deleted or not — is put on the Complimentary plan:
 *
 *   - no expiry and no grace date, so no date rule can ever lock it;
 *   - unlimited limits and quotas, so no branch, doctor or message is refused;
 *   - every feature (video consultation, the mobile app), so nothing that
 *     works today stops;
 *   - NO modules of its own. The modules a clinic has today are the rows in
 *     `organization_modules`, which this does not touch and which ModuleAccess
 *     still reads (as overrides). Including modules here would quietly hand
 *     every clinic modules it was never sold.
 *
 * A Super Admin moves an organization off Complimentary by assigning a plan.
 *
 * Safe to run again: an organization that already has a current subscription
 * is skipped, and the plan is upserted by slug.
 *
 * The DB facade, not models, for the reason every data migration here gives:
 * this file has to replay against the schema as it is today, long after the
 * models have moved on.
 */
return new class extends Migration
{
    private const SLUG = 'complimentary';

    /** Frozen copies of App\Support\Subscriptions\Entitlements, as of today. */
    private const LIMITS = ['branches', 'doctors', 'employees'];

    private const QUOTAS = ['whatsapp_messages', 'sms_messages', 'emails', 'video_minutes', 'video_sessions'];

    private const FEATURES = ['video_consultation', 'mobile_app'];

    public function up(): void
    {
        $now = now();

        DB::transaction(function () use ($now) {
            $planId = $this->complimentaryPlan($now);

            $organizations = DB::table('organizations')
                ->select('id', 'activated_at', 'created_at')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('subscriptions')
                        ->whereColumn('subscriptions.organization_id', 'organizations.id')
                        ->where('subscriptions.is_current', true);
                })
                ->orderBy('id')
                ->get();

            $snapshot = json_encode($this->snapshot($planId, $now));

            foreach ($organizations as $organization) {
                $startedAt = $organization->activated_at ?? $organization->created_at ?? $now;

                $subscriptionId = DB::table('subscriptions')->insertGetId([
                    'uuid' => (string) Str::ulid(),
                    'organization_id' => $organization->id,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'billing_cycle' => 'complimentary',
                    'is_current' => true,
                    'started_at' => $startedAt,
                    'current_period_start' => $startedAt,
                    'payment_status' => 'waived',
                    'snapshot' => $snapshot,
                    'entitlements_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('organizations')
                    ->where('id', $organization->id)
                    ->update(['plan_id' => $planId]);

                DB::table('subscription_events')->insert([
                    'subscription_id' => $subscriptionId,
                    'organization_id' => $organization->id,
                    'type' => 'created',
                    'to_status' => 'active',
                    'actor_type' => 'system',
                    'reason' => 'Complimentary at billing rollout: existing organizations keep their current access until a plan is assigned.',
                    'dedupe_key' => "rollout:{$organization->id}",
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        $planId = DB::table('plans')->where('slug', self::SLUG)->value('id');

        if ($planId === null) {
            return;
        }

        DB::table('organizations')->where('plan_id', $planId)->update(['plan_id' => null]);

        $ids = DB::table('subscriptions')->where('plan_id', $planId)->pluck('id');
        DB::table('subscription_events')->whereIn('subscription_id', $ids)->delete();
        DB::table('subscriptions')->whereIn('id', $ids)->delete();

        DB::table('plan_limits')->where('plan_id', $planId)->delete();
        DB::table('plans')->where('id', $planId)->delete();
    }

    private function complimentaryPlan($now): int
    {
        DB::table('plans')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'uuid' => DB::table('plans')->where('slug', self::SLUG)->value('uuid') ?? (string) Str::ulid(),
                'name' => 'Complimentary',
                'description' => 'Internal plan for organizations that existed before billing, or that a Super Admin keeps free. Never sold and never shown on the website.',
                'status' => 'published',
                'is_public' => false,
                'is_system' => true,
                'sort_order' => 0,
                'currency' => 'INR',
                'monthly_price' => 0,
                'annual_price' => 0,
                'trial_days' => 0,
                'grace_days' => 0,
                'features' => json_encode(self::FEATURES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $planId = (int) DB::table('plans')->where('slug', self::SLUG)->value('id');

        foreach ([...self::LIMITS, ...self::QUOTAS] as $key) {
            // NULL value = unlimited.
            DB::table('plan_limits')->updateOrInsert(
                ['plan_id' => $planId, 'key' => $key],
                ['value' => null, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        return $planId;
    }

    /** @return array<string, mixed> */
    private function snapshot(int $planId, $now): array
    {
        return [
            'plan' => ['id' => $planId, 'slug' => self::SLUG, 'name' => 'Complimentary'],
            'billing_cycle' => 'complimentary',
            'currency' => 'INR',
            'price' => 0,
            'modules' => [],
            'limits' => array_fill_keys(self::LIMITS, null),
            'quotas' => array_fill_keys(self::QUOTAS, null),
            'features' => self::FEATURES,
            'grace_days' => 0,
            'captured_at' => $now->toIso8601String(),
        ];
    }
};
