<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is subscribed to what, until when — and everything that happened to it.
 *
 * MASTER DATABASE. A subscription is a contract between the platform and an
 * organization; the tenant's own database never holds it, so nothing done
 * inside a clinic can extend its own term or raise its own limits.
 *
 * Two locks, deliberately separate:
 *
 *   organizations.status = suspended   a platform/security decision, made by a
 *                                      person, lifted by a person. Blocks
 *                                      sign-in altogether.
 *   subscriptions.locked_at            the commercial lock: the term and its
 *                                      grace ran out. Lifted by a verified
 *                                      renewal — which never touches the
 *                                      first, so paying cannot undo a
 *                                      security suspension.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per contract. A plan change closes this one (`is_current`
         * off, `replaced_by_id` set) and opens another, so the old terms stay
         * readable exactly as they were agreed.
         *
         * `snapshot` is the agreement: the price, modules, limits, quotas and
         * features as they stood when it was bought or last renewed. A Super
         * Admin repricing the plan afterwards changes the next renewal, never
         * this one.
         *
         * `status` is what the lifecycle last PERSISTED. Access is decided from
         * the dates (EntitlementService::effectiveStatus), so a scheduler that
         * runs late cannot keep an expired clinic open; the column catches up
         * when it runs.
         */
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();

            $table->string('status', 20);
            $table->string('billing_cycle', 20);
            $table->boolean('is_current')->default(true);

            $table->timestamp('started_at');
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // NULL on a complimentary subscription: nothing can expire it.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamp('locked_at')->nullable();
            $table->string('lock_reason', 30)->nullable();

            // A billing suspension a Super Admin placed (a dispute, a bounced
            // cheque). Separate from the term running out, and from the
            // organization-level security suspension.
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();

            $table->string('payment_status', 20)->default('none');

            $table->json('snapshot');

            // Bumped on every change to what this subscription entitles, so a
            // cached entitlement keyed on it is invalid the moment it changes.
            $table->unsignedInteger('entitlements_version')->default(1);

            // A plan change queued for the end of the period (a downgrade).
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('pending_billing_cycle', 20)->nullable();

            $table->foreignId('replaced_by_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('grace_ends_at');
        });

        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_rules_check CHECK ('
            ."status IN ('trial', 'active', 'past_due', 'grace', 'expired', 'suspended', 'cancelled') AND "
            ."billing_cycle IN ('monthly', 'annual', 'complimentary') AND "
            ."payment_status IN ('none', 'pending', 'paid', 'failed', 'waived'))"
        );

        // One live contract per organization. Everything else is history.
        DB::statement(
            'CREATE UNIQUE INDEX subscriptions_one_current ON subscriptions (organization_id) WHERE is_current'
        );

        /*
         * What was added on top of the plan, at what price.
         *
         *   module    a module bought as an add-on (ref = module key)
         *   addon     capacity, credits or a feature (ref = addon key)
         *   override  a Super Admin raising a limit or granting a feature
         *             without charge — always with a note and who did it
         */
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('ref', 60);

            // What it grants: for a resource/credit add-on, the units it adds
            // (quantity × the add-on's unit_quantity, copied at purchase).
            $table->string('target', 40)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('grants')->nullable();

            $table->unsignedBigInteger('unit_price')->default(0);
            $table->string('billing', 20)->default('recurring');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('active');

            $table->string('note', 255)->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE subscription_items ADD CONSTRAINT subscription_items_rules_check CHECK ('
            ."type IN ('module', 'addon', 'override') AND "
            ."billing IN ('recurring', 'one_time', 'free') AND "
            ."status IN ('active', 'pending', 'ended', 'cancelled'))"
        );

        /*
         * The subscription's history, append-only.
         *
         * `dedupe_key` is what makes the scheduler safe to run twice: the
         * same transition for the same period writes the same key, and the
         * unique index turns the second write into nothing.
         */
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            // platform (a Super Admin), tenant (an owner renewing), system
            // (the scheduler, a webhook).
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();

            $table->string('reason', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        /*
         * Each expiry reminder, once per channel per term.
         *
         * The unique key is the whole deduplication: a scheduler that runs
         * every hour asks to send "7 days left" every hour, and only the first
         * ask finds no row.
         */
        Schema::create('subscription_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('kind', 40);
            $table->timestamp('period_end');
            $table->string('channel', 20);

            $table->string('recipient', 191)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'kind', 'period_end', 'channel'], 'subscription_reminders_once');
        });

        /*
         * This period's allowance of each quota, and how much is spent.
         *
         * `included` NULL means unlimited (the Complimentary plan). Spending is
         * a single conditional UPDATE — `used = used + n WHERE remaining >= n`
         * — so two sends racing for the last credit cannot both get it.
         */
        Schema::create('usage_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('quota_key', 40);
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->unsignedBigInteger('included')->nullable();
            $table->unsignedBigInteger('purchased')->default(0);
            $table->unsignedBigInteger('granted')->default(0);
            $table->unsignedBigInteger('used')->default(0);

            // Units used past the allowance, charged on the next invoice.
            $table->unsignedBigInteger('overage')->default(0);
            $table->unsignedBigInteger('overage_charge_paise')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'quota_key', 'period_start']);
        });

        /*
         * The usage ledger: one row per billable event, never two.
         *
         * `idempotency_key` names the event itself —
         * `{organization}:{channel}:{reference_type}:{reference_id}` — so a
         * retried job, a duplicate delivery callback or a second "end call"
         * all arrive at the same key and are refused by the unique index.
         *
         * A charge is never deleted. A send that was charged and then failed
         * gets a second row, `status = reversed`, pointing at the first.
         */
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('channel', 20);
            $table->string('provider', 40)->nullable();
            $table->string('category', 30)->nullable();

            // The tenant row this charge is for (message_logs, video_calls).
            // An id in another database: not a foreign key, and never joined.
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');

            $table->unsignedInteger('quantity');
            $table->string('unit', 20);

            $table->foreignId('usage_rate_id')->nullable()->constrained('usage_rates')->nullOnDelete();
            $table->unsignedBigInteger('provider_cost_e4')->default(0);
            $table->unsignedBigInteger('price_e4')->default(0);

            $table->unsignedInteger('from_quota')->default(0);
            $table->unsignedInteger('overage')->default(0);
            $table->unsignedBigInteger('charge_paise')->default(0);

            $table->string('status', 20)->default('recorded');
            $table->foreignId('reverses_id')->nullable()->constrained('usage_records')->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->string('idempotency_key', 191)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'channel', 'occurred_at']);
        });

        DB::statement(
            "ALTER TABLE usage_records ADD CONSTRAINT usage_records_status_check CHECK (status IN ('recorded', 'reversed', 'reconciled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('usage_balances');
        Schema::dropIfExists('subscription_reminder_deliveries');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
