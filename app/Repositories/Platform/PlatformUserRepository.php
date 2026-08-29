<?php

namespace App\Repositories\Platform;

use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\PlatformUserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PlatformUserRepository extends BaseRepository implements PlatformUserRepositoryInterface
{
    public function __construct(PlatformUser $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?PlatformUser
    {
        return $this->query()->where('email', $email)->first();
    }

    public function findByUuid(string $uuid): ?PlatformUser
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    public function activeWithRoles(): Collection
    {
        return $this->query()
            ->with('roles')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function createWithRoles(array $attributes, array $roleCodes): PlatformUser
    {
        // One transaction, so a failure while attaching roles cannot leave an
        // administrator behind who can sign in but is authorised for nothing.
        return DB::transaction(function () use ($attributes, $roleCodes) {
            /** @var PlatformUser $user */
            $user = $this->create($attributes);

            return $this->syncRoles($user, $roleCodes);
        });
    }

    public function syncRoles(PlatformUser $user, array $roleCodes): PlatformUser
    {
        $ids = PlatformRole::query()
            ->whereIn('code', $roleCodes)
            ->pluck('id');

        $user->roles()->sync($ids);

        return $user->load('roles');
    }

    public function recordLogin(PlatformUser $user, ?string $ip): void
    {
        // forceFill because these columns are deliberately not fillable —
        // nothing that comes from a request should ever be able to set them.
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }
}
