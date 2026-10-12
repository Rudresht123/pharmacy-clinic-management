<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Jobs\ProvisionOrganization;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\TenantProvisionEvent;
use App\Services\Platform\OrganizationProvisioningService;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Setting a new organization up runs on the `provisioning` queue.
 *
 * The request that creates it returns at once with the organization pending;
 * a worker builds the database, the schema, the roles and the owner's login,
 * and the organization's page follows each step — and each migration — as it
 * lands. OrganizationProvisioningTest covers the steps themselves, run
 * inline the way the `sync` queue runs them.
 */
class OrganizationProvisioningQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A real queue, so the job is held rather than run on dispatch.
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService)->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    private function actingAsAdmin(): self
    {
        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();

        return $this->actingAs($admin, 'platform');
    }

    private function create(): Organization
    {
        $type = OrganizationType::create(['name' => 'Clinic '.uniqid(), 'slug' => 'clinic-'.uniqid(), 'is_active' => true]);
        $unique = uniqid();

        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Queue Test '.$unique,
                'organization_code' => OrganizationCode::generate(),
                'organization_type_id' => $type->id,
                'subdomain' => 'queue-test-'.$unique,
                'email' => "owner-{$unique}@example.com",
                'password' => 'OwnerPass1!',
                'password_confirmation' => 'OwnerPass1!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Organization::PENDING)
            ->assertJsonPath('message', 'Organization created. Setting it up in the background.');

        $organization = Organization::sole();
        $this->createdDatabases[] = $organization->database_name;

        return $organization;
    }

    /** What the queue would do with the job it was handed. */
    private function work(Organization $organization): void
    {
        $job = Queue::pushedJobs()[ProvisionOrganization::class][0]['job'] ?? null;
        $this->assertNotNull($job, 'Creating the organization queued nothing.');

        $job->handle(app(OrganizationProvisioningService::class));
        $organization->refresh();
    }

    private function migrationFiles(): int
    {
        return count(glob(base_path('database/migrations/organization').'/*_*.php'))
            + count(glob(base_path('database/migrations/organization/after-seed').'/*_*.php'));
    }

    public function test_creating_an_organization_queues_its_setup_and_returns_at_once(): void
    {
        $organization = $this->create();

        Queue::assertPushedOn('provisioning', ProvisionOrganization::class, fn (ProvisionOrganization $job) => $job->organizationId === $organization->id);
        Queue::assertPushed(ProvisionOrganization::class, fn (ProvisionOrganization $job) => $job->connection === 'provisioning');

        // Nothing has been built yet — that is the worker's job.
        $this->assertFalse(DatabaseService::exists($organization->database_name));

        $progress = $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/provisioning")
            ->assertOk()
            ->json('data');

        $this->assertSame(Organization::PENDING, $progress['status']);
        $this->assertTrue($progress['waiting_for_worker']);
        $this->assertSame(0, $progress['completed_steps']);
        $this->assertSame(array_keys(OrganizationProvisioningService::STEPS), array_column($progress['steps'], 'key'));
        $this->assertSame(['waiting'], array_values(array_unique(array_column($progress['steps'], 'status'))));
    }

    public function test_the_worker_builds_the_tenant_and_counts_every_migration_as_it_runs(): void
    {
        $organization = $this->create();

        $this->work($organization);

        $this->assertSame(Organization::ACTIVE, $organization->status);
        $this->assertTrue(DatabaseService::exists($organization->database_name));

        $progress = $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/provisioning")->assertOk()->json('data');

        $this->assertSame(Organization::ACTIVE, $progress['status']);
        $this->assertFalse($progress['waiting_for_worker']);
        $this->assertSame($progress['total_steps'], $progress['completed_steps']);
        $this->assertNotNull($progress['finished_at']);

        $migrate = collect($progress['steps'])->firstWhere('key', 'migrate_tenant');
        $total = $this->migrationFiles();

        $this->assertSame('completed', $migrate['status']);
        $this->assertSame($total, $migrate['progress_total'], 'Every migration was counted up front.');
        $this->assertSame($total, $migrate['progress_current'], 'Every migration was counted as it ran.');
        $this->assertNotEmpty($migrate['detail']);
    }

    public function test_a_second_run_of_a_finished_organization_does_nothing(): void
    {
        $organization = $this->create();
        $this->work($organization);

        $events = TenantProvisionEvent::query()->count();

        (new ProvisionOrganization($organization->id))->handle(app(OrganizationProvisioningService::class));

        $this->assertSame($events, TenantProvisionEvent::query()->count());
        $this->assertSame(Organization::ACTIVE, $organization->fresh()->status);
    }

    public function test_a_worker_that_stops_mid_step_leaves_a_failure_the_page_can_show(): void
    {
        $organization = $this->create();
        $organization->forceFill(['status' => Organization::PROVISIONING])->save();

        TenantProvisionEvent::query()->create([
            'organization_id' => $organization->id,
            'step' => 'migrate_tenant',
            'status' => 'in_progress',
            'progress_current' => 40,
            'progress_total' => 92,
            'started_at' => now(),
        ]);

        // A timeout or a killed worker: the queue calls failed() and nothing else.
        (new ProvisionOrganization($organization->id))->failed(new RuntimeException('Job timed out.'));

        $this->assertSame(Organization::FAILED, $organization->fresh()->status);

        $migrate = collect(
            $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/provisioning")->assertOk()->json('data.steps')
        )->firstWhere('key', 'migrate_tenant');

        $this->assertSame('failed', $migrate['status']);
        $this->assertSame('Job timed out.', $migrate['error']);
        $this->assertSame(40, $migrate['progress_current'], 'How far it got stays on the record.');
    }

    public function test_retrying_a_failed_organization_queues_it_again(): void
    {
        $organization = $this->create();
        $organization->forceFill(['status' => Organization::FAILED])->save();

        $this->postJson("/api/v1/admin/organizations/{$organization->uuid}/retry-provisioning")
            ->assertOk()
            ->assertJsonPath('data.status', Organization::PENDING)
            ->assertJsonPath('message', 'Provisioning queued again. It resumes from the step that failed.');

        Queue::assertPushed(ProvisionOrganization::class, 2);
    }

    public function test_only_a_platform_administrator_sees_the_progress(): void
    {
        $organization = $this->create();

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/admin/organizations/{$organization->uuid}/provisioning")->assertUnauthorized();
    }
}
