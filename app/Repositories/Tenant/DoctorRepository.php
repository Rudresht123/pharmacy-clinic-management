<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\DoctorRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
                 * Through the postings, not through a column on the doctor and
                 * no longer through the schedules either.
                 *
                 * A doctor still has no branch of their own — they are posted
                 * to as many as they cover. Reading the timetable instead
                 * meant somebody added a minute ago, before anyone had agreed
                 * their hours, was missing from the list of the very branch
                 * that added them.
                 */
                fn (Builder $query) => $query->where(
                    fn (Builder $inner) => $inner
                        ->whereIn(
                            'id',
                            DB::connection($this->model->getConnectionName())
                                ->table('doctor_locations')
                                ->where('location_id', $locationId)
                                ->where('is_active', true)
                                ->select('doctor_id')
                        )
                        /*
                         * Plus anybody posted nowhere at all.
                         *
                         * A doctor added a moment ago has no posting yet, and
                         * a filter that hid them would answer "not here" when
                         * the truth is "not placed anywhere" — leaving the
                         * branch that just added them unable to see them, or
                         * to post them. They stay visible until somebody says
                         * where they work, and the filter applies properly
                         * from then on.
                         */
                        ->orWhereNotIn(
                            'id',
                            DB::connection($this->model->getConnectionName())
                                ->table('doctor_locations')
                                ->select('doctor_id')
                        )
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
