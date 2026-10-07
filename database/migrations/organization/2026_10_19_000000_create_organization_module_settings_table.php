<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation's own answer about a module it was sold.
 *
 * Three questions decide whether a module runs at a branch, and they belong to
 * three different people:
 *
 *   1. Was it sold?            `organization_modules`, master — the super admin
 *   2. Does the organisation   THIS TABLE, tenant — the owner
 *      want it at all?
 *   3. Does this branch        `location_modules`, tenant — the owner, per branch
 *      opt out?
 *
 * Before this table the owner could only answer the third, one branch at a
 * time — and a branch opened next month started with the module on, because
 * that is what "no row" means there. A clinic that bought the pharmacy and has
 * not started dispensing had no single switch that said so.
 *
 * SPARSE, the same shape as `location_modules` and `module_locks`: a row means
 * switched off. An organisation that never opens the screen has no rows and
 * behaves exactly as it did before the table existed, and switching a module
 * back on deletes the row rather than storing `true` — absence is what "on"
 * means here.
 *
 * NOT a column on `organization_modules`. That row is the super admin's
 * commercial record in the master database, and an owner switching something
 * off for their own reasons must not be able to touch what they were sold.
 *
 * `module_key` is a varchar rather than a foreign key for the reason given on
 * `location_modules`: the catalogue lives in the master database and Postgres
 * cannot reference across databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_module_settings', function (Blueprint $table) {
            $table->id();

            // One answer per module; the vocabulary is ModuleRegistry, in code.
            $table->string('module_key', 100)->unique();

            /*
             * Always false in practice — a row is only written to switch
             * something off. Kept as a column rather than implied by the row,
             * for the reason `location_modules` gives: "there is a row,
             * therefore it is off" reads backwards to whoever finds it next.
             */
            $table->boolean('is_enabled')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_module_settings');
    }
};
