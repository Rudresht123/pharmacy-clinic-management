<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The foreign key `organizations.plan_id` was promised when `plans` arrived,
 * and the reason a module override was granted.
 *
 * `plan_id` is a pointer to the CURRENT subscription's plan, kept for lists
 * and filters; the subscription is the authority. Any value already in the
 * column pointed at a table that did not exist, so it meant nothing and is
 * cleared before the constraint goes on — the backfill that follows sets it
 * properly.
 *
 * `organization_modules` keeps exactly the meaning it has always had: what a
 * Super Admin switched on or off for one organization by hand. With plans,
 * that is an OVERRIDE of what the plan includes (ModuleAccess decides the
 * precedence), and an override is a commercial decision somebody has to be
 * able to explain — so it now carries why, and who.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->whereNotNull('plan_id')->update(['plan_id' => null]);

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        Schema::table('organization_modules', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('organization_modules', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
        });
    }
};
