<?php

namespace Tests\Feature\History;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Who gave whom which permission, and when.
 *
 * The most security-relevant change this software allows is a capability
 * being granted or a branch losing a module, and until now neither left any
 * trace at all: both are written in bulk — a mass delete and a loop of
 * inserts — so no model event ever saw them. A trait on the join tables would
 * have been worse than nothing, recording every permission granted and not
 * one withdrawn.
 *
 * So the entry is written against the OWNER of the set, whole: the role whose
 * meaning changed, the branch that lost the module.
 */
class PermissionChangesAreLoggedTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('L');
        $this->grantModule($this->organization, 'medicines');
        $this->grantModule($this->organization, 'appointments');

        $this->signInAsOwner($this->organization);
    }

    /** The log entries about one role, newest last. */
    private function roleEntries(int $roleId): array
    {
        return $this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'Role')
            ->where('entity_id', $roleId)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function createRole(array $capabilities): array
    {
        return $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Front Desk',
            'scope' => Role::SCOPE_BRANCH,
            'capabilities' => $capabilities,
        ])->assertCreated()->json('data');
    }

    public function test_granting_a_capability_is_recorded_against_the_role(): void
    {
        $role = $this->createRole(['customers.view']);

        $this->putJson("/api/v1/tenant/roles/{$role['id']}", [
            'name' => 'Front Desk',
            'capabilities' => ['customers.view', 'customers.create'],
        ])->assertOk();

        $entries = $this->roleEntries($role['id']);
        $last = end($entries);

        $this->assertSame('Front Desk', $last->entity_label);
        $this->assertSame(['customers.view'], $last->before['capabilities']);
        $this->assertSame(['customers.create', 'customers.view'], $last->after['capabilities']);
        $this->assertSame('tenant', $last->actor_type);
        $this->assertNotNull($last->actor_name);
    }

    /**
     * The half that was silent, and the half that matters most.
     *
     * A capability is removed by a mass delete on the relation, which raises
     * no model event whatsoever — so before this, a role could be stripped of
     * everything it held and the log would show nothing at all.
     */
    public function test_revoking_a_capability_is_recorded(): void
    {
        $role = $this->createRole(['customers.view', 'customers.create', 'customers.edit']);

        $this->putJson("/api/v1/tenant/roles/{$role['id']}", [
            'name' => 'Front Desk',
            'capabilities' => ['customers.view'],
        ])->assertOk();

        $entries = $this->roleEntries($role['id']);
        $last = end($entries);

        $this->assertSame(
            ['customers.create', 'customers.edit', 'customers.view'],
            $last->before['capabilities'],
        );
        $this->assertSame(['customers.view'], $last->after['capabilities']);
    }

    /** Saving the same set again, in any order, is not a change. */
    public function test_re_saving_the_same_capabilities_writes_nothing(): void
    {
        $role = $this->createRole(['customers.view', 'customers.create']);

        $before = count($this->roleEntries($role['id']));

        $this->putJson("/api/v1/tenant/roles/{$role['id']}", [
            'name' => 'Front Desk',
            // Same set, submitted in the other order.
            'capabilities' => ['customers.create', 'customers.view'],
        ])->assertOk();

        $this->assertCount($before, $this->roleEntries($role['id']));
    }

    /**
     * A branch switching a module off, and back on again.
     *
     * Switching one back ON deletes its row, so the row itself can never
     * carry this history — the branch has to.
     */
    public function test_a_branch_turning_a_module_off_and_on_is_recorded(): void
    {
        $branch = $this->onTenant($this->organization, fn () => Location::on('organization')
            ->create([
                'name' => 'Delhi',
                'code' => 'L-DEL',
                'type' => Location::CLINIC,
                'is_active' => true,
            ])->id);

        $this->putJson("/api/v1/tenant/locations/{$branch}/modules", [
            'modules' => ['medicines'],
        ])->assertOk();

        $entries = $this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'Location')
            ->where('entity_id', $branch)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get()
            ->all());

        $off = end($entries);

        $this->assertSame([], $off->before['modules_off']);
        $this->assertSame(['appointments'], $off->after['modules_off']);

        // And back on: the row disappears, the history does not.
        $this->putJson("/api/v1/tenant/locations/{$branch}/modules", [
            'modules' => ['medicines', 'appointments'],
        ])->assertOk();

        $entries = $this->onTenant($this->organization, fn () => ActivityLog::on('organization')
            ->where('entity_type', 'Location')
            ->where('entity_id', $branch)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get()
            ->all());

        $on = end($entries);

        $this->assertSame(['appointments'], $on->before['modules_off']);
        $this->assertSame([], $on->after['modules_off']);
    }
}
