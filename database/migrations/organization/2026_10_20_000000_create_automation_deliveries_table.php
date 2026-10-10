<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which clock-driven automated messages have already gone out.
 *
 * Appointment reminders are found by a scheduler that asks "what is due?"
 * every few minutes, so the same appointment is due on several runs. The
 * unique key here is what makes every run after the first a no-op — see
 * App\Models\Tenant\AutomationDelivery, which claims a row BEFORE sending.
 *
 * Generic over the subject (an appointment today) so a later clock-driven
 * message — a follow-up nudge — needs no table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 60);
            $table->string('channel', 20);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_key', 'channel', 'subject_type', 'subject_id'], 'automation_deliveries_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_deliveries');
    }
};
