<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's qualifications are a list, not a sentence.
 *
 * "MBBS, MD" was one varchar, so the only thing the software could do with it
 * was print it back. Nobody could filter on MD, count the DMs, or offer the
 * qualifications this organization actually uses as options — because as far
 * as the database was concerned it was a single opaque string that happened to
 * contain a comma.
 *
 * jsonb rather than a lookup table and a pivot: a qualification is a label a
 * clinic types once and reuses, not an entity with anything hanging off it.
 * The set of labels an organization uses lives in `entity_field_settings`,
 * where every other configurable option list already lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function ($table) {
            $table->jsonb('qualifications')->nullable()->after('qualification');
        });

        /*
         * Split what is already there on commas.
         *
         * Done in SQL rather than by loading every doctor: this runs against
         * every tenant database on deploy, and the ones that matter are the
         * ones with enough doctors to make a loop worth avoiding.
         *
         * COALESCE keeps a doctor with no qualification as an empty list
         * rather than null, so the column has one shape everywhere.
         */
        DB::statement(<<<'SQL'
            UPDATE doctors
            SET qualifications = COALESCE(
                (
                    SELECT jsonb_agg(TRIM(part))
                    FROM unnest(string_to_array(qualification, ',')) AS part
                    WHERE TRIM(part) <> ''
                ),
                '[]'::jsonb
            )
        SQL);

        Schema::table('doctors', function ($table) {
            $table->dropColumn('qualification');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function ($table) {
            $table->string('qualification', 191)->nullable()->after('specialisation');
        });

        // Back to a sentence, which is lossless here only because that is
        // exactly what it was before.
        DB::statement(<<<'SQL'
            UPDATE doctors
            SET qualification = NULLIF(
                (
                    SELECT string_agg(value, ', ')
                    FROM jsonb_array_elements_text(COALESCE(qualifications, '[]'::jsonb)) AS value
                ),
                ''
            )
        SQL);

        Schema::table('doctors', function ($table) {
            $table->dropColumn('qualifications');
        });
    }
};
