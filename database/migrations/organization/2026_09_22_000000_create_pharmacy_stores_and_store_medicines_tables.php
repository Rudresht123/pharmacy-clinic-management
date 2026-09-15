<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy stores, and what each one stocks.
 *
 * A store always belongs to a branch (`locations`): a branch can have a
 * counter, an IPD pharmacy and a store room, and each keeps its own stock
 * from Phase 3 on. One store per branch is the default — the one whose
 * availability a doctor sees while prescribing.
 *
 * `store_medicines` is the branch-wise configuration: which medicines this
 * store stocks, and the levels that make one "low". It holds no quantity;
 * stock lives on batches and is only ever changed through the ledger.
 *
 * Deferred to Phase 3, with the suppliers table it points at:
 * `store_medicines.preferred_supplier_id`.
 *
 * Foreign keys restrict: a store or a medicine with configuration cannot be
 * erased from under it, from a console or anywhere else.
 */
return new class extends Migration
{
    private const STORE_TYPES = ['hospital_pharmacy', 'opd_counter', 'ipd_pharmacy', 'emergency', 'retail', 'central'];

    public function up(): void
    {
        Schema::create('pharmacy_stores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            $table->string('name', 191);
            $table->string('code', 30);
            $table->string('store_type', 20);

            // The store a doctor sees availability from. One per branch.
            $table->boolean('is_default')->default(false);

            $table->foreignId('pharmacist_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Blank means the branch's own address and phone apply.
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();

            // Only when the store holds its own licence; otherwise the branch's applies.
            $table->string('drug_license_no', 60)->nullable();
            $table->date('drug_license_expiry_date')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index('location_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_stores ADD CONSTRAINT pharmacy_stores_store_type_check '.
            "CHECK (store_type IN ('".implode("', '", self::STORE_TYPES)."'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX pharmacy_stores_code_unique ON pharmacy_stores (lower(code)) '.
            'WHERE deleted_at IS NULL'
        );

        // At most one default per branch, among live stores.
        DB::statement(
            'CREATE UNIQUE INDEX pharmacy_stores_one_default_per_branch ON pharmacy_stores (location_id) '.
            'WHERE is_default AND deleted_at IS NULL'
        );

        Schema::create('store_medicines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();

            // At or below the reorder level, the medicine counts as low stock.
            $table->integer('reorder_level')->default(0);
            $table->integer('minimum_stock_level')->default(0);
            $table->integer('maximum_stock_level')->nullable();

            // Off = "we have stopped stocking this here".
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index('medicine_id');
        });

        DB::statement(
            'ALTER TABLE store_medicines ADD CONSTRAINT store_medicines_levels_check CHECK ('.
            'reorder_level >= 0 AND minimum_stock_level >= 0 '.
            'AND (maximum_stock_level IS NULL OR maximum_stock_level >= minimum_stock_level))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX store_medicines_pair_unique ON store_medicines (pharmacy_store_id, medicine_id) '.
            'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('store_medicines');
        Schema::dropIfExists('pharmacy_stores');
    }
};
