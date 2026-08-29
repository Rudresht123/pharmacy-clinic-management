<?php

namespace Database\Factories\Platform;

use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformUser>
 */
class PlatformUserFactory extends Factory
{
    protected $model = PlatformUser::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::ulid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Attach a role after creation, creating the role row if the seeder has
     * not run in this test.
     */
    public function withRole(string $code = PlatformRole::SUPER_ADMIN): static
    {
        return $this->afterCreating(function (PlatformUser $user) use ($code) {
            $role = PlatformRole::firstOrCreate(
                ['code' => $code],
                ['name' => Str::headline($code)]
            );

            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
