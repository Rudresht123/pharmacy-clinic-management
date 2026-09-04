<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared implementation every repository inherits.
 *
 * A subclass normally only has to name its model:
 *
 *     class OrganizationRepository extends BaseRepository
 *     {
 *         public function __construct(Organization $model)
 *         {
 *             parent::__construct($model);
 *         }
 *
 *         public function activeForPlan(int $planId): Collection
 *         {
 *             return $this->query()->where('plan_id', $planId)->get();
 *         }
 *     }
 *
 * Methods beyond the shared eight go on the subclass, and get declared on
 * that subclass's own interface so callers can still type-hint the contract.
 */
abstract class BaseRepository implements RepositoryInterface
{
    public function __construct(protected Model $model) {}

    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    public function find(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    public function findBy(string $column, mixed $value): ?Model
    {
        return $this->query()->where($column, $value)->first();
    }

    public function all(): Collection
    {
        return $this->query()->get();
    }

    public function create(array $attributes): Model
    {
        return $this->model->newInstance()->create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        // Return the saved state, not the in-memory one — casts, mutators and
        // database defaults have all been applied by now.
        return $model->refresh();
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }
}
