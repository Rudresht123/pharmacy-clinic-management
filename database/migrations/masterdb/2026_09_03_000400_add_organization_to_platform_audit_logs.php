<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which organization an audit row is about.
 *
 * "Everything that happened to this organization" is the question the
 * detail screen's Audit tab asks, and it cannot be answered by entity alone:
 * assigning a module writes an `OrganizationModule` row whose subject is the
 * binding, not the organization, so the tab would show renames and miss
 * every commercial decision.
 *
 * Deliberately not a foreign key, for the same reason the actor's is not:
 * a referential action would have to rewrite an append-only table, and
 * Postgres refuses that — see the migration that dropped the actor's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');

            // The tab reads this ordered by time, which is the whole query.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn('organization_id');
        });
    }
};
