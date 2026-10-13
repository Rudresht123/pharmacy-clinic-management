<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far each side's phone has received a conversation — the second grey
 * tick, between "sent" (the server has it) and "read" (they opened it).
 *
 * A pointer per side, like the read pointers beside it: ids only grow, so
 * "delivered" is every message at or before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('doctor_delivered_id')->default(0)->after('patient_last_read_id');
            $table->unsignedBigInteger('patient_delivered_id')->default(0)->after('doctor_delivered_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['doctor_delivered_id', 'patient_delivered_id']);
        });
    }
};
