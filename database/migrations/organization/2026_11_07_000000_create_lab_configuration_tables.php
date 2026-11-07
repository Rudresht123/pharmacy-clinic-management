<?php

use App\Models\Tenant\LabContainer;
use App\Models\Tenant\LabSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The laboratory's own vocabulary, before any test is built on it.
 *
 * Phase 1 of the LIS (docs/architecture/laboratory.md). Ten tables: one row of
 * settings and nine short lists the test master, the sample screens and the
 * bench will pick from — departments, categories, methods, units, containers,
 * sample types, rejection reasons, equipment and partner labs.
 *
 * ALL ORGANISATION-WIDE. A chain runs one menu of tests and calls a serum tube a
 * serum tube at every branch; the only branch column here is where a piece of
 * equipment physically sits. That is also why they are written under an
 * organisation-scoped tick.
 *
 * NOTHING CLINICAL IS SEEDED. The lists start empty, and a clinic may load a
 * starter list of names from the screen (App\Support\Laboratory\StarterLists).
 * There are no reference ranges anywhere in this migration, on purpose: a range
 * is a clinical decision a qualified person makes, and it arrives with the test
 * master, entered by them.
 *
 * Each list is soft-deleted with who and why, like every master here. Names are
 * unique ignoring case among the rows still live, so "Serum" cannot be added
 * twice and a removed one can be added again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
        |------------------------------------------------------------------
        | Settings: one row, the organisation's
        |------------------------------------------------------------------
        */
        Schema::create('lab_settings', function (Blueprint $table) {
            $table->id();

            /*
             * How many people a result passes before it is final. One: the
             * technician enters, an approver signs. Two: a technical verifier
             * checks it in between. Small labs run one; a lab with a
             * pathologist and a senior technician usually runs two.
             */
            $table->smallInteger('approval_levels')->default(1);

            /*
             * Whether the same person may enter a result and then verify or
             * approve it. Off by default: a second pair of eyes is the point.
             * A one-person lab switches it on knowingly.
             */
            $table->boolean('allow_self_review')->default(false);

            /*
             * No sample is taken while its bill is unpaid. Off by default;
             * STAT orders and clinics that bill on credit are exempt whatever
             * this says, and the exemption is recorded on the sample.
             */
            $table->boolean('collect_requires_payment')->default(false);

            // A report goes out as soon as some tests are final, rather than
            // waiting for the slowest one.
            $table->boolean('release_partial_reports')->default(true);

            /*
             * A critical value cannot be approved until somebody has logged
             * who they told and how. On by default because the call is the
             * thing that protects the patient, not the report.
             */
            $table->boolean('critical_call_required')->default(true);

            // Turnaround for the two faster priorities, across every test.
            // Null = the test's own turnaround applies whatever the priority.
            $table->smallInteger('urgent_tat_hours')->nullable();
            $table->smallInteger('stat_tat_minutes')->nullable();

            // The label stock the collection counter prints on.
            $table->string('label_size', 12)->default(LabSetting::LABEL_50X25);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE lab_settings ADD CONSTRAINT lab_settings_rules_check CHECK ('.
            'approval_levels IN ('.implode(', ', LabSetting::APPROVAL_LEVELS).') '.
            'AND label_size IN ('.$in(LabSetting::LABEL_SIZES).') '.
            'AND (urgent_tat_hours IS NULL OR urgent_tat_hours BETWEEN 1 AND 720) '.
            'AND (stat_tat_minutes IS NULL OR stat_tat_minutes BETWEEN 5 AND 1440))'
        );

        DB::statement('CREATE UNIQUE INDEX lab_settings_singleton ON lab_settings ((true))');

        if (! DB::connection()->pretending()) {
            DB::table('lab_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        }

        /*
        |------------------------------------------------------------------
        | The lists
        |------------------------------------------------------------------
        */
        Schema::create('lab_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->text('description')->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_test_categories', function (Blueprint $table) {
            $table->id();
            // A category may sit under a department, or stand on its own.
            $table->foreignId('department_id')->nullable()->constrained('lab_departments')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_units', function (Blueprint $table) {
            $table->id();
            /*
             * Compared WITH case, unlike every other name here: mU/L and MU/L
             * are a thousand million apart, and a unit list that folded them
             * together would be wrong rather than tidy.
             */
            $table->string('symbol', 32);
            $table->string('name', 80)->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_containers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // The cap colour the collector reaches for; a closed list so the
            // screens can draw it.
            $table->string('cap_colour', 20)->nullable();
            $table->string('additive', 60)->nullable();
            $table->decimal('volume_ml', 6, 2)->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_sample_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->foreignId('default_container_id')->nullable()->constrained('lab_containers')->restrictOnDelete();
            $table->text('handling_notes')->nullable();
            $this->listColumns($table);
        });

        Schema::create('lab_rejection_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('reason', 191);
            // Most reasons mean a fresh sample; a few (a duplicate) do not.
            $table->boolean('requires_recollection')->default(true);
            $this->listColumns($table);
        });

        Schema::create('lab_equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_no', 80)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('lab_departments')->restrictOnDelete();
            // Where it physically sits. The only branch column in this file.
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $this->listColumns($table);
        });

        Schema::create('lab_outsource_labs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 24)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $this->listColumns($table);
        });

        /*
        |------------------------------------------------------------------
        | Rules: names present, unique among live rows
        |------------------------------------------------------------------
        */
        $this->rules('lab_departments', 'name');
        $this->rules('lab_test_categories', 'name');
        $this->rules('lab_methods', 'name');
        $this->rules('lab_units', 'symbol');
        $this->rules('lab_rejection_reasons', 'reason');
        $this->rules('lab_equipment', 'name');
        $this->rules('lab_outsource_labs', 'name');
        $this->rules('lab_sample_types', 'name');

        DB::statement(
            'ALTER TABLE lab_containers ADD CONSTRAINT lab_containers_rules_check CHECK ('.
            'length(btrim(name)) > 0 AND position >= 0 '.
            'AND (cap_colour IS NULL OR cap_colour IN ('.$in(LabContainer::CAP_COLOURS).')) '.
            'AND (volume_ml IS NULL OR (volume_ml > 0 AND volume_ml <= 1000)))'
        );

        $this->uniqueIgnoringCase('lab_departments', 'name');
        $this->uniqueIgnoringCase('lab_departments', 'code');
        $this->uniqueIgnoringCase('lab_methods', 'name');
        $this->uniqueIgnoringCase('lab_containers', 'name');
        $this->uniqueIgnoringCase('lab_sample_types', 'name');
        $this->uniqueIgnoringCase('lab_sample_types', 'code');
        $this->uniqueIgnoringCase('lab_rejection_reasons', 'reason');
        $this->uniqueIgnoringCase('lab_equipment', 'code');
        $this->uniqueIgnoringCase('lab_outsource_labs', 'name');
        $this->uniqueIgnoringCase('lab_outsource_labs', 'code');

        // A category's name is unique within its department (or among those with none).
        DB::statement(
            'CREATE UNIQUE INDEX lab_test_categories_name_unique ON lab_test_categories '.
            '(COALESCE(department_id, 0), lower(name)) WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX lab_units_symbol_unique ON lab_units (symbol) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        foreach ([
            'lab_outsource_labs',
            'lab_equipment',
            'lab_rejection_reasons',
            'lab_sample_types',
            'lab_containers',
            'lab_units',
            'lab_methods',
            'lab_test_categories',
            'lab_departments',
            'lab_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** What every list has: on/off, an order, and an audited soft delete. */
    private function listColumns(Blueprint $table): void
    {
        $table->boolean('is_active')->default(true);
        $table->integer('position')->default(0);
        $table->timestamps();
        $table->softDeletes();
        $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->string('deletion_reason', 500)->nullable();

        $table->index(['is_active', 'position']);
    }

    private function rules(string $table, string $column): void
    {
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_rules_check CHECK (".
            "length(btrim({$column})) > 0 AND position >= 0)"
        );
    }

    /** Unique ignoring case among the live rows; a blank code is not a code. */
    private function uniqueIgnoringCase(string $table, string $column): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX {$table}_{$column}_unique ON {$table} (lower({$column})) ".
            "WHERE deleted_at IS NULL AND {$column} IS NOT NULL"
        );
    }
};
