<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform (landlord) identity — Build Spec §5, §18.
 *
 * Deliberately separate from `users`. A platform admin and a tenant user must
 * never share a table or a session: the spec lists it as non-negotiable, and
 * splitting now is cheap because no tenant users exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id();

            // Immutable machine key the code checks against; `name` is display.
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();

            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);

            /*
             * Two-factor and the IP allowlist are required by §18 but are only
             * enforced in the hardening step. The columns land now so that
             * turning enforcement on later is a code change, not a migration
             * against a table that already holds live admins.
             */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('ip_allowlist')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('platform_role_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('platform_user_id')
                ->constrained('platform_users')
                ->cascadeOnDelete();

            $table->foreignId('platform_role_id')
                ->constrained('platform_roles')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['platform_user_id', 'platform_role_id'], 'platform_role_user_unique');
        });

        // Same shape as Laravel's password_reset_tokens, on its own table so a
        // reset issued for an admin can never be redeemed by a tenant user.
        Schema::create('platform_password_resets', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_resets');
        Schema::dropIfExists('platform_role_user');
        Schema::dropIfExists('platform_users');
        Schema::dropIfExists('platform_roles');
    }
};
