<?php

use App\Models\Tenant\LabReferenceRange;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\LabTestParameter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The test master: what a lab can run, what it reads off each test, and what
 * normal looks like.
 *
 * Phase 2 of the LIS (docs/architecture/laboratory.md). Four tables:
 *
 *   lab_tests             one row per orderable test (CBC, Lipid profile, TSH)
 *   lab_test_parameters   what is read off it — a CBC has haemoglobin, WBC…;
 *                         a single-value test has exactly one
 *   lab_reference_ranges  per parameter, by sex and age band, with critical
 *                         limits; entered by qualified staff, NEVER seeded
 *   lab_branch_prices     a branch's own price for a test, where it differs
 *
 * EVERY TEST HAS AT LEAST ONE PARAMETER. That is what lets result entry, flags
 * and the report work one way for a TSH and for a CBC, rather than having a
 * "single value" path beside a "panel" path. Nothing about a particular test is
 * written into code.
 *
 * AGE BANDS KEEP THEIR UNIT. "0–30 days", "1–12 months", "18+ years" are how a
 * pathologist writes them, and a patient on their eighteenth birthday is 18 in
 * years whatever the leap days made of their age in days. So a band stores
 * `age_min`, `age_max` and `age_unit`, and is compared in that unit.
 *
 * The old price list (`lab_test_catalog`) is moved across by the next
 * migration and frozen; order lines gain `lab_test_id` beside the old column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24);
            $table->string('name', 191);
            $table->string('short_name', 60)->nullable();

            $table->foreignId('department_id')->constrained('lab_departments')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('lab_test_categories')->restrictOnDelete();
            $table->foreignId('sample_type_id')->nullable()->constrained('lab_sample_types')->restrictOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('lab_containers')->restrictOnDelete();
            $table->foreignId('method_id')->nullable()->constrained('lab_methods')->restrictOnDelete();

            $table->string('sample_volume', 40)->nullable();
            $table->text('description')->nullable();
            $table->text('collection_instructions')->nullable();
            $table->text('processing_instructions')->nullable();

            /*
             * `parameters`: values read off it. `narrative`: a written report
             * (histopathology, a culture), which may still carry parameters.
             */
            $table->string('result_layout', 12)->default(LabTest::LAYOUT_PARAMETERS);
            $table->text('report_template')->nullable();

            // Routine turnaround; urgent and STAT come from Laboratory settings.
            $table->smallInteger('tat_hours')->nullable();

            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->string('hsn_code', 16)->nullable();

            // Run elsewhere: which partner lab, and what they charge us.
            $table->boolean('is_outsourced')->default(false);
            $table->foreignId('outsource_lab_id')->nullable()->constrained('lab_outsource_labs')->restrictOnDelete();
            $table->decimal('outsource_cost', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);

            // The `lab_test_catalog` row this came from, when it was moved across.
            $table->unsignedBigInteger('legacy_catalog_id')->nullable()->index();

            $this->audited($table);

            $table->index(['department_id', 'is_active']);
        });

        DB::statement(
            'ALTER TABLE lab_tests ADD CONSTRAINT lab_tests_rules_check CHECK ('.
            'length(btrim(name)) > 0 AND length(btrim(code)) > 0 AND position >= 0 '.
            'AND result_layout IN ('.$in(LabTest::LAYOUTS).') '.
            'AND price >= 0 AND tax_percent BETWEEN 0 AND 100 '.
            'AND (tat_hours IS NULL OR tat_hours BETWEEN 1 AND 2160) '.
            'AND (outsource_cost IS NULL OR outsource_cost >= 0))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX lab_tests_code_unique ON lab_tests (lower(code)) WHERE deleted_at IS NULL'
        );

        Schema::create('lab_test_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            // A sub-heading on the report, e.g. "Differential count".
            $table->string('section', 60)->nullable();

            $table->string('result_type', 20)->default(LabTestParameter::NUMERIC);
            $table->foreignId('unit_id')->nullable()->constrained('lab_units')->restrictOnDelete();
            $table->smallInteger('decimals')->nullable();

            // A dropdown's answers, each saying whether it is abnormal: [{value, abnormal}].
            $table->jsonb('choices')->nullable();
            // What a normal answer reads as, for text, choice and positive/negative.
            $table->string('normal_value', 60)->nullable();

            $table->foreignId('method_id')->nullable()->constrained('lab_methods')->restrictOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_printed')->default(true);
            $table->integer('position')->default(0);

            $this->audited($table);

            $table->index(['lab_test_id', 'position']);
        });

        DB::statement(
            'ALTER TABLE lab_test_parameters ADD CONSTRAINT lab_test_parameters_rules_check CHECK ('.
            'length(btrim(name)) > 0 AND position >= 0 '.
            'AND result_type IN ('.$in(LabTestParameter::RESULT_TYPES).') '.
            'AND (decimals IS NULL OR decimals BETWEEN 0 AND 6) '.
            "AND (result_type <> 'choice' OR choices IS NOT NULL))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX lab_test_parameters_code_unique ON lab_test_parameters '.
            '(lab_test_id, lower(code)) WHERE deleted_at IS NULL AND code IS NOT NULL'
        );

        Schema::create('lab_reference_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_parameter_id')->constrained('lab_test_parameters')->restrictOnDelete();

            $table->string('sex', 8)->default(LabReferenceRange::ANY);
            $table->smallInteger('age_min')->nullable();
            $table->smallInteger('age_max')->nullable();
            $table->string('age_unit', 6)->nullable();

            // Wide enough for 0.000001 and for 1,500,000 cells/µL alike.
            $table->decimal('low', 18, 6)->nullable();
            $table->decimal('high', 18, 6)->nullable();
            $table->decimal('critical_low', 18, 6)->nullable();
            $table->decimal('critical_high', 18, 6)->nullable();

            // For a text answer ("Negative"), and how a range prints when it is
            // more than two numbers ("< 200 desirable").
            $table->string('normal_text', 120)->nullable();
            $table->string('display_text', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->integer('position')->default(0);

            $this->audited($table);

            $table->index(['lab_test_parameter_id', 'position']);
        });

        DB::statement(
            'ALTER TABLE lab_reference_ranges ADD CONSTRAINT lab_reference_ranges_rules_check CHECK ('.
            'sex IN ('.$in(LabReferenceRange::SEXES).') '.
            'AND (age_unit IS NULL OR age_unit IN ('.$in(LabReferenceRange::AGE_UNITS).')) '.
            'AND ((age_min IS NULL AND age_max IS NULL) OR age_unit IS NOT NULL) '.
            'AND (age_min IS NULL OR age_min >= 0) '.
            'AND (age_min IS NULL OR age_max IS NULL OR age_min < age_max) '.
            'AND (low IS NULL OR high IS NULL OR low <= high) '.
            'AND (critical_low IS NULL OR low IS NULL OR critical_low <= low) '.
            'AND (critical_high IS NULL OR high IS NULL OR high <= critical_high))'
        );

        Schema::create('lab_branch_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->restrictOnDelete();
            $table->decimal('price', 12, 2);
            // Null takes the test's own tax.
            $table->decimal('tax_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'lab_test_id']);
        });

        DB::statement(
            'ALTER TABLE lab_branch_prices ADD CONSTRAINT lab_branch_prices_rules_check CHECK ('.
            'price >= 0 AND (tax_percent IS NULL OR tax_percent BETWEEN 0 AND 100))'
        );

        /*
         * An order line names the test it is, beside the old catalogue column.
         * Nullable forever: a test typed by hand is still ordered and run.
         */
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->foreignId('lab_test_id')->nullable()->after('test_code')
                ->constrained('lab_tests')->restrictOnDelete();
            $table->index('lab_test_id');
        });
    }

    public function down(): void
    {
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropIndex(['lab_test_id']);
            $table->dropConstrainedForeignId('lab_test_id');
        });

        Schema::dropIfExists('lab_branch_prices');
        Schema::dropIfExists('lab_reference_ranges');
        Schema::dropIfExists('lab_test_parameters');
        Schema::dropIfExists('lab_tests');
    }

    /** Soft-deleted with who and why, like every master. */
    private function audited(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->string('deletion_reason', 500)->nullable();
    }
};
