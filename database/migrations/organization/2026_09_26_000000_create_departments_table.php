<?php

use App\Support\Fields\DoctorFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments, and the sub-departments under them.
 *
 * Until now a department was a word: the doctor form's "Department" option
 * list, written onto the doctor as text. That cannot hold "Interventional
 * Cardiology under Cardiology", so departments become rows with an optional
 * parent — two levels, a department and its sub-departments.
 *
 * Organisation-wide, as the list was: a doctor works at whichever branches
 * they are posted to, and so does their department.
 *
 * Nothing is lost on the way in. Today's list — the organisation's own if it
 * edited one, the starter list if not — plus any name a doctor already
 * carries becomes a top-level department, and each doctor is linked to theirs
 * by name. `doctors.specialisation` stays, still read by the OPD board, the
 * filters and booking; from here on the server writes it from the department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            // Null: a department. Set: a sub-department of that department.
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['parent_id', 'sort_order']);
        });

        DB::statement(
            'ALTER TABLE departments ADD CONSTRAINT departments_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)'
        );

        // Unique among the live children of one parent: "Preventive Care" can sit under two departments.
        DB::statement(
            'CREATE UNIQUE INDEX departments_name_unique ON departments (coalesce(parent_id, 0), lower(name)) '.
            'WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX departments_code_unique ON departments (lower(code)) '.
            'WHERE deleted_at IS NULL AND code IS NOT NULL'
        );

        Schema::table('doctors', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('specialisation')
                ->constrained('departments')->restrictOnDelete();
        });

        $this->carryOverExistingList();
    }

    /**
     * Today's department list, and the names doctors already use, as
     * top-level departments; each doctor linked to theirs by name.
     *
     * Public so it can be tested on its own. Safe to run twice: a name that
     * is already a department is reused, not repeated.
     */
    public function carryOverExistingList(): int
    {
        // A dry run (--pretend) runs no queries, so there is nothing to read or link.
        if (DB::connection()->pretending()) {
            return 0;
        }

        $saved = DB::table('entity_field_settings')
            ->where('entity', 'doctor')
            ->where('field_key', 'specialisation')
            ->value('options');

        $pairs = $saved
            ? (json_decode($saved, true) ?: [])
            : (collect(DoctorFields::all())->firstWhere('key', 'specialisation')['options'] ?? []);

        $inUse = DB::table('doctors')
            ->whereNotNull('specialisation')
            ->where('specialisation', '<>', '')
            ->distinct()
            ->pluck('specialisation');

        foreach ($inUse as $name) {
            $pairs[] = ['value' => $name, 'label' => $name];
        }

        $created = 0;

        foreach (array_values($pairs) as $order => $pair) {
            $label = mb_substr(trim((string) ($pair['label'] ?? $pair['value'] ?? '')), 0, 120);

            if ($label === '') {
                continue;
            }

            $id = DB::table('departments')
                ->whereNull('parent_id')
                ->whereNull('deleted_at')
                ->whereRaw('lower(name) = ?', [mb_strtolower($label)])
                ->value('id');

            if (! $id) {
                $id = DB::table('departments')->insertGetId([
                    'name' => $label,
                    'is_active' => true,
                    'sort_order' => $order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created++;
            }

            // A renamed option kept its stored value, so match on either.
            $value = mb_strtolower(trim((string) ($pair['value'] ?? $label)));

            DB::table('doctors')
                ->whereNull('department_id')
                ->where(fn ($query) => $query
                    ->whereRaw('lower(specialisation) = ?', [$value])
                    ->orWhereRaw('lower(specialisation) = ?', [mb_strtolower($label)]))
                ->update(['department_id' => $id]);
        }

        return $created;
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
