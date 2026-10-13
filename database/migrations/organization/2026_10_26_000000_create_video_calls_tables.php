<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Video consultations: a visit that happens on the phone instead of in a room.
 *
 * `appointments.mode` says which kind of visit was booked. Everything else
 * about the visit — the slot, the token, the queue, the write-up — is the same
 * row moving through the same workflow; only where the patient sits differs.
 *
 * `video_calls` is one ring of the doctor's phone to the patient's, and what
 * became of it. A visit can have several: a dropped connection is redialled,
 * and the first attempt stays on record as having happened.
 *
 * `video_call_signals` carries the WebRTC handshake between the two phones —
 * the session descriptions and network candidates each side must hand the
 * other before any media flows. Stored rather than broadcast whole because a
 * video offer runs to several kilobytes and Reverb's HTTP API refuses a
 * request over 10 KB; the broadcast is only a nudge to fetch. The rows hold
 * both phones' IP addresses, so they are deleted the moment the call ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('mode', 10)->default('in_clinic')->after('type');
        });

        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_mode_check CHECK (mode IN ('in_clinic', 'video'))"
        );

        Schema::create('video_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();

            // Copied from the visit so a doctor's or patient's call history
            // reads without a join, and so ownership is checked on the call
            // itself rather than on whatever the visit says today.
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // The login that rang, and the one that answered. A patient may be
            // signed in on two phones; the call belongs to the one that picked up.
            $table->foreignId('doctor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('patient_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 12)->default('ringing');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Which side put the phone down, or `system` for a ring nobody answered.
            $table->string('ended_by', 10)->nullable();

            $table->timestamps();

            $table->index(['doctor_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE video_calls ADD CONSTRAINT video_calls_status_check CHECK (status IN ('ringing', 'active', 'ended', 'missed', 'declined', 'cancelled'))"
        );

        // One live call per visit: the doctor's second tap on "Call" finds the
        // first ring instead of starting another beside it.
        DB::statement(
            "CREATE UNIQUE INDEX video_calls_one_live_per_visit ON video_calls (appointment_id) WHERE status IN ('ringing', 'active')"
        );

        Schema::create('video_call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_call_id')->constrained('video_calls')->cascadeOnDelete();
            $table->string('from_side', 10);
            $table->string('type', 12);
            $table->jsonb('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['video_call_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_call_signals');
        Schema::dropIfExists('video_calls');

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_mode_check');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
