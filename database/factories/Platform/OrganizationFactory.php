<?php

namespace Database\Factories\Platform;

use App\Models\Platform\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::limit(Str::slug($name), 55, '');

        return [
            'uuid' => (string) Str::ulid(),
            'organization_name' => $name,
            'slug' => $slug,
            'tenant_key' => Str::slug($slug, '_'),
            'organization_code' => fake()->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'subdomain' => $slug,

            // Never actually created by the factory — provisioning owns that.
            'database_name' => 'hms_tenant_'.Str::slug($slug, '_'),

            'email' => fake()->unique()->safeEmail(),
            'contact_person_name' => fake()->name(),
            'status' => Organization::PENDING,
            'is_active' => true,
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'country' => 'IN',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => Organization::ACTIVE,
            'activated_at' => now(),
            'is_setup_completed' => true,
            'setup_completed_at' => now(),
        ]);
    }

    public function suspended(string $reason = 'Non-payment'): static
    {
        return $this->state(fn () => [
            'status' => Organization::SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }
}
