<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\DoctorRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DoctorRepository extends BaseRepository implements DoctorRepositoryInterface
{
    public function __construct(Doctor $model)
    {
        parent::__construct($model);
    }

    public function listing(?string $status = null, ?int $locationId = null): Builder
    {
        return $this->query()
            ->when(
                $status === 'active',
                fn (Builder $query) => $query->where('is_active', true)
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query) => $query->where('is_active', false)
            )
            ->when(
                $locationId !== null,
                /*
                 * Through the schedules, not through a column on the doctor.
                 * A doctor has no branch of their own — where they work is
                 * the set of places they are scheduled to sit.
                 */
                fn (Builder $query) => $query->whereIn(
                    'id',
                    DoctorSchedule::on($this->model->getConnectionName())
                        ->where('location_id', $locationId)
                        ->select('doctor_id')
                )
            );
    }

    public function activeOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
