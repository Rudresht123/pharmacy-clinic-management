<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Campaign;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Turns a segment's filters into the patients it means, now.
 *
 * Resolved at dispatch rather than when a segment is saved, so "patients over
 * fifty" includes whoever turned fifty since. That is the whole reason a
 * segment stores rules instead of a list of ids.
 *
 * Every filter is applied as an AND. An OR would need a nested structure the
 * audience builder does not offer, and inventing one here would let a campaign
 * reach people the screen never showed.
 */
class AudienceResolver
{
    /** Fields the builder may filter on, and how each one is read. */
    public const FIELDS = [
        'age' => 'Age group',
        'gender' => 'Gender',
        'city' => 'City',
        'registered' => 'Registered',
        'last_visit' => 'Last visit',
    ];

    /**
     * The query behind an audience, without running it.
     *
     * A builder rather than a collection because both callers want different
     * things from it: the preview wants a count and a breakdown, the
     * dispatcher wants to walk it in chunks without loading a clinic's whole
     * register into memory.
     *
     * @param  list<array<string, mixed>>  $filters
     */
    public function query(string $channel, array $filters = []): Builder
    {
        $query = Customer::query()->where('is_active', true);

        // Somebody with no address on this channel cannot be reached on it,
        // and counting them would promise an audience that does not exist.
        $query->when(
            $channel === CommunicationChannel::EMAIL,
            fn (Builder $q) => $q->whereNotNull('email')->where('email', '<>', ''),
            fn (Builder $q) => $q->whereNotNull('phone')->where('phone', '<>', ''),
        );

        foreach ($filters as $filter) {
            $this->apply($query, $filter);
        }

        return $query;
    }

    /**
     * How many this would reach, and the shape of it.
     *
     * The breakdown is what stops somebody sending a diabetes campaign to
     * twelve thousand people by leaving a filter off.
     *
     * @param  list<array<string, mixed>>  $filters
     * @return array<string, mixed>
     */
    public function preview(string $channel, array $filters = []): array
    {
        $base = $this->query($channel, $filters);

        $total = (clone $base)->count();

        // Coalesced: a patient with no gender recorded still has to be
        // counted somewhere, and a null key is not somewhere.
        $byGender = (clone $base)
            ->selectRaw("coalesce(gender, 'unknown') as bucket, count(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return [
            'total' => $total,
            'breakdown' => [
                'male' => (int) $byGender->get('male', 0),
                'female' => (int) $byGender->get('female', 0),
                'other' => $total - (int) $byGender->get('male', 0) - (int) $byGender->get('female', 0),
            ],
        ];
    }

    /**
     * One condition, or nothing at all.
     *
     * An unknown field is ignored rather than refused: filters are stored as
     * JSON and a segment written against a field that has since gone should
     * still send to a wider audience, not fail at midnight with nobody
     * watching.
     *
     * @param  array<string, mixed>  $filter
     */
    private function apply(Builder $query, array $filter): void
    {
        $field = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? 'equals';
        $value = $filter['value'] ?? null;

        if ($field === null || $value === null || $value === '') {
            return;
        }

        match ($field) {
            'age' => $this->applyAge($query, $operator, $filter),
            'gender' => $query->where('gender', $value),
            'city' => $query->whereRaw('lower(city) = ?', [mb_strtolower((string) $value)]),
            'registered' => $this->applyWindow($query, 'created_at', $operator, (int) $value),
            'last_visit' => $this->applyLastVisit($query, $operator, (int) $value),
            default => null,
        };
    }

    /**
     * Age is stored as a birth date, so the comparison runs the other way:
     * somebody older than 60 was born BEFORE the date 60 years ago.
     *
     * @param  array<string, mixed>  $filter
     */
    private function applyAge(Builder $query, string $operator, array $filter): void
    {
        $value = (int) ($filter['value'] ?? 0);

        if ($operator === 'between') {
            $upper = (int) ($filter['value_to'] ?? $value);

            $query->whereBetween('date_of_birth', [
                Carbon::now()->subYears($upper + 1)->toDateString(),
                Carbon::now()->subYears($value)->toDateString(),
            ]);

            return;
        }

        match ($operator) {
            'greater_than' => $query->where('date_of_birth', '<=', Carbon::now()->subYears($value)->toDateString()),
            'less_than' => $query->where('date_of_birth', '>=', Carbon::now()->subYears($value)->toDateString()),
            default => null,
        };
    }

    private function applyWindow(Builder $query, string $column, string $operator, int $months): void
    {
        $cutoff = Carbon::now()->subMonths($months);

        match ($operator) {
            'older_than_months' => $query->where($column, '<', $cutoff),
            default => $query->where($column, '>=', $cutoff),
        };
    }

    /**
     * "Has not been in for six months" includes somebody who has never been.
     *
     * A patient registered online who never attended is exactly who a
     * re-engagement campaign is for, and excluding them because they have no
     * appointment row would quietly drop the people it most wants.
     */
    private function applyLastVisit(Builder $query, string $operator, int $months): void
    {
        // Customer has no appointments relation, so the ids are gathered from
        // the Appointment model rather than reaching for the query builder.
        $seen = Appointment::query()
            ->where('appointment_date', '>=', Carbon::now()->subMonths($months)->toDateString())
            ->distinct()
            ->pluck('customer_id');

        match ($operator) {
            'older_than_months' => $query->whereNotIn('id', $seen),
            default => $query->whereIn('id', $seen),
        };
    }

    /** The address a campaign on this channel writes to. */
    public function addressFor(Customer $customer, string $channel): ?string
    {
        return $channel === CommunicationChannel::EMAIL ? $customer->email : $customer->phone;
    }

    /** A campaign's own filters, falling back to its segment's. */
    public function filtersFor(Campaign $campaign): array
    {
        return $campaign->audience_filters ?? $campaign->segment?->filters ?? [];
    }
}
