<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The vocabulary every repository shares.
 *
 * Controllers depend on an interface like this one rather than on Eloquent
 * directly, which buys three things:
 *
 *   1. Query logic lives in one place per table, so "how do we list active
 *      organizations" has exactly one answer instead of one per controller.
 *   2. A test can hand the controller a fake, with no database at all.
 *   3. When a model later moves to a tenant connection, only its repository
 *      changes — no controller has to know.
 *
 * Keep this small. Anything specific to one table belongs on that table's
 * own interface, not here.
 */
interface RepositoryInterface
{
    /** A fresh query builder, for the cases a caller genuinely needs one. */
    public function query(): Builder;

    public function find(int|string $id): ?Model;

    public function findOrFail(int|string $id): Model;

    /** First row matching a single column, or null. */
    public function findBy(string $column, mixed $value): ?Model;

    public function all(): Collection;

    public function create(array $attributes): Model;

    public function update(Model $model, array $attributes): Model;

    public function delete(Model $model): bool;

    public function paginate(int $perPage = 15): LengthAwarePaginator;
}
