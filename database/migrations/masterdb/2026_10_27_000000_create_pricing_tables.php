<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The price list: what the platform sells, and for how much.
 *
 * MASTER DATABASE, all of it. A price is a platform fact — a tenant database
 * must never be able to read, let alone write, what another clinic pays.
 *
 * MONEY IS INTEGER. Amounts are paise in a bigint: no float ever touches a
 * price, so ₹1,499.00 is 149900 and adding three of them is exact. Per-unit
 * usage rates go below a paisa (a WhatsApp utility message costs ₹0.1150), so
 * those are stored as ten-thousandths of a rupee — the `_e4` suffix — and a
 * charge is rounded to paise once, when it is recorded.
 *
 * Every row here is audited by RecordsHistory on its model, which is what the
 * brief calls the pricing audit log: who changed which price, from what, to
 * what, and when. History of the price itself is kept the other way round — a
 * module's price is never edited in place once it has applied, a new row
 * takes over from a date (see `module_prices`).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A plan is a bundle: a price per cycle, the modules it includes, the
         * limits and monthly quotas it grants, and the features it unlocks.
         *
         * `status` is the lifecycle Super Admin moves it through; only
         * `published` can be bought. `is_public` is separate on purpose: a
         * published plan can still be private (a negotiated Enterprise deal,
         * or the Complimentary plan) and is then never shown on the website.
         */
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();

            $table->string('status', 20)->default('draft');
            $table->boolean('is_public')->default(false);

            // The internal plan existing organizations sit on until a Super
            // Admin assigns a real one. Never sold, never shown, never deleted.
            $table->boolean('is_system')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('monthly_price')->default(0);
            $table->unsignedBigInteger('annual_price')->default(0);

            // Zero means "no trial on this plan" and "lock as soon as it ends".
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(0);

            // Feature keys this plan unlocks (video_consultation, mobile_app…)
            // — see App\Support\Subscriptions\Entitlements::FEATURES.
            $table->json('features')->nullable();

            // A short marketing line per plan, for the website's cards.
            $table->json('highlights')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'is_public', 'sort_order']);
        });

        DB::statement(
            "ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('draft', 'published', 'unpublished', 'archived'))"
        );

        /*
         * Limits and monthly quotas, one row per key. A missing row and a NULL
         * value mean different things: no row is "this plan grants none", a
         * NULL value is "unlimited". The plan editor writes every key, so the
         * difference is only ever visible to somebody reading the table.
         */
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedBigInteger('value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'module_id']);
        });

        /*
         * What a module costs on its own — as an add-on to a plan that does
         * not include it.
         *
         * Never edited once it has applied: a change closes the current row
         * (`effective_to`) and opens a new one from a date. That is the price
         * history, and it is why a subscription's own snapshot — not this
         * table — says what a clinic agreed to pay.
         */
        Schema::create('module_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();

            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('monthly_price')->default(0);
            $table->unsignedBigInteger('annual_price')->default(0);

            // What it costs the platform to run, for margin. Never public.
            $table->unsignedBigInteger('provider_cost')->nullable();

            $table->boolean('is_addon_eligible')->default(true);
            $table->boolean('trial_available')->default(false);
            $table->boolean('is_public')->default(true);

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['module_id', 'effective_from']);
        });

        // One open price per module: the one with no end date.
        DB::statement(
            'CREATE UNIQUE INDEX module_prices_one_open ON module_prices (module_id) WHERE effective_to IS NULL'
        );

        /*
         * Everything else a clinic can buy on top of its plan.
         *
         *   resource  — capacity: +1 branch, +5 employees.
         *   credits   — a pack of messages or video minutes for this period.
         *   feature   — a switch: video consultation, the mobile app.
         *
         * Module add-ons are not here: their price lives in `module_prices`,
         * so a module has exactly one price and cannot be sold at two.
         */
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();

            $table->string('kind', 20);
            $table->string('target', 40);
            $table->unsignedInteger('unit_quantity')->default(1);

            // recurring: charged every cycle. one_time: once, for this period.
            $table->string('billing', 20)->default('recurring');

            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('monthly_price')->default(0);
            $table->unsignedBigInteger('annual_price')->default(0);
            $table->unsignedBigInteger('provider_cost')->nullable();

            $table->string('status', 20)->default('draft');
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE addons ADD CONSTRAINT addons_rules_check CHECK ('
            ."kind IN ('resource', 'credits', 'feature') AND "
            ."billing IN ('recurring', 'one_time') AND "
            ."status IN ('draft', 'published', 'unpublished', 'archived'))"
        );

        /*
         * What one unit of usage costs, and what it is sold for.
         *
         * Versioned the same way module prices are — a rate is never edited
         * once something has been charged at it; a new row takes over from a
         * date. Every usage record names the row it was charged at, so a rate
         * change mid-month leaves the first half of the month priced as it
         * was.
         *
         * The dimensions are the ones the providers actually price by:
         * WhatsApp by template category and destination country, SMS by route
         * and destination, email flat. `*` is "any" for destination; NULL
         * category is "any category".
         */
        Schema::create('usage_rates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('provider', 40)->default('*');
            $table->string('category', 30)->nullable();
            $table->string('destination', 8)->default('*');

            $table->string('billing_unit', 20);
            $table->char('currency', 3)->default('INR');

            // Ten-thousandths of a rupee — see the class docblock.
            $table->unsignedBigInteger('provider_cost_e4')->default(0);
            $table->unsignedBigInteger('price_e4')->default(0);

            // Past the included quota: refuse the send, or charge for it.
            $table->string('overage_policy', 10)->default('block');

            // A send the provider refused is not charged unless this says so.
            $table->boolean('charge_failed')->default(false);

            // [{from: 0, price_e4: …}, {from: 10000, price_e4: …}] — volume
            // pricing within a period. Empty means the flat `price_e4`.
            $table->json('tiers')->nullable();

            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'status', 'effective_from']);
        });

        DB::statement(
            'ALTER TABLE usage_rates ADD CONSTRAINT usage_rates_rules_check CHECK ('
            ."channel IN ('whatsapp', 'sms', 'email', 'video') AND "
            ."billing_unit IN ('message', 'segment', 'email', 'minute', 'session') AND "
            ."overage_policy IN ('block', 'allow') AND "
            ."status IN ('draft', 'published', 'archived'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_rates');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('module_prices');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plans');
    }
};
