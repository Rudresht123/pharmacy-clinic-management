<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: suppliers, batches, the stock ledger and the documents that
 * move stock (goods received, adjustments, transfers).
 *
 * Quantities are integers in each medicine's base unit; prices are per base
 * unit. A batch's `quantity_available` is written by one piece of code only
 * (StockMovementService), always in the same transaction as the ledger row
 * that explains it, and three things stand behind that rule:
 *
 *   - the service locks the batch row before reading it;
 *   - CHECK (quantity_available >= 0) on the batch;
 *   - CHECK (quantity_after = quantity_before + quantity AND quantity_after >= 0)
 *     on every ledger row.
 *
 * The ledger, adjustments, transfers and the lines of a GRN are append-only,
 * enforced by trigger the same way activity_logs is — a wrong row is answered
 * by a compensating row, never by an edit. A GRN header can be cancelled (an
 * update) but never deleted.
 *
 * `movement_date` is a plain timestamp, not the timestamptz the architecture
 * note proposed: every timestamp in this schema is without zone, in
 * Asia/Kolkata since the Phase 0 shift, and one exception would be the
 * column every report joins on.
 *
 * Document numbers come from the Phase 0 sequences, as column defaults.
 */
return new class extends Migration
{
    private const BATCH_STATUSES = ['active', 'blocked', 'recalled', 'exhausted', 'expired'];

    private const MOVEMENT_TYPES = [
        'opening_balance', 'purchase', 'stock_inward', 'transfer_in', 'transfer_out', 'dispensing',
        'dispensing_reversal', 'return_from_patient', 'supplier_return', 'damage', 'expiry_writeoff',
        'adjustment_increase', 'adjustment_decrease', 'correction',
    ];

    private const INWARD_TYPES = ['purchase', 'opening_balance', 'return_from_patient'];

    private const REASON_CODES = ['damage', 'expiry_writeoff', 'count_correction', 'loss', 'other'];

    /** Tables no row of which may change or disappear once written. */
    private const APPEND_ONLY = [
        'stock_movements', 'stock_adjustments', 'stock_transfers', 'stock_transfer_items', 'stock_inward_items',
    ];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_forbid_change() RETURNS trigger AS $trigger$
BEGIN
    RAISE EXCEPTION '% is append-only: % is not permitted', TG_TABLE_NAME, TG_OP;
END;
$trigger$ LANGUAGE plpgsql;
SQL);

        /*
        |------------------------------------------------------------------
        | Suppliers
        |------------------------------------------------------------------
        */
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('code', 30)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('drug_license_no', 60)->nullable();
            $table->date('drug_license_expiry_date')->nullable();
            $table->string('contact_person', 191)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX suppliers_code_unique ON suppliers (lower(code)) '.
            'WHERE deleted_at IS NULL AND code IS NOT NULL'
        );
        // A GSTIN identifies one business.
        DB::statement(
            'CREATE UNIQUE INDEX suppliers_gstin_unique ON suppliers (upper(gstin)) '.
            'WHERE deleted_at IS NULL AND gstin IS NOT NULL'
        );

        // Deferred from Phase 2, now that there is something to point at.
        Schema::table('store_medicines', function (Blueprint $table) {
            $table->foreignId('preferred_supplier_id')->nullable()->after('maximum_stock_level')
                ->constrained('suppliers')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | Batches
        |------------------------------------------------------------------
        */
        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            // The GRN line that created it; the constraint is added below.
            $table->unsignedBigInteger('stock_inward_item_id')->nullable();

            $table->string('batch_number', 60);
            $table->date('expiry_date');
            $table->date('manufacture_date')->nullable();

            // Per base unit: a tablet, not a strip.
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('mrp', 12, 2);

            $table->integer('quantity_received');
            $table->integer('quantity_available')->default(0);
            $table->integer('damaged_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);

            $table->string('status', 12)->default('active');
            $table->string('blocked_reason', 500)->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();

            $table->date('received_date');

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();
        });

        DB::statement(
            'ALTER TABLE medicine_batches ADD CONSTRAINT medicine_batches_rules_check CHECK ('.
            'status IN ('.$in(self::BATCH_STATUSES).') '.
            'AND (manufacture_date IS NULL OR expiry_date > manufacture_date) '.
            'AND purchase_price >= 0 AND selling_price >= 0 AND mrp >= 0 '.
            // Selling above MRP is unlawful in India.
            'AND selling_price <= mrp '.
            'AND quantity_received > 0 AND quantity_available >= 0 '.
            'AND damaged_quantity >= 0 AND returned_quantity >= 0)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX medicine_batches_number_unique '.
            'ON medicine_batches (pharmacy_store_id, medicine_id, lower(batch_number)) '.
            'WHERE deleted_at IS NULL'
        );

        // The one query dispensing runs on every line: first expiry, first out.
        DB::statement(
            'CREATE INDEX medicine_batches_fefo ON medicine_batches (pharmacy_store_id, medicine_id, expiry_date) '.
            "WHERE status = 'active' AND quantity_available > 0 AND deleted_at IS NULL"
        );

        /*
        |------------------------------------------------------------------
        | Goods received
        |------------------------------------------------------------------
        */
        Schema::create('stock_inwards', function (Blueprint $table) {
            $table->id();
            $table->string('inward_number', 20)
                ->default(DB::raw("hms_document_number('GRN', 'stock_inward_number_seq')"));
            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('inward_type', 20);
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('received_date');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('posted');
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('inward_number');
            $table->index(['pharmacy_store_id', 'received_date']);
        });

        DB::statement(
            'ALTER TABLE stock_inwards ADD CONSTRAINT stock_inwards_rules_check CHECK ('.
            'inward_type IN ('.$in(self::INWARD_TYPES).') '.
            "AND status IN ('posted', 'cancelled') ".
            "AND (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)) ".
            'AND total_amount >= 0)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX stock_inwards_idempotency_unique ON stock_inwards (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('stock_inward_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_inward_id')->constrained('stock_inwards')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->string('batch_number', 60);
            $table->date('expiry_date');
            $table->date('manufacture_date')->nullable();
            // What a pack held when this was received, so the line reads the
            // same after the medicine's pack size changes.
            $table->integer('pack_size');
            $table->integer('quantity');
            $table->integer('free_quantity')->default(0);
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('mrp', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE stock_inward_items ADD CONSTRAINT stock_inward_items_rules_check CHECK ('.
            'quantity > 0 AND free_quantity >= 0 AND pack_size > 0 AND line_total >= 0 '.
            'AND purchase_price >= 0 AND selling_price <= mrp)'
        );

        Schema::table('medicine_batches', function (Blueprint $table) {
            $table->foreign('stock_inward_item_id')->references('id')->on('stock_inward_items')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | The ledger
        |------------------------------------------------------------------
        */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // Branch and medicine restated, so ledger reports need no join.
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->integer('quantity');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->text('notes')->nullable();
            // Restrict, not null-on-delete: nulling would be an UPDATE the
            // trigger refuses. Users are soft-deleted, so this never bites.
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('performed_by_name', 191)->nullable();
            $table->timestamp('movement_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pharmacy_store_id', 'medicine_id', 'movement_date']);
            $table->index(['medicine_batch_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_rules_check CHECK ('.
            'movement_type IN ('.$in(self::MOVEMENT_TYPES).') '.
            'AND quantity <> 0 '.
            'AND quantity_after = quantity_before + quantity '.
            'AND quantity_after >= 0)'
        );

        /*
        |------------------------------------------------------------------
        | Adjustments and transfers
        |------------------------------------------------------------------
        */
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number', 20)
                ->default(DB::raw("hms_document_number('ADJ', 'stock_adjustment_number_seq')"));
            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->string('direction', 10);
            $table->integer('quantity');
            $table->string('reason_code', 20);
            $table->string('reason', 500);
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->timestamps();

            $table->unique('adjustment_number');
        });

        DB::statement(
            'ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_rules_check CHECK ('.
            "direction IN ('increase', 'decrease') ".
            'AND reason_code IN ('.$in(self::REASON_CODES).') '.
            'AND quantity > 0 AND length(trim(reason)) > 0)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX stock_adjustments_idempotency_unique ON stock_adjustments (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 20)
                ->default(DB::raw("hms_document_number('TRF', 'stock_transfer_number_seq')"));
            $table->foreignId('from_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('to_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            // Immediate in v1; an "in transit" state can be added to the CHECK later.
            $table->string('status', 12)->default('completed');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->timestamps();

            $table->unique('transfer_number');
        });

        DB::statement(
            'ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_rules_check CHECK ('.
            "from_store_id <> to_store_id AND status IN ('completed'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX stock_transfers_idempotency_unique ON stock_transfers (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('source_batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->foreignId('destination_batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->integer('quantity');
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_rules_check CHECK (quantity > 0)'
        );

        /*
        |------------------------------------------------------------------
        | Immutability
        |------------------------------------------------------------------
        */
        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_no_update_or_delete BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION hms_forbid_change()'
            );
        }

        // A GRN is cancelled, never deleted.
        DB::unprepared(
            'CREATE TRIGGER stock_inwards_no_delete BEFORE DELETE ON stock_inwards '.
            'FOR EACH ROW EXECUTE FUNCTION hms_forbid_change()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');

        Schema::table('medicine_batches', function (Blueprint $table) {
            $table->dropForeign(['stock_inward_item_id']);
        });

        Schema::dropIfExists('stock_inward_items');
        Schema::dropIfExists('stock_inwards');
        Schema::dropIfExists('medicine_batches');

        Schema::table('store_medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
        });

        Schema::dropIfExists('suppliers');

        DB::unprepared('DROP FUNCTION IF EXISTS hms_forbid_change()');
    }
};
