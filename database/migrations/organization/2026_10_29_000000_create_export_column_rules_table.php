<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Export Center: which columns each role may take away, and the export
 * capabilities for the roles that already exist.
 *
 * A rule names the columns HIDDEN from a role in one export, not the ones
 * shown. No row is "every column", which is what a role starts with; and a
 * column added to an export in a later release reaches the roles that were
 * never narrowed instead of silently missing from all of them.
 *
 * The DB facade for the capability backfill, as in the earlier capability
 * migrations: a migration has to replay against the schema as it was the day
 * it was written, and a model changes underneath it.
 */
return new class extends Migration
{
    /**
     * Roles made from a template get what that template now holds — the
     * same set RoleTemplates gives a new organization's roles, so an existing
     * clinic's Branch manager is not one capability short of a new clinic's.
     */
    private const GRANTS = [
        'head-office' => ['exports.patients', 'exports.pharmacy', 'exports.billing', 'exports.communication', 'exports.columns'],
        'branch-manager' => ['exports.patients', 'exports.pharmacy', 'exports.billing'],
        'accountant' => ['exports.billing'],
    ];

    public function up(): void
    {
        Schema::create('export_column_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            // An export's key, e.g. "billing.invoices" — see ExportCatalog.
            $table->string('dataset', 60);
            $table->json('hidden_columns');
            $table->timestamps();

            $table->unique(['role_id', 'dataset']);
        });

        $now = now();

        // The patient list's own export became the Export Center's patient tab.
        $renamed = DB::table('role_capabilities')->where('capability', 'customers.export')->pluck('role_id');

        foreach ($renamed as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => 'exports.patients'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }

        DB::table('role_capabilities')->where('capability', 'customers.export')->delete();

        foreach (self::GRANTS as $slug => $capabilities) {
            foreach (DB::table('roles')->where('slug', $slug)->pluck('id') as $roleId) {
                foreach ($capabilities as $capability) {
                    DB::table('role_capabilities')->updateOrInsert(
                        ['role_id' => $roleId, 'capability' => $capability],
                        ['updated_at' => $now, 'created_at' => $now],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('role_capabilities')->where('capability', 'like', 'exports.%')->delete();

        Schema::dropIfExists('export_column_rules');
    }
};
