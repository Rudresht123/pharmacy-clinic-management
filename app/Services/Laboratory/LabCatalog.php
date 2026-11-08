<?php

namespace App\Services\Laboratory;

use App\Models\Tenant\LabBranchPrice;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\LabTestParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The test master: a test and its parameters, saved together; its branch
 * prices; and the list an order screen picks from.
 *
 * A test and its parameters are ONE SAVE, in one transaction — a test with no
 * parameters would be a test nothing can be entered against. Reference ranges
 * are not saved here: they are a clinical decision behind their own tick
 * (ReferenceRanges, `laboratory.ranges_manage`).
 */
class LabCatalog
{
    /** What a test's own screen needs alongside it. */
    public const DETAIL = [
        'department', 'category', 'sampleType', 'container', 'method', 'outsourceLab',
        'parameters.unit', 'parameters.method', 'parameters.ranges', 'branchPrices.location',
    ];

    /** @param  array<string, mixed>  $data */
    public function create(array $data): LabTest
    {
        return (new LabTest)->getConnection()->transaction(function () use ($data) {
            $values = $this->testValues($data);
            $values['position'] ??= ((int) LabTest::query()->max('position')) + 1;

            $test = LabTest::query()->create($values);
            $this->syncParameters($test, $data['parameters'] ?? [], $data);

            return $test->fresh(self::DETAIL);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(LabTest $test, array $data): LabTest
    {
        return $test->getConnection()->transaction(function () use ($test, $data) {
            $locked = LabTest::query()->whereKey($test->id)->lockForUpdate()->firstOrFail();
            $locked->fill($this->testValues($data))->save();

            if (array_key_exists('parameters', $data)) {
                $this->syncParameters($locked, $data['parameters'] ?? [], $data);
            } elseif ($locked->result_layout === LabTest::LAYOUT_PARAMETERS && ! $locked->parameters()->exists()) {
                throw ValidationException::withMessages([
                    'parameters' => ['A test read as values needs at least one parameter.'],
                ]);
            }

            return $locked->fresh(self::DETAIL);
        });
    }

    /**
     * Take a test off the menu, with a reason.
     *
     * Orders already placed keep its name and price — they copied both — so
     * nothing that happened is lost. It simply stops being offered.
     */
    public function remove(LabTest $test, string $reason): void
    {
        $test->deleteWithReason($reason);
    }

    /**
     * What this test costs at a branch: the branch's own price where it has
     * one, the test's otherwise. A branch price with no tax takes the test's.
     *
     * @return array{price: float, tax_percent: float, branch_price: bool}
     */
    public function priceAt(LabTest $test, ?int $locationId): array
    {
        $own = $locationId === null ? null : ($test->relationLoaded('branchPrices')
            ? $test->branchPrices->firstWhere('location_id', $locationId)
            : $test->branchPrices()->where('location_id', $locationId)->first());

        return [
            'price' => round((float) ($own?->price ?? $test->price), 2),
            'tax_percent' => round((float) ($own?->tax_percent ?? $test->tax_percent), 2),
            'branch_price' => $own !== null,
        ];
    }

    /**
     * Replace a test's branch prices with the given set.
     *
     * @param  list<array{location_id: int, price: mixed, tax_percent?: mixed}>  $rows
     * @return Collection<int, LabBranchPrice>
     */
    public function savePrices(LabTest $test, array $rows): Collection
    {
        return $test->getConnection()->transaction(function () use ($test, $rows) {
            $current = $test->branchPrices()->get()->keyBy('location_id');
            $kept = [];

            foreach ($rows as $row) {
                $locationId = (int) $row['location_id'];

                $price = $current->get($locationId) ?? new LabBranchPrice([
                    'lab_test_id' => $test->id,
                    'location_id' => $locationId,
                ]);

                $price->fill([
                    'price' => $row['price'],
                    'tax_percent' => ($row['tax_percent'] ?? null) === '' ? null : ($row['tax_percent'] ?? null),
                ]);

                if (! $price->exists || $price->isDirty()) {
                    $price->save();
                }

                $kept[] = $locationId;
            }

            foreach ($current->except($kept) as $gone) {
                $gone->delete();
            }

            return $test->branchPrices()->with('location')->get();
        });
    }

    /**
     * Tests an order screen can offer, priced for the branch it is at.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function orderables(?string $search, ?int $locationId, int $limit = 25): Collection
    {
        $term = trim((string) $search);

        return LabTest::query()
            ->active()
            ->with(['department', 'sampleType', 'container', 'branchPrices' => fn ($q) => $q->where('location_id', $locationId ?? 0)])
            ->withCount('parameters')
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $query->where(fn (Builder $q) => $q
                    ->where('code', 'ILIKE', $like)
                    ->orWhere('name', 'ILIKE', $like)
                    ->orWhere('short_name', 'ILIKE', $like));
            })
            // An exact code first: somebody typing "CBC" means CBC.
            ->orderByRaw('CASE WHEN lower(code) = ? THEN 0 ELSE 1 END', [mb_strtolower($term)])
            ->orderBy('position')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (LabTest $test) => [
                'type' => 'test',
                'id' => $test->id,
                'code' => $test->code,
                'name' => $test->name,
                'short_name' => $test->short_name,
                'department_name' => $test->department?->name,
                'sample_type_name' => $test->sampleType?->name,
                'container_name' => $test->container?->name,
                'tat_hours' => $test->tat_hours,
                'is_outsourced' => (bool) $test->is_outsourced,
                'parameters_count' => (int) $test->parameters_count,
                ...$this->priceAt($test, $locationId),
            ]);
    }

    /**
     * The test's own columns from a request, without its parameters.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function testValues(array $data): array
    {
        $values = collect($data)->only((new LabTest)->getFillable())->all();

        // An in-house test has no partner lab or partner cost.
        if (array_key_exists('is_outsourced', $values) && ! $values['is_outsourced']) {
            $values['outsource_lab_id'] = null;
            $values['outsource_cost'] = null;
        }

        return $values;
    }

    /**
     * Make the test's parameters match the request, in order.
     *
     * A parameter that already has reference ranges keeps its kind of answer:
     * turning a number into text would leave numeric limits behind that no
     * longer mean anything, so the ranges must be cleared first.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $data
     */
    private function syncParameters(LabTest $test, array $lines, array $data): void
    {
        $lines = array_values($lines);

        // A new test's layout is the column default until it is read back.
        $layout = $data['result_layout'] ?? $test->result_layout ?? LabTest::LAYOUT_PARAMETERS;

        if ($lines === [] && $layout === LabTest::LAYOUT_PARAMETERS) {
            throw ValidationException::withMessages([
                'parameters' => ['A test read as values needs at least one parameter.'],
            ]);
        }

        $current = $test->parameters()->withCount('ranges')->get()->keyBy('id');
        $kept = [];

        foreach ($lines as $index => $line) {
            $parameter = ! empty($line['id'])
                ? ($current->get((int) $line['id']) ?? throw ValidationException::withMessages([
                    "parameters.{$index}.id" => ['That parameter is not on this test. Reload and try again.'],
                ]))
                : new LabTestParameter(['lab_test_id' => $test->id]);

            $type = $line['result_type'];

            if ($parameter->exists && $parameter->result_type !== $type && $parameter->ranges_count > 0) {
                throw ValidationException::withMessages([
                    "parameters.{$index}.result_type" => [
                        "{$parameter->name} has reference ranges. Remove them before changing how it is answered.",
                    ],
                ]);
            }

            $parameter->fill([
                'name' => $line['name'],
                'code' => $line['code'] ?? null,
                'section' => $line['section'] ?? null,
                'result_type' => $type,
                'unit_id' => $type === LabTestParameter::NUMERIC ? ($line['unit_id'] ?? null) : null,
                'decimals' => $type === LabTestParameter::NUMERIC ? ($line['decimals'] ?? null) : null,
                'choices' => $type === LabTestParameter::CHOICE ? array_values($line['choices'] ?? []) : null,
                'normal_value' => $type === LabTestParameter::NUMERIC ? null : ($line['normal_value'] ?? null),
                'method_id' => $line['method_id'] ?? null,
                'is_required' => (bool) ($line['is_required'] ?? true),
                'is_printed' => (bool) ($line['is_printed'] ?? true),
                'position' => $index,
            ]);

            unset($parameter->ranges_count);

            if (! $parameter->exists || $parameter->isDirty()) {
                $parameter->save();
            }

            $kept[] = $parameter->id;
        }

        foreach ($current->except($kept) as $gone) {
            unset($gone->ranges_count);
            $gone->deleteWithReason('Removed from the test');
        }
    }
}
