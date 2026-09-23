<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use App\Models\Tenant\Supplier;
use App\Services\Pharmacy\Inventory\StockConflict;
use App\Services\Pharmacy\Inventory\StockInwardService;
use App\Services\Pharmacy\Sales\SalesService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A working pharmacy in one tenant: catalogue, stock, and a fortnight of bills.
 *
 * Every figure on the screens is about *shape* — what is low, what expires in
 * three weeks, what yesterday took, who still owes — and none of that can be
 * judged against two medicines and one sale. So this builds a shop that has
 * been trading for a while.
 *
 * Stock and bills go through the real services, never straight into the
 * tables. That is the point: the demo data obeys every rule the product
 * does — one ledger row per movement, quantities that add up, FEFO, no
 * expired stock sold — so what a client is shown is what they would get.
 *
 * Two consequences of doing it honestly, both deliberate:
 *
 *   - Bills are back-dated afterwards, so reports have a history. The stock
 *     LEDGER keeps its real timestamps, because it is append-only by trigger
 *     and nothing, including this, may rewrite it.
 *   - Credit sales are switched on in the organisation's settings, because a
 *     demo with nobody owing anything cannot show the ledger it is meant to.
 *
 * Run by hand against a chosen organisation, never from `db:seed`: it writes
 * real rows into a real tenant.
 */
class SeedPharmacyDemoCommand extends Command
{
    protected $signature = 'pharmacy:demo
        {--org= : Organization id or subdomain; the only one, if there is only one}
        {--days=14 : Days of billing history to build, ending today}
        {--bills=18 : Roughly how many bills a day}';

    protected $description = 'Build a stocked pharmacy with a fortnight of bills, for looking at the screens with';

    /**
     * The catalogue.
     *
     * generic, brand, strength, form, unit, pack, maker, category, schedule,
     * prescription-only, HSN, GST%, ₹ cost per pack, ₹ MRP per pack.
     */
    private const MEDICINES = [
        ['Paracetamol', 'Dolo 650', '650 mg', 'tablet', 'tablet', 15, 'Micro Labs', 'Analgesic', 'OTC', false, '3004', 12, 22, 31],
        ['Amoxicillin', 'Mox 500', '500 mg', 'capsule', 'capsule', 10, 'Sun Pharma', 'Antibiotic', 'H', true, '3004', 12, 68, 92],
        ['Azithromycin', 'Azithral 500', '500 mg', 'tablet', 'tablet', 5, 'Alembic', 'Antibiotic', 'H', true, '3004', 12, 82, 118],
        ['Pantoprazole', 'Pan 40', '40 mg', 'tablet', 'tablet', 15, 'Alkem', 'Antacid', 'H', true, '3004', 12, 95, 134],
        ['Cetirizine', 'Cetzine', '10 mg', 'tablet', 'tablet', 10, 'GSK', 'Antihistamine', 'OTC', false, '3004', 12, 18, 28],
        ['Metformin', 'Glycomet 500', '500 mg', 'tablet', 'tablet', 20, 'USV', 'Antidiabetic', 'H', true, '3004', 12, 34, 48],
        ['Amlodipine', 'Amlokind 5', '5 mg', 'tablet', 'tablet', 15, 'Mankind', 'Antihypertensive', 'H', true, '3004', 12, 26, 39],
        ['Atorvastatin', 'Atorva 10', '10 mg', 'tablet', 'tablet', 10, 'Zydus', 'Statin', 'H', true, '3004', 12, 58, 84],
        ['Ibuprofen', 'Brufen 400', '400 mg', 'tablet', 'tablet', 15, 'Abbott', 'Analgesic', 'OTC', false, '3004', 12, 31, 46],
        ['Ondansetron', 'Emeset 4', '4 mg', 'tablet', 'tablet', 10, 'Cipla', 'Antiemetic', 'H', true, '3004', 12, 42, 62],
        ['Cough syrup', 'Ascoril LS', '100 ml', 'syrup', 'bottle', 1, 'Glenmark', 'Respiratory', 'H', true, '3004', 12, 88, 126],
        ['ORS powder', 'Electral', '21.8 g', 'powder', 'sachet', 1, 'FDC', 'Rehydration', 'OTC', false, '2106', 5, 16, 22],
        ['Vitamin D3', 'Uprise D3', '60000 IU', 'capsule', 'capsule', 4, 'Alkem', 'Supplement', 'OTC', false, '3004', 12, 52, 76],
        ['Calcium carbonate', 'Shelcal 500', '500 mg', 'tablet', 'tablet', 15, 'Torrent', 'Supplement', 'OTC', false, '3004', 12, 88, 121],
        ['Diclofenac gel', 'Volini', '30 g', 'gel', 'tube', 1, 'Sun Pharma', 'Topical', 'OTC', false, '3004', 12, 96, 138],
        ['Betadine ointment', 'Betadine', '20 g', 'ointment', 'tube', 1, 'Win-Medicare', 'Antiseptic', 'OTC', false, '3004', 12, 78, 112],
        ['Insulin glargine', 'Lantus', '100 IU/ml', 'injection', 'vial', 1, 'Sanofi', 'Antidiabetic', 'H', true, '3004', 5, 720, 892],
        ['Salbutamol inhaler', 'Asthalin HFA', '100 mcg', 'inhaler', 'unit', 1, 'Cipla', 'Respiratory', 'H', true, '3004', 12, 128, 176],
    ];

    /**
     * What a medical store sells besides medicine.
     *
     * kind, name, brand, unit, pack, maker, category, HSN, GST%, cost, MRP.
     */
    private const GOODS = [
        [Medicine::CONSUMABLE, 'Examination gloves (medium)', 'Dr. Trust', 'unit', 100, 'Dr. Trust', 'Disposables', '4015', 12, 380, 520],
        [Medicine::CONSUMABLE, 'Disposable syringe 5 ml', 'Dispo Van', 'unit', 100, 'HMD', 'Disposables', '9018', 12, 420, 590],
        [Medicine::CONSUMABLE, 'Cotton roll 100 g', 'Bella', 'unit', 1, 'Bella', 'Dressing', '5601', 5, 42, 65],
        [Medicine::CONSUMABLE, 'Surgical face mask', '3-ply', 'unit', 50, 'Magnum', 'Disposables', '6307', 5, 95, 150],
        [Medicine::DEVICE, 'Digital BP monitor', 'Omron HEM-7124', 'unit', 1, 'Omron', 'Devices', '9018', 12, 1720, 2295],
        [Medicine::DEVICE, 'Digital thermometer', 'Dr. Morepen', 'unit', 1, 'Morepen', 'Devices', '9025', 18, 118, 199],
        [Medicine::DEVICE, 'Glucometer strips (25)', 'Accu-Chek', 'unit', 25, 'Roche', 'Devices', '3822', 12, 640, 850],
        [Medicine::OTHER_ITEM, 'Hand sanitiser 500 ml', 'Lifebuoy', 'bottle', 1, 'HUL', 'Personal care', '3808', 18, 148, 210],
        [Medicine::OTHER_ITEM, 'Baby soap 75 g', 'Himalaya', 'unit', 1, 'Himalaya', 'Personal care', '3401', 18, 52, 80],
    ];

    /** Name, GSTIN, city. */
    private const SUPPLIERS = [
        ['Micro Distributors', '09AABCM1234C1Z5', 'Lucknow'],
        ['Shree Pharma Agencies', '09AACCS5678D1Z2', 'Kanpur'],
        ['Medilink Wholesale', '09AADCM9012E1Z8', 'Prayagraj'],
        ['Surgical House', '09AAECS3456F1Z4', 'Varanasi'],
    ];

    /** Marks the batches this run delivers, so a re-run restocks rather than clashing. */
    private readonly string $run;

    public function __construct(
        private readonly StockInwardService $inwards,
        private readonly SalesService $sales,
    ) {
        parent::__construct();

        $this->run = mb_strtoupper(Str::random(3));
    }

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $this->info("Seeding pharmacy demo data into {$organization->organization_name}.");

        (new TenantConnectionService)->connect($organization->database_name);

        try {
            $settings = $this->settings();
            $suppliers = $this->suppliers();
            $catalogue = $this->catalogue();
            $stores = $this->stores();

            $this->line('');

            foreach ($stores as $store) {
                $this->fill($store, $catalogue, $suppliers);
            }

            $this->line('');

            $bills = $this->trade($stores, $settings, (int) $this->option('days'), (int) $this->option('bills'));

            $this->line('');
            $this->info("Done. {$catalogue->count()} items, {$stores->count()} store(s), {$bills} bills.");
            $this->line('Open /pharmacy/stock, /pharmacy/sales and /pharmacy/pos to see it.');

            if (! $settings->require_prescription) {
                $this->comment('Note: prescription-only enforcement is off in this tenant.');
            }
        } finally {
            (new TenantConnectionService)->disconnect();
        }

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------- setup */

    private function organization(): ?Organization
    {
        $asked = $this->option('org');

        if ($asked) {
            /*
             * A subdomain is never compared against `id`: Postgres types the
             * column as bigint and refuses the text outright, so `--org=demo`
             * used to fail with a cast error rather than finding the demo.
             */
            $organization = Organization::query()
                ->when(is_numeric($asked), fn ($query) => $query->whereKey((int) $asked))
                ->when(! is_numeric($asked), fn ($query) => $query->where('subdomain', $asked))
                ->first();

            if (! $organization) {
                $this->error("No organization matches \"{$asked}\".");
            }

            return $organization;
        }

        $all = Organization::all();

        if ($all->count() === 1) {
            return $all->first();
        }

        $this->error('Name one with --org=<id|subdomain>:');

        foreach ($all as $organization) {
            $this->line("  {$organization->id}  {$organization->subdomain}  {$organization->organization_name}");
        }

        return null;
    }

    /**
     * Credit on, so there is something owed to look at.
     *
     * A demo where every bill is settled to the rupee cannot show the
     * customer ledger, the "owed" column or the part-paid badge — which are
     * exactly the screens a medical store asks about first.
     */
    private function settings(): PharmacySetting
    {
        $settings = PharmacySetting::current();

        $settings->forceFill(['credit_sales_enabled' => true])->save();

        $this->line('  Settings: credit sales switched on for the demo');

        return $settings;
    }

    private function suppliers()
    {
        foreach (self::SUPPLIERS as [$name, $gstin, $city]) {
            Supplier::firstOrCreate(
                ['name' => $name],
                [
                    'gstin' => $gstin,
                    'contact_person' => 'Sales desk',
                    'phone' => '9'.random_int(100000000, 999999999),
                    'address' => $city,
                    'is_active' => true,
                ],
            );
        }

        $suppliers = Supplier::query()->orderBy('id')->get();

        $this->line("  Suppliers: {$suppliers->count()}");

        return $suppliers;
    }

    /**
     * The catalogue: medicines, and the things a store sells beside them.
     *
     * Prices are carried here per PACK, the way an invoice is written, and
     * the receiving service turns them into per-unit prices.
     */
    private function catalogue()
    {
        $made = 0;

        foreach (self::MEDICINES as [$generic, $brand, $strength, $form, $unit, $pack, $maker, $category, $schedule, $rx, $hsn, $gst]) {
            $medicine = Medicine::firstOrCreate(
                [
                    'generic_name' => $generic,
                    'brand_name' => $brand,
                    'strength' => $strength,
                    'dosage_form' => $form,
                    'manufacturer' => $maker,
                ],
                [
                    'item_kind' => Medicine::MEDICINE,
                    'base_unit' => $unit,
                    'pack_size' => $pack,
                    'category' => $category,
                    'schedule' => $schedule,
                    'prescription_required' => $rx,
                    'hsn_code' => $hsn,
                    'tax_rate' => $gst,
                    'is_active' => true,
                ],
            );

            $made += $medicine->wasRecentlyCreated ? 1 : 0;
        }

        foreach (self::GOODS as [$kind, $name, $brand, $unit, $pack, $maker, $category, $hsn, $gst]) {
            $item = Medicine::firstOrCreate(
                [
                    'generic_name' => $name,
                    'brand_name' => $brand,
                    'strength' => null,
                    'dosage_form' => null,
                    'manufacturer' => $maker,
                ],
                [
                    'item_kind' => $kind,
                    'base_unit' => $unit,
                    'pack_size' => $pack,
                    'category' => $category,
                    // Nothing here needs a prescription.
                    'prescription_required' => false,
                    'hsn_code' => $hsn,
                    'tax_rate' => $gst,
                    'is_active' => true,
                ],
            );

            $made += $item->wasRecentlyCreated ? 1 : 0;
        }

        $catalogue = Medicine::query()->orderBy('id')->get();

        $this->line("  Catalogue: {$catalogue->count()} items ({$made} added)");

        return $catalogue;
    }

    /** Whatever stores exist, or one at each branch. */
    private function stores()
    {
        $stores = PharmacyStore::query()->where('is_active', true)->orderBy('id')->get();

        if ($stores->isEmpty()) {
            $branches = Location::active()->orderBy('id')->get();

            if ($branches->isEmpty()) {
                $branches = collect([Location::create([
                    'name' => 'Main Store',
                    'code' => 'MAIN',
                    'type' => Location::RETAIL_STORE,
                    'city' => 'Pratapgarh',
                    'is_active' => true,
                ])]);
            }

            foreach ($branches as $index => $branch) {
                PharmacyStore::create([
                    'location_id' => $branch->id,
                    'name' => $branch->name.' Pharmacy',
                    'code' => Str::upper(Str::substr($branch->code ?? 'STR', 0, 6)).'-PH',
                    'store_type' => PharmacyStore::RETAIL,
                    'is_default' => $index === 0,
                    'is_active' => true,
                ]);
            }

            $stores = PharmacyStore::query()->where('is_active', true)->orderBy('id')->get();
        }

        $this->line('  Stores: '.$stores->pluck('name')->join(', '));

        return $stores;
    }

    /* ------------------------------------------------------------- the stock */

    /**
     * Stock on the shelf, received as goods received notes.
     *
     * Two batches for most items, one of them short-dated, so the expiry
     * screen has something to show and FEFO has something to choose between.
     * A few items are deliberately left thin, so "low stock" is not an empty
     * list on the day of the demo.
     */
    private function fill(PharmacyStore $store, $catalogue, $suppliers): void
    {
        $prices = $this->priceList();
        $lines = [];
        $short = [];

        foreach ($catalogue as $index => $item) {
            $price = $prices[$item->generic_name] ?? [40, 60];

            // One in seven is kept scarce, to put something in "low stock".
            $thin = $index % 7 === 3;

            $lines[] = [
                'medicine_id' => $item->id,
                'batch_number' => $this->batchNumber('B', $index),
                'expiry_date' => now()->addMonths(random_int(14, 30))->startOfMonth()->toDateString(),
                // Deep enough to trade on for a fortnight without emptying the
                // shelf; the thin ones stay thin on purpose.
                'quantity' => $thin ? 2 : random_int(24, 60),
                'free_quantity' => $index % 5 === 0 ? 1 : 0,
                'purchase_price' => $price[0],
                'mrp' => $price[1],
            ];

            // Every third item also has an older batch going out of date soon.
            if ($index % 3 === 0) {
                $short[] = [
                    'medicine_id' => $item->id,
                    'batch_number' => $this->batchNumber('A', $index),
                    'expiry_date' => now()->addDays(random_int(12, 75))->toDateString(),
                    'quantity' => random_int(2, 6),
                    'purchase_price' => $price[0],
                    'mrp' => $price[1],
                ];
            }
        }

        $received = 0;
        $notes = 0;

        /*
         * Delivered a few lines at a time over the fortnight, not all at once.
         *
         * A shop is restocked weekly, and the difference is not cosmetic: one
         * goods received note for the whole catalogue puts a single spike of
         * tens of thousands against a thousand rupees a day of selling, and
         * every chart drawn from it reads as a flat line beside one cliff.
         */
        foreach ([$short, $lines] as $batch) {
            foreach (array_chunk($batch, 6) as $chunk) {
                if ($chunk === []) {
                    continue;
                }

                // Deliveries walk backwards from a fortnight ago to this week.
                $received_on = now()->subDays(max(0, 18 - ($notes * 3) - random_int(0, 2)));

                try {
                    $this->inwards->receive($store, [
                        'inward_type' => 'purchase',
                        'supplier_id' => $suppliers->random()->id,
                        'supplier_invoice_no' => 'S-'.Str::upper(Str::random(6)),
                        'supplier_invoice_date' => $received_on->copy()->subDay()->toDateString(),
                        'received_date' => $received_on->toDateString(),
                        'items' => $chunk,
                    ], (string) Str::uuid());

                    $received += count($chunk);
                    $notes++;
                } catch (StockConflict $conflict) {
                    // Already on the shelf from an earlier run: nothing to do.
                    $this->line("    {$store->name}: {$conflict->getMessage()}");
                }
            }
        }

        /*
         * Reorder levels worth having.
         *
         * A flat thirty for everything was meaningless in both directions: a
         * shop holding four hundred tablets of paracetamol is never near it,
         * and a shop holding two BP monitors is never above it. A level is
         * about how fast the thing moves, so it is set against what the shelf
         * actually holds — a few weeks of a fast mover, one or two of a
         * device — and every sixth item is set deliberately high, so the
         * low-stock screen is never an empty list on the day of the demo.
         */
        $onHand = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->selectRaw('medicine_id, SUM(quantity_available) AS units')
            ->groupBy('medicine_id')
            ->pluck('units', 'medicine_id');

        foreach ($catalogue as $index => $item) {
            $units = (int) ($onHand[$item->id] ?? 0);
            $pack = max(1, (int) $item->pack_size);

            $level = $index % 6 === 2
                // Running out: a level above what is left.
                ? max($pack, (int) round($units * 1.3) + $pack)
                // Comfortable: about a fifth of the shelf, rounded to packs.
                : max($pack, (int) round($units * 0.2 / $pack) * $pack);

            StoreMedicine::updateOrCreate(
                ['pharmacy_store_id' => $store->id, 'medicine_id' => $item->id],
                [
                    'reorder_level' => $level,
                    'minimum_stock_level' => (int) round($level / 3),
                    'maximum_stock_level' => max($level * 4, $units + $pack),
                    'is_active' => true,
                ],
            );
        }

        $this->line("  {$store->name}: {$received} batch line(s) received");
    }

    /**
     * A batch number for this delivery.
     *
     * Stamped with the run, because a second delivery of the same medicine
     * arrives as a NEW lot with its own expiry — and the receiving service
     * rightly refuses to add stock to an existing batch number whose expiry
     * and prices disagree. Without this, re-running the seeder restocked
     * nothing and quietly left the shop empty.
     */
    private function batchNumber(string $prefix, int $index): string
    {
        return $prefix.str_pad((string) (1100 + $index), 4, '0', STR_PAD_LEFT).'-'.$this->run;
    }

    /**
     * Cost and MRP per pack, by generic name.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function priceList(): array
    {
        $prices = [];

        foreach (self::MEDICINES as $row) {
            $prices[$row[0]] = [$row[12], $row[13]];
        }

        foreach (self::GOODS as $row) {
            $prices[$row[1]] = [$row[9], $row[10]];
        }

        return $prices;
    }

    /* ------------------------------------------------------------- the trade */

    /**
     * A fortnight of billing.
     *
     * Bills are rung up through the counter's own service — so every one of
     * them moves stock, prices itself from the batch, and obeys FEFO — and
     * then back-dated, which is the only way to have a history to report on.
     */
    private function trade($stores, PharmacySetting $settings, int $days, int $perDay): int
    {
        $sellable = Medicine::query()
            ->where('is_active', true)
            ->where('prescription_required', false)
            ->pluck('id')
            ->all();

        if ($sellable === []) {
            $this->warn('  Nothing sellable without a prescription — no bills made.');

            return 0;
        }

        $customers = Customer::query()->inRandomOrder()->limit(60)->get();
        $made = 0;

        for ($back = $days - 1; $back >= 0; $back--) {
            $date = now()->subDays($back);

            // A quieter Sunday, and today only as far as the clock has got.
            $count = (int) round($perDay * ($date->isSunday() ? 0.45 : 1) * ($back === 0 ? 0.6 : 1));

            foreach ($stores as $store) {
                for ($n = 0; $n < $count; $n++) {
                    $made += $this->bill($store, $date, $sellable, $customers, $settings) ? 1 : 0;
                }
            }

            $this->line("  {$date->toDateString()}: billed");
        }

        // A couple of mistakes, put right the way the product puts them right.
        $this->reverse($stores);

        return $made;
    }

    /**
     * One bill: a few items, a payment, and now and then something owed.
     *
     * @param  list<int>  $sellable
     */
    private function bill(PharmacyStore $store, Carbon $date, array $sellable, $customers, PharmacySetting $settings): bool
    {
        // Two in five are regulars; the rest walk in and are gone.
        $customer = $customers->isNotEmpty() && random_int(1, 5) <= 2 ? $customers->random() : null;

        $sale = $customer
            ? $this->onAccount($store, $customer->id, $sellable)
            : $this->overTheCounter($store, $sellable, $settings);

        if (! $sale) {
            return false;
        }

        $this->backdate($sale, $date);

        return true;
    }

    /**
     * A regular's bill: several lines, the odd discount, settled afterwards.
     *
     * Made with no payment on it, which the counter allows against a name —
     * that is what credit is — and then settled below, in full or in part.
     *
     * @param  list<int>  $sellable
     */
    private function onAccount(PharmacyStore $store, int $customerId, array $sellable): ?PharmacySale
    {
        $items = [];

        foreach ((array) array_rand(array_flip($sellable), random_int(2, 4)) as $id) {
            $medicine = Medicine::query()->find((int) $id);

            if (! $medicine) {
                continue;
            }

            $items[] = [
                'medicine_id' => $medicine->id,
                'quantity' => $this->howMany($medicine),
                // One line in six is discounted, which is what a regular gets.
                'discount_percent' => random_int(1, 6) === 1 ? random_int(2, 10) : null,
            ];
        }

        try {
            [$sale] = $this->sales->sell($store, [
                'customer_id' => $customerId,
                'items' => $items,
                'payments' => [],
            ], (string) Str::uuid());
        } catch (StockConflict) {
            // The shelf ran out during the demo run: skip this one quietly.
            return null;
        }

        $this->settle($sale);

        return $sale;
    }

    /**
     * A walk-in's bill: paid as it is rung up, because nobody can be chased
     * for it afterwards — which is exactly what the counter refuses, and
     * rightly.
     *
     * So the money has to be known before the bill is made, and that means
     * pricing it here: one line, no discount, from the batch FEFO will
     * choose, in a quantity that batch can fill on its own. Those three
     * constraints make the arithmetic exact rather than approximate — the
     * same figure the service will reach. Where another bill empties the
     * batch first, the counter refuses the payment as too much and this one
     * is skipped.
     *
     * @param  list<int>  $sellable
     */
    private function overTheCounter(PharmacyStore $store, array $sellable, PharmacySetting $settings): ?PharmacySale
    {
        /*
         * A few items are deliberately kept scarce so the low-stock screen
         * has something on it, and they sell out. Reaching for another item
         * is what a customer does at a counter; giving up on the whole bill
         * is not, and it cost the demo half its takings.
         */
        $items = [];
        $net = 0.0;

        /*
         * A few items are deliberately kept scarce so the low-stock screen has
         * something on it, and they sell out. Reaching for another item is
         * what a customer does at a counter; giving up on the whole bill is
         * not, and it cost the demo half its takings.
         */
        foreach ((array) array_rand(array_flip($sellable), min(6, count($sellable))) as $candidate) {
            if (count($items) >= random_int(1, 3) + 1) {
                break;
            }

            $medicine = Medicine::query()->find((int) $candidate);

            if (! $medicine) {
                continue;
            }

            $quantity = $this->howMany($medicine);

            /*
             * The oldest batch that can fill the whole line on its own.
             *
             * Still first-expiry-first — just not the scrapings of a batch
             * with four tablets left in it. Taking whatever remained was what
             * made the demo's bills come to three rupees: a line that spills
             * into a second batch at another price would also break the
             * arithmetic this method depends on.
             */
            $batch = MedicineBatch::query()
                ->availableForDispensing($store->id, $medicine->id)
                ->where('quantity_available', '>=', $quantity)
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->first();

            if (! $batch) {
                continue;
            }

            $price = (float) ($settings->price_basis === PharmacySetting::PRICE_SELLING
                ? $batch->selling_price
                : $batch->mrp);

            // Tax-inclusive or not, a line comes to price × quantity; only the
            // split between goods and tax differs, and that does not change it.
            $net += round($price * $quantity, 2);

            $items[] = ['medicine_id' => $medicine->id, 'quantity' => $quantity];
        }

        if ($items === []) {
            return null;
        }

        $total = $settings->round_off_enabled ? round($net) : round($net, 2);

        try {
            [$sale] = $this->sales->sell($store, [
                'walk_in_name' => 'Walk-in customer',
                'items' => $items,
                'payments' => [[
                    'method' => ['cash', 'cash', 'cash', 'upi', 'upi', 'card'][random_int(0, 5)],
                    'amount' => $total,
                ]],
            ], (string) Str::uuid());
        } catch (StockConflict) {
            return null;
        }

        return $sale;
    }

    /**
     * How much of something somebody actually buys.
     *
     * Medicine leaves the counter in strips and bottles, not in ones: a
     * course of antibiotics is a strip or two, not three capsules. Seeded in
     * ones, every bill came to eight rupees and the whole demo read as a
     * shop nobody uses.
     */
    private function howMany(Medicine $medicine): int
    {
        $pack = max(1, (int) $medicine->pack_size);

        return in_array($medicine->base_unit, ['tablet', 'capsule'], true)
            // A strip, or a fortnight's worth.
            ? $pack * random_int(1, 3)
            : random_int(1, 2);
    }

    /**
     * What a regular paid against their bill.
     *
     * Three in four settle it there and then; the fourth pays something on
     * account and owes the rest, which is what puts a figure in the "owed"
     * column and gives the customer ledger something to be about.
     */
    private function settle(PharmacySale $sale): void
    {
        $total = (float) $sale->total_amount;

        $credit = random_int(1, 4) === 1;

        $paid = $credit ? round($total * (random_int(0, 6) / 10), 2) : $total;

        if ($paid > 0) {
            $sale->payments()->create([
                'method' => ['cash', 'cash', 'upi', 'upi', 'card'][random_int(0, 4)],
                'amount' => $paid,
                'paid_at' => $sale->sale_date,
            ]);
        }

        $sale->forceFill([
            'paid_amount' => $paid,
            'payment_status' => match (true) {
                $paid >= $total - 0.001 => PharmacySale::PAID,
                $paid > 0 => PharmacySale::PARTIAL,
                default => PharmacySale::UNPAID,
            },
        ])->save();
    }

    /**
     * Put the bill where it belongs in the week.
     *
     * Only the bill: the stock ledger is append-only by trigger, so its rows
     * keep the moment they were actually written. That is the honest trade —
     * a demo may have a history of takings; nothing may rewrite stock history.
     */
    private function backdate(PharmacySale $sale, Carbon $date): void
    {
        $at = $date->copy()->setTime(random_int(9, 20), random_int(0, 59));

        $sale->forceFill(['sale_date' => $at, 'created_at' => $at, 'updated_at' => $at])->saveQuietly();
        $sale->payments()->update(['paid_at' => $at]);
    }

    /** One cancelled bill per store, so the screen shows what that looks like. */
    private function reverse($stores): void
    {
        foreach ($stores as $store) {
            $sale = PharmacySale::query()
                ->where('pharmacy_store_id', $store->id)
                ->where('status', PharmacySale::COMPLETED)
                ->latest('id')
                ->first();

            if (! $sale) {
                continue;
            }

            try {
                $this->sales->cancel($sale, 'Customer changed their mind at the counter');
            } catch (StockConflict) {
                // Its stock has already moved on; leave it as it is.
            }
        }
    }
}
