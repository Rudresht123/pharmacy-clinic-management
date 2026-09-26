<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules a branch may not switch off.
 *
 * `location_modules` lets each branch opt out of what the organization holds.
 * This is the organization saying a module is not optional: billing runs at
 * every site, or the numbers the organization reports are made of nothing.
 *
 * NOT a column on `location_modules`. That table is a sparse record of what is
 * OFF — a module running everywhere has no rows at all — so there would be
 * nowhere to write "locked on" for the very modules most likely to be locked.
 * The lock is also the organization's answer, one per module, not a per-branch
 * one; keeping it here says so, instead of repeating the same value across
 * every branch and inviting them to disagree.
 *
 * Sparse again: a row means locked. Nothing is locked until somebody says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_locks', function (Blueprint $table) {
            $table->id();

            // One answer per module; the vocabulary is ModuleRegistry, in code.
            $table->string('module_key', 100)->unique();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_locks');
    }
};
