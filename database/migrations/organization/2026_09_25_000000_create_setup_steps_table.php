<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation setup: the sections an admin signs off.
 *
 * Most of the setup is read straight from the organization's own data — its
 * details, its branches, its staff — and needs nothing stored. What cannot be
 * read is a decision: "the department list is right", "I have looked at the
 * roles", "the setup is finished". Those are recorded here, one row each.
 *
 * In the tenant database, so one organization's progress can never be read
 * or written as another's.
 */
return new class extends Migration
{
    private const STEPS = ['departments', 'roles', 'settings', 'review'];

    public function up(): void
    {
        Schema::create('setup_steps', function (Blueprint $table) {
            $table->id();
            $table->string('step', 30);
            $table->timestamp('completed_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('step');
        });

        DB::statement(
            "ALTER TABLE setup_steps ADD CONSTRAINT setup_steps_step_check CHECK (step IN ('".
            implode("', '", self::STEPS)."'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_steps');
    }
};
