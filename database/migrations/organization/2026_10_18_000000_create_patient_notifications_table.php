<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the app has told a patient about their own care.
 *
 * An event log, like message_logs: raised when something happens to an
 * appointment, read once the patient opens it, never edited. Nothing here is
 * a record anybody maintains, so there is no history and no deletion audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // What raised it, and the appointment it was raised about, if any.
            $table->string('type', 40);
            $table->string('title', 191);
            $table->string('body', 500)->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'read_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_notifications');
    }
};
