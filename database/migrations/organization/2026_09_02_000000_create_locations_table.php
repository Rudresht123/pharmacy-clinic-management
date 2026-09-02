<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The second tier of the tenancy model: one organization operates many
 * physical locations — stores, warehouses, and later clinics.
 *
 * Deliberately generic. The spec is explicit that "store" must not be
 * hardcoded anywhere, so the kind of place a row describes lives in `type`
 * rather than in the table name or in separate tables per kind.
 *
 * No `organization_id` column: this table only ever exists inside a tenant
 * database, so the database itself is the tenant boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            /*
            |------------------------------------------------------------------
            | Identity
            |------------------------------------------------------------------
            */
            $table->string('name', 191);
            $table->string('code', 30);
            $table->string('type', 40);
            $table->boolean('is_active')->default(true);

            /*
            |------------------------------------------------------------------
            | Where it is
            |------------------------------------------------------------------
            */
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 191)->nullable();

            /*
            |------------------------------------------------------------------
            | Compliance
            |
            | Per premises, not per organization: in India each pharmacy
            | location holds its own drug licence, so these cannot live on
            | `organizations` alone.
            |------------------------------------------------------------------
            */
            $table->string('gstin', 20)->nullable();
            $table->string('drug_license_no', 60)->nullable();
            $table->date('drug_license_expiry_date')->nullable();

            /*
             * Values for fields an organization adds itself. Nothing writes
             * this yet — it ships now because adding a column later means
             * another `tenants:migrate` across every tenant database.
             */
            $table->jsonb('custom_fields')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
        });

        // Varchar + CHECK rather than a native enum, matching the house style.
        // CLINIC and DOCTOR_VISITING_LOCATION are accepted by the database but
        // withheld from validation until those modules exist.
        DB::statement(
            'ALTER TABLE locations ADD CONSTRAINT locations_type_check '.
            "CHECK (type IN ('RETAIL_STORE', 'WHOLESALE_STORE', 'WAREHOUSE', 'CLINIC', 'DOCTOR_VISITING_LOCATION'))"
        );

        /*
         * Partial unique index rather than a plain one: a soft-deleted
         * location should hand its code back for reuse, and the plain
         * constraint has no way to say that. Same reasoning — and the same
         * index name Laravel would have generated — as
         * masterdb/..._make_organization_code_and_subdomain_unique_per_soft_delete.
         */
        DB::statement(
            'CREATE UNIQUE INDEX locations_code_unique ON locations (code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS locations_code_unique');
        DB::statement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS locations_type_check');

        Schema::dropIfExists('locations');
    }
};
