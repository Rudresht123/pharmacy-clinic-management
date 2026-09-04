<?php

namespace App\Repositories\Tenant\Contracts;

use App\Models\Tenant\Doctor;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

interface DoctorRepositoryInterface extends RepositoryInterface
{
    /**
     * The base query for the list screen, with its filters applied.
     *
     * `locationId` narrows to doctors who sit at a branch — answered through
     * their schedules, because that is the only place the doctor-to-branch
     * relationship exists.
     */
    public function listing(?string $status = null, ?int $locationId = null): Builder;

    /**
     * Active doctors, ordered by name — the dropdown case.
     *
     * @return Collection<int, Doctor>
     */
    public function activeOrdered(): Collection;
}
