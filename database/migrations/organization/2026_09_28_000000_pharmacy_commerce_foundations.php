<?php

use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\StockMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the pharmacy needs before it can sell anything.
 *
 * Three things, all of them decisions that are expensive to change once
 * sales exist:
 *
 *   1. The catalogue stops being medicine-only. A medical store sells
 *      syringes, gloves, a BP monitor and baby soap beside its tablets, and
 *      those have no dosage form. `item_kind` says which is which, and the
 *      form's fixed lists only apply to a medicine.
 *
 *   2. Every item carries its tax. In India MRP is tax-inclusive, so the
 *      rate is what a bill splits out of the price rather than adds to it —
 *      but the rate has to be recorded per item, with its HSN code, or no
 *      invoice can be printed. What a SALE stores is a copy of this rate at
 *      the moment of sale; changing it here never rewrites an old bill.
 *
 *   3. One row of settings per organisation: how bills are numbered and
 *      rounded, how far ahead expiry is a warning, whether a counter may
 *      sell to somebody who is not registered, and on credit.
 *
 * Plus the two document sequences and the two ledger movement types that
 * selling will need, added here so the ledger's CHECK is widened once.
 */
return new class extends Migration
{
    /** Numbering for what Phase B issues. */
    private const SEQUENCES = ['sale_number_seq', 'sale_return_number_seq'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} AS bigint START WITH 1 MINVALUE 1 NO CYCLE");
        }

        /*
        |------------------------------------------------------------------
        | The catalogue: what is sold, not only what is prescribed
        |------------------------------------------------------------------
        */
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('item_kind', 20)->default(Medicine::MEDICINE)->after('id');

            // What the counter scans and the shelf label says.
            $table->string('sku', 40)->nullable()->after('medicine_code');
            $table->string('barcode', 60)->nullable()->after('sku');

            // What the bill needs.
            $table->string('hsn_code', 10)->nullable()->after('category');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('hsn_code');
        });

        // A pair of gloves has no dosage form; a tablet must have one.
        DB::statement('ALTER TABLE medicines ALTER COLUMN dosage_form DROP NOT NULL');
        DB::statement('ALTER TABLE medicines DROP CONSTRAINT medicines_dosage_form_check');

        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_dosage_form_check CHECK ('.
            '(dosage_form IS NULL OR dosage_form IN ('.$in(Medicine::DOSAGE_FORMS).')) '.
            "AND (item_kind <> '".Medicine::MEDICINE."' OR dosage_form IS NOT NULL))"
        );

        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_item_kind_check '.
            'CHECK (item_kind IN ('.$in(Medicine::ITEM_KINDS).'))'
        );

        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_tax_rate_check '.
            'CHECK (tax_rate >= 0 AND tax_rate <= 100)'
        );

        /*
         * The identity index compared dosage_form directly. Two NULLs are
         * distinct to Postgres, so without coalesce the same box of gloves
         * could be added twice.
         */
        DB::statement('DROP INDEX medicines_identity_unique');
        DB::statement(
            'CREATE UNIQUE INDEX medicines_identity_unique ON medicines ('.
            "lower(generic_name), lower(coalesce(brand_name, '')), lower(coalesce(strength, '')), ".
            "coalesce(dosage_form, ''), lower(coalesce(manufacturer, ''))".
            ') WHERE deleted_at IS NULL'
        );

        // One barcode, one item — a scan that matched two would be unusable.
        DB::statement(
            'CREATE UNIQUE INDEX medicines_sku_unique ON medicines (lower(sku)) '.
            'WHERE deleted_at IS NULL AND sku IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX medicines_barcode_unique ON medicines (barcode) '.
            'WHERE deleted_at IS NULL AND barcode IS NOT NULL'
        );

        /*
        |------------------------------------------------------------------
        | The ledger learns about selling
        |------------------------------------------------------------------
        |
        | Dispensing against a prescription already has its own type. A sale
        | is the counter sale — the same movement, without a prescription
        | behind it — and a sale return puts the stock back.
        */
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_rules_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_rules_check CHECK ('.
            'movement_type IN ('.$in(StockMovement::TYPES).') '.
            'AND quantity <> 0 '.
            'AND quantity_after = quantity_before + quantity '.
            'AND quantity_after >= 0)'
        );

        /*
        |------------------------------------------------------------------
        | Settings — one row, the organisation's own
        |------------------------------------------------------------------
        */
        Schema::create('pharmacy_settings', function (Blueprint $table) {
            $table->id();

            // Numbering and rounding on a bill.
            $table->string('invoice_prefix', 6)->default('INV');
            $table->boolean('round_off_enabled')->default(true);

            /*
             * Which price the counter charges. MRP is the printed ceiling and
             * what most stores charge; a store that discounts by policy sets
             * its own selling price on the batch and charges that instead.
             */
            $table->string('price_basis', 10)->default(PharmacySetting::PRICE_MRP);

            // Indian MRP includes GST, so tax is split out of the price.
            $table->boolean('prices_include_tax')->default(true);

            // How far ahead a batch counts as expiring soon.
            $table->integer('expiry_warning_days')->default(90);

            // A standalone store sells to whoever walks in.
            $table->boolean('allow_walk_in')->default(true);
            $table->boolean('credit_sales_enabled')->default(false);

            /*
             * Whether a medicine marked prescription-only refuses to be sold
             * without one. Kept as a setting because a clinic pharmacy always
             * has the prescription to hand, while a store sometimes records
             * it after the fact.
             */
            $table->boolean('require_prescription')->default(true);

            $table->string('default_payment_method', 14)->default(PharmacySetting::CASH);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE pharmacy_settings ADD CONSTRAINT pharmacy_settings_rules_check CHECK ('.
            'price_basis IN ('.$in(PharmacySetting::PRICE_BASES).') '.
            'AND default_payment_method IN ('.$in(PharmacySetting::PAYMENT_METHODS).') '.
            'AND expiry_warning_days BETWEEN 1 AND 365 '.
            "AND invoice_prefix <> '')"
        );

        // One row, forever: the settings are the organisation's, not a list.
        DB::statement('CREATE UNIQUE INDEX pharmacy_settings_singleton ON pharmacy_settings ((true))');

        if (! DB::connection()->pretending()) {
            DB::table('pharmacy_settings')->insert([
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        Schema::dropIfExists('pharmacy_settings');

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_rules_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_rules_check CHECK ('.
            'movement_type IN ('.$in(array_values(array_diff(StockMovement::TYPES, [StockMovement::SALE, StockMovement::SALE_RETURN]))).') '.
            'AND quantity <> 0 '.
            'AND quantity_after = quantity_before + quantity '.
            'AND quantity_after >= 0)'
        );

        DB::statement('DROP INDEX IF EXISTS medicines_sku_unique');
        DB::statement('DROP INDEX IF EXISTS medicines_barcode_unique');
        DB::statement('ALTER TABLE medicines DROP CONSTRAINT medicines_item_kind_check');
        DB::statement('ALTER TABLE medicines DROP CONSTRAINT medicines_tax_rate_check');

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn(['item_kind', 'sku', 'barcode', 'hsn_code', 'tax_rate']);
        });

        DB::statement("UPDATE medicines SET dosage_form = 'other' WHERE dosage_form IS NULL");
        DB::statement('ALTER TABLE medicines ALTER COLUMN dosage_form SET NOT NULL');
        DB::statement('ALTER TABLE medicines DROP CONSTRAINT medicines_dosage_form_check');
        DB::statement(
            'ALTER TABLE medicines ADD CONSTRAINT medicines_dosage_form_check '.
            'CHECK (dosage_form IN ('.$in(Medicine::DOSAGE_FORMS).'))'
        );

        DB::statement('DROP INDEX medicines_identity_unique');
        DB::statement(
            'CREATE UNIQUE INDEX medicines_identity_unique ON medicines ('.
            "lower(generic_name), lower(coalesce(brand_name, '')), lower(coalesce(strength, '')), ".
            "dosage_form, lower(coalesce(manufacturer, ''))".
            ') WHERE deleted_at IS NULL'
        );

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }
    }
};
