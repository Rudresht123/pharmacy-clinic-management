<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the old lab price list (`lab_test_catalog`) into the test master.
 *
 * The price list was name, code, price and tax, with a branch able to hold its
 * own row for the same test. In the test master that becomes:
 *
 *   an organisation row     → a test, with ONE text parameter named after it
 *                             (old results were free text, so text is honest)
 *   a branch row whose code
 *   (or name) matches one   → that branch's price for the organisation's test
 *   a branch-only row       → a test of its own, priced at that row's price,
 *                             with the same branch price; other branches now
 *                             see it too, at that price
 *
 * Tests need a department, so one called "General" is made when the list has
 * anything to move and no department of that name exists. The old `specimen`
 * word (blood, urine…) is not turned into a sample type — it stays on the order
 * lines that carried it, and the lab picks real sample types on the new screen.
 *
 * Order lines that named a catalogue row are linked to the test it became, so
 * an order keeps knowing which test it was. Removed rows move across removed.
 * `lab_test_catalog` itself is left in place, frozen: nothing writes to it now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('lab_test_catalog')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $department = $this->generalDepartment($now);

        $taken = DB::table('lab_tests')->whereNull('deleted_at')->pluck('code')
            ->map(fn ($code) => mb_strtolower((string) $code))
            ->all();

        $key = fn (object $row) => mb_strtolower(trim((string) ($row->code ?: $row->name)));

        $testFor = [];   // catalogue id => test id
        $live = [];      // match key => test id, for branch rows to find

        foreach ($rows->whereNull('location_id') as $row) {
            $testFor[$row->id] = $this->test($row, $department, $taken, $now);

            if ($row->deleted_at === null) {
                $live[$key($row)] ??= $testFor[$row->id];
            }
        }

        foreach ($rows->whereNotNull('location_id') as $row) {
            $testId = $row->deleted_at === null ? ($live[$key($row)] ?? null) : null;

            if ($testId === null) {
                $testId = $this->test($row, $department, $taken, $now);

                if ($row->deleted_at === null) {
                    $live[$key($row)] = $testId;
                }
            }

            $testFor[$row->id] = $testId;

            if ($row->deleted_at === null) {
                DB::table('lab_branch_prices')->updateOrInsert(
                    ['location_id' => $row->location_id, 'lab_test_id' => $testId],
                    ['price' => $row->price, 'tax_percent' => $row->tax_percent, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        foreach ($testFor as $catalogueId => $testId) {
            DB::table('lab_order_items')
                ->where('lab_test_catalog_id', $catalogueId)
                ->whereNull('lab_test_id')
                ->update(['lab_test_id' => $testId]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('lab_tests')->whereNotNull('legacy_catalog_id')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('lab_order_items')->whereIn('lab_test_id', $ids)->update(['lab_test_id' => null]);
        DB::table('lab_branch_prices')->whereIn('lab_test_id', $ids)->delete();
        DB::table('lab_test_parameters')->whereIn('lab_test_id', $ids)->delete();
        DB::table('lab_tests')->whereIn('id', $ids)->delete();
    }

    private function generalDepartment(DateTimeInterface $now): int
    {
        $existing = DB::table('lab_departments')
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', ['general'])
            ->value('id');

        return (int) ($existing ?? DB::table('lab_departments')->insertGetId([
            'name' => 'General',
            'is_active' => true,
            'position' => (int) DB::table('lab_departments')->max('position') + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * One catalogue row as a test with its single parameter.
     *
     * @param  list<string>  $taken  live codes already used, lower-cased
     */
    private function test(object $row, int $department, array &$taken, DateTimeInterface $now): int
    {
        $code = mb_strtoupper(mb_substr(trim((string) ($row->code ?: 'T'.$row->id)), 0, 24));

        // Codes are unique among live tests; a removed row may share one.
        if ($row->deleted_at === null) {
            if (in_array(mb_strtolower($code), $taken, true)) {
                $code = mb_substr($code, 0, 24 - strlen('-'.$row->id)).'-'.$row->id;
            }

            $taken[] = mb_strtolower($code);
        }

        $testId = DB::table('lab_tests')->insertGetId([
            'code' => $code,
            'name' => mb_substr(trim((string) $row->name), 0, 191),
            'department_id' => $department,
            'result_layout' => 'parameters',
            'price' => $row->price,
            'tax_percent' => $row->tax_percent,
            'is_active' => (bool) $row->active,
            'position' => (int) $row->position,
            'legacy_catalog_id' => $row->id,
            'created_at' => $row->created_at ?? $now,
            'updated_at' => $now,
            'deleted_at' => $row->deleted_at,
            'deletion_reason' => $row->deleted_at ? 'Removed from the old price list' : null,
        ]);

        DB::table('lab_test_parameters')->insert([
            'lab_test_id' => $testId,
            'name' => mb_substr(trim((string) $row->name), 0, 120),
            'result_type' => 'text',
            'is_required' => true,
            'is_printed' => true,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $row->deleted_at,
        ]);

        return $testId;
    }
};
