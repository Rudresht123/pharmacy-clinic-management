<?php

namespace Tests\Feature\Support;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * The one-time move of stored timestamps from UTC to Asia/Kolkata.
 *
 * Asserted on raw column values (the DB facade, not models), because the
 * question is what is stored — a model would put its own cast in between.
 *
 * The audit tables are the delicate part: their append-only triggers are
 * switched off for the rewrite and must be back on, still refusing UPDATE
 * and DELETE, before the migration finishes.
 */
class TimezoneShiftTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MASTER = 'database/migrations/masterdb/2026_09_20_000100_shift_timestamps_to_asia_kolkata.php';

    private const TENANT = 'database/migrations/organization/2026_09_20_000100_shift_timestamps_to_asia_kolkata.php';

    private function migration(string $path): object
    {
        return require base_path($path);
    }

    /** Runs a tenant migration the way tenants:migrate does: on `organization`. */
    private function onOrganizationConnection(callable $callback): void
    {
        $this->app->make('migrator')->usingConnection('organization', $callback);
    }

    private function triggerState(string $connection, string $trigger): ?string
    {
        return DB::connection($connection)->scalar('SELECT tgenabled FROM pg_trigger WHERE tgname = ?', [$trigger]);
    }

    /**
     * The statement must fail with the trigger's own message.
     *
     * Wrapped in a transaction so the failure rolls back to a savepoint
     * rather than aborting the test's own transaction.
     */
    private function assertRefused(string $connection, callable $statement): void
    {
        try {
            DB::connection($connection)->transaction($statement);
            $this->fail('The append-only trigger let the statement through.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    private function plus330(string $value): string
    {
        return Carbon::parse($value)->addMinutes(330)->format('Y-m-d H:i:s');
    }

    private function raw(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    public function test_the_tenant_shift_moves_instants_and_nothing_else(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            $db = DB::connection('organization');

            $location = Location::on('organization')->create([
                'name' => 'Noida', 'code' => 'TZ-NOI', 'type' => Location::CLINIC, 'is_active' => true,
            ]);
            $doctor = Doctor::on('organization')->create(['name' => 'Dr Mehta', 'is_active' => true]);
            $schedule = DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id,
                'location_id' => $location->id,
                'weekday' => 1,
                'starts_at' => '09:00',
                'ends_at' => '13:00',
            ]);
            $customer = Customer::on('organization')->create([
                'name' => 'Asha Rane',
                'date_of_birth' => '1990-05-15',
                'is_active' => true,
            ]);

            // Known values: what the application wrote in UTC.
            $db->table('customers')->where('id', $customer->id)->update([
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 18:45:00',
            ]);

            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Customer')
                ->where('entity_id', $customer->id)
                ->firstOrFail();
            $before = (array) $db->table('activity_logs')->where('id', $log->id)->first();

            $this->onOrganizationConnection(fn () => $this->migration(self::TENANT)->up());

            $row = $db->table('customers')->where('id', $customer->id)->first();
            $this->assertSame('2026-01-01 05:30:00', $this->raw($row->created_at));
            // Crosses midnight, as a real evening visit would.
            $this->assertSame('2026-01-02 00:15:00', $this->raw($row->updated_at));

            // Wall-clock columns stay exactly as they were.
            $this->assertSame('1990-05-15', (string) $row->date_of_birth);
            $sitting = $db->table('doctor_schedules')->where('id', $schedule->id)->first();
            $this->assertSame('09:00:00', $sitting->starts_at);
            $this->assertSame('13:00:00', $sitting->ends_at);

            // The audit row: its time moved, and not one other byte.
            $after = (array) $db->table('activity_logs')->where('id', $log->id)->first();
            $this->assertSame($this->plus330($before['created_at']), $this->raw($after['created_at']));
            unset($before['created_at'], $after['created_at']);
            $this->assertSame($before, $after);

            // Its guard is back on, and still refuses.
            $this->assertSame('O', $this->triggerState('organization', 'activity_logs_no_update_or_delete'));
            $this->assertRefused('organization', fn () => $db->table('activity_logs')->where('id', $log->id)->update(['event' => 'tampered']));
            $this->assertRefused('organization', fn () => $db->table('activity_logs')->where('id', $log->id)->delete());

            // And the rollback puts every value back.
            $this->onOrganizationConnection(fn () => $this->migration(self::TENANT)->down());

            $row = $db->table('customers')->where('id', $customer->id)->first();
            $this->assertSame('2026-01-01 00:00:00', $this->raw($row->created_at));
            $this->assertSame('2026-01-01 18:45:00', $this->raw($row->updated_at));
            $this->assertSame('1990-05-15', (string) $row->date_of_birth);
            $this->assertSame(
                $this->raw($log->getRawOriginal('created_at')),
                $this->raw($db->table('activity_logs')->where('id', $log->id)->value('created_at')),
            );

            $this->assertSame('O', $this->triggerState('organization', 'activity_logs_no_update_or_delete'));
            $this->assertRefused('organization', fn () => $db->table('activity_logs')->where('id', $log->id)->update(['event' => 'tampered']));
        });
    }

    public function test_the_master_shift_moves_instants_but_not_subscription_dates(): void
    {
        $type = OrganizationType::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);
        $organization = Organization::factory()->create(['organization_type_id' => $type->id]);

        DB::table('organizations')->where('id', $organization->id)->update([
            'created_at' => '2026-03-10 20:00:00',
            'trial_ends_at' => '2026-04-10 20:00:00',
        ]);

        $binding = OrganizationModule::create([
            'organization_id' => $organization->id,
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
            'starts_at' => '2026-02-01',
            'expires_at' => '2026-12-31',
        ]);

        $auditId = DB::table('platform_audit_logs')->insertGetId([
            'action' => 'organization.created',
            'entity_type' => 'organization',
            'entity_id' => $organization->id,
            'entity_label' => 'Known row',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $before = (array) DB::table('platform_audit_logs')->where('id', $auditId)->first();

        $this->migration(self::MASTER)->up();

        $org = DB::table('organizations')->where('id', $organization->id)->first();
        $this->assertSame('2026-03-11 01:30:00', $this->raw($org->created_at));
        $this->assertSame('2026-04-11 01:30:00', $this->raw($org->trial_ends_at));

        // Calendar dates in timestamp columns stay at midnight.
        $module = DB::table('organization_modules')->where('id', $binding->id)->first();
        $this->assertSame('2026-02-01 00:00:00', $this->raw($module->starts_at));
        $this->assertSame('2026-12-31 00:00:00', $this->raw($module->expires_at));

        $after = (array) DB::table('platform_audit_logs')->where('id', $auditId)->first();
        $this->assertSame('2026-01-01 05:30:00', $this->raw($after['created_at']));
        unset($before['created_at'], $after['created_at']);
        $this->assertSame($before, $after);

        $this->assertSame('O', $this->triggerState('pgsql', 'platform_audit_logs_no_update_or_delete'));
        $this->assertRefused('pgsql', fn () => DB::table('platform_audit_logs')->where('id', $auditId)->update(['action' => 'tampered']));
        $this->assertRefused('pgsql', fn () => DB::table('platform_audit_logs')->where('id', $auditId)->delete());

        $this->migration(self::MASTER)->down();

        $this->assertSame('2026-03-10 20:00:00', $this->raw(DB::table('organizations')->where('id', $organization->id)->value('created_at')));
        $this->assertSame('2026-02-01 00:00:00', $this->raw(DB::table('organization_modules')->where('id', $binding->id)->value('starts_at')));
        $this->assertSame('2026-01-01 00:00:00', $this->raw(DB::table('platform_audit_logs')->where('id', $auditId)->value('created_at')));
        $this->assertSame('O', $this->triggerState('pgsql', 'platform_audit_logs_no_update_or_delete'));
    }

    /** The application and its database session agree on the zone. */
    public function test_the_application_and_the_database_session_run_in_india_time(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('Asia/Kolkata', now()->timezoneName);
        $this->assertSame('Asia/Kolkata', DB::scalar('SHOW TIME ZONE'));

        $organization = $this->provisionOrganization();

        $this->assertSame(
            'Asia/Kolkata',
            $this->onTenant($organization, fn () => DB::connection('organization')->scalar('SHOW TIME ZONE')),
        );
    }

    /**
     * The screens send `since` as a UTC instant (toISOString()). It has to be
     * compared as that instant, not as a 5h30-earlier wall-clock time.
     */
    public function test_since_filters_compare_instants_not_wall_clocks(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Since Check',
            'phone' => '9876500077',
            'is_active' => true,
        ])->assertCreated();

        $labels = fn (string $since) => array_column(
            $this->getJson('/api/v1/tenant/history?since='.urlencode($since))->assertOk()->json('data'),
            'entity_label',
        );

        $this->assertContains('Since Check', $labels(now()->subMinutes(2)->utc()->toIso8601ZuluString()));
        $this->assertNotContains('Since Check', $labels(now()->addMinutes(2)->utc()->toIso8601ZuluString()));

        // The platform log reads the same parameter the same way.
        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $this->actingAs($admin, 'platform');

        $type = OrganizationType::create(['name' => 'Clinic', 'slug' => 'clinic-since', 'is_active' => true]);
        $organizationName = Organization::factory()->create(['organization_type_id' => $type->id])->organization_name;

        $audit = fn (string $since) => array_column(
            $this->getJson('/api/v1/admin/audit?since='.urlencode($since))->assertOk()->json('data'),
            'entity_label',
        );

        $this->assertContains($organizationName, $audit(now()->subMinutes(2)->utc()->toIso8601ZuluString()));
        $this->assertNotContains($organizationName, $audit(now()->addMinutes(2)->utc()->toIso8601ZuluString()));
    }
}
