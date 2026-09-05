<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A number a patient can quote, and the desk can search on.
 *
 * Until now a patient was identified by name and phone. Two people called
 * Rahul Sharma were indistinguishable in a search result and in the queue, a
 * phone number is shared across a family, and there was nothing to read out
 * over the counter or print on a slip.
 *
 * Nullable, because the column has to arrive on databases that already hold
 * patients and backfilling under a unique index inside the migration would
 * fail the moment two organizations were migrated concurrently. Existing rows
 * are numbered below, in order, and everything created afterwards is numbered
 * by CustomerRepository.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('id');
        });

        /*
         * Partial, on both counts. `deleted_at IS NULL` so a deleted
         * patient's number can be reused, matching how locations already
         * treat their code. `code IS NOT NULL` so the column can stay
         * nullable — Postgres treats NULLs as distinct anyway, but stating it
         * makes the intent readable rather than incidental.
         */
        DB::statement(
            'CREATE UNIQUE INDEX customers_code_unique ON customers (code) '.
            'WHERE deleted_at IS NULL AND code IS NOT NULL'
        );

        $this->backfill();
    }

    /**
     * Number the patients already on the books, oldest first.
     *
     * In id order so the numbers agree with the order people were registered,
     * which is what anybody would expect of them. The DB facade rather than
     * the model is deliberate here: a migration has to keep replaying
     * correctly against the schema as it was the day it was written, and a
     * model changes underneath it.
     */
    private function backfill(): void
    {
        $rows = DB::table('customers')->orderBy('id')->pluck('id');

        foreach ($rows as $index => $id) {
            DB::table('customers')
                ->where('id', $id)
                ->update(['code' => 'P-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_code_unique');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
