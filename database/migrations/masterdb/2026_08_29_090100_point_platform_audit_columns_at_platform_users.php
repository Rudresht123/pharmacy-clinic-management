<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-point created_by / updated_by from `users` to `platform_users`.
 *
 * Organizations and organization types are central records — only a platform
 * administrator can create one. Their audit columns referenced `users`, which
 * was correct only while the panel and the tenants shared a login table. Now
 * that §18's split guard exists, an id in these columns means a platform_users
 * row, and the constraint has to say so.
 *
 * The handful of existing rows were stamped with `users` ids that no longer
 * mean anything here, so they are nulled rather than guessed at. The columns
 * are nullable and only carry audit metadata, so nothing downstream breaks —
 * and from the next write onward they are populated correctly.
 */
return new class extends Migration
{
    /** table => the two columns to re-point. */
    private const TABLES = [
        'organizations' => ['created_by', 'updated_by'],
        'organization_type' => ['created_by', 'updated_by'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $blueprint->dropForeign("{$table}_{$column}_foreign");
                }
            });

            DB::table($table)->update(array_fill_keys($columns, null));

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->foreign($column)
                        ->references('id')
                        ->on('platform_users')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $blueprint->dropForeign("{$table}_{$column}_foreign");
                }
            });

            DB::table($table)->update(array_fill_keys($columns, null));

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->foreign($column)
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }
            });
        }
    }
};
