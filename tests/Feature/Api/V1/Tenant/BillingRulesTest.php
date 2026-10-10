<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Services\Billing\BillingService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PDO;
use Tests\TenantTestCase;

/**
 * The rules every invoice is held to, whichever department drew it.
 *
 *   - PAYMENT POLICY. "Pay in full" takes the whole balance — in one tender
 *     or split across several — and refuses a part; "part payment" takes any
 *     part; nobody takes more than is owed, or by a method the clinic does
 *     not accept.
 *   - ONCE. A payment or refund carrying a key already seen is not recorded
 *     again.
 *   - A REFUND CREDITS THE BILL. Money handed back is never owed again:
 *     refunded in full reads `refunded`, in part `partially_refunded`.
 *   - EDITING. A bill any money has moved against is never edited; an
 *     issued unpaid one only where the settings allow; every edit is in the
 *     activity log; and a line naming a pharmacy sale or a consultation
 *     cannot be typed in by hand.
 *   - HISTORY. A bill keeps the currency and tax rule it was drawn under.
 *   - NUMBERS. Two bills at one branch at the same instant cannot be handed
 *     the same number.
 */
class BillingRulesTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('BR');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'BR-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->patient = Customer::on('organization')->create([
                'name' => 'Ravi Kumar', 'phone' => '9810000021', 'is_active' => true,
            ])->id;
        });

        $this->signInAsOwner($this->organization);
    }

    /* ------------------------------------------------------------ helpers */

    private function settings(array $values): void
    {
        $this->onTenant($this->organization, fn () => BillingSetting::current()->forceFill($values)->save());
    }

    private function bill(float $price = 500, float $tax = 0): Invoice
    {
        return $this->onTenant($this->organization, fn () => app(BillingService::class)->draw(
            ['location_id' => $this->branch, 'customer_id' => $this->patient, 'trigger' => 'manual', 'kind' => Invoice::KIND_MANUAL],
            [['source_type' => InvoiceItem::SOURCE_SERVICE, 'description' => 'Dressing', 'unit_price' => $price, 'tax_percent' => $tax]],
        ));
    }

    private function pay(Invoice $invoice, array $body, ?string $key = null)
    {
        return $this->postJson(
            "/api/v1/tenant/invoices/{$invoice->id}/payments",
            $body,
            ['Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }

    private function fresh(Invoice $invoice): Invoice
    {
        return $this->onTenant($this->organization, fn () => Invoice::on('organization')->with('payments')->findOrFail($invoice->id));
    }

    /* ----------------------------------------------------- payment policy */

    public function test_pay_in_full_refuses_a_part_but_takes_a_split(): void
    {
        $invoice = $this->bill(500);

        $this->pay($invoice, ['method' => 'cash', 'amount' => 200])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'in full'));

        $this->pay($invoice, ['tenders' => [
            ['method' => 'cash', 'amount' => 300],
            ['method' => 'upi', 'amount' => 200, 'reference' => 'UPI-1'],
        ]])->assertCreated()->assertJsonPath('data.status', Invoice::STATUS_PAID);

        $this->assertSame(2, $this->fresh($invoice)->payments->count(), 'One row per tender, each with its own receipt.');
    }

    public function test_part_payment_takes_any_part_but_never_more_than_is_owed(): void
    {
        $this->allowPartPayments($this->organization);
        $invoice = $this->bill(500);

        $this->pay($invoice, ['method' => 'cash', 'amount' => 200])
            ->assertCreated()
            ->assertJsonPath('data.status', Invoice::STATUS_PARTIALLY_PAID)
            ->assertJsonPath('data.outstanding', 300);

        $this->pay($invoice, ['method' => 'cash', 'amount' => 301])->assertStatus(422);
    }

    public function test_a_method_the_clinic_does_not_accept_is_refused(): void
    {
        $this->settings(['payment_methods' => ['cash']]);
        $invoice = $this->bill(500);

        $this->pay($invoice, ['method' => 'card', 'amount' => 500])->assertStatus(422);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();
    }

    public function test_a_payment_without_a_key_is_refused(): void
    {
        $invoice = $this->bill(500);

        $this->postJson("/api/v1/tenant/invoices/{$invoice->id}/payments", ['method' => 'cash', 'amount' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    /* --------------------------------------------------------------- once */

    public function test_a_double_submitted_payment_is_recorded_once(): void
    {
        $this->allowPartPayments($this->organization);
        $invoice = $this->bill(500);
        $key = (string) Str::uuid();

        $this->pay($invoice, ['method' => 'cash', 'amount' => 100], $key)->assertCreated();
        $this->pay($invoice, ['method' => 'cash', 'amount' => 100], $key)
            ->assertOk()
            ->assertJsonPath('message', 'That payment was already recorded');

        $fresh = $this->fresh($invoice);
        $this->assertSame(1, $fresh->payments->count());
        $this->assertSame('100.00', $fresh->paid_amount);
    }

    public function test_a_key_used_on_another_bill_is_refused_not_replayed(): void
    {
        $first = $this->bill(500);
        $second = $this->bill(500);
        $key = (string) Str::uuid();

        $this->pay($first, ['method' => 'cash', 'amount' => 500], $key)->assertCreated();
        $this->pay($second, ['method' => 'cash', 'amount' => 500], $key)->assertStatus(422);

        $this->assertSame(0, $this->fresh($second)->payments->count());
    }

    public function test_a_double_submitted_refund_is_recorded_once(): void
    {
        $invoice = $this->bill(500);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();
        $payment = $this->fresh($invoice)->payments->first();
        $key = (string) Str::uuid();

        foreach ([201, 200] as $status) {
            $this->postJson(
                "/api/v1/tenant/invoices/{$invoice->id}/payments/{$payment->id}/refund",
                ['amount' => 100, 'reason' => 'Overcharged'],
                ['Idempotency-Key' => $key],
            )->assertStatus($status);
        }

        $this->assertSame(1, $this->fresh($invoice)->payments->where('is_refund', true)->count());
    }

    /* --------------------------------------------------- refunds credit */

    public function test_a_full_refund_leaves_nothing_owing_and_reads_refunded(): void
    {
        $invoice = $this->bill(500);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();
        $payment = $this->fresh($invoice)->payments->first();

        $this->postJson(
            "/api/v1/tenant/invoices/{$invoice->id}/payments/{$payment->id}/refund",
            ['amount' => 500, 'reason' => 'Service not given'],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertCreated()
            ->assertJsonPath('data.status', Invoice::STATUS_REFUNDED)
            ->assertJsonPath('data.outstanding', 0)
            ->assertJsonPath('data.refunded_amount', 500);

        // And it does not reappear on the outstanding list as a debt.
        $this->getJson('/api/v1/tenant/invoices?outstanding_only=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $invoice->id]);
    }

    public function test_a_part_refund_reads_partially_refunded(): void
    {
        $invoice = $this->bill(500);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();
        $payment = $this->fresh($invoice)->payments->first();

        $this->postJson(
            "/api/v1/tenant/invoices/{$invoice->id}/payments/{$payment->id}/refund",
            ['amount' => 200, 'reason' => 'Discount agreed'],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertCreated()
            ->assertJsonPath('data.status', Invoice::STATUS_PARTIALLY_REFUNDED)
            ->assertJsonPath('data.paid_amount', 300)
            ->assertJsonPath('data.outstanding', 0);
    }

    /* ------------------------------------------------------------ editing */

    public function test_an_unpaid_issued_bill_can_be_corrected_and_the_change_is_logged(): void
    {
        $invoice = $this->bill(500);

        $this->putJson("/api/v1/tenant/invoices/{$invoice->id}", [
            'location_id' => $this->branch,
            'customer_id' => $this->patient,
            'items' => [['source_type' => 'service', 'description' => 'Dressing', 'quantity' => 1, 'unit_price' => 450]],
        ])->assertOk()->assertJsonPath('data.total_amount', 450);

        $logged = $this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'Invoice')
            ->where('entity_id', $invoice->id)
            ->where('event', 'updated')
            ->get());

        $this->assertTrue($logged->contains(fn ($entry) => isset($entry->before['items'], $entry->after['items'])));
    }

    public function test_an_issued_bill_is_not_edited_when_the_settings_say_so(): void
    {
        $this->settings(['allow_edit_before_payment' => false]);
        $invoice = $this->bill(500);

        $this->putJson("/api/v1/tenant/invoices/{$invoice->id}", [
            'location_id' => $this->branch,
            'customer_id' => $this->patient,
            'items' => [['source_type' => 'service', 'description' => 'Dressing', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(422);

        $this->assertSame('500.00', $this->fresh($invoice)->total_amount);
    }

    public function test_a_bill_money_has_moved_against_is_never_edited(): void
    {
        $invoice = $this->bill(500);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();
        $payment = $this->fresh($invoice)->payments->first();

        // Even refunded in full, so nothing is held, the record stands.
        $this->postJson(
            "/api/v1/tenant/invoices/{$invoice->id}/payments/{$payment->id}/refund",
            ['amount' => 500],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertCreated();

        $this->putJson("/api/v1/tenant/invoices/{$invoice->id}", [
            'location_id' => $this->branch,
            'customer_id' => $this->patient,
            'items' => [['source_type' => 'service', 'description' => 'Dressing', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(422);

        $this->assertSame(1, $this->onTenant($this->organization, fn () => InvoiceItem::on('organization')
            ->where('invoice_id', $invoice->id)->count()));
    }

    public function test_a_line_of_a_paid_bill_cannot_be_deleted_even_directly(): void
    {
        $invoice = $this->bill(500);
        $this->pay($invoice, ['method' => 'cash', 'amount' => 500])->assertCreated();

        $this->expectException(QueryException::class);

        $this->onTenant($this->organization, function () use ($invoice) {
            $connection = (new Invoice)->getConnection();

            $connection->transaction(function () use ($connection, $invoice) {
                $connection->statement("SET LOCAL hms.invoice_rewrite = 'on'");
                InvoiceItem::on('organization')->where('invoice_id', $invoice->id)->delete();
            });
        });
    }

    public function test_a_hand_made_bill_cannot_claim_a_pharmacy_or_lab_line(): void
    {
        foreach (['pharmacy_sale_item', 'consultation', 'lab_test'] as $source) {
            $this->postJson('/api/v1/tenant/invoices', [
                'location_id' => $this->branch,
                'customer_id' => $this->patient,
                'items' => [['source_type' => $source, 'source_id' => 1, 'description' => 'Sneaky', 'quantity' => 1, 'unit_price' => 100]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.source_type');
        }
    }

    /* ------------------------------------------------------------ history */

    public function test_a_bill_keeps_the_currency_and_tax_rule_it_was_drawn_under(): void
    {
        $this->settings(['currency_code' => 'INR', 'prices_include_tax' => false]);
        $invoice = $this->bill(100, 18);

        $this->assertSame('118.00', $invoice->total_amount);

        // The clinic changes both.
        $this->settings(['currency_code' => 'USD', 'currency_symbol' => '$', 'prices_include_tax' => true]);

        // An edit re-prices under the bill's own rule, not today's.
        $this->putJson("/api/v1/tenant/invoices/{$invoice->id}", [
            'location_id' => $this->branch,
            'customer_id' => $this->patient,
            'items' => [['source_type' => 'service', 'description' => 'Dressing', 'quantity' => 1, 'unit_price' => 100, 'tax_percent' => 18]],
        ])->assertOk()
            ->assertJsonPath('data.total_amount', 118)
            ->assertJsonPath('data.currency_code', 'INR')
            ->assertJsonPath('data.prices_include_tax', false);
    }

    public function test_tax_inclusive_prices_back_the_tax_out(): void
    {
        $this->settings(['prices_include_tax' => true]);
        $invoice = $this->bill(118, 18);

        $this->assertSame('118.00', $invoice->total_amount);
        $this->assertSame('18.00', $invoice->tax_amount);
        $this->assertSame('100.00', $invoice->subtotal);
    }

    /* ----------------------------------------------------------- settings */

    public function test_settings_refuse_an_unknown_method_and_a_non_opd_timing_and_are_logged(): void
    {
        $settings = $this->getJson('/api/v1/tenant/billing/settings')->assertOk()->json('data');

        $payload = collect($settings)->only([
            'default_trigger', 'lab_trigger', 'payment_methods', 'payment_behaviour', 'prices_include_tax',
            'default_tax_percent', 'allow_edit_before_payment', 'invoice_prefix', 'currency_code',
            'currency_symbol', 'terms', 'footer',
        ])->all();

        $this->putJson('/api/v1/tenant/billing/settings', [...$payload, 'payment_methods' => ['cash', 'bitcoin']])
            ->assertStatus(422)->assertJsonValidationErrors('payment_methods.1');

        $this->putJson('/api/v1/tenant/billing/settings', [...$payload, 'default_trigger' => 'pharmacy'])
            ->assertStatus(422)->assertJsonValidationErrors('default_trigger');

        $this->putJson('/api/v1/tenant/billing/settings', [...$payload, 'lab_trigger' => 'lab_order'])->assertOk();

        $this->assertTrue($this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'BillingSetting')
            ->where('event', 'updated')
            ->exists()));
    }

    public function test_only_billing_settings_access_may_change_them(): void
    {
        $this->setStaffCapabilities($this->organization, ['billing.view']);
        $this->signInAsStaff($this->organization);

        $this->putJson('/api/v1/tenant/billing/settings', ['default_trigger' => 'manual'])->assertForbidden();
    }

    /* ------------------------------------------------------------ numbers */

    /**
     * Two bills at one branch at the same instant.
     *
     * Two real connections: the first takes a number inside a transaction
     * it has not committed; the second, asking for the next number at the
     * same branch, has to wait for it — and, once the first commits, is
     * handed the number after it, never the same one.
     */
    public function test_numbers_are_never_handed_out_twice_under_concurrent_requests(): void
    {
        $this->bill(100);

        $config = config('database.connections.'.TenantConnectionService::CONNECTION);
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $this->organization->database_name);

        $first = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $insert = "INSERT INTO invoices (location_id, customer_id, invoice_date, status, trigger, kind, payment_status, created_at, updated_at)
                   VALUES ({$this->branch}, {$this->patient}, now(), 'pending', 'manual', 'manual', 'unpaid', now(), now())
                   RETURNING invoice_number";

        $first->beginTransaction();
        $a = $first->query($insert)->fetchColumn();

        // The second waits on the first's counter row rather than reading past it.
        $second->exec("SET lock_timeout = '300ms'");
        $second->beginTransaction();

        try {
            $second->query($insert);
            $this->fail('The second request was handed a number while the first still held one.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
            $second->rollBack();
        }

        $first->commit();

        $second->exec("SET lock_timeout = '5s'");
        $b = $second->query($insert)->fetchColumn();

        $this->assertNotSame($a, $b);
        $this->assertSame((int) substr($a, -5) + 1, (int) substr($b, -5));

        // Closed before tearDown drops the database.
        $first = $second = null;
    }
}
