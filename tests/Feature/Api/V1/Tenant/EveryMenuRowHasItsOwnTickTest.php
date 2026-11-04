<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Role;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * One tick per menu row, enforced by the server rather than only by the menu.
 *
 * The rows that used to share `pharmacy.view`, `billing.view`, `communication.view`,
 * `settings.manage` and a doctor's two list ticks. Whoever held the old tick keeps
 * every row it used to open, and after that each row is its own.
 */
class EveryMenuRowHasItsOwnTickTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('TCK');

        foreach (['appointments', 'medicines', 'pharmacy', 'communication'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        [$this->branch, $this->store] = $this->onTenant($this->organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Rows', 'code' => 'TCK-1', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $store = PharmacyStore::on('organization')->create([
                'location_id' => $branch,
                'name' => 'Front Counter',
                'code' => 'TCK-A',
                'store_type' => PharmacyStore::RETAIL,
                'is_active' => true,
                'is_default' => true,
            ])->id;

            return [$branch, $store];
        });

        $this->placeStaffAt($this->organization, $this->branch);
    }

    /** @param list<string> $ticks */
    private function holding(array $ticks): void
    {
        $this->setStaffCapabilities($this->organization, $ticks);
        $this->signInAsStaff($this->organization);
    }

    /** @return array<string, string> a screen's own read, by the tick that opens it */
    private function pharmacyReads(): array
    {
        return [
            'pharmacy.view' => "/api/v1/tenant/pharmacy-stores/{$this->store}/dashboard",
            'pharmacy.inventory_view' => "/api/v1/tenant/pharmacy-stores/{$this->store}/batches",
            'pharmacy.purchases_view' => "/api/v1/tenant/pharmacy-stores/{$this->store}/inwards",
            'pharmacy.bills_view' => "/api/v1/tenant/pharmacy-stores/{$this->store}/sales",
            'pharmacy.movements_view' => "/api/v1/tenant/pharmacy-stores/{$this->store}/movements",
            'pharmacy.suppliers_view' => '/api/v1/tenant/suppliers',
            'pharmacy.stores_view' => "/api/v1/tenant/pharmacy-stores/{$this->store}",
            'pharmacy.settings_view' => '/api/v1/tenant/pharmacy/settings',
        ];
    }

    public function test_every_new_tick_is_a_screen_a_role_can_be_given(): void
    {
        $screens = collect(ModuleRegistry::all())
            ->flatMap(fn (array $module) => $module['capabilities'])
            ->where('screen', true)
            ->pluck('key')
            ->all();

        foreach ([
            'appointments.availability_view', 'appointments.my_consultations', 'appointments.my_prescriptions',
            'appointments.my_investigations', 'appointments.my_follow_ups', 'settings.departments',
            'pharmacy.inventory_view', 'pharmacy.purchases_view', 'pharmacy.bills_view', 'pharmacy.movements_view',
            'pharmacy.suppliers_view', 'pharmacy.stores_view', 'pharmacy.settings_view',
            'billing.invoices_view', 'billing.payments_view', 'billing.outstanding_view',
            'communication.email', 'communication.push',
        ] as $tick) {
            $this->assertContains($tick, $screens);
        }
    }

    public function test_each_pharmacy_screen_opens_on_its_own_tick_and_no_other(): void
    {
        $reads = $this->pharmacyReads();

        foreach ($reads as $tick => $url) {
            $this->holding([$tick]);

            $this->getJson($url)->assertOk();

            foreach ($reads as $other => $elsewhere) {
                if ($other === $tick) {
                    continue;
                }

                // Purchases names suppliers, so that one read is shared on purpose.
                if ($tick === 'pharmacy.purchases_view' && $other === 'pharmacy.suppliers_view') {
                    continue;
                }

                // Any screen may read the settings it needs only where listed: the Bills screen does.
                if ($tick === 'pharmacy.bills_view' && $other === 'pharmacy.settings_view') {
                    continue;
                }

                $this->getJson($elsewhere)->assertForbidden();
            }

            // And every one of them can pick a store.
            $this->getJson('/api/v1/tenant/pharmacy-stores')->assertOk();
        }
    }

    public function test_the_counter_reads_the_shelf_it_sells_from_but_not_the_shops_figures(): void
    {
        $this->holding(['pharmacy.sell']);

        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/stock")->assertOk();
        $this->getJson('/api/v1/tenant/pharmacy/settings')->assertOk();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/dashboard")->assertForbidden();
        $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/movements")->assertForbidden();
    }

    public function test_each_billing_screen_opens_on_its_own_tick_and_shares_only_what_it_reads(): void
    {
        $this->holding(['billing.payments_view']);
        $this->getJson('/api/v1/tenant/billing/payments')->assertOk();
        $this->getJson('/api/v1/tenant/billing/overview')->assertForbidden();
        $this->getJson('/api/v1/tenant/invoices')->assertForbidden();

        // Outstanding is drawn from the invoice list, without opening the Overview.
        $this->holding(['billing.outstanding_view']);
        $this->getJson('/api/v1/tenant/billing/overview/outstanding/summary')->assertOk();
        $this->getJson('/api/v1/tenant/invoices')->assertOk();
        $this->getJson('/api/v1/tenant/billing/overview')->assertForbidden();
        $this->getJson('/api/v1/tenant/billing/payments')->assertForbidden();

        $this->holding(['billing.invoices_view']);
        $this->getJson('/api/v1/tenant/invoices')->assertOk();
        $this->getJson('/api/v1/tenant/billing/overview')->assertForbidden();
        $this->getJson('/api/v1/tenant/billing/payments')->assertForbidden();

        $this->holding(['billing.view']);
        $this->getJson('/api/v1/tenant/billing/overview')->assertOk();
        $this->getJson('/api/v1/tenant/invoices')->assertOk();
        $this->getJson('/api/v1/tenant/billing/payments')->assertForbidden();
    }

    public function test_each_channel_is_its_own_tick(): void
    {
        $this->holding(['communication.email']);

        $this->getJson('/api/v1/tenant/communication/email/overview')->assertOk();
        $this->getJson('/api/v1/tenant/communication/whatsapp/overview')->assertForbidden();
        $this->getJson('/api/v1/tenant/communication/push/overview')->assertForbidden();
        $this->getJson('/api/v1/tenant/communication/push/devices')->assertForbidden();

        // A channel's templates and rules do not leak through another channel's tick.
        $this->assertSame([], $this->getJson('/api/v1/tenant/communication/templates?channel=push')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/tenant/communication/automation-rules?channel=push')->assertOk()->json('data'));

        $this->holding(['communication.push']);

        $this->getJson('/api/v1/tenant/communication/push/overview')->assertOk();
        $this->getJson('/api/v1/tenant/communication/push/devices')->assertOk();
        $this->getJson('/api/v1/tenant/communication/email/overview')->assertForbidden();
        $this->assertNotSame([], $this->getJson('/api/v1/tenant/communication/automation-rules?channel=push')->assertOk()->json('data'));

        // WhatsApp stays where the old tick always opened it.
        $this->holding(['communication.view']);

        $this->getJson('/api/v1/tenant/communication/whatsapp/overview')->assertOk();
        $this->getJson('/api/v1/tenant/communication/email/overview')->assertForbidden();
    }

    public function test_departments_are_changed_under_their_own_tick(): void
    {
        $this->holding(['settings.manage']);
        $this->postJson('/api/v1/tenant/departments', ['name' => 'Sleep medicine'])->assertForbidden();

        $this->holding(['settings.departments']);
        $this->postJson('/api/v1/tenant/departments', ['name' => 'Sleep medicine'])->assertCreated();
    }

    /** The route opens for any of a doctor's lists; the list asked for is checked after that. */
    public function test_each_of_a_doctors_lists_is_its_own_tick(): void
    {
        $notTheirs = 'Your role does not include this list.';
        $notADoctor = "These are a doctor's own records, and you are not signed in as one.";

        $this->holding(['appointments.my_prescriptions']);

        $this->getJson('/api/v1/tenant/opd/my-records?view=follow-ups')
            ->assertForbidden()
            ->assertJsonPath('message', $notTheirs);

        // Past the tick: all that stops this list is that the login is not a doctor's.
        $this->getJson('/api/v1/tenant/opd/my-records?view=prescriptions')
            ->assertForbidden()
            ->assertJsonPath('message', $notADoctor);

        // My queue keeps reading what the phone's doctor screens read from it.
        $this->holding(['appointments.my_queue']);

        $this->getJson('/api/v1/tenant/opd/my-records?view=appointments')
            ->assertForbidden()
            ->assertJsonPath('message', $notADoctor);
        $this->getJson('/api/v1/tenant/opd/my-records?view=prescriptions')
            ->assertForbidden()
            ->assertJsonPath('message', $notTheirs);
    }

    public function test_availability_opens_on_its_own_tick_and_still_for_the_doctors_screen(): void
    {
        $this->holding(['dashboard.view']);
        $this->getJson('/api/v1/tenant/availability/exceptions')->assertForbidden();

        $this->holding(['appointments.availability_view']);
        $this->getJson('/api/v1/tenant/availability/exceptions')->assertOk();
        $this->getJson('/api/v1/tenant/doctors')->assertOk();

        // The phone's Doctors screen shows availability, and the old tick still opens it.
        $this->holding(['appointments.doctors_view']);
        $this->getJson('/api/v1/tenant/availability/exceptions')->assertOk();
    }

    /** Nobody's menu changes on the day the ticks arrive, and no removal is undone. */
    public function test_whoever_held_the_old_tick_keeps_every_row_and_removals_stay_removed(): void
    {
        $this->onTenant($this->organization, function () {
            $connection = TenantConnectionService::CONNECTION;

            $holds = Role::on($connection)->create(['name' => 'Holds it', 'slug' => 'holds-it', 'scope' => Role::SCOPE_BRANCH]);
            $holds->syncCapabilities([
                'pharmacy.view', 'billing.view', 'communication.view', 'appointments.doctors_view',
                'appointments.my_queue', 'appointments.my_patients',
            ]);

            $lacks = Role::on($connection)->create(['name' => 'Lacks it', 'slug' => 'lacks-it', 'scope' => Role::SCOPE_BRANCH]);
            $lacks->syncCapabilities(['dashboard.view']);

            // This branch took the bills off the role, so it must not gain the new rows.
            BranchRoleCapability::on($connection)->create([
                'location_id' => $this->branch,
                'role_id' => $holds->id,
                'capability' => 'billing.view',
            ]);

            $default = DB::getDefaultConnection();
            DB::setDefaultConnection($connection);

            try {
                (require base_path('database/migrations/organization/2026_11_04_000100_give_every_menu_row_its_own_tick.php'))->up();
            } finally {
                DB::setDefaultConnection($default);
            }

            $held = $holds->fresh()->load('capabilities')->capabilityKeys();

            foreach ([
                'pharmacy.view', 'pharmacy.inventory_view', 'pharmacy.purchases_view', 'pharmacy.bills_view',
                'pharmacy.movements_view', 'pharmacy.suppliers_view', 'pharmacy.stores_view', 'pharmacy.settings_view',
                'billing.view', 'billing.invoices_view', 'billing.payments_view', 'billing.outstanding_view',
                'communication.view', 'communication.email', 'communication.push',
                'appointments.doctors_view', 'appointments.availability_view',
                'appointments.my_queue', 'appointments.my_consultations',
                'appointments.my_patients', 'appointments.my_prescriptions',
                'appointments.my_investigations', 'appointments.my_follow_ups',
            ] as $tick) {
                $this->assertContains($tick, $held);
            }

            $this->assertSame(['dashboard.view'], $lacks->fresh()->load('capabilities')->capabilityKeys());

            $removed = BranchRoleCapability::removedAt($this->branch, (int) $holds->id);

            foreach (['billing.invoices_view', 'billing.payments_view', 'billing.outstanding_view'] as $tick) {
                $this->assertContains($tick, $removed);
            }

            $this->assertNotContains('pharmacy.inventory_view', $removed);
        });
    }
}
