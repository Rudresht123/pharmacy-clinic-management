<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The rules of the department tree.
 *
 * Two levels and no more: a sub-department's parent is a department, and a
 * department that has sub-departments cannot become one. That is also what
 * rules out a department being its own parent or its own descendant.
 *
 * A doctor's department text (`doctors.specialisation`, still read by the
 * OPD board, filters and booking) is the top-level department's name, so it
 * is rewritten here whenever a rename or a move changes it.
 */
class DepartmentService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Department
    {
        $data['parent_id'] = $this->parentId($data);

        return $this->transaction(function () use ($data) {
            $this->assertPlacement(null, $data['parent_id']);
            $this->assertUniqueName((string) $data['name'], $data['parent_id']);

            return Department::query()->create($data)->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Department $department, array $data): Department
    {
        $data['parent_id'] = array_key_exists('parent_id', $data) ? $this->parentId($data) : $department->parent_id;

        return $this->transaction(function () use ($department, $data) {
            $this->assertPlacement($department, $data['parent_id']);
            $this->assertUniqueName((string) ($data['name'] ?? $department->name), $data['parent_id'], $department->id);

            $moved = $data['parent_id'] !== $department->parent_id;
            $renamed = isset($data['name']) && $data['name'] !== $department->name;

            $department->update($data);

            if ($moved || $renamed) {
                $this->resyncDoctors($department->refresh());
            }

            return $department;
        });
    }

    /**
     * Remove it, with a reason — only once nothing is in it. A department in
     * use is deactivated instead, which keeps every record that names it.
     */
    public function remove(Department $department, string $reason): void
    {
        if ($department->children()->exists()) {
            throw new ConflictHttpException(
                "{$department->name} has sub-departments. Move or remove them first, or deactivate it instead."
            );
        }

        $doctors = $department->doctors()->count();
        $staff = $department->staff()->count();

        if ($doctors + $staff > 0) {
            $who = array_filter([
                $doctors ? $doctors.' '.($doctors === 1 ? 'doctor' : 'doctors') : null,
                $staff ? $staff.' staff' : null,
            ]);

            throw new ConflictHttpException(sprintf(
                '%s %s in %s. Move them to another department first, or deactivate it instead.',
                implode(' and ', $who),
                $doctors + $staff === 1 ? 'is' : 'are',
                $department->name,
            ));
        }

        $department->deleteWithReason($reason);
    }

    private function assertPlacement(?Department $self, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($self && $parentId === $self->id) {
            throw ValidationException::withMessages(['parent_id' => 'A department cannot be its own parent.']);
        }

        $parent = Department::query()->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'That department has been removed.']);
        }

        if (! $parent->isTopLevel()) {
            throw ValidationException::withMessages([
                'parent_id' => "{$parent->name} is itself a sub-department. Choose a department — sub-departments go one level deep.",
            ]);
        }

        if ($self && $self->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => "{$self->name} has sub-departments of its own, so it cannot become a sub-department.",
            ]);
        }
    }

    private function assertUniqueName(string $name, ?int $parentId, ?int $except = null): void
    {
        $taken = Department::query()
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => $parentId === null
                    ? "There is already a department called {$name}."
                    : "That department already has a sub-department called {$name}.",
            ]);
        }
    }

    /** A rename or a move changes the department text of every doctor it covers. */
    private function resyncDoctors(Department $department): void
    {
        if ($department->isTopLevel()) {
            $ids = $department->children()->pluck('id')->push($department->id);

            Doctor::query()->whereIn('department_id', $ids)->update(['specialisation' => $department->name]);

            return;
        }

        Doctor::query()
            ->where('department_id', $department->id)
            ->update(['specialisation' => $department->topLevelName()]);
    }

    /** @param  array<string, mixed>  $data */
    private function parentId(array $data): ?int
    {
        return empty($data['parent_id']) ? null : (int) $data['parent_id'];
    }

    private function transaction(callable $callback): mixed
    {
        return (new Department)->getConnection()->transaction($callback);
    }
}
