<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * What the pharmacy needs before it can sell anything.
 *
 * What is asserted: an organisation has billing settings from the moment its
 * database exists; only pharmacy setup changes them while every counter may
 * read them; the catalogue holds things that are not medicines; a barcode
 * belongs to one item; and the stock ledger accepts a sale.
 */
class PharmacyFoundationsTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('P');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'pharmacy');
        $this->signInAsOwner($this->organization);
    }

    /** @param  array<string, mixed>  $overrides */
    private function addItem(array $overrides = [])
    {
        return $this->postJson('/api/v1/tenant/medicines', [
            'generic_name' => 'Paracetamol',
            'dosage_form' => 'tablet',
            'base_unit' => 'tablet',
            ...$overrides,
        ]);
    }

    public function test_an_organisation_has_billing_settings_from_the_start(): void
    {
        $settings = $this->getJson('/api/v1/tenant/pharmacy/settings')->assertOk()->json('data');

        $this->assertSame('INV', $settings['invoice_prefix']);
        $this->assertSame(PharmacySetting::PRICE_MRP, $settings['price_basis']);

        // Indian MRP is tax-inclusive, so a bill splits the tax out of the price.
        $this->assertTrue($settings['prices_include_tax']);

        $this->assertSame(90, $settings['expiry_warning_days']);
        $this->assertTrue($settings['allow_walk_in']);
        $this->assertFalse($settings['credit_sales_enabled']);
        $this->assertTrue($settings['require_prescription']);
        $this->assertSame(PharmacySetting::CASH, $settings['default_payment_method']);
    }

    public function test_settings_are_saved_and_checked(): void
    {
        $this->putJson('/api/v1/tenant/pharmacy/settings', [
            'invoice_prefix' => 'bill',
            'price_basis' => PharmacySetting::PRICE_SELLING,
            'expiry_warning_days' => 30,
            'credit_sales_enabled' => true,
        ])->assertOk()
            // Stored the way it will be printed.
            ->assertJsonPath('data.invoice_prefix', 'BILL')
            ->assertJsonPath('data.price_basis', PharmacySetting::PRICE_SELLING)
            ->assertJsonPath('data.expiry_warning_days', 30)
            ->assertJsonPath('data.credit_sales_enabled', true);

        // The rest of the settings kept their values.
        $this->assertTrue($this->getJson('/api/v1/tenant/pharmacy/settings')->json('data.allow_walk_in'));

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['invoice_prefix' => 'INV-2026'])
            ->assertStatus(422)->assertJsonValidationErrors('invoice_prefix');

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['expiry_warning_days' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('expiry_warning_days');

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['price_basis' => 'wholesale'])
            ->assertStatus(422)->assertJsonValidationErrors('price_basis');

        // One row, whatever happens.
        $this->onTenant($this->organization, fn () => $this->assertSame(
            1,
            PharmacySetting::on('organization')->count(),
        ));
    }

    public function test_every_counter_reads_the_settings_but_only_setup_changes_them(): void
    {
        $this->setStaffCapabilities($this->organization, ['pharmacy.view']);
        $this->signInAsStaff($this->organization);

        // The POS prices and warns from these, so reading is the counter's.
        $this->getJson('/api/v1/tenant/pharmacy/settings')->assertOk();

        $this->putJson('/api/v1/tenant/pharmacy/settings', ['allow_walk_in' => false])->assertForbidden();
    }

    public function test_the_catalogue_holds_things_that_are_not_medicines(): void
    {
        // A box of gloves has no dosage form, and is not refused for it.
        $gloves = $this->addItem([
            'item_kind' => Medicine::CONSUMABLE,
            'generic_name' => 'Examination gloves (medium)',
            'dosage_form' => null,
            'base_unit' => 'unit',
            'prescription_required' => false,
            'hsn_code' => '4015',
            'tax_rate' => 12,
        ])->assertCreated()->json('data');

        $this->assertSame(Medicine::CONSUMABLE, $gloves['item_kind']);
        $this->assertNull($gloves['dosage_form']);
        // A whole percentage arrives as a number, not as "12.00".
        $this->assertEqualsWithDelta(12, $gloves['tax_rate'], 0.001);

        // A medicine still has to say what form it comes in.
        $this->addItem(['dosage_form' => null])
            ->assertStatus(422)->assertJsonValidationErrors('dosage_form');

        // Two kinds of glove are still two items, neither of which has a form.
        $this->addItem([
            'item_kind' => Medicine::CONSUMABLE,
            'generic_name' => 'Examination gloves (large)',
            'dosage_form' => null,
            'base_unit' => 'unit',
        ])->assertCreated();

        // But the same one twice is not.
        $this->addItem([
            'item_kind' => Medicine::CONSUMABLE,
            'generic_name' => 'examination gloves (medium)',
            'dosage_form' => null,
            'base_unit' => 'unit',
        ])->assertStatus(422)->assertJsonValidationErrors('generic_name');
    }

    public function test_a_barcode_belongs_to_one_item(): void
    {
        $this->addItem(['barcode' => '8901234567890', 'sku' => 'PCM-650'])->assertCreated();

        $this->addItem([
            'generic_name' => 'Amoxicillin',
            'barcode' => '8901234567890',
        ])->assertStatus(422)->assertJsonValidationErrors('barcode');

        $this->addItem([
            'generic_name' => 'Amoxicillin',
            'sku' => 'pcm-650',
        ])->assertStatus(422)->assertJsonValidationErrors('sku');

        // A scanner sends digits, not a sentence.
        $this->addItem([
            'generic_name' => 'Cetirizine',
            'barcode' => 'not a barcode',
        ])->assertStatus(422)->assertJsonValidationErrors('barcode');

        $this->addItem(['generic_name' => 'Cetirizine', 'tax_rate' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('tax_rate');
    }

    /** Selling is a stock movement, and the ledger has to accept one. */
    public function test_the_stock_ledger_knows_about_selling(): void
    {
        $this->assertContains(StockMovement::SALE, StockMovement::TYPES);
        $this->assertContains(StockMovement::SALE_RETURN, StockMovement::TYPES);

        $this->onTenant($this->organization, function () {
            $types = collect(DB::connection('organization')->select(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'stock_movements_rules_check'"
            ))->pluck('def')->first();

            $this->assertStringContainsString("'sale'", (string) $types);
            $this->assertStringContainsString("'sale_return'", (string) $types);
        });
    }
}
