<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform bills an organization, and the money that settles it.
 *
 * Named `subscription_invoices` / `subscription_payments`, not `invoices` /
 * `payments`: those names already belong to every tenant database, where they
 * are a clinic billing its patients. The two never share a database, but a
 * grep, a stack trace or a support conversation that says "the invoice" should
 * not have to work out which one is meant.
 *
 * An issued invoice is never edited. Its lines are a JSON snapshot of what was
 * charged and why, so a price changed afterwards cannot change what somebody
 * was already asked to pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A sign-up from the marketing website, before it is an organization.
         *
         * `reference` is the only id the website ever sees; it polls status
         * with it. `idempotency_key` is the website's own — a double-clicked
         * submit, or a retried request, finds the first registration instead
         * of starting a second clinic.
         *
         * The organization is provisioned from here by the existing onboarding
         * service, after a trial is chosen or a payment is verified — never
         * inside the request.
         */
        Schema::create('public_registrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->string('idempotency_key', 191)->unique();

            $table->string('status', 20)->default('pending_payment');

            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('billing_cycle', 20);
            $table->boolean('start_trial')->default(false);
            $table->json('addons')->nullable();

            $table->string('organization_name', 191);
            $table->string('contact_name', 150);
            $table->string('email', 191);
            $table->string('phone', 30)->nullable();
            $table->string('subdomain', 63);
            $table->foreignId('organization_type_id')->nullable()->constrained('organization_types')->nullOnDelete();

            // The server's quote at the moment of registering.
            $table->json('quote');

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('failure_reason', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE public_registrations ADD CONSTRAINT public_registrations_status_check CHECK ("
            ."status IN ('pending_payment', 'paid', 'provisioning', 'active', 'failed', 'cancelled'))"
        );

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();

            // Issued numbers only — a draft has none, so the series has no gaps.
            $table->string('number', 40)->nullable()->unique();

            // Null only for a website sign-up's first invoice, issued before
            // the organization exists — `public_registration_id` says whose.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('public_registration_id')->nullable()->constrained('public_registrations')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // new | renewal | upgrade | addon | overage | manual
            $table->string('kind', 20);
            $table->string('status', 20)->default('draft');

            $table->char('currency', 3)->default('INR');
            $table->json('lines');
            $table->unsignedBigInteger('subtotal');

            // exclusive | inclusive | none, and the rate in basis points
            // (1800 = 18%), both copied from the billing settings at issue.
            $table->string('tax_mode', 20);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('total');

            // The term this invoice pays for, when it pays for one.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('billing_cycle', 20)->nullable();

            // What paying this does: e.g. {"action":"renew","plan_id":3,
            // "cycle":"annual"}. Read by PaymentConfirmation, never by a client.
            $table->json('fulfilment')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            // Who the invoice is addressed to, as it stood when issued.
            $table->json('bill_to')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        DB::statement(
            "ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_rules_check CHECK ("
            ."kind IN ('new', 'renewal', 'upgrade', 'addon', 'overage', 'manual') AND "
            ."status IN ('draft', 'issued', 'paid', 'void') AND "
            ."tax_mode IN ('exclusive', 'inclusive', 'none'))"
        );

        /*
         * One attempt to pay, and how it ended.
         *
         * Razorpay's order and payment ids are unique: a webhook delivered
         * twice, or a browser callback racing the webhook, finds the same row
         * rather than recording the money twice. A manual payment carries the
         * bank/UPI reference the administrator typed and who typed it.
         */
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();

            $table->string('method', 20);
            $table->string('status', 20)->default('created');

            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('amount');

            $table->string('gateway_order_id', 80)->nullable()->unique();
            $table->string('gateway_payment_id', 80)->nullable()->unique();

            // Set only by a server-side check — a verified signature or a
            // verified webhook. Never by a browser saying "it worked".
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_via', 20)->nullable();

            $table->string('reference', 120)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('payload')->nullable();

            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->foreignId('recorded_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_rules_check CHECK ("
            ."method IN ('razorpay', 'manual') AND "
            ."status IN ('created', 'captured', 'failed', 'refunded'))"
        );

    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('public_registrations');
    }
};
