<?php

namespace App\Services\Exports;

use App\Services\Documents\PdfTable;
use App\Support\Exports\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rows in, a file out — Excel, CSV or PDF, the same for every export.
 *
 * Rows are read a thousand at a time and written as they arrive, so a
 * register of any length never sits in memory whole. Each format writes a
 * value the way its readers expect: money a number in Excel (so it adds up),
 * plain "1250.00" in a CSV (so other software parses it), "1,250.00" in a
 * PDF (so a person reads it). Excel and PDF close with a totals row under
 * the amounts; a CSV does not, because a row that is not a record breaks
 * every program that imports one.
 */
final class ExportWriter
{
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /**
     * The most rows a PDF carries — about seven hundred pages, past anything
     * a clinic prints. Excel and CSV have no limit.
     */
    public const PDF_LIMIT = 25000;

    public function __construct(
        private readonly PdfTable $pdf,
    ) {}

    /**
     * @param  list<Column>  $columns  what to write, in order
     * @param  callable(Model): array<string, mixed>  $read  one record as its cells, keyed by column
     * @param  array{organization: string, title: string, noun: string, by: string, filters?: list<string>}  $heading
     * @param  (callable(Model): bool)|null  $muted  rows to print in grey in a PDF — an inactive patient
     */
    public function download(
        string $format,
        string $name,
        array $columns,
        Builder $query,
        callable $read,
        int $total,
        array $heading,
        ?callable $muted = null,
    ): Response|StreamedResponse|BinaryFileResponse {
        $file = $name.'-'.Carbon::today()->toDateString().'.'.$format;

        return match ($format) {
            'csv' => $this->csv($file, $columns, $query, $read),
            'pdf' => $this->pdf($file, $columns, $query, $read, $total, $heading, $muted),
            default => $this->xlsx($file, $columns, $query, $read, $heading['title']),
        };
    }

    /** A value as a person reads it — the PDF's cells, and the screen's preview. */
    public function display(Column $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            Column::MONEY => number_format((float) $value, 2),
            Column::NUMBER => is_float($value) ? number_format($value, 2) : number_format((int) $value),
            default => (string) $value,
        };
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function displayRow(array $columns, array $row): array
    {
        return array_map(fn (Column $column) => $this->display($column, $row[$column->key] ?? null), $columns);
    }

    /* ------------------------------------------------------------- formats */

    /** @param list<Column> $columns */
    private function csv(string $file, array $columns, Builder $query, callable $read): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $query, $read) {
            $out = fopen('php://output', 'w');

            // So Excel reads ₹ and names in any script as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn (Column $column) => $column->label, $columns));

            $query->chunk(1000, function ($records) use ($out, $columns, $read) {
                foreach ($records as $record) {
                    $row = $read($record);

                    fputcsv($out, array_map(function (Column $column) use ($row) {
                        $value = $row[$column->key] ?? null;

                        return $column->type === Column::MONEY && $value !== null
                            ? number_format((float) $value, 2, '.', '')
                            : ($value ?? '');
                    }, $columns));
                }
            });

            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param list<Column> $columns */
    private function xlsx(string $file, array $columns, Builder $query, callable $read, string $title): BinaryFileResponse
    {
        $sheet = (new XlsxWriter($title))->header(array_map(fn (Column $column) => $column->label, $columns));
        $sums = $this->emptySums($columns);
        $count = 0;

        $query->chunk(1000, function ($records) use ($sheet, $columns, $read, &$sums, &$count) {
            foreach ($records as $record) {
                $row = $read($record);
                $this->addUp($sums, $columns, $row);
                $count++;

                $sheet->row(array_map(fn (Column $column) => $row[$column->key] ?? null, $columns));
            }
        });

        if ($count > 0 && $sums !== []) {
            $sheet->footer($this->totalsRow($columns, $sums, fn (Column $column, float $sum) => round($sum, 2)));
        }

        return response()
            ->download($sheet->save(), $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /** @param list<Column> $columns */
    private function pdf(
        string $file,
        array $columns,
        Builder $query,
        callable $read,
        int $total,
        array $heading,
        ?callable $muted,
    ): Response {
        // A long register is a long render; it is still one request.
        set_time_limit(300);

        $shown = min($total, self::PDF_LIMIT);
        $sums = $this->emptySums($columns);

        $notes = array_values(array_filter([
            ($heading['filters'] ?? []) !== [] ? ['text' => 'Filtered: '.implode(' · ', $heading['filters']), 'tone' => 'info'] : null,
            $shown < $total ? [
                'text' => 'The first '.number_format($shown).' of '.number_format($total).' '.$heading['noun'].' are listed. '
                    .'Download Excel or CSV for all of them, or narrow the dates.',
                'tone' => 'warning',
            ] : null,
        ]));

        $rows = $query->lazy(1000)->take($shown)->map(function (Model $record) use ($columns, $read, $muted, &$sums) {
            $row = $read($record);
            $this->addUp($sums, $columns, $row);

            return ['cells' => $this->displayRow($columns, $row), 'muted' => $muted !== null && $muted($record)];
        });

        $bytes = $this->pdf->render(
            [
                'organization' => $heading['organization'],
                'title' => $heading['title'],
                'meta' => [
                    number_format($total).' '.$heading['noun'],
                    'Generated '.Carbon::now()->format('d M Y, h:i A').' by '.$heading['by'],
                ],
                'notes' => $notes,
            ],
            array_map(fn (Column $column) => [
                'label' => $column->label,
                'width' => $column->width,
                'align' => $column->isNumeric() ? 'right' : 'left',
                'bold' => $column === $columns[0],
                // "20 Oct 2026" over three lines, or an amount split, reads wrong.
                'nowrap' => $column->type !== Column::TEXT,
            ], $columns),
            $rows,
            empty: 'Nothing matches these filters.',
            // By reference: the sums fill in while the rows are drawn, after this is written.
            totals: function () use ($columns, &$sums) {
                return $sums === [] ? null : $this->totalsRow(
                    $columns,
                    $sums,
                    fn (Column $column, float $sum) => number_format($sum, 2),
                );
            },
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /* -------------------------------------------------------------- totals */

    /**
     * A running sum for each money column; none at all when there is no money.
     *
     * @param  list<Column>  $columns
     * @return array<string, float>
     */
    private function emptySums(array $columns): array
    {
        $sums = [];

        foreach ($columns as $column) {
            if ($column->type === Column::MONEY) {
                $sums[$column->key] = 0.0;
            }
        }

        return $sums;
    }

    /**
     * @param  array<string, float>  $sums
     * @param  list<Column>  $columns
     * @param  array<string, mixed>  $row
     */
    private function addUp(array &$sums, array $columns, array $row): void
    {
        foreach ($sums as $key => $sum) {
            $sums[$key] = $sum + (float) ($row[$key] ?? 0);
        }
    }

    /**
     * "Total" under the first column that is not money, the sums under the
     * money, nothing elsewhere.
     *
     * @param  list<Column>  $columns
     * @param  array<string, float>  $sums
     * @param  callable(Column, float): (string|float)  $write
     * @return list<string|float|null>
     */
    private function totalsRow(array $columns, array $sums, callable $write): array
    {
        $labelled = false;

        return array_map(function (Column $column) use ($sums, $write, &$labelled) {
            if (array_key_exists($column->key, $sums)) {
                return $write($column, $sums[$column->key]);
            }

            if (! $labelled) {
                $labelled = true;

                return 'Total';
            }

            return null;
        }, $columns);
    }
}
