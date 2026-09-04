<?php

namespace Tests\Feature\History;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Customer;
use App\Models\Tenant\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * The history trait.
 *
 * What matters is not that rows appear, but that they are *usable evidence*:
 * only the fields that changed, never a secret, attributable after the actor
 * is deleted, and impossible to edit afterwards.
 */
class RecordsHistoryTest extends TenantTestCase
{
    use RefreshDatabase;

    private function admin(): PlatformUser
    {
        return PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
    }

    /**
     * The highest log id so far.
     *
     * The table cannot be truncated between assertions — that is the whole
     * point of it — so a test scopes itself to what happened after this
     * mark rather than to an empty table.
     */
    private function watermark(): int
    {
        return (int) PlatformAuditLog::max('id');
    }

    private function organization(): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        return Organization::factory()->create(['organization_type_id' => $type->id]);
    }

    /**
     * An update records the changed field and nothing else.
     *
     * Storing the whole row would make every entry a haystack, and would
     * accumulate copies of data an organization may later ask to have
     * deleted.
     */
    public function test_an_update_records_only_the_fields_that_changed(): void
    {
        $organization = $this->organization();
        $mark = $this->watermark();

        $organization->update(['organization_name' => 'Renamed Pharmacy']);

        $log = PlatformAuditLog::where('id', '>', $mark)
            ->where('entity_type', 'Organization')
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(['organization_name'], array_keys($log->after));
        $this->assertSame('Renamed Pharmacy', $log->after['organization_name']);

        // And what it was, which is the half that makes it an audit trail.
        $this->assertSame(['organization_name'], array_keys($log->before));
        $this->assertNotSame('Renamed Pharmacy', $log->before['organization_name']);
    }

    /** Touching a record without changing anything is not an event. */
    public function test_an_update_that_changes_nothing_writes_no_row(): void
    {
        $organization = $this->organization();
        $mark = $this->watermark();

        $organization->update(['organization_name' => $organization->organization_name]);

        $this->assertSame(
            0,
            PlatformAuditLog::where('id', '>', $mark)->where('action', 'updated')->count(),
        );
    }

    /**
     * A secret must never reach a table that cannot be edited afterwards.
     *
     * Asserted on a tenant user because that is the model that actually
     * carries a password hash.
     */
    public function test_a_password_is_never_written_to_the_log(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            $user = User::on('organization')->firstOrFail();

            $user->update(['password' => bcrypt('a-new-secret-value')]);

            $rows = ActivityLog::on('organization')->get();

            foreach ($rows as $row) {
                $payload = json_encode([$row->before, $row->after]);

                $this->assertStringNotContainsString('password', (string) $payload);
                $this->assertStringNotContainsString('$2y$', (string) $payload);
            }
        });
    }

    /**
     * A tenant record's history stays in the tenant's own database.
     *
     * Pooling every organization's record-level history in the master
     * database is the one thing a database per tenant exists to prevent.
     */
    public function test_a_tenant_change_is_logged_in_the_tenant_database(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Asha Rane',
            'phone' => '9876500001',
            'is_active' => true,
        ])->assertCreated();

        $this->onTenant($organization, function () {
            $log = ActivityLog::on('organization')
                ->where('entity_type', 'Customer')
                ->where('event', 'created')
                ->firstOrFail();

            $this->assertSame('Asha Rane', $log->entity_label);
            $this->assertSame('tenant', $log->actor_type);
            $this->assertNotNull($log->actor_name);
        });

        // And nothing about that customer reached the master database.
        $this->assertSame(
            0,
            PlatformAuditLog::where('entity_type', 'Customer')->count(),
        );
    }

    /**
     * The actor's name is copied, not looked up.
     *
     * A join answers nothing once the account is gone — "somebody renamed
     * this organization" is not an audit trail — so the name is written
     * alongside the id at the time of the change.
     */
    public function test_the_actor_name_survives_the_actor_being_deleted(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform');

        $organization = $this->organization();
        $organization->update(['organization_name' => 'Still Attributable']);

        $log = PlatformAuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $log->platform_user_id);
        $this->assertSame($admin->name, $log->actor_name);

        /*
         * Force-deleted, not soft-deleted: the account is really gone.
         *
         * That this succeeds at all is the first assertion. The column used
         * to be ON DELETE SET NULL, which made Postgres issue an UPDATE
         * against an append-only table — the trigger refused it and the
         * delete failed, so an administrator with any history could never be
         * removed.
         */
        $admin->forceDelete();

        $log->refresh();

        // The row survives untouched, and the name is what still identifies
        // who acted. The id is now a reference that resolves to nothing.
        $this->assertSame($admin->name, $log->actor_name);
        $this->assertNull($log->platformUser);
    }

    /** A soft delete is a deletion to whoever reads the log. */
    public function test_a_soft_delete_is_recorded_as_a_deletion_not_an_update(): void
    {
        $organization = $this->organization();
        $mark = $this->watermark();

        $organization->delete();

        $events = PlatformAuditLog::where('id', '>', $mark)
            ->where('entity_type', 'Organization')
            ->pluck('action')
            ->all();

        $this->assertSame(['deleted'], $events);
    }

    /**
     * An organization's own history includes what it was sold.
     *
     * Assigning a module writes a row about the *binding*, not about the
     * organization, so a tab filtered by entity type would show renames and
     * silently miss every commercial decision. It is scoped by
     * organization_id for exactly that reason.
     */
    public function test_an_organizations_history_includes_its_module_changes(): void
    {
        $this->artisan('modules:sync');

        $admin = $this->admin();
        $this->actingAs($admin, 'platform');

        $organization = $this->organization();
        $organization->update(['organization_name' => 'Renamed']);

        $this->putJson("/api/v1/admin/organizations/{$organization->uuid}/modules", [
            'modules' => [['key' => 'appointments', 'is_enabled' => true]],
        ])->assertOk();

        $rows = $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/history")
            ->assertOk()
            ->json('data');

        $types = array_column($rows, 'entity_type');

        $this->assertContains('Organization', $types);
        $this->assertContains('OrganizationModule', $types);

        // A binding has no name of its own, so it borrows the module's.
        $binding = collect($rows)->firstWhere('entity_type', 'OrganizationModule');
        $this->assertSame('Appointments', $binding['entity_label']);

        // And the diff arrives ready to render, oldest value first.
        $rename = collect($rows)->first(
            fn (array $row) => $row['entity_type'] === 'Organization'
                && $row['event'] === 'updated'
        );

        $this->assertSame('organization_name', $rename['changes'][0]['field']);
        $this->assertSame('Renamed', $rename['changes'][0]['to']);
        $this->assertNotNull($rename['changes'][0]['from']);
    }

    /** Another organization's changes never appear in this one's history. */
    public function test_one_organizations_history_does_not_leak_into_another(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform');

        $mine = $this->organization();
        $theirs = $this->organization();

        $theirs->update(['organization_name' => 'Not Mine']);

        $labels = collect(
            $this->getJson("/api/v1/admin/organizations/{$mine->uuid}/history")
                ->assertOk()
                ->json('data')
        )->pluck('entity_label');

        $this->assertFalse($labels->contains('Not Mine'));
    }

    /**
     * The two logs are separate, and each side sees only its own.
     *
     * A super administrator's platform actions are not the organization's
     * business, and the organization's records are not the platform log's.
     * The isolation is physical — a different table in a different database
     * — rather than a where clause somebody has to remember.
     */
    public function test_each_side_sees_only_its_own_log(): void
    {
        $admin = $this->admin();

        // Something on the platform side, by an administrator.
        $this->actingAs($admin, 'platform');
        $platformOrg = $this->organization();
        $platformOrg->update(['organization_name' => 'Platform Side Change']);

        // And something inside a tenant, by its own owner.
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Tenant Side Change',
            'phone' => '9876500002',
            'is_active' => true,
        ])->assertCreated();

        $tenantRows = $this->getJson('/api/v1/tenant/history')->assertOk()->json('data');
        $tenantLabels = array_column($tenantRows, 'entity_label');

        $this->assertContains('Tenant Side Change', $tenantLabels);
        $this->assertNotContains('Platform Side Change', $tenantLabels);

        // The platform log, read as the administrator, has the mirror image.
        $this->actingAs($admin, 'platform');

        $platformLabels = array_column(
            $this->getJson('/api/v1/admin/audit')->assertOk()->json('data'),
            'entity_label'
        );

        $this->assertContains('Platform Side Change', $platformLabels);
        $this->assertNotContains('Tenant Side Change', $platformLabels);
    }

    /** The whole tenant log is the owner's; staff are refused. */
    public function test_staff_cannot_read_the_whole_tenant_log(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/history')->assertForbidden();
    }

    /**
     * One record's history is open to anybody who can already see it.
     *
     * Somebody serving a customer may reasonably ask who last changed their
     * phone number; that is not the same privilege as reading everything
     * everyone in the organization has ever done.
     */
    public function test_staff_can_read_one_records_history(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsStaff($organization);

        $customer = $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Asha Rane',
            'phone' => '9876500003',
            'is_active' => true,
        ])->assertCreated()->json('data');

        $rows = $this->getJson("/api/v1/tenant/history/Customer/{$customer['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame('created', $rows[0]['event']);
        $this->assertSame('Asha Rane', $rows[0]['entity_label']);
    }

    /**
     * The log cannot be rewritten, and the database is what says so.
     *
     * Convention is not a control: a log the application can quietly edit is
     * not evidence of anything.
     */
    public function test_the_platform_log_refuses_to_be_edited(): void
    {
        $organization = $this->organization();
        $log = PlatformAuditLog::where('entity_type', 'Organization')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('platform_audit_logs')
            ->where('id', $log->id)
            ->update(['action' => 'something else']);
    }

    /** The same guarantee inside a tenant database. */
    public function test_the_tenant_log_refuses_to_be_edited(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            Customer::on('organization')->create([
                'name' => 'Vikram Shah',
                'is_active' => true,
            ]);

            $log = ActivityLog::on('organization')->firstOrFail();

            $this->expectException(QueryException::class);

            DB::connection('organization')
                ->table('activity_logs')
                ->where('id', $log->id)
                ->delete();
        });
    }
}
