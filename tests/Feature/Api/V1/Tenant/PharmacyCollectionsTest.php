<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The pharmacy's own till.
 *
 *   - It takes only what the organisation accepts — one list of payment
 *     methods for every counter — and credit is a policy (the pharmacy's
 *     credit setting), never a method.
 *   - The rest of a credit sale is collected AT THE COUNTER, once per tap.
 *   - Goods come back: the stock returns to its batch, the bill is credited,
 *     and money goes back only for what had been paid over the new total.
 *   - A cancelled bill records the money going back.
 *   - Bills and returns are numbered per branch and per financial year, and a
 *     number already printed is never changed.
 */
class PharmacyCollectionsTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $store;

    private int $medicine;

    private int $customer;

    private int $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('PC');

        foreach (['medicines', 'pharmacy'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'PC-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch, 'name' => 'Front Counter', 'code' => 'PC-A',
                'store_type' => PharmacyStore::RETAIL, 'is_active' => true, 'is_default' => true,
            ])->id;

            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Paracetamol', 'brand_name' => 'Calpol 500', 'strength' => '500 mg',
                'dosage_form' => 'tablet', 'base_unit' => 'tablet', 'pack_size' => 10,
                'prescription_required' => false, 'tax_rate' => 12, 'hsn_code' => '3004', 'is_active' => true,
            ])->id;

            $this->customer = Customer::on('organization')->create([
                'name' => 'Meena Iyer', 'phone' => '9810000031', 'is_active' => true,
            ])->id;
        });

        $this->signInAsOwner($this->organization);

        $supplier = $this->onTenant($this->organization, fn () => Supplier::on('organization')
            ->create(['name' => 'Micro Distributors', 'is_active' => true])->id);

        // Ten packs of ten at ₹112 a pack: ₹11.20 a tablet, tax-inclusive.
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine, 'batch_number' => 'B-1',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 10, 'purchase_price' => 70, 'mrp' => 112,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->batch = (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->where('pharmacy_store_id', $this->store)->value('id'));
    }

    /* ------------------------------------------------------------ helpers */

    private function sell(int $tablets, array $payments, bool $registered = true)
    {
        return $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            ...($registered ? ['customer_id' => $this->customer] : ['walk_in_name' => 'Walk-in']),
            'items' => [['medicine_id' => $this->medicine, 'quantity' => $tablets]],
            'payments' => $payments,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function allowCredit(): void
    {
        $this->onTenant($this->organization, fn () => PharmacySetting::current()->forceFill(['credit_sales_enabled' => true])->save());
    }

    private function onHand(): int
    {
        return (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->whereKey($this->batch)->value('quantity_available'));
    }

    /* ----------------------------------------------------- what it takes */

    public function test_the_counter_takes_only_what_the_organisation_accepts(): void
    {
        $this->onTenant($this->organization, fn () => BillingSetting::current()->forceFill(['payment_methods' => ['cash']])->save());

        $this->sell(10, [['method' => 'card', 'amount' => 112]])->assertStatus(409);
        $this->sell(10, [['method' => 'cash', 'amount' => 112]])->assertCreated();

        $this->getJson('/api/v1/tenant/pharmacy/settings')
            ->assertOk()
            ->assertJsonPath('data.accepted_payment_methods', ['cash']);
    }

    public function test_credit_is_a_policy_not_a_payment_method(): void
    {
        // There is no "credit" tender to choose.
        $this->sell(10, [['method' => 'credit', 'amount' => 112]])->assertStatus(422);

        // Leaving a bill owing is what the credit setting decides.
        $this->sell(10, [])->assertStatus(409);

        $this->allowCredit();

        $this->sell(10, [])->assertCreated()->assertJsonPath('data.payment_status', PharmacySale::UNPAID);
        $this->sell(10, [], registered: false)->assertStatus(409);
    }

    /* ------------------------------------------------------------ collect */

    public function test_the_rest_of_a_credit_sale_is_collected_at_the_counter_once(): void
    {
        $this->allowCredit();

        $sale = $this->sell(10, [])->assertCreated()->json('data');
        $key = (string) Str::uuid();

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/payments", [
            'tenders' => [['method' => 'cash', 'amount' => 50]],
        ], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', PharmacySale::PARTIAL)
            ->assertJsonPath('data.amount_due', 62);

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/payments", [
            'tenders' => [['method' => 'cash', 'amount' => 50]],
        ], ['Idempotency-Key' => $key])->assertOk();

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/payments", [
            'tenders' => [['method' => 'cash', 'amount' => 70]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/payments", [
            'tenders' => [['method' => 'cash', 'amount' => 30], ['method' => 'upi', 'amount' => 32]],
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', PharmacySale::PAID)
            ->assertJsonPath('data.amount_due', 0);

        $this->assertSame(3, $this->onTenant($this->organization, fn () => PharmacySalePayment::on('organization')
            ->where('pharmacy_sale_id', $sale['id'])->count()));
    }

    /* ------------------------------------------------------------ returns */

    public function test_a_return_restocks_credits_the_bill_and_refunds_what_was_overpaid(): void
    {
        $sale = $this->sell(20, [['method' => 'cash', 'amount' => 224]])->assertCreated()->json('data');
        $line = $sale['items'][0]['id'];

        $this->assertSame(80, $this->onHand());

        $returned = $this->postJson("/api/v1/tenant/sales/{$sale['id']}/returns", [
            'reason' => 'Doctor changed the dose',
            'items' => [['pharmacy_sale_item_id' => $line, 'quantity' => 5]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame(85, $this->onHand(), 'Back on the shelf it came from.');
        $this->assertEquals(56, $returned['returned_amount']);
        $this->assertEquals(168, $returned['paid_amount']);
        $this->assertEquals(0, $returned['amount_due']);
        $this->assertSame(PharmacySale::PAID, $returned['payment_status']);
        $this->assertMatchesRegularExpression('#^PC-GGN/PHR/\d{2}-\d{2}/00001$#', $returned['returns'][0]['return_number']);
        $this->assertEquals(56, $returned['returns'][0]['refund_amount']);

        $refund = collect($returned['payments'])->firstWhere('is_refund', true);
        $this->assertEquals(56, $refund['amount']);

        $this->assertTrue($this->onTenant($this->organization, fn () => StockMovement::on('organization')
            ->where('movement_type', StockMovement::SALE_RETURN)
            ->where('medicine_batch_id', $this->batch)
            ->where('quantity', 5)
            ->exists()));
    }

    public function test_a_return_on_a_credit_sale_reduces_the_debt_instead(): void
    {
        $this->allowCredit();

        $sale = $this->sell(20, [])->assertCreated()->json('data');

        $returned = $this->postJson("/api/v1/tenant/sales/{$sale['id']}/returns", [
            'reason' => 'Not needed',
            'items' => [['pharmacy_sale_item_id' => $sale['items'][0]['id'], 'quantity' => 5]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertEquals(168, $returned['amount_due']);
        $this->assertEquals(0, $returned['returns'][0]['refund_amount']);
        $this->assertNull(collect($returned['payments'])->firstWhere('is_refund', true));
    }

    public function test_no_more_comes_back_than_was_sold_and_a_return_is_taken_once(): void
    {
        $sale = $this->sell(20, [['method' => 'cash', 'amount' => 224]])->assertCreated()->json('data');
        $line = $sale['items'][0]['id'];
        $key = (string) Str::uuid();

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/returns", [
            'reason' => 'Too many', 'items' => [['pharmacy_sale_item_id' => $line, 'quantity' => 21]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        foreach ([201, 200] as $status) {
            $this->postJson("/api/v1/tenant/sales/{$sale['id']}/returns", [
                'reason' => 'All of it', 'items' => [['pharmacy_sale_item_id' => $line, 'quantity' => 20]],
            ], ['Idempotency-Key' => $key])->assertStatus($status);
        }

        $this->assertSame(100, $this->onHand());

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/returns", [
            'reason' => 'Again', 'items' => [['pharmacy_sale_item_id' => $line, 'quantity' => 1]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
    }

    /* ------------------------------------------------------------- cancel */

    public function test_cancelling_a_paid_bill_records_the_money_going_back(): void
    {
        $sale = $this->sell(10, [['method' => 'upi', 'amount' => 112]])->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", ['reason' => 'Wrong patient'])->assertOk();

        $rows = $this->onTenant($this->organization, fn () => PharmacySalePayment::on('organization')
            ->where('pharmacy_sale_id', $sale['id'])->orderBy('id')->get());

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[1]->is_refund);
        $this->assertSame('112.00', $rows[1]->amount);
        $this->assertSame('upi', $rows[1]->method);
        $this->assertSame(100, $this->onHand());
    }

    /* ------------------------------------------------------------ numbers */

    public function test_bills_are_numbered_by_branch_and_year_and_old_numbers_are_kept(): void
    {
        // A bill printed under the old organisation-wide numbering.
        $legacy = $this->onTenant($this->organization, fn () => PharmacySale::on('organization')->forceCreate([
            'sale_number' => 'INV-00042',
            'pharmacy_store_id' => $this->store,
            'location_id' => $this->branch,
            'walk_in_name' => 'Walk-in',
            'sale_date' => now(),
            'price_basis' => PharmacySetting::PRICE_MRP,
            'prices_include_tax' => true,
            'total_amount' => 10,
            'paid_amount' => 10,
            'payment_status' => PharmacySale::PAID,
        ])->id);

        $sale = $this->sell(10, [['method' => 'cash', 'amount' => 112]])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('#^PC-GGN/PH/\d{2}-\d{2}/00001$#', $sale['sale_number']);
        $this->assertSame('INV-00042', $this->onTenant($this->organization, fn () => PharmacySale::on('organization')
            ->whereKey($legacy)->value('sale_number')));
    }
}
