<?php

namespace App\Services\Laboratory;

use App\Support\Laboratory\LabMasters;
use App\Support\Laboratory\StarterLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Adding, changing and removing rows of the laboratory's short lists.
 *
 * What may be written is the route's question (`laboratory.settings_manage`);
 * what a row must look like is LabMasters'. This is what happens in between:
 * a new row goes to the end of its list, and a row something still points at is
 * not removed — it is switched off instead, so what used it keeps naming it.
 */
class LabMasterService
{
    /**
     * One list in its own order.
     *
     * @param  array{search?: string|null, active_only?: bool}  $filters
     * @return Collection<int, Model>
     */
    public function list(string $key, array $filters = []): Collection
    {
        $definition = LabMasters::get($key);
        $search = trim((string) ($filters['search'] ?? ''));

        return $definition['model']::query()
            ->with(LabMasters::relations($key))
            ->when(! empty($filters['active_only']), fn (Builder $query) => $query->active())
            ->when($search !== '', function (Builder $query) use ($definition, $search) {
                $columns = collect($definition['fields'])
                    ->whereIn('type', ['text', 'code', 'phone', 'email'])
                    ->pluck('name');

                $query->where(function (Builder $inner) use ($columns, $search) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'ILIKE', '%'.addcslashes($search, '%_\\').'%');
                    }
                });
            })
            ->inListOrder()
            ->get();
    }

    public function find(string $key, int $id): Model
    {
        return LabMasters::model($key)::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $data */
    public function create(string $key, array $data): Model
    {
        $class = LabMasters::model($key);

        return (new $class)->getConnection()->transaction(function () use ($class, $key, $data) {
            // A new row goes to the end of the list unless it was given a place.
            $data['position'] ??= ((int) $class::query()->max('position')) + 1;

            $record = $class::query()->create($data);

            return $record->load(LabMasters::relations($key));
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $key, Model $record, array $data): Model
    {
        $record->fill($data)->save();

        return $record->load(LabMasters::relations($key));
    }

    /**
     * Remove a row, with a reason.
     *
     * REFUSED WHILE ANYTHING STILL USES IT. A soft delete never fires the
     * foreign key, so without this a department could vanish from under the
     * categories filed in it. Switching it off is always allowed and is what
     * the refusal suggests.
     */
    public function remove(string $key, Model $record, string $reason): void
    {
        $definition = LabMasters::get($key);
        $uses = [];

        foreach ($definition['used_by'] as $use) {
            $count = $use['model']::query()->where($use['column'], $record->getKey())->count();

            if ($count > 0) {
                $uses[] = $count.' '.($count === 1 ? $use['one'] : $use['many']);
            }
        }

        if ($uses !== []) {
            throw new ConflictHttpException(sprintf(
                '%s is still used by %s. Switch it off instead, or move %s first.',
                $record->label(),
                $this->sentence($uses),
                array_sum(array_map('intval', $uses)) === 1 ? 'it' : 'them',
            ));
        }

        $record->deleteWithReason($reason);
    }

    /**
     * Add the starter rows this list does not already have.
     *
     * Matched by the list's own label, ignoring case except where the list
     * itself compares with case (units), so pressing it twice adds nothing
     * the second time and never touches a row somebody edited.
     *
     * @return int how many rows were added
     */
    public function loadStarter(string $key): int
    {
        $definition = LabMasters::get($key);

        if (! $definition['starter']) {
            throw new ConflictHttpException("There is no starter list for {$definition['plural']}.");
        }

        $class = $definition['model'];
        $label = $definition['label'];
        $caseSensitive = collect($definition['fields'])->firstWhere('name', $label)['unique'] === 'cs';

        return (new $class)->getConnection()->transaction(function () use ($class, $key, $label, $caseSensitive) {
            $have = $class::query()->pluck($label)
                ->map(fn ($value) => $caseSensitive ? (string) $value : mb_strtolower((string) $value))
                ->all();

            $position = (int) $class::query()->max('position');
            $added = 0;

            foreach (StarterLists::for($key) as $row) {
                $name = $caseSensitive ? $row[$label] : mb_strtolower($row[$label]);

                if (in_array($name, $have, true)) {
                    continue;
                }

                $class::query()->create([...$row, 'is_active' => true, 'position' => ++$position]);
                $have[] = $name;
                $added++;
            }

            return $added;
        });
    }

    /** @param  list<string>  $parts */
    private function sentence(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(', ', array_slice($parts, 0, -1)).' and '.end($parts);
    }
}
