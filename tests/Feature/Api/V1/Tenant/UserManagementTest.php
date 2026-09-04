<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Fields\UserFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The owner managing who is in the organization.
 *
 * The rules that matter most here are the ones that stop an organization
 * locking itself out — there is no way back from that except a platform
 * admin editing the tenant database by hand.
 */
class UserManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    private function userId(Organization $organization, string $email): int
    {
        (new TenantConnectionService)->connect($organization->database_name);

        $id = TenantUser::on(TenantConnectionService::CONNECTION)
            ->where('email', $email)
            ->value('id');

        (new TenantConnectionService)->disconnect();

        return (int) $id;
    }

    /**
     * A valid new-person payload.
     *
     * Takes the organization because a staff account now needs a role to hold
     * — level three of the permission flow — and roles live in the tenant
     * database. An owner is given none: they bypass roles, and the request
     * refuses one rather than storing a limit nothing enforces.
     *
     * @return array<string, mixed>
     */
    private function payload(Organization $organization, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Priya Sharma',
            'email' => 'priya-'.uniqid().'@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'role' => TenantUser::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
        ], $overrides);

        if (($payload['role'] ?? null) === TenantUser::OWNER) {
            unset($payload['role_id']);
        }

        return $payload;
    }

    public function test_an_owner_can_add_and_list_people(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/users', $this->payload($organization))
            ->assertCreated()
            ->assertJsonPath('data.role', TenantUser::STAFF);

        // The owner and the staff member seeded above, plus the new one.
        $this->getJson('/api/v1/tenant/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_somebody_added_here_can_actually_sign_in(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $email = 'newcomer-'.uniqid().'@example.com';

        $this->postJson('/api/v1/tenant/users', $this->payload($organization, ['email' => $email]))
            ->assertCreated();

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $email,
            'password' => 'Str0ng!Pass',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', TenantUser::STAFF);
    }

    public function test_staff_cannot_manage_people(): void
    {
        $organization = $this->provisionOrganization();
        $staffId = $this->userId($organization, self::STAFF_EMAIL);
        $this->signIn($organization, self::STAFF_EMAIL);

        $this->getJson('/api/v1/tenant/users')->assertStatus(403);
        $this->postJson('/api/v1/tenant/users', $this->payload($organization))->assertStatus(403);
        $this->deleteJson("/api/v1/tenant/users/{$staffId}")->assertStatus(403);
    }

    /** Leaving the password blank keeps the one they already have. */
    public function test_editing_without_a_password_leaves_it_alone(): void
    {
        $organization = $this->provisionOrganization();
        $staffId = $this->userId($organization, self::STAFF_EMAIL);
        $this->signIn($organization, $organization->email);

        $this->putJson("/api/v1/tenant/users/{$staffId}", [
            'name' => 'Renamed Staff',
            'email' => self::STAFF_EMAIL,
            'role' => TenantUser::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed Staff');

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => self::STAFF_EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_the_only_owner_cannot_be_demoted(): void
    {
        $organization = $this->provisionOrganization();
        $ownerId = $this->userId($organization, $organization->email);
        $this->signIn($organization, $organization->email);

        $this->putJson("/api/v1/tenant/users/{$ownerId}", [
            'name' => 'Owner',
            'email' => $organization->email,
            'role' => TenantUser::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_the_only_owner_cannot_be_removed(): void
    {
        $organization = $this->provisionOrganization();
        $ownerId = $this->userId($organization, $organization->email);
        $this->signIn($organization, $organization->email);

        $this->deleteJson("/api/v1/tenant/users/{$ownerId}")->assertStatus(422);
    }

    public function test_an_owner_can_be_demoted_once_there_is_another(): void
    {
        $organization = $this->provisionOrganization();
        $ownerId = $this->userId($organization, $organization->email);
        $this->signIn($organization, $organization->email);

        $secondOwner = 'second-owner-'.uniqid().'@example.com';

        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'email' => $secondOwner,
            'role' => TenantUser::OWNER,
        ]))->assertCreated();

        $this->putJson("/api/v1/tenant/users/{$ownerId}", [
            'name' => 'Owner',
            'email' => $organization->email,
            'role' => TenantUser::STAFF,
            'role_id' => $this->staffRoleId($organization),
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.role', TenantUser::STAFF);
    }

    public function test_you_cannot_remove_or_deactivate_yourself(): void
    {
        $organization = $this->provisionOrganization();
        $ownerId = $this->userId($organization, $organization->email);
        $this->signIn($organization, $organization->email);

        // A second owner, so the "last owner" rule is not what refuses these.
        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'email' => 'second-owner-'.uniqid().'@example.com',
            'role' => TenantUser::OWNER,
        ]))->assertCreated();

        $this->deleteJson("/api/v1/tenant/users/{$ownerId}")->assertStatus(422);

        $this->putJson("/api/v1/tenant/users/{$ownerId}", [
            'name' => 'Owner',
            'email' => $organization->email,
            'role' => TenantUser::OWNER,
            'is_active' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    /**
     * Removing somebody should free their address again — the plain unique
     * index counted soft-deleted rows and made that impossible.
     */
    public function test_a_removed_persons_email_can_be_used_again(): void
    {
        $organization = $this->provisionOrganization();
        $staffId = $this->userId($organization, self::STAFF_EMAIL);
        $this->signIn($organization, $organization->email);

        $this->deleteJson("/api/v1/tenant/users/{$staffId}")->assertOk();

        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'email' => self::STAFF_EMAIL,
        ]))->assertCreated();
    }

    public function test_a_duplicate_email_is_rejected_while_the_person_is_live(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'email' => self::STAFF_EMAIL,
        ]))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * The same configuration layer Locations uses, on People.
     *
     * Nearly every built-in field here is locked — a person with no email
     * cannot sign in — so what an organization actually customises on this
     * screen is the fields it adds for itself.
     */
    public function test_an_organization_can_add_its_own_field_to_people(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $fields = array_map(
            fn (array $field, int $index) => [
                'field_key' => $field['key'],
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? null,
                'is_custom' => false,
                'data_type' => null,
                'options' => null,
                'is_required' => $field['required'],
                'show_in_form' => true,
                'show_in_table' => $field['in_table'],
                'sort_order' => $index,
            ],
            UserFields::all(),
            array_keys(UserFields::all())
        );

        $fields[] = [
            'field_key' => 'employee_code',
            'label' => 'Employee code',
            'placeholder' => 'EMP-014',
            'is_custom' => true,
            'data_type' => EntityFieldSetting::TYPE_TEXT,
            'options' => null,
            'is_required' => true,
            'show_in_form' => true,
            'show_in_table' => true,
            'sort_order' => 50,
        ];

        $this->putJson('/api/v1/tenant/settings/fields/user', ['fields' => $fields])
            ->assertOk();

        // Now mandatory, so somebody without it is refused…
        $this->postJson('/api/v1/tenant/users', $this->payload($organization))
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_fields.employee_code');

        // …and it round-trips once given.
        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'custom_fields' => ['employee_code' => 'EMP-014'],
        ]))->assertCreated();
    }

    public function test_the_people_field_registry_is_readable(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->getJson('/api/v1/tenant/users/fields')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'name');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/users', $this->payload($organization, [
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
