<?php

namespace App\Services\Billing;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Models\Tenant\NumberSeries;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Branch-wise numbering: what each series starts with, and what it issues next.
 *
 * CHOOSING, NOT ISSUING. Numbers are taken by the database inside the INSERT
 * of the invoice or payment itself — `hms_series_number()`, called from a
 * trigger. This class only reads how far each series has got and lets
 * somebody change the prefix it issues under.
 *
 * THE DEFAULT PREFIX HAS ONE DEFINITION, in SQL (`hms_series_default_prefix`),
 * because that is where a branch's first bill takes it from. This class asks
 * the database for it rather than keeping a PHP copy that could drift.
 */
class BranchNumbering
{
    /**
     * Every active branch, each with its three series.
     *
     * @return array{
     *     financial_year: string,
     *     branches: list<array{
     *         location_id: int,
     *         name: string,
     *         code: string,
     *         series: array<string, array{prefix: string, next: string, next_sequence: string}>,
     *     }>,
     * }
     */
    public function overview(): array
    {
        $year = self::financialYear(Carbon::now());

        $branches = Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $series = NumberSeries::query()
            ->whereIn('location_id', $branches->modelKeys())
            ->with(['counters' => fn ($query) => $query->where('financial_year', $year)])
            ->get()
            ->keyBy(fn (NumberSeries $row) => self::key($row->location_id, $row->document_type));

        return [
            'financial_year' => $year,
            'branches' => $branches->map(fn (Location $branch) => [
                'location_id' => (int) $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'series' => collect(NumberSeries::TYPES)->mapWithKeys(function (string $type) use ($branch, $series, $year) {
                    $existing = $series->get(self::key($branch->id, $type));

                    $prefix = $existing?->prefix ?? $this->defaultPrefix($branch->id, $type);
                    $sequence = self::sequence(($existing?->counters->first()?->last_number ?? 0) + 1);

                    return [$type => [
                        'prefix' => $prefix,
                        'next' => "{$prefix}/{$year}/{$sequence}",
                        'next_sequence' => $sequence,
                    ]];
                })->all(),
            ])->all(),
        ];
    }

    /**
     * Change the prefix some series issue under.
     *
     * @param  list<array{location_id: int, document_type: string, prefix: string}>  $changes
     *
     * @throws ValidationException naming the row whose prefix cannot be used
     */
    public function update(array $changes): void
    {
        /*
         * Every series' prefix as it will stand once this is saved — stored
         * ones, the default a branch that has not billed yet would take, and
         * what was just typed. A clash with a DEFAULT counts: otherwise that
         * branch's first bill would fail to save months from now.
         */
        $final = $this->currentPrefixes();

        foreach ($changes as $change) {
            $final[self::key($change['location_id'], $change['document_type'])] = $change['prefix'];
        }

        $errors = [];

        foreach ($changes as $index => $change) {
            $key = self::key($change['location_id'], $change['document_type']);
            $prefix = $change['prefix'];

            $shared = collect($final)->contains(
                fn (string $other, string $otherKey) => $otherKey !== $key && strcasecmp($other, $prefix) === 0,
            );

            if ($shared) {
                $errors["series.{$index}.prefix"] = "{$prefix} is already another series' prefix. Each one needs its own.";

                continue;
            }

            if ($this->issuedElsewhere($prefix, (int) $change['location_id'], $change['document_type'])) {
                $errors["series.{$index}.prefix"] = "Numbers starting {$prefix}/ were already issued by another series. Choose a prefix of its own.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            (new NumberSeries)->getConnection()->transaction(function () use ($changes) {
                foreach ($changes as $change) {
                    NumberSeries::query()->updateOrCreate(
                        ['location_id' => $change['location_id'], 'document_type' => $change['document_type']],
                        ['prefix' => $change['prefix']],
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Somebody else saved the same prefix between the check and here.
            throw ValidationException::withMessages([
                'series' => 'One of those prefixes was taken a moment ago. Reload and choose again.',
            ]);
        }
    }

    /** 1 April to 31 March, as the number prints it: 26-27. Mirrors hms_financial_year(). */
    public static function financialYear(Carbon $at): string
    {
        $start = $at->month < 4 ? $at->year - 1 : $at->year;

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    private static function sequence(int $number): string
    {
        return str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private static function key(int|string $locationId, string $type): string
    {
        return $locationId.':'.$type;
    }

    /** @return array<string, string> keyed by self::key() */
    private function currentPrefixes(): array
    {
        $prefixes = NumberSeries::query()
            ->get(['location_id', 'document_type', 'prefix'])
            ->mapWithKeys(fn (NumberSeries $row) => [self::key($row->location_id, $row->document_type) => $row->prefix])
            ->all();

        foreach (Location::query()->where('is_active', true)->pluck('id') as $locationId) {
            foreach (NumberSeries::TYPES as $type) {
                $prefixes[self::key($locationId, $type)] ??= $this->defaultPrefix((int) $locationId, $type);
            }
        }

        return $prefixes;
    }

    private function defaultPrefix(int $locationId, string $type): string
    {
        return (string) (new NumberSeries)->getConnection()
            ->selectOne('SELECT hms_series_default_prefix(?, ?) AS prefix', [$locationId, $type])
            ->prefix;
    }

    /**
     * Whether some OTHER series has already put numbers out under this prefix.
     *
     * A prefix given up is not free: its old numbers are still on paper, and a
     * second series reusing it would mint GGN/INV/26-27/00001 twice — the
     * second bill refused by the unique index at the counter, months later.
     */
    private function issuedElsewhere(string $prefix, int $locationId, string $type): bool
    {
        $like = $prefix.'/%';

        $invoices = Invoice::query()
            ->where('invoice_number', 'like', $like)
            ->when($type === NumberSeries::INVOICE, fn ($query) => $query->where('location_id', '!=', $locationId))
            ->exists();

        if ($invoices) {
            return true;
        }

        $payments = InvoicePayment::query()->where('receipt_number', 'like', $like);

        if ($type !== NumberSeries::INVOICE) {
            // Everything except this very series: the other direction of
            // money at this branch, or anything at another branch.
            $payments->where(fn ($query) => $query
                ->where('is_refund', $type === NumberSeries::RECEIPT)
                ->orWhereHas('invoice', fn ($invoice) => $invoice->where('location_id', '!=', $locationId)));
        }

        return $payments->exists();
    }
}
