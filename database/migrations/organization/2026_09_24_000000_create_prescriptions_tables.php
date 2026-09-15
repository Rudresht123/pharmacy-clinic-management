<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured prescriptions: one document per visit, with lines.
 *
 * A line names a catalogue medicine (and copies what it needs from it into
 * snapshots that are never rewritten), or is "unlisted" — free text the doctor
 * typed, readable and printable but not dispensable until it is mapped.
 *
 * The consultation's JSON lines are copied across as unlisted lines on an
 * issued, `is_legacy` prescription, each keeping its original text in
 * `legacy_line` so every screen reading the old shape reads exactly what it
 * read before. `consultations.prescription` itself is left untouched — no
 * longer read or written, kept as the fallback for down() — and is dropped
 * once the copy has been checked (Phase 6).
 *
 * Numbers (RX-00001) come from the Phase 0 sequence, as a column default.
 */
return new class extends Migration
{
    private const STATUSES = ['draft', 'issued', 'partially_dispensed', 'dispensed', 'cancelled', 'expired'];

    private const ITEM_STATUSES = ['pending', 'partially_dispensed', 'dispensed', 'cancelled'];

    private const FREQUENCIES = ['od', 'bd', 'tds', 'qid', 'hs', 'sos', 'stat', 'weekly', 'custom'];

    private const FOOD_TIMINGS = ['before_food', 'after_food', 'with_food', 'empty_stomach', 'any'];

    private const DURATION_UNITS = ['days', 'weeks', 'months', 'continuous'];

    // The medicines table's own list; a line's route defaults from its medicine.
    private const ROUTES = [
        'oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'ophthalmic', 'otic', 'nasal',
        'rectal', 'vaginal', 'sublingual', 'other',
    ];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('prescription_number', 20)
                ->default(DB::raw("hms_document_number('RX', 'prescription_number_seq')"));

            // Restated rather than read through the appointment, as consultations do.
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();

            $table->date('prescription_date');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft');

            // Kept out of activity_logs (historyExcept): the log cannot be redacted.
            $table->text('clinical_notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->boolean('is_legacy')->default(false);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['customer_id', 'prescription_date']);
            $table->index(['doctor_id', 'prescription_date']);
            $table->index(['location_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_rules_check CHECK ('.
            'status IN ('.$in(self::STATUSES).') '.
            'AND (valid_until IS NULL OR valid_until >= prescription_date) '.
            // Only a draft has never been issued; a draft is removed, not cancelled.
            "AND (status = 'draft' OR issued_at IS NOT NULL) ".
            "AND (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX prescriptions_number_unique ON prescriptions (prescription_number) '.
            'WHERE deleted_at IS NULL'
        );

        // One live prescription per visit; a replacement is allowed after a cancellation.
        DB::statement(
            'CREATE UNIQUE INDEX prescriptions_live_per_visit ON prescriptions (appointment_id) '.
            "WHERE status <> 'cancelled' AND deleted_at IS NULL"
        );

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            // Null: an unlisted medicine, not dispensable until mapped.
            $table->foreignId('medicine_id')->nullable()->constrained('medicines')->restrictOnDelete();

            // Written once, when the medicine is chosen; later edits to it never reach here.
            $table->string('medicine_name_snapshot', 191);
            $table->string('generic_name_snapshot', 191)->nullable();
            $table->string('strength_snapshot', 60)->nullable();
            $table->string('dosage_form_snapshot', 30)->nullable();

            // "1 tablet", "5 ml".
            $table->decimal('dose_amount', 8, 2)->nullable();
            $table->string('dose_unit', 20)->nullable();

            // The "1–0–1" pattern, as numbers.
            $table->decimal('morning', 4, 2)->nullable();
            $table->decimal('afternoon', 4, 2)->nullable();
            $table->decimal('evening', 4, 2)->nullable();
            $table->decimal('night', 4, 2)->nullable();

            $table->string('frequency', 12)->nullable();
            $table->string('food_timing', 16)->nullable();
            $table->smallInteger('duration')->nullable();
            $table->string('duration_unit', 12)->nullable();
            $table->string('route', 20)->nullable();

            // Base units. Required for a catalogue medicine (the CHECK below).
            $table->integer('prescribed_quantity')->nullable();
            // Written only by dispensing (Phase 5).
            $table->integer('dispensed_quantity')->default(0);
            $table->integer('over_dispense_allowance')->default(0);
            $table->foreignId('over_dispense_approved_by')->nullable()->constrained('users')->restrictOnDelete();

            // Kept out of activity_logs, like clinical notes.
            $table->text('instructions')->nullable();

            // The original consultation line, verbatim, for lines moved across.
            $table->jsonb('legacy_line')->nullable();

            $table->string('status', 20)->default('pending');
            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['prescription_id', 'sort_order']);
            $table->index('medicine_id');
        });

        DB::statement(
            'ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_rules_check CHECK ('.
            'status IN ('.$in(self::ITEM_STATUSES).') '.
            'AND (frequency IS NULL OR frequency IN ('.$in(self::FREQUENCIES).')) '.
            'AND (food_timing IS NULL OR food_timing IN ('.$in(self::FOOD_TIMINGS).')) '.
            'AND (duration_unit IS NULL OR duration_unit IN ('.$in(self::DURATION_UNITS).')) '.
            'AND (route IS NULL OR route IN ('.$in(self::ROUTES).')) '.
            'AND (dose_amount IS NULL OR dose_amount > 0) '.
            'AND coalesce(morning, 0) >= 0 AND coalesce(afternoon, 0) >= 0 '.
            'AND coalesce(evening, 0) >= 0 AND coalesce(night, 0) >= 0 '.
            'AND (duration IS NULL OR duration > 0) '.
            'AND (prescribed_quantity IS NULL OR prescribed_quantity > 0) '.
            'AND (medicine_id IS NULL OR prescribed_quantity IS NOT NULL) '.
            'AND dispensed_quantity >= 0 AND over_dispense_allowance >= 0 '.
            'AND dispensed_quantity <= coalesce(prescribed_quantity, 0) + over_dispense_allowance '.
            'AND length(trim(medicine_name_snapshot)) > 0)'
        );

        $this->copyLegacyPrescriptions();
    }

    /**
     * Every consultation's JSON lines, as an issued legacy prescription.
     *
     * Public so the copy can be tested on its own. Safe to run twice: a
     * visit that already has a live prescription is skipped.
     */
    public function copyLegacyPrescriptions(): int
    {
        $copied = 0;

        $consultations = DB::table('consultations')
            ->whereNotNull('prescription')
            ->whereRaw("jsonb_typeof(prescription) = 'array' AND jsonb_array_length(prescription) > 0")
            ->orderBy('id')
            ->get();

        foreach ($consultations as $consultation) {
            $taken = DB::table('prescriptions')
                ->where('appointment_id', $consultation->appointment_id)
                ->where('status', '<>', 'cancelled')
                ->whereNull('deleted_at')
                ->exists();

            if ($taken) {
                continue;
            }

            $visit = DB::table('appointments')->where('id', $consultation->appointment_id)->first();

            if (! $visit) {
                continue;
            }

            $id = DB::table('prescriptions')->insertGetId([
                'location_id' => $visit->location_id,
                'customer_id' => $consultation->customer_id,
                'doctor_id' => $consultation->doctor_id,
                'appointment_id' => $consultation->appointment_id,
                'consultation_id' => $consultation->id,
                'prescription_date' => $visit->appointment_date ?? substr((string) $consultation->created_at, 0, 10),
                'status' => 'issued',
                'issued_at' => $consultation->updated_at ?? $consultation->created_at,
                'is_legacy' => true,
                'created_at' => $consultation->created_at,
                'updated_at' => $consultation->updated_at,
            ]);

            foreach (array_values(json_decode($consultation->prescription, true) ?: []) as $index => $line) {
                $line = is_array($line) ? $line : ['drug' => (string) $line];
                $name = trim((string) ($line['drug'] ?? ''));

                DB::table('prescription_items')->insert([
                    'prescription_id' => $id,
                    'medicine_name_snapshot' => mb_substr($name !== '' ? $name : 'Unnamed medicine', 0, 191),
                    'legacy_line' => json_encode($line),
                    'status' => 'pending',
                    'sort_order' => $index,
                    'created_at' => $consultation->created_at,
                    'updated_at' => $consultation->updated_at,
                ]);
            }

            $copied++;
        }

        return $copied;
    }

    public function down(): void
    {
        // The consultation JSON was never changed, so nothing needs putting back.
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
