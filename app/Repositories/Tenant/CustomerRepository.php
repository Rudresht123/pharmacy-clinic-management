<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Support\AgeBands;
use App\Support\Fields\CustomerFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

class CustomerRepository extends BaseRepository implements CustomerRepositoryInterface
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }

    /**
     * Register somebody, giving them a number if they arrived without one.
     *
     * Allocation is `max + 1`, and two receptionists registering at the same
     * moment will both read the same maximum. The partial unique index on
     * `customers.code` is what stops them both winning; this retries around
     * the collision rather than handing one of them an error they cannot act
     * on. The same arrangement as OPD tokens, for the same reason.
     */
    public function create(array $attributes): Customer
    {
        if (filled($attributes['code'] ?? null)) {
            return parent::create($attributes);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return parent::create([...$attributes, 'code' => $this->nextCode()]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'customers_code_unique')) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Could not allocate a patient number. Please try again.');
    }

    /**
     * The next number in sequence.
     *
     * Read off the highest code rather than off the row count, which would
     * repeat a number the moment anybody was deleted. The prefix is stripped
     * and the remainder compared as an integer so P-00009 sorts below P-00010,
     * which a plain string ordering would get backwards.
     */
    private function nextCode(): string
    {
        $highest = $this->model->newQuery()
            ->withTrashed()
            ->whereNotNull('code')
            ->where('code', 'like', Customer::PREFIX.'%')
            ->selectRaw('MAX(NULLIF(regexp_replace(code, \'\D\', \'\', \'g\'), \'\')::bigint) AS n')
            ->value('n');

        return Customer::PREFIX.str_pad(
            (string) (((int) $highest) + 1),
            Customer::CODE_LENGTH,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * @param  array<string, string|int|null>  $filters  Anything the filter
     *                                                   card offers; unknown
     *                                                   or blank values are
     *                                                   simply not applied.
     */
    public function listing(
        bool $activeOnly = false,
        ?string $status = null,
        ?int $registeredLocationId = null,
        array $filters = [],
    ): Builder {
        $value = fn (string $key) => filled($filters[$key] ?? null) ? (string) $filters[$key] : null;

        return $this->query()
            ->with('registeredLocation')
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when(
                $status === 'active',
                fn (Builder $query) => $query->where('is_active', true)
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query) => $query->where('is_active', false)
            )
            ->when(
                $registeredLocationId !== null,
                fn (Builder $query) => $query->where('registered_location_id', $registeredLocationId)
            )
            ->when(
                $value('gender'),
                fn (Builder $query, string $gender) => $query->where('gender', $gender)
            )
            ->when(
                $value('age_band'),
                fn (Builder $query, string $band) => AgeBands::apply($query, $band)
            )
            ->when(
                // Free text, so a partial town name still finds the rows —
                // "pun" has to reach Pune, and case cannot matter.
                $value('city'),
                fn (Builder $query, string $city) => $query->where('city', 'ILIKE', '%'.$city.'%')
            )
            ->when(
                $value('joined_within'),
                fn (Builder $query, string $days) => $query->where(
                    'created_at',
                    '>=',
                    now()->subDays((int) $days)
                )
            );
    }

    /**
     * How many joined in each of the last twelve months, oldest first.
     *
     * Months with nobody are filled in rather than skipped — a gap in the
     * sequence would draw as a narrower chart rather than as a quiet month,
     * which reads as the opposite of the truth.
     *
     * @return list<array{month: string, label: string, total: int, running: int}>
     */
    private function byMonth(int $months = 12): array
    {
        $spine = $this->monthSpine($months);

        $counts = $this->query()
            ->where('created_at', '>=', $spine[0])
            ->selectRaw("to_char(created_at, 'YYYY-MM') AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $series = [];
        $running = 0;

        foreach ($spine as $month) {
            $total = (int) ($counts[$month->format('Y-m')] ?? 0);
            $running += $total;

            $series[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'total' => $total,
                // The book of people as it stood at the end of that month,
                // counting only what this window covers.
                'running' => $running,
            ];
        }

        return $series;
    }

    /**
     * The last N months as 'YYYY-MM', oldest first — the spine every
     * time series in here is hung on, so two charts can never disagree
     * about which months exist.
     *
     * @return list<Carbon>
     */
    private function monthSpine(int $months = 12): array
    {
        $spine = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            $spine[] = now()->subMonths($back)->startOfMonth();
        }

        return $spine;
    }

    /**
     * The same monthly series, but one line per branch.
     *
     * Small multiples rather than several series on one axis: with a line
     * each, branches of very different sizes are compared by shape, and a
     * quiet branch is not flattened into the axis by a busy one. Each keeps
     * its own scale for that reason, which is why every row also prints its
     * total — shape and size are read separately, on purpose.
     *
     * @return list<array{location_id: int|null, label: string, total: int, points: list<int>}>
     */
    private function byBranchMonth(int $months = 12): array
    {
        $spine = $this->monthSpine($months);

        $rows = $this->query()
            ->where('created_at', '>=', $spine[0])
            ->selectRaw(
                "registered_location_id, to_char(created_at, 'YYYY-MM') AS bucket, COUNT(*) AS total"
            )
            ->groupBy('registered_location_id', 'bucket')
            ->get();

        $names = Location::on('organization')->pluck('name', 'id');

        /** @var array<string, array<string, int>> $grid */
        $grid = [];

        foreach ($rows as $row) {
            // Array keys cannot be null; '' stands in for "no branch".
            $key = $row->registered_location_id === null
                ? ''
                : (string) $row->registered_location_id;

            $grid[$key][$row->bucket] = (int) $row->total;
        }

        $series = [];

        foreach ($grid as $key => $buckets) {
            $points = [];

            foreach ($spine as $month) {
                $points[] = $buckets[$month->format('Y-m')] ?? 0;
            }

            $series[] = [
                'location_id' => $key === '' ? null : (int) $key,
                'label' => $key === '' ? 'Not recorded' : ($names[(int) $key] ?? 'Unknown'),
                'total' => array_sum($points),
                'points' => $points,
            ];
        }

        usort($series, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $series;
    }

    /**
     * Which days of the week people actually come in on.
     *
     * Seven fixed buckets starting on Monday, so the chart is the same
     * shape every week and a quiet Sunday reads as quiet rather than
     * missing. Postgres numbers Sunday 0, which is why the labels are
     * indexed rather than taken straight from the query.
     *
     * @return list<array{label: string, total: int}>
     */
    private function byWeekday(): array
    {
        $counts = $this->query()
            ->selectRaw('EXTRACT(DOW FROM created_at) AS dow, COUNT(*) AS total')
            ->groupBy('dow')
            ->pluck('total', 'dow');

        $days = [
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
            0 => 'Sun',
        ];

        $series = [];

        foreach ($days as $dow => $label) {
            $series[] = [
                'label' => $label,
                'total' => (int) ($counts[$dow] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * The gender split, in the registry's own order.
     *
     * Every option is returned even when nobody has it, because a missing
     * segment and an empty segment mean different things and the reader
     * cannot tell them apart from the chart alone. "Not recorded" is last
     * and is marked so the screen can grey it — an absence is not a category.
     *
     * @return list<array{key: string, label: string, total: int, muted: bool}>
     */
    private function byGender(): array
    {
        $counts = $this->query()
            ->selectRaw('gender, COUNT(*) AS total')
            ->groupBy('gender')
            ->pluck('total', 'gender');

        $series = [];

        foreach (CustomerFields::genderOptions() as $option) {
            $series[] = [
                'key' => $option['value'],
                'label' => $option['label'],
                'total' => (int) ($counts[$option['value']] ?? 0),
                'muted' => false,
            ];
        }

        $series[] = [
            'key' => 'unknown',
            'label' => 'Not recorded',
            // pluck() keys a null group as an empty string.
            'total' => (int) ($counts[''] ?? 0),
            'muted' => true,
        ];

        return $series;
    }

    /**
     * How the people served are spread across age bands.
     *
     * Ordinal, so the screen must not sort it by size — reordering age bands
     * by popularity destroys the only thing the chart is for. The bounds come
     * from App\Support\AgeBands, which the listing filter reads too.
     *
     * @return list<array{key: string, label: string, total: int, muted: bool}>
     */
    private function byAgeBand(): array
    {
        $counts = $this->query()
            ->whereNotNull('date_of_birth')
            ->selectRaw(AgeBands::caseExpression().' AS band, COUNT(*) AS total')
            // Postgres allows grouping by an output column name.
            ->groupBy('band')
            ->pluck('total', 'band');

        $series = [];

        foreach (AgeBands::all() as $band) {
            $series[] = [
                'key' => $band['key'],
                'label' => $band['label'],
                'total' => (int) ($counts[$band['key']] ?? 0),
                'muted' => false,
            ];
        }

        $missing = $this->query()->whereNull('date_of_birth')->count();

        if ($missing > 0) {
            $series[] = [
                'key' => AgeBands::UNKNOWN,
                'label' => 'Not recorded',
                'total' => $missing,
                'muted' => true,
            ];
        }

        return $series;
    }

    /**
     * The towns the organization actually serves, busiest first.
     *
     * Capped, because the tail of a city list is a table's job, not a
     * chart's. Blank strings are treated as absent — a form that was
     * skipped stores '' as readily as null.
     *
     * Grouped on the tidied name rather than the raw one: this is free text
     * typed at a counter, so "pune", "Pune" and " Pune " are one town, and
     * splitting them into three bars would draw each of them as smaller
     * than it is.
     *
     * @return list<array{label: string, total: int}>
     */
    private function byCity(int $limit = 6): array
    {
        $name = 'INITCAP(TRIM(city))';

        return $this->query()
            ->whereNotNull('city')
            ->whereRaw("TRIM(city) <> ''")
            ->selectRaw("{$name} AS town, COUNT(*) AS total")
            ->groupBy('town')
            ->orderByDesc('total')
            // Ties break alphabetically, so the chart does not reshuffle
            // between two identical loads.
            ->orderBy('town')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->town,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    public function stats(): array
    {
        $total = $this->query()->count();

        $byLocation = $this->query()
            ->selectRaw('registered_location_id, COUNT(*) AS total')
            ->groupBy('registered_location_id')
            ->pluck('total', 'registered_location_id');

        $names = Location::on('organization')->pluck('name', 'id');

        return [
            'total' => $total,
            'active' => $this->query()->where('is_active', true)->count(),
            'inactive' => $this->query()->where('is_active', false)->count(),

            // Signed up in the last 30 days — "new this month" in practice,
            // without the awkwardness of a partial calendar month.
            'recent' => $this->query()->where('created_at', '>=', now()->subDays(30))->count(),

            'by_month' => $this->byMonth(),
            'by_branch_month' => $this->byBranchMonth(),
            'by_weekday' => $this->byWeekday(),
            'by_gender' => $this->byGender(),
            'by_age_band' => $this->byAgeBand(),
            'by_city' => $this->byCity(),

            'by_location' => $byLocation
                ->map(fn ($count, $locationId) => [
                    'location_id' => $locationId ? (int) $locationId : null,
                    // Customers on file before this was recorded, and walk-ins.
                    'label' => $locationId ? ($names[$locationId] ?? 'Unknown') : 'Not recorded',
                    'total' => (int) $count,
                ])
                ->values()
                ->all(),
        ];
    }
}
