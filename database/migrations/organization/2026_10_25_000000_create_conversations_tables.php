<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor-to-patient messaging.
 *
 * One conversation per doctor and patient, whoever wrote first — the unique
 * pair is what makes "open the chat with Dr. Sharma" find the thread they
 * already have rather than starting a second one beside it.
 *
 * Read state is a pointer per side (the last message id each has seen) rather
 * than a flag per message: ids only ever grow, so "unread" is every message
 * past the pointer from the other side, and marking a thread read is one
 * update instead of one per message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Ordering the inbox without a subquery per row.
            $table->timestamp('last_message_at')->nullable();

            $table->unsignedBigInteger('doctor_last_read_id')->default(0);
            $table->unsignedBigInteger('patient_last_read_id')->default(0);

            $table->timestamps();

            $table->unique(['doctor_id', 'customer_id']);
            $table->index(['doctor_id', 'last_message_at']);
            $table->index(['customer_id', 'last_message_at']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // Which side wrote it, kept apart from the login: an account can be
            // removed, and the thread must still say who said what.
            $table->string('sender_side', 10);
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
