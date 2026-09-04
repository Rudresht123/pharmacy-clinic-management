<?php

namespace App\Repositories\Platform;

use App\Models\Platform\Organization;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationRepository extends BaseRepository implements OrganizationRepositoryInterface
{
    public function __construct(Organization $model)
    {
        parent::__construct($model);
    }

    public function findByUuid(string $uuid): ?Organization
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    public function findByUuidOrFail(string $uuid): Organization
    {
        return $this->findByUuid($uuid)
            ?? throw (new ModelNotFoundException)->setModel(Organization::class, [$uuid]);
    }

    public function findBySlug(string $slug): ?Organization
    {
        return $this->query()->where('slug', $slug)->first();
    }

    public function listing(array $filters = []): Builder
    {
        return $this->query()
            ->with('organizationType')
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $query) => $query->where('status', $filters['status'])
            )
            ->when(
                filled($filters['organization_type_id'] ?? null),
                fn (Builder $query) => $query->where(
                    'organization_type_id',
                    $filters['organization_type_id']
                )
            )
            ->when(
                ! is_null($filters['is_active'] ?? null),
                fn (Builder $query) => $query->where('is_active', $filters['is_active'])
            );
    }

    public function changeStatus(
        Organization $organization,
        string $status,
        ?string $reason = null,
        ?int $changedBy = null,
    ): Organization {
        $from = $organization->status;

        if ($from === $status) {
            return $organization;
        }

        return DB::transaction(function () use ($organization, $from, $status, $reason, $changedBy) {
            $attributes = ['status' => $status];

            /*
             * The lifecycle timestamps belong to the transition, not to the
             * caller. Setting them here means no controller can move an
             * organization to active and forget to stamp activated_at.
             */
            if ($status === Organization::ACTIVE) {
                $attributes['activated_at'] ??= $organization->activated_at ?? now();
                $attributes['suspended_at'] = null;
                $attributes['suspension_reason'] = null;
            }

            if ($status === Organization::SUSPENDED) {
                $attributes['suspended_at'] = now();
                $attributes['suspension_reason'] = $reason;
            }

            $organization->update($attributes);

            $organization->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $status,
                'reason' => $reason,
                'changed_by' => $changedBy,
            ]);

            return $organization->refresh();
        });
    }

    public function countsByStatus(): Collection
    {
        return $this->query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }
}
