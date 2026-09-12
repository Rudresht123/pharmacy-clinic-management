<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The medicine master: what a medicine is.
 *
 * It knows nothing about stock or patients. Prescriptions, batches and
 * dispensing (later phases) point at it and copy what they need into
 * snapshots, so editing a medicine never rewrites a prescription.
 *
 * Stock is counted in `base_unit` — the tablet, the bottle, the vial — and
 * `pack_size` is how many of those a purchase pack holds, so a strip of 10
 * is received as 10 tablets and can be sold loose.
 *
 * The fixed lists (dosage form, route, base unit, schedule) are varchar with
 * CHECK constraints, never enums, the same as every other list in the
 * tenant schema.
 *
 * No created_by / updated_by: tenant tables record who did what in
 * activity_logs through RecordsHistory, and this one does the same.
 * deleted_by is here because the deletion reason travels with it.
 */
return new class extends Migration
{
    private const DOSAGE_FORMS = [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'ointment', 'cream', 'drops',
        'inhaler', 'powder', 'gel', 'lotion', 'patch', 'suppository', 'other',
    ];

    private const ROUTES = [
        'oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'ophthalmic', 'otic', 'nasal',
        'rectal', 'vaginal', 'sublingual', 'other',
    ];

    private const BASE_UNITS = ['tablet', 'capsule', 'bottle', 'vial', 'ampoule', 'tube', 'sachet', 'unit'];

    private const SCHEDULES = ['OTC', 'G', 'H', 'H1', 'X'];

    public function up(): void
    {
        /*
         * Trigram search on the names. A trusted extension on Postgres 13+,
         * so the database owner can create it without superuser.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();

            // The organization's own code, when it keeps one.
            $table->string('medicine_code', 40)->nullable();

            $table->string('generic_name', 191);
            // Null for a generic sold under its own name.
            $table->string('brand_name', 191)->nullable();
            $table->string('strength', 60)->nullable();
            $table->string('dosage_form', 30);
            // The default route for a new prescription line.
            $table->string('route', 20)->nullable();

            $table->string('base_unit', 20);
            $table->integer('pack_size')->default(1);

            $table->string('manufacturer', 191)->nullable();
            // Free text until somebody needs to manage the list.
            $table->string('category', 100)->nullable();

            // The Indian drug schedule: OTC, G, H, H1, X.
            $table->string('schedule', 10)->nullable();
            $table->boolean('prescription_required')->default(true);

            $table->text('description')->nullable();

            // Operational switch. Separate from deletion: an inactive
            // medicine exists and is not offered; a deleted one is gone
            // from every working screen.
            $table->boolean('is_active')->default(true);

            $table->jsonb('custom_fields')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index('is_active');
        });

        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_dosage_form_check '.
            'CHECK (dosage_form IN ('.$in(self::DOSAGE_FORMS).'))'
        );
        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_route_check '.
            'CHECK (route IS NULL OR route IN ('.$in(self::ROUTES).'))'
        );
        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_base_unit_check '.
            'CHECK (base_unit IN ('.$in(self::BASE_UNITS).'))'
        );
        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_schedule_check '.
            'CHECK (schedule IS NULL OR schedule IN ('.$in(self::SCHEDULES).'))'
        );
        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_pack_size_check CHECK (pack_size > 0)'
        );

        // Case-insensitive, and free again once the medicine is removed.
        DB::statement(
            'CREATE UNIQUE INDEX medicines_code_unique ON medicines (lower(medicine_code)) '.
            'WHERE deleted_at IS NULL AND medicine_code IS NOT NULL'
        );

        /*
         * Names alone are never unique: one generic comes in several
         * strengths, forms and brands. What makes a medicine distinct is the
         * combination. The request also catches near-duplicates this index
         * cannot see ("500mg" against "500 mg"); this is the backstop.
         */
        DB::statement(
            'CREATE UNIQUE INDEX medicines_identity_unique ON medicines ('.
            "lower(generic_name), lower(coalesce(brand_name, '')), lower(coalesce(strength, '')), ".
            "dosage_form, lower(coalesce(manufacturer, ''))".
            ') WHERE deleted_at IS NULL'
        );

        DB::statement('CREATE INDEX medicines_generic_name_trgm ON medicines USING gin (generic_name gin_trgm_ops)');
        DB::statement('CREATE INDEX medicines_brand_name_trgm ON medicines USING gin (brand_name gin_trgm_ops)');

        // Widen the settings CHECK so the medicine form can be configured too.
        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );
        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user', 'customer', 'doctor', 'medicine'))"
        );
    }

    public function down(): void
    {
        DB::table('entity_field_settings')->where('entity', 'medicine')->delete();

        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );
        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user', 'customer', 'doctor'))"
        );

        Schema::dropIfExists('medicines');

        // The extension is left in place: other tables may come to use it,
        // and dropping it is not this migration's decision.
    }
};
